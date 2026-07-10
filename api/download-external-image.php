<?php
/**
 * Download an external image URL to uploads/covers/, optionally
 * updating a game's cover column.
 *
 * All heavy lifting lives in includes/external-image-service.php so the
 * v2 endpoint (api/v2/external-image.php) shares the same logic without
 * having to fake $_SESSION and `require` this file.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/external-image-service.php';

$userId = requireUser();

header('Content-Type: application/json');

// POST-only — this endpoint downloads a file to disk and optionally
// updates the games table. SameSite=Lax does not fully protect
// GET-triggered mutations.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$imageUrl = $_POST['url'] ?? '';
$gameId   = isset($_POST['game_id']) ? (int)$_POST['game_id'] : null;
$type     = $_POST['type'] ?? 'front'; // 'front' or 'back'

$result = gt_download_and_save_cover($pdo, $userId, (string)$imageUrl, $gameId, (string)$type);

if (!$result['ok']) {
    sendJsonResponse(
        ['success' => false, 'message' => $result['message']],
        $result['status']
    );
}

$response = [
    'success'  => true,
    'message'  => 'Image downloaded successfully',
    'filename' => $result['filename'],
    'url'      => $result['url'],
];
if ($result['game_id'] !== null) {
    $response['game_id'] = $result['game_id'];
    $response['message'] = 'Image downloaded and saved successfully';
}
if (isset($result['warning'])) {
    $response['warning'] = $result['warning'];
    $response['message'] = 'Image downloaded but database update failed';
}
sendJsonResponse($response);
