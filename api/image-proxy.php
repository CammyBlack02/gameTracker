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

// Decode URL
$url = urldecode($url);

// Validate URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid URL');
}

// Only allow specific domains for security
$allowedDomains = [
    // TheCoverProject
    'coverproject.sfo2.cdn.digitaloceanspaces.com',
    'cdn.digitaloceanspaces.com',
    'thecoverproject.net',
    // Common game cover/image sources
    'vgchartz.com',
    'mobygames.com',
    'media-amazon.com',
    'm.media-amazon.com',
    'ebayimg.com',
    'i.ebayimg.com',
    'wikimedia.org',
    'upload.wikimedia.org',
    'pricecharting.com',
    'storage.googleapis.com', // PriceCharting uses this
    'thegamesdb.net',
    'cdn.thegamesdb.net',
    'staticneo.com',
    'cdn.staticneo.com',
    'lukiegames.com',
    'www.lukiegames.com',
    'psxdatacenter.com',
    'webuy.com',
    'uk.static.webuy.com',
    'static.thcdn.com',
    'thcdn.com',
    'dodo.ac',
    'doorwaytodorkness.co.uk',
    // Google images (for thumbnails)
    'gstatic.com',
    'encrypted-tbn0.gstatic.com'
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
curl_setopt($ch, CURLOPT_FAILONERROR, false); // Don't fail on HTTP errors, we'll check the code

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

// Log detailed error info for debugging
if ($error || $httpCode !== 200) {
    error_log("Image proxy failed - URL: $url, HTTP: $httpCode, Error: $error, Errno: $curlErrno");
}

if ($error) {
    error_log("Image proxy error for $url: $error");
    // Return a 1x1 transparent PNG instead of error text
    header('Content-Type: image/png');
    header('Cache-Control: no-cache');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

if ($httpCode !== 200 || empty($imageData)) {
    error_log("Image proxy: HTTP $httpCode for $url");
    // Return a 1x1 transparent PNG instead of error text
    header('Content-Type: image/png');
    header('Cache-Control: no-cache');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
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
