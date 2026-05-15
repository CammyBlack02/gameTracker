<?php
/**
 * Internal endpoint used by the test harness to verify auth wiring.
 * Returns the authenticated user's ID on success.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);
v2_ok(['user_id' => $userId, 'pong' => true]);
