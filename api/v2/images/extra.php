<?php
/**
 * GET /api/v2/images/extra.php?id=<image_id>&type=game|item&size=thumb|full
 *
 * Streams an extra photo (game_images or item_images) for the
 * authenticated user. The ?type=game|item param selects the table.
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/thumbnail.php';
require_once __DIR__ . '/../_auth.php';

$userId = v2_require_auth($pdo);

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'game';
$size = $_GET['size'] ?? 'full';

if ($id <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($type, ['game', 'item'], true)) {
    v2_error('bad_request', 'type must be game or item', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}

$table = $type === 'item' ? 'item_images' : 'game_images';
$stmt = $pdo->prepare("SELECT image_path AS path FROM $table WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['path'])) {
    v2_error('not_found', 'Image not found', 404);
}

$projectRoot = realpath(__DIR__ . '/../../..');
// Tolerate either bare filename (v1 convention) or prefixed path (defensive).
$filename = basename($row['path']);
$fullPath = $projectRoot . '/uploads/extras/' . $filename;

if ($size === 'thumb') {
    $thumbPath = gt_thumbnail_path($fullPath);
    if (file_exists($thumbPath)) {
        $fullPath = $thumbPath;
    }
}

if (!file_exists($fullPath)) {
    v2_error('not_found', 'Image file missing on disk', 404);
}

$realFull = realpath($fullPath);
$uploadsRoot = realpath($projectRoot . '/uploads');
if ($realFull === false || strpos($realFull, $uploadsRoot) !== 0) {
    v2_error('forbidden', 'Path escapes uploads directory', 403);
}

$info = @getimagesize($realFull);
$mime = $info['mime'] ?? 'application/octet-stream';
header_remove('Content-Type');
header("Content-Type: $mime");
header("Content-Length: " . filesize($realFull));
header("Cache-Control: private, max-age=3600");
readfile($realFull);
exit;
