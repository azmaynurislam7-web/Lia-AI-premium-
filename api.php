<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

// ===== RAZORPAY LIVE & FIREBASE CONFIG (lia-premium-fd4da) =====
$key_id     = getenv("RZP_KEY_ID") ?: "rzp_live_SeyrEq0RiSqkzs";
$key_secret = getenv("RZP_KEY_SECRET") ?: "A65T0Cuz7uHfpg09fBrs4Xni";

define("RZP_KEY_ID", $key_id);
define("RZP_KEY_SECRET", $key_secret);
define("FIREBASE_DB_URL", rtrim(getenv("FIREBASE_DB_URL") ?: "https://lia-premium-fd4da-default-rtdb.firebaseio.com", "/"));
define("FIREBASE_AUTH_SECRET", getenv("FIREBASE_AUTH_SECRET") ?: "");

function fbUrl(string $path): string {
    $url = FIREBASE_DB_URL . "/" . ltrim($path, "/") . ".json";
    if (FIREBASE_AUTH_SECRET !== "") {
        $url .= "?auth=" . urlencode(FIREBASE_AUTH_SECRET);
    }
    return $url;
}

function fbRequest(string $method, string $path, $data = null) {
    $ch = curl_init(fbUrl($path));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $headers = ["Content-Type: application/json"];
    if ($data !== null) {
        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        $headers[] = "Content-Length: " . strlen($payload);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) {
        return null;
    }
    return json_decode($res, true);
}

function getAppConfig(): array {
    $cfg = fbRequest("GET", "app_config");
    $price = (is_array($cfg) && isset($cfg["price_inr"]) && is_numeric($cfg["price_inr"]) && $cfg["price_inr"] > 0)
        ? (int)$cfg["price_inr"]
        : 249;

    return [
        "price_inr"     => $price,
        "app_name"      => is_array($cfg) && !empty($cfg["app_name"]) ? (string)$cfg["app_name"] : "KINETIX AI Assistant",
        "version"       => is_array($cfg) && !empty($cfg["version"]) ? (string)$cfg["version"] : "1.0.0",
        "sales_enabled" => is_array($cfg) && isset($cfg["sales_enabled"]) ? (bool)$cfg["sales_enabled"] : true
    ];
}

function rzpRequest(string $method, string $endpoint, array $body = []): array {
    $ch = curl_init("https://api.razorpay.com/v1/" . ltrim($endpoint, "/"));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_USERPWD, RZP_KEY_ID . ":" . RZP_KEY_SECRET);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    if ($method === "POST" || $method === "PUT" || $method === "PATCH") {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw, true);
    return [
        "ok"   => ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)),
        "code" => $httpCode,
        "data" => is_array($decoded) ? $decoded : []
    ];
}

$action = $_GET["action"] ?? "";

if ($action === "config") {
    $cfg = getAppConfig();
    echo json_encode([
        "success"       => true,
        "key_id"        => RZP_KEY_ID,
        "price_inr"     => $cfg["price_inr"],
        "app_name"      => $cfg["app_name"],
        "version"       => $cfg["version"],
        "sales_enabled" => $cfg["sales_enabled"]
    ]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    $input = [];
}

if ($action === "create_order") {
    $cfg = getAppConfig();
    if (!$cfg["sales_enabled"]) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Downloads are temporarily paused."]);
        exit;
    }

    $amountPaise = $cfg["price_inr"] * 100;
    $receipt = "rcpt_" . time() . "_" . bin2hex(random_bytes(3));

    $rzp = rzpRequest("POST", "orders", [
        "amount"          => $amountPaise,
        "currency"        => "INR",
        "receipt"         => $receipt,
        "payment_capture" => 1,
        "notes"           => [
            "product"   => $cfg["app_name"],
            "price_inr" => (string)$cfg["price_inr"]
        ]
    ]);

    if (!$rzp["ok"] || empty($rzp["data"]["id"])) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => $rzp["data"]["error"]["description"] ?? "Failed to create Razorpay order."
        ]);
        exit;
    }

    $orderId = $rzp["data"]["id"];
    $now = time();

    fbRequest("PUT", "orders/" . $orderId, [
        "order_id"     => $orderId,
        "amount_inr"   => $cfg["price_inr"],
        "amount_paise" => $amountPaise,
        "currency"     => "INR",
        "status"       => "pending",
        "created_at"   => $now
    ]);

    echo json_encode([
        "success"      => true,
        "key_id"       => RZP_KEY_ID,
        "order_id"     => $orderId,
        "amount_paise" => $amountPaise,
        "amount_inr"   => $cfg["price_inr"],
        "app_name"     => $cfg["app_name"]
    ]);
    exit;
}

if ($action === "verify_payment") {
    $paymentId = trim($input["razorpay_payment_id"] ?? "");
    $orderId   = trim($input["razorpay_order_id"] ?? "");
    $signature = trim($input["razorpay_signature"] ?? "");

    if ($paymentId === "" || $orderId === "" || $signature === "") {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Missing payment details."]);
        exit;
    }

    $expectedSig = hash_hmac("sha256", $orderId . "|" . $paymentId, RZP_KEY_SECRET);
    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Invalid payment signature."]);
        exit;
    }

    $payCheck = rzpRequest("GET", "payments/" . $paymentId);
    if (!$payCheck["ok"]) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Payment lookup failed on Razorpay."]);
        exit;
    }

    $orderRecord = fbRequest("GET", "orders/" . $orderId);
    $expectedPaise = (is_array($orderRecord) && isset($orderRecord["amount_paise"]))
        ? (int)$orderRecord["amount_paise"]
        : (getAppConfig()["price_inr"] * 100);

    $payOrder  = $payCheck["data"]["order_id"] ?? "";
    $payStatus = $payCheck["data"]["status"] ?? "";
    $payAmount = (int)($payCheck["data"]["amount"] ?? 0);

    if ($payOrder !== $orderId || ($payStatus !== "captured" && $payStatus !== "authorized") || $payAmount < $expectedPaise) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "Payment not captured or amount mismatch."]);
        exit;
    }

    $now = time();
    $ttlSeconds = 120;
    $expiresAt = $now + $ttlSeconds;
    $token = "dl_" . bin2hex(random_bytes(16));

    fbRequest("PUT", "download_tokens/" . $token, [
        "token"      => $token,
        "order_id"   => $orderId,
        "payment_id" => $paymentId,
        "amount_inr" => (int)($payAmount / 100),
        "paid"       => true,
        "used"       => false,
        "created_at" => $now,
        "expires_at" => $expiresAt
    ]);

    fbRequest("PATCH", "orders/" . $orderId, [
        "status"           => "paid",
        "payment_id"       => $paymentId,
        "download_token"   => $token,
        "token_expires_at" => $expiresAt,
        "paid_at"          => $now
    ]);

    echo json_encode([
        "success"      => true,
        "paid"         => true,
        "token"        => $token,
        "order_id"     => $orderId,
        "payment_id"   => $paymentId,
        "ttl_seconds"  => $ttlSeconds,
        "expires_at"   => $expiresAt,
        "download_url" => "download.php?token=" . urlencode($token)
    ]);
    exit;
}

http_response_code(400);
echo json_encode(["success" => false, "message" => "Unknown action."]);
