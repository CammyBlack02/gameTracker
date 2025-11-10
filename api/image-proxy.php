<?php
/**
 * Image Proxy - Fetches images from external sources to bypass CORS restrictions
 */

header('Content-Type: image/jpeg');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=86400'); // Cache for 1 day

$url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($url)) {
    http_response_code(400);
    echo 'Missing URL parameter';
    exit;
}

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo 'Invalid URL';
    exit;
}

// Only allow certain domains for security
$allowedDomains = [
    'thecoverproject.net',
    'coverproject.sfo2.cdn.digitaloceanspaces.com',
    'thegamesdb.net',
    'images.igdb.com'
];

$parsedUrl = parse_url($url);
$host = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';

$isAllowed = false;
foreach ($allowedDomains as $domain) {
    if (strpos($host, $domain) !== false) {
        $isAllowed = true;
        break;
    }
}

if (!$isAllowed) {
    http_response_code(403);
    echo 'Domain not allowed';
    exit;
}

// Fetch the image
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || $imageData === false) {
    http_response_code(404);
    echo 'Image not found';
    exit;
}

// Set appropriate content type
if ($contentType) {
    header('Content-Type: ' . $contentType);
}

// Output the image
echo $imageData;

