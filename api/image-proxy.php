<?php
/**
 * Image Proxy - Fetches images from external URLs to avoid CORS issues
 * Usage: /api/image-proxy.php?url=<encoded_url>
 */

// Disable output buffering early
if (ob_get_level()) {
    ob_end_clean();
}

$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing URL parameter');
}

// Decode URL if needed
$url = urldecode($url);

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid URL');
}

// Only allow specific domains for security
$allowedDomains = [
    'coverproject.sfo2.cdn.digitaloceanspaces.com',
    'cdn.digitaloceanspaces.com',
    'thecoverproject.net'
];

$parsedUrl = parse_url($url);
$host = $parsedUrl['host'] ?? '';

$allowed = false;
foreach ($allowedDomains as $domain) {
    if (strpos($host, $domain) !== false) {
        $allowed = true;
        break;
    }
}

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Domain not allowed');
}

// Fetch the image
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GameTracker/1.0)');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    error_log("Image proxy error for $url: $error");
    http_response_code(500);
    header('Content-Type: text/plain');
    die('Error fetching image');
}

if ($httpCode !== 200 || empty($imageData)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    die('Image not found');
}

// Set appropriate content type (default to jpeg if not detected)
if ($contentType && strpos($contentType, 'image/') === 0) {
    header('Content-Type: ' . $contentType);
} else {
    header('Content-Type: image/jpeg');
}

// Set cache headers
header('Cache-Control: public, max-age=31536000');

// Set Content-Length
header('Content-Length: ' . strlen($imageData));

echo $imageData;
