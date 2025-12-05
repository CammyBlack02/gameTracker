<?php
/**
 * Image Proxy - Fetches images from external URLs to avoid CORS issues
 * Usage: /api/image-proxy.php?url=<encoded_url>
 */

// Disable output buffering early and ensure clean output
while (ob_get_level()) {
    ob_end_clean();
}
// Disable compression that might interfere
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 'Off');

$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Missing URL parameter');
}

// Decode URL - handle double-encoding by decoding until it stops changing
$prevUrl = '';
while ($url !== $prevUrl) {
    $prevUrl = $url;
    $url = urldecode($url);
}

// Try to parse the URL first - more lenient than filter_var
$parsedUrl = @parse_url($url);
if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
    error_log("Image proxy: Invalid URL format - Original: " . $_GET['url'] . ", Decoded: $url");
    http_response_code(400);
    header('Content-Type: text/plain');
    die('Invalid URL format');
}

// Security: Only allow HTTPS URLs and basic validation
// $parsedUrl already set above
$scheme = $parsedUrl['scheme'] ?? '';
$host = $parsedUrl['host'] ?? '';

// Only allow HTTPS for security
if ($scheme !== 'https') {
    error_log("Image proxy blocked: Non-HTTPS scheme '$scheme' for URL: $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Only HTTPS URLs are allowed');
}

// Block local/internal IPs for security (simple check)
if (empty($host)) {
    error_log("Image proxy blocked: Empty host for URL: $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Invalid host');
}

// Block obvious localhost patterns
if ($host === 'localhost' || 
    $host === '127.0.0.1' || 
    $host === '::1' ||
    preg_match('/^192\.168\./', $host) ||
    preg_match('/^10\./', $host) ||
    preg_match('/^172\.(1[6-9]|2[0-9]|3[01])\./', $host)) {
    error_log("Image proxy blocked: Local/internal host '$host' for URL: $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Local/internal URLs not allowed');
}

// Fetch the image
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Increased timeout for large images
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; GameTracker/1.0)');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_FAILONERROR, false); // Don't fail on HTTP errors, we'll check the code
curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384); // 16KB buffer for better performance
curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1); // Use HTTP/1.1

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$sizeDownloaded = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
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
    error_log("Image proxy: HTTP $httpCode for $url, Size: " . strlen($imageData));
    // Return a 1x1 transparent PNG instead of error text
    header('Content-Type: image/png');
    header('Cache-Control: no-cache');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}

// Verify we got the expected amount of data
$actualSize = strlen($imageData);
if ($sizeDownloaded > 0 && $actualSize < $sizeDownloaded * 0.9) {
    error_log("Image proxy: Possible truncation - Expected: $sizeDownloaded, Got: $actualSize for $url");
}

// Set appropriate content type (default to jpeg if not detected)
if ($contentType && strpos($contentType, 'image/') === 0) {
    header('Content-Type: ' . $contentType);
} else {
    header('Content-Type: image/jpeg');
}

// Set cache headers
header('Cache-Control: public, max-age=31536000');

// Set Content-Length - must be set before any output
$contentLength = strlen($imageData);
header('Content-Length: ' . $contentLength);

// Ensure no output buffering interferes
if (ob_get_level()) {
    ob_end_flush();
}

// Output the image data
echo $imageData;

// Ensure output is flushed
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}
