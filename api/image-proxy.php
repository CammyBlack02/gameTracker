<?php
/**
 * Image Proxy - Fetches images from external URLs to avoid CORS issues
 * Usage: /api/image-proxy.php?url=<encoded_url>
 */

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year

$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    die('Missing URL parameter');
}

// Decode URL if needed
$url = urldecode($url);

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
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
    die('Domain not allowed');
}

// Fetch the image
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GameTracker/1.0)');

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || empty($imageData)) {
    http_response_code(404);
    die('Image not found');
}

// Set appropriate content type
if ($contentType && strpos($contentType, 'image/') === 0) {
    header('Content-Type: ' . $contentType);
}

echo $imageData;
