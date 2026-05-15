<?php
/**
 * POST /api/v2/games/cover-upload.php?game_id=<id>&face=front|back
 * Body: multipart/form-data with field "image"
 *
 * Validates the upload (uses the existing isValidImage() helper),
 * stores it under uploads/covers/, generates a thumbnail, updates
 * the games row's front_cover_image or back_cover_image.
 *
 * Response: { "data": { "path": "uploads/covers/<file>", "thumb_path": "..." } }
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';  // isValidImage, generateUniqueFilename
require_once __DIR__ . '/../../../includes/thumbnail.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');
$userId = v2_require_auth($pdo);

$gameId = (int)($_GET['game_id'] ?? 0);
$face   = $_GET['face'] ?? 'front';

if ($gameId <= 0)                              v2_error('bad_request', 'game_id required', 400);
if (!in_array($face, ['front', 'back'], true)) v2_error('bad_request', 'face must be front or back', 400);
if (!isset($_FILES['image']))                  v2_error('bad_request', 'image file required', 400);

// Verify game belongs to user.
$stmt = $pdo->prepare("SELECT id FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, $userId]);
if (!$stmt->fetch()) v2_error('not_found', 'Game not found', 404);

if (!isValidImage($_FILES['image'])) {
    v2_error('bad_request', 'Invalid image (type, size, or upload failed)', 400);
}

$projectRoot = realpath(__DIR__ . '/../../..');
$coversDir = $projectRoot . '/uploads/covers/';
$filename = generateUniqueFilename($_FILES['image']['name'], $coversDir);
$targetPath = $coversDir . $filename;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    v2_error('server_error', 'Failed to move uploaded file', 500);
}

// Generate thumbnail (best-effort).
$thumbPath = gt_thumbnail_path($targetPath);
gt_generate_thumbnail($targetPath, $thumbPath, 512);

// Update games row.
// Store bare filename (v1 convention) so the existing web UI still renders correctly.
$relative = 'uploads/covers/' . $filename;       // For response only
$relativeThumb = 'uploads/covers/thumbs/' . $filename;  // For response only
$col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
$upd = $pdo->prepare("UPDATE games SET $col = ? WHERE id = ? AND user_id = ?");
$upd->execute([$filename, $gameId, $userId]);    // Store BARE filename

v2_ok([
    'path'       => $relative,
    'thumb_path' => $relativeThumb,
]);
