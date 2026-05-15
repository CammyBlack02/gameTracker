<?php
/**
 * GET /api/v2/metacritic.php?title=<title>&platform=<platform>
 *
 * Thin Bearer-auth wrapper around the v1 metacritic endpoint.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;

require __DIR__ . '/../metacritic.php';
