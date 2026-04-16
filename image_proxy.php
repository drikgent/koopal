<?php
declare(strict_types=1);

// Lightweight image proxy for externally hosted chapter pages.
// Helps with hosts that block direct hotlink requests.

$url = trim((string) ($_GET['url'] ?? ''));
if ($url === '') {
    http_response_code(400);
    exit('Missing url');
}

$parts = parse_url($url);
if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
    http_response_code(400);
    exit('Invalid url');
}

$scheme = strtolower($parts['scheme']);
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('Unsupported scheme');
}

$host = strtolower((string) $parts['host']);
if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
    http_response_code(403);
    exit('Forbidden host');
}

$resolvedIps = gethostbynamel($host) ?: [];
foreach ($resolvedIps as $ip) {
    if (!filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    )) {
        http_response_code(403);
        exit('Forbidden host');
    }
}

$ch = curl_init($url);
if ($ch === false) {
    http_response_code(500);
    exit('Proxy init failed');
}

$headers = [
    'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
];

$referer = 'https://' . $host . '/';
if (str_contains($host, 'manhuaus.com')) {
    $referer = 'https://manhuaus.com/';
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_REFERER => $referer,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$body = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($body === false || $httpCode < 200 || $httpCode >= 300) {
    http_response_code(502);
    exit('Upstream image fetch failed' . ($curlErr !== '' ? ': ' . $curlErr : ''));
}

if ($contentType === '' || !str_starts_with(strtolower($contentType), 'image/')) {
    // Keep safe default if upstream omits type.
    $contentType = 'image/webp';
}

header('Content-Type: ' . $contentType);
header('Cache-Control: public, max-age=86400');
echo $body;

