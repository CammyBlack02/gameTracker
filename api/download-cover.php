<?php
/**
 * Download cover image from URL and save it
 */

require_once __DIR__ . '/../includes/auth.php';
$userId = requireUser();

header('Content-Type: application/json');

$imageUrl = $_GET['url'] ?? '';

if (empty($imageUrl)) {
    sendJsonResponse(['success' => false, 'message' => 'Image URL is required'], 400);
}

// Validate URL
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid URL'], 400);
}

// Fetch through the SSRF-safe helper — TLS verification stays on and
// private/loopback/reserved IPs (including cloud metadata) are blocked
// before the request is issued.
require_once __DIR__ . '/../includes/http-fetch.php';

try {
    $result = gt_safe_http_fetch($imageUrl, [
        'accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
    ]);
} catch (GtSsrfException $e) {
    error_log("download-cover blocked SSRF: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'URL not allowed'], 400);
} catch (GtFetchException $e) {
    error_log("download-cover fetch failed: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image'], 500);
}

$imageData   = $result['data'];
$contentType = $result['content_type'];

// Validate it's an image
if (!str_starts_with($contentType, 'image/')) {
    sendJsonResponse(['success' => false, 'message' => 'URL does not point to an image'], 400);
}

// Determine file extension from content type or URL
$extension = 'jpg';
if (strpos($contentType, 'png') !== false) {
    $extension = 'png';
} else if (strpos($contentType, 'gif') !== false) {
    $extension = 'gif';
} else if (strpos($contentType, 'webp') !== false) {
    $extension = 'webp';
} else {
    // Try to get extension from URL
    $urlPath = parse_url($imageUrl, PHP_URL_PATH);
    $urlExtension = pathinfo($urlPath, PATHINFO_EXTENSION);
    if (in_array(strtolower($urlExtension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
        $extension = strtolower($urlExtension);
    }
}

// Generate unique filename
$filename = generateUniqueFilename('cover_' . time() . '.' . $extension, COVERS_DIR);
$targetPath = COVERS_DIR . $filename;

// Save image
if (!file_put_contents($targetPath, $imageData)) {
    sendJsonResponse(['success' => false, 'message' => 'Failed to save image'], 500);
}

sendJsonResponse([
    'success' => true,
    'message' => 'Image downloaded successfully',
    'filename' => $filename,
    'url' => '/uploads/covers/' . $filename
]);

