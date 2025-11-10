<?php
/**
 * Image upload handler
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

$type = $_POST['type'] ?? 'cover'; // 'cover' or 'extra'
$gameId = $_POST['game_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (!isset($_FILES['image'])) {
    sendJsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
}

$file = $_FILES['image'];

if (!isValidImage($file)) {
    sendJsonResponse(['success' => false, 'message' => 'Invalid image file. Must be JPEG, PNG, GIF, or WebP and under 5MB'], 400);
}

// Determine upload directory
if ($type === 'cover') {
    $uploadDir = COVERS_DIR;
} else if ($type === 'extra') {
    if (!$gameId) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required for extra images'], 400);
    }
    $uploadDir = EXTRAS_DIR;
} else {
    sendJsonResponse(['success' => false, 'message' => 'Invalid upload type'], 400);
}

// Generate unique filename
$filename = generateUniqueFilename($file['name'], $uploadDir);
$targetPath = $uploadDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    sendJsonResponse(['success' => false, 'message' => 'Failed to save file'], 500);
}

// If it's an extra image, save to database
if ($type === 'extra' && $gameId) {
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO game_images (game_id, image_path) VALUES (?, ?)");
    $stmt->execute([$gameId, $filename]);
    $imageId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Image uploaded successfully',
        'image_path' => $filename,
        'image_id' => $imageId,
        'url' => '/uploads/extras/' . $filename
    ]);
} else {
    // For cover images, just return the path
    sendJsonResponse([
        'success' => true,
        'message' => 'Image uploaded successfully',
        'image_path' => $filename,
        'url' => '/uploads/covers/' . $filename
    ]);
}

