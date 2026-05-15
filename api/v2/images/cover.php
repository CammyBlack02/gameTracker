<?php
/**
 * GET /api/v2/images/cover.php?id=<game_id>&size=thumb|full[&face=front|back]
 *
 * Streams the cover image for the given game, if it belongs to the
 * authenticated user. Defaults: face=front, size=full.
 *
 * On success, sends raw image bytes with appropriate Content-Type.
 * Errors are returned as JSON.
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/thumbnail.php';
require_once __DIR__ . '/../_auth.php';

$userId = v2_require_auth($pdo);

$gameId = (int)($_GET['id'] ?? 0);
$size   = $_GET['size'] ?? 'full';
$face   = $_GET['face'] ?? 'front';

if ($gameId <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}
if (!in_array($face, ['front', 'back'], true)) {
    v2_error('bad_request', 'face must be front or back', 400);
}

$col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
$stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['path'])) {
    v2_error('not_found', 'Image not found', 404);
}

$projectRoot = realpath(__DIR__ . '/../../..');
// Tolerate either bare filename (v1 convention) or prefixed path (defensive).
$filename = basename($row['path']);
$fullPath = $projectRoot . '/uploads/covers/' . $filename;

if ($size === 'thumb') {
    $thumbPath = gt_thumbnail_path($fullPath);
    if (file_exists($thumbPath)) {
        $fullPath = $thumbPath;
    }
    // If no thumb exists, fall through to the full image rather than 404.
}

if (!file_exists($fullPath)) {
    v2_error('not_found', 'Image file missing on disk', 404);
}

// Bounds-check: never serve anything outside uploads/.
$realFull = realpath($fullPath);
$uploadsRoot = realpath($projectRoot . '/uploads');
if ($realFull === false || strpos($realFull, $uploadsRoot) !== 0) {
    v2_error('forbidden', 'Path escapes uploads directory', 403);
}

// Stream the file.
$info = @getimagesize($realFull);
$mime = $info['mime'] ?? 'application/octet-stream';
header_remove('Content-Type'); // _helpers.php's response functions would set JSON; we want the image MIME.
header("Content-Type: $mime");
header("Content-Length: " . filesize($realFull));
header("Cache-Control: private, max-age=3600");
readfile($realFull);
exit;
