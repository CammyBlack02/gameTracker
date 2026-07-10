<?php
/**
 * POST /api/v2/external-image.php
 *   Body: url=<https url>&game_id=<id>&type=front|back
 *
 * Downloads an external cover image, saves it under uploads/covers/,
 * and optionally updates games.{front|back}_cover_image for the
 * authenticated user's game row.
 *
 * All logic lives in includes/external-image-service.php — this file
 * is the Bearer-token → service-call adapter. No session-faking, no
 * `require` of a v1 file (removed in Phase 2c).
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../includes/external-image-service.php';

$userId = v2_require_auth($pdo);

// Accept POST (v1-compatible) and GET (backwards-compat with any caller
// that still uses GET — but the service enforces the same auth checks).
$imageUrl = $_POST['url']     ?? $_GET['url']     ?? '';
$gameId   = isset($_POST['game_id']) ? (int)$_POST['game_id']
          : (isset($_GET['game_id']) ? (int)$_GET['game_id'] : null);
$type     = $_POST['type']    ?? $_GET['type']    ?? 'front';

$result = gt_download_and_save_cover($pdo, $userId, (string)$imageUrl, $gameId, (string)$type);

if (!$result['ok']) {
    v2_error($result['code'], $result['message'], $result['status']);
}

$data = [
    'filename' => $result['filename'],
    'url'      => $result['url'],
    'game_id'  => $result['game_id'],
];
if (isset($result['warning'])) {
    $data['warning'] = $result['warning'];
}
v2_ok($data);
