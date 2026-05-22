<?php
/**
 * GET /api/v2/images/cover.php?id=<row_id>[&type=game|item][&face=front|back][&size=thumb|full]
 *
 * Streams the cover image for the given game or item row, if it
 * belongs to the authenticated user. Defaults: type=game (back-compat),
 * face=front, size=full.
 *
 * On success, sends raw image bytes with appropriate Content-Type.
 * Errors are returned as JSON.
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/thumbnail.php';
require_once __DIR__ . '/../_auth.php';

$userId = v2_require_auth($pdo);

$id   = (int)($_GET['id'] ?? 0);
$size = $_GET['size'] ?? 'full';
$face = $_GET['face'] ?? 'front';
$type = $_GET['type'] ?? 'game';   // 'game' (default, back-compat) or 'item'

if ($id <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}
if (!in_array($face, ['front', 'back'], true)) {
    v2_error('bad_request', 'face must be front or back', 400);
}
if (!in_array($type, ['game', 'item'], true)) {
    v2_error('bad_request', 'type must be game or item', 400);
}

// Resolve which table + columns to look up.
if ($type === 'item') {
    $col = $face === 'back' ? 'back_image' : 'front_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM items WHERE id = ? AND user_id = ?");
} else {
    $col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
    $stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
}
$stmt->execute([$id, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['path'])) {
    v2_error('not_found', 'Image not found', 404);
}

$path = $row['path'];

// front_cover_image is MEDIUMTEXT and can hold any of three formats
// (matching the web app's getImageUrl): a bare local filename, an
// HTTPS URL to an external host, or a data: URI with inline bytes.
// Bring v2 to parity with the web by handling all three.

// Format 1: data: URI — decode and stream inline.
if (strncmp($path, 'data:', 5) === 0) {
    if (preg_match('#^data:([^;,]+)(?:;base64)?,(.+)$#s', $path, $m)) {
        $mime = $m[1];
        $body = strpos($path, ';base64,') !== false
                ? base64_decode($m[2], true)
                : urldecode($m[2]);
        if ($body === false) {
            v2_error('not_found', 'Invalid data URI payload', 404);
        }
        header_remove('Content-Type');
        header("Content-Type: $mime");
        header("Content-Length: " . strlen($body));
        header("Cache-Control: private, max-age=3600");
        echo $body;
        exit;
    }
    v2_error('not_found', 'Malformed data URI', 404);
}

// Format 2: external HTTPS URL — fetch via curl and stream.
// (http:// is intentionally not supported, matching api/image-proxy.php.)
if (strncmp($path, 'https://', 8) === 0) {
    $ch = curl_init($path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'GameTracker/1.0',
    ]);
    $data        = curl_exec($ch);
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    curl_close($ch);

    if ($httpCode !== 200 || $data === false || $data === '') {
        v2_error('not_found', "Failed to fetch external image (HTTP $httpCode)", 404);
    }

    header_remove('Content-Type');
    header("Content-Type: $contentType");
    header("Content-Length: " . strlen($data));
    header("Cache-Control: private, max-age=3600");
    echo $data;
    exit;
}

// Format 3: bare filename under uploads/covers/ (v1 convention).
$projectRoot = realpath(__DIR__ . '/../../..');
// Tolerate either bare filename or accidentally prefixed path (defensive).
$filename = basename($path);
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
