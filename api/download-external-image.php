<?php
/**
 * Download external image and store locally
 * Called automatically when external URLs are set, or can be called manually
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
}

header('Content-Type: application/json');

$imageUrl = $_POST['url'] ?? $_GET['url'] ?? '';
$gameId = $_POST['game_id'] ?? $_GET['game_id'] ?? null;
$type = $_POST['type'] ?? $_GET['type'] ?? 'front'; // 'front' or 'back'

if (empty($imageUrl)) {
    sendJsonResponse(['success' => false, 'message' => 'URL is required'], 400);
}

// Validate URL
$parsedUrl = @parse_url($imageUrl);
if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid URL format'], 400);
}

// Only allow HTTPS
if ($parsedUrl['scheme'] !== 'https') {
    sendJsonResponse(['success' => false, 'message' => 'Only HTTPS URLs are allowed'], 400);
}

// Block local/internal IPs
$host = $parsedUrl['host'] ?? '';
if (preg_match('/^(localhost|127\.0\.0\.1|::1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)) {
    sendJsonResponse(['success' => false, 'message' => 'Local/internal URLs are not allowed'], 400);
}

// Download image using cURL
$ch = curl_init($imageUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_USERAGENT => 'GameTracker/1.0',
    CURLOPT_HTTPHEADER => [
        'Accept: image/jpeg,image/png,image/gif,image/webp,*/*'
    ]
]);

$imageData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    error_log("Failed to download image: $error");
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image: ' . $error], 500);
}

if ($httpCode !== 200 || empty($imageData)) {
    error_log("Failed to download image: HTTP $httpCode");
    sendJsonResponse(['success' => false, 'message' => "Failed to download image: HTTP $httpCode"], 500);
}

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

