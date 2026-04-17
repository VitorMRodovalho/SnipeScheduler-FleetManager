<?php
// image_proxy.php
//
// Authenticated proxy for Snipe-IT model images.
// Accepts either:
//   ?src=/uploads/models/...
// or
//   ?src=https://snipeit.example.com/uploads/models/...
//
// Validates that the final URL points at the configured Snipe-IT host AND
// that the response is an image. Redirects are NOT followed — a 3xx from
// the upstream server is rejected outright so it cannot escape the host check.

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

$config   = load_config();
$snipeCfg = $config['snipeit'] ?? [];

$baseUrl   = rtrim($snipeCfg['base_url'] ?? '', '/');
$verifySsl = !empty($snipeCfg['verify_ssl']);

// ---------------------------------------------------------------------
// Validate input
// ---------------------------------------------------------------------
$srcParam = $_GET['src'] ?? '';

if ($srcParam === '') {
    http_response_code(400);
    echo 'Missing src parameter';
    exit;
}

$src = urldecode($srcParam);

// Build full URL
if (preg_match('#^https?://#i', $src)) {
    $url = $src;
} else {
    if ($baseUrl === '') {
        http_response_code(500);
        echo 'Snipe-IT base URL not configured.';
        exit;
    }
    $url = $baseUrl . '/' . ltrim($src, '/');
}

// ---------------------------------------------------------------------
// Host validation (anti-SSRF).
// parse_url must succeed, scheme must be http/https, and host must match
// the configured Snipe-IT host exactly.
// ---------------------------------------------------------------------
$parts = parse_url($url);
if (!$parts || empty($parts['host']) || empty($parts['scheme'])) {
    http_response_code(400);
    echo 'Invalid src parameter';
    exit;
}
if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
    http_response_code(400);
    echo 'Invalid src scheme';
    exit;
}

$baseHost = parse_url($baseUrl, PHP_URL_HOST);
if (!$baseHost || strcasecmp($baseHost, $parts['host']) !== 0) {
    http_response_code(400);
    echo 'Invalid src parameter (host mismatch)';
    exit;
}

// ---------------------------------------------------------------------
// Fetch image from Snipe-IT.
// FOLLOWLOCATION is disabled so a 302 cannot redirect us off-host and
// bypass the validation above.
// ---------------------------------------------------------------------
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER         => true,
    CURLOPT_SSL_VERIFYPEER => $verifySsl,
    CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
]);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(502);
    echo 'Error fetching image';
    curl_close($ch);
    exit;
}

$headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$body        = substr($response, $headerSize);
curl_close($ch);

// Reject redirects outright — we do not follow them and will not silently
// serve a non-image payload. A 3xx likely means the upstream moved the file.
if ($httpCode >= 300 && $httpCode < 400) {
    http_response_code(502);
    echo 'Upstream redirect not allowed';
    exit;
}

if ($httpCode >= 400) {
    http_response_code($httpCode);
    echo 'Error fetching image (HTTP ' . (int)$httpCode . ')';
    exit;
}

// Only serve image content.
$ct = strtolower(trim((string)$contentType));
if ($ct === '' || strpos($ct, 'image/') !== 0) {
    http_response_code(415);
    echo 'Upstream response is not an image';
    exit;
}

// ---------------------------------------------------------------------
// Stream the image back to the user.
// ---------------------------------------------------------------------
header('Content-Type: ' . $contentType);
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

echo $body;
