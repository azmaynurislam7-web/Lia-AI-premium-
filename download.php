<?php
define("FIREBASE_DB_URL", rtrim(getenv("FIREBASE_DB_URL") ?: "https://lia-premium-fd4da-default-rtdb.firebaseio.com", "/"));
define("FIREBASE_AUTH_SECRET", getenv("FIREBASE_AUTH_SECRET") ?: "");
define("APK_FILE_PATH", getenv("APK_FILE_PATH") ?: __DIR__ . "/private_storage/kinetix_ai.apk");
define("APK_DOWNLOAD_NAME", "KINETIX_AI_Assistant.apk");

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

$token = preg_replace("/[^a-zA-Z0-9_]/", "", $_GET["token"] ?? "");
if ($token === "") {
    http_response_code(400);
    exit("Invalid download token.");
}

$record = fbRequest("GET", "download_tokens/" . $token);
if (!is_array($record) || empty($record["paid"])) {
    http_response_code(403);
    exit("Unauthorized download request.");
}

if (!empty($record["used"])) {
    http_response_code(410);
    exit("Download token already used.");
}

$now = time();
$expiresAt = (int)($record["expires_at"] ?? 0);
if ($now > $expiresAt) {
    http_response_code(410);
    exit("2-minute download time expired. Order ID: " . htmlspecialchars($record["order_id"] ?? ""));
}

if (!is_file($APK_FILE_PATH) || !is_readable($APK_FILE_PATH)) {
    http_response_code(500);
    exit("APK file not found on server.");
}

fbRequest("PATCH", "download_tokens/" . $token, [
    "used"          => true,
    "downloaded_at" => $now
]);

while (ob_get_level()) {
    ob_end_clean();
}

$fileSize = filesize($APK_FILE_PATH);
header("Content-Description: File Transfer");
header("Content-Type: application/vnd.android.package-archive");
header("Content-Disposition: attachment; filename=\"" . APK_DOWNLOAD_NAME . "\"");
header("Content-Transfer-Encoding: binary");
header("Expires: 0");
header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
header("Pragma: public");
header("Content-Length: " . $fileSize);
header("X-Robots-Tag: noindex, nofollow");

$fp = fopen($APK_FILE_PATH, "rb");
if ($fp) {
    while (!feof($fp)) {
        echo fread($fp, 65536);
        flush();
    }
    fclose($fp);
}
exit;
