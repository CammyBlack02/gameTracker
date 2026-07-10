<?php
/**
 * Download external image and store locally
 * Called automatically when external URLs are set, or can be called manually
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/thumbnail.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
}

header('Content-Type: application/json');

// POST-only — this endpoint downloads a file to disk and optionally
// updates the games table. SameSite=Lax does not fully protect
// GET-triggered mutations.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$imageUrl = $_POST['url'] ?? '';
$gameId = $_POST['game_id'] ?? null;
$type = $_POST['type'] ?? 'front'; // 'front' or 'back'

if (empty($imageUrl)) {
    sendJsonResponse(['success' => false, 'message' => 'URL is required'], 400);
}

// Fetch through the shared SSRF-safe helper — it enforces HTTPS,
// resolves the host, and rejects private/loopback/reserved IPs
// (including 169.254.169.254 cloud metadata).
require_once __DIR__ . '/../includes/http-fetch.php';

try {
    $result = gt_safe_http_fetch($imageUrl, [
        'accept' => 'image/jpeg,image/png,image/gif,image/webp,*/*',
    ]);
} catch (GtSsrfException $e) {
    error_log("download-external-image blocked SSRF: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'URL not allowed'], 400);
} catch (GtFetchException $e) {
    error_log("download-external-image fetch failed: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image'], 500);
}

$imageData   = $result['data'];
$contentType = $result['content_type'];

// Validate it's actually an image
if (!isValidImageData($imageData, $contentType)) {
    sendJsonResponse(['success' => false, 'message' => 'Downloaded data is not a valid image'], 400);
}

// Determine file extension from content type or URL
$extension = 'jpg';
if (stripos($contentType, 'png') !== false) {
    $extension = 'png';
} else if (stripos($contentType, 'gif') !== false) {
    $extension = 'gif';
} else if (stripos($contentType, 'webp') !== false) {
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
$filename = generateUniqueFilename('cover_' . time() . '_' . uniqid() . '.' . $extension, COVERS_DIR);
$targetPath = COVERS_DIR . $filename;

// Save image
if (!file_put_contents($targetPath, $imageData)) {
    error_log("Failed to save image to: $targetPath");
    sendJsonResponse(['success' => false, 'message' => 'Failed to save image'], 500);
}
// Generate thumbnail (best-effort; failure is non-fatal)
gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

// Update database if game ID provided
if ($gameId) {
    try {
        $column = $type === 'back' ? 'back_cover_image' : 'front_cover_image';
        $stmt = $pdo->prepare("UPDATE games SET $column = ? WHERE id = ?");
        $stmt->execute([$filename, $gameId]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Image downloaded and saved successfully',
            'filename' => $filename,
            'url' => '/uploads/covers/' . $filename,
            'game_id' => $gameId
        ]);
    } catch (PDOException $e) {
        error_log("Failed to update database: " . $e->getMessage());
        // Still return success since file was saved
        sendJsonResponse([
            'success' => true,
            'message' => 'Image downloaded but database update failed',
            'filename' => $filename,
            'url' => '/uploads/covers/' . $filename,
            'warning' => 'Database update failed: ' . $e->getMessage()
        ]);
    }
} else {
    sendJsonResponse([
        'success' => true,
        'message' => 'Image downloaded successfully',
        'filename' => $filename,
        'url' => '/uploads/covers/' . $filename
    ]);
}

/**
 * Validate image data
 */
function isValidImageData($data, $contentType = null) {
    if (empty($data) || strlen($data) < 100) {
        return false;
    }
    
    // Check magic bytes
    $magicBytes = substr($data, 0, 4);
    $magicBytesHex = bin2hex($magicBytes);
    
    // JPEG: FF D8 FF
    if (substr($magicBytesHex, 0, 6) === 'ffd8ff') {
        return true;
    }
    
    // PNG: 89 50 4E 47
    if ($magicBytesHex === '89504e47') {
        return true;
    }
    
    // GIF: 47 49 46 38
    if (substr($magicBytesHex, 0, 8) === '47494638') {
        return true;
    }
    
    // WebP: Check for RIFF...WEBP
    if (substr($magicBytesHex, 0, 8) === '52494646' && strpos($data, 'WEBP') !== false) {
        return true;
    }
    
    return false;
}

