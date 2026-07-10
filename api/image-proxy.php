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

// Fetch through the SSRF-safe helper. It resolves the host and rejects
// private/loopback/reserved IPs (including 169.254.169.254 cloud metadata),
// enforces HTTPS + TLS verification, and revalidates each redirect hop.
require_once __DIR__ . '/../includes/http-fetch.php';

try {
    $result = gt_safe_http_fetch($url, [
        'timeout'         => 60,
        'connect_timeout' => 15,
        'user_agent'      => 'Mozilla/5.0 (compatible; GameTracker/1.0)',
        'accept'          => 'image/webp,image/apng,image/*,*/*;q=0.8',
    ]);
    $imageData   = $result['data'];
    $contentType = $result['content_type'];
} catch (GtSsrfException $e) {
    error_log("Image proxy blocked SSRF: {$e->getMessage()} for URL $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Blocked');
} catch (GtFetchException $e) {
    error_log("Image proxy fetch failed: {$e->getMessage()} for URL $url");
    // Return a 1x1 transparent PNG (existing failure UX).
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
