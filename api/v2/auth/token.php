<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * POST /api/v2/auth/token
 *
 * Body (form-encoded):
 *   username, password, device_name (optional)
 *
 * Response on success:
 *   { "data": { "token": "<64 hex>", "user_id": N, "username": "..." } }
 *
 * The raw token is returned to the client EXACTLY ONCE. Only its hash
 * is stored server-side.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$deviceName = $_POST['device_name'] ?? null;

if ($username === '' || $password === '') {
    v2_error('bad_request', 'username and password are required', 400);
}

$stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    if (function_exists('logSecurityEvent')) {
        logSecurityEvent('v2_token_failed', ['username' => $username]);
    }
    v2_error('invalid_credentials', 'Username or password is incorrect', 401);
}

// Generate 32 random bytes → 64 hex chars
$rawToken = bin2hex(random_bytes(32));
$tokenHash = v2_hash_token($rawToken);

$insert = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name) VALUES (?, ?, ?)");
$insert->execute([$user['id'], $tokenHash, $deviceName]);

if (function_exists('logSecurityEvent')) {
    logSecurityEvent('v2_token_issued', ['user_id' => $user['id'], 'device' => $deviceName]);
}

v2_ok([
    'token'    => $rawToken,
    'user_id'  => (int)$user['id'],
    'username' => $user['username'],
]);
