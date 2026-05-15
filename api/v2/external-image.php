<?php
/**
 * GET /api/v2/external-image.php?url=<https url>&game_id=<id>&type=front|back
 *
 * Thin wrapper around v1's download-external-image.php that uses Bearer-token
 * auth instead of session auth. The v1 file's logic (validating the URL,
 * downloading via curl, saving locally) is reused by injecting the
 * authenticated user into the session before requiring it.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);

// Validate game_id ownership before delegating to v1 (which doesn't check).
$gameId = (int)($_GET['game_id'] ?? $_POST['game_id'] ?? 0);
if ($gameId > 0) {
    $check = $pdo->prepare("SELECT id FROM games WHERE id = ? AND user_id = ?");
    $check->execute([$gameId, $userId]);
    if (!$check->fetch()) {
        v2_error('not_found', 'Game not found', 404);
    }
}

// The v1 endpoint reads $_SESSION['user_id'] / $_SESSION['username'].
// Populate them so the v1 logic accepts the request.
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;

// The v1 file expects POST or GET; both work. It writes its own JSON
// response and exits, so we just include it.
require __DIR__ . '/../download-external-image.php';
