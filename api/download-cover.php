<?php
/**
 * Download cover image from URL and save it
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

$imageUrl = $_GET['url'] ?? '';

if (empty($imageUrl)) {
    sendJsonResponse(['success' => false, 'message' => 'Image URL is required'], 400);
}

// Validate URL
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid URL'], 400);
}

// Initialize cURL to download image
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $imageUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
    'Accept-Language: en-US,en;q=0.9'
]);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image'], 500);
}

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

