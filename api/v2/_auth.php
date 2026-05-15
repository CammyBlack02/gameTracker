<?php
/**
 * Bearer-token authentication for /api/v2/.
 *
 * Tokens are random 32 bytes, presented to clients as 64 hex chars.
 * The server stores SHA-256 of the token (not bcrypt — we look tokens
 * up on every request, so the hash must be cheap and deterministic).
 *
 * Usage at the top of an endpoint:
 *
 *   require_once __DIR__ . '/../_auth.php';
 *   require_once __DIR__ . '/../_helpers.php';
 *   $userId = v2_require_auth($pdo);
 */

function v2_extract_bearer(): ?string {
    // Try standard Apache/Nginx header first.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Some setups expose it under a different key.
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * Hash a raw token for storage/lookup. SHA-256 is enough — the token is
 * already a high-entropy random value; we just need a deterministic
 * lookup key that doesn't leak the original if the DB is dumped.
 */
function v2_hash_token(string $rawToken): string {
    return hash('sha256', $rawToken);
}

/**
 * Verify the request's Bearer token. On success, returns the user ID.
 * On failure, sends a JSON error response and exits.
 */
function v2_require_auth(PDO $pdo): int {
    $raw = v2_extract_bearer();
    if ($raw === null) {
        v2_error('missing_token', 'Authorization: Bearer <token> required', 401);
    }
    $hash = v2_hash_token($raw);
    $stmt = $pdo->prepare("SELECT id, user_id FROM api_tokens
        WHERE token_hash = ? AND revoked_at IS NULL");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        v2_error('invalid_token', 'Token is invalid or revoked', 401);
    }
    // Update last_used_at lazily (don't block the request on this).
    $upd = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
    $upd->execute([$row['id']]);
    return (int)$row['user_id'];
}
