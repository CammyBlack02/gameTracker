<?php
/**
 * Internal endpoint used by the test harness to verify auth wiring.
 * Accepts GET and POST — POST is used by dual-auth tests to exercise
 * the CSRF verification branch in v2_require_auth().
 * Returns the authenticated user's ID on success.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    v2_error('method_not_allowed', 'Use GET or POST', 405);
}

$userId = v2_require_auth($pdo);
v2_ok(['user_id' => $userId, 'pong' => true]);
