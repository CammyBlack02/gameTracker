<?php
/**
 * Authentication for /api/v2/ endpoints.
 *
 * Accepts EITHER:
 *   1. Authorization: Bearer <64-hex-token>   — iOS / API clients
 *   2. An active PHP session (from api/auth.php?action=login)
 *      PLUS a valid CSRF token on mutating requests (POST/PUT/DELETE/PATCH)
 *
 * The browser uses path (2): its HttpOnly session cookie is strictly
 * safer against XSS than any JS-readable Bearer token would be, and the
 * CSRF token the frontend already sends via X-CSRF-Token (see js/api.js)
 * covers the request-forgery risk that Bearer tokens would sidestep.
 *
 * This is NOT the session-faking pattern Fable §2 called out — no v1
 * file inclusion, no forced $_SESSION state, no output-buffer reshaping.
 * The session referred to here is the same one PHP set during v1 login;
 * we just recognise it as a valid credential.
 *
 * Usage at the top of an endpoint (unchanged):
 *
 *   require_once __DIR__ . '/_helpers.php';
 *   require_once __DIR__ . '/../../includes/config.php';
 *   require_once __DIR__ . '/_auth.php';
 *   $userId = v2_require_auth($pdo);
 */

require_once __DIR__ . '/../../includes/csrf.php';

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
 * @internal — Bearer credential path. Returns user_id on hit, null on
 * absence (no Authorization header). Exits with 401 invalid_token if a
 * Bearer is present but doesn't match a live token.
 */
function _v2_try_bearer(PDO $pdo): ?int {
    $raw = v2_extract_bearer();
    if ($raw === null) {
        return null;
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

/**
 * @internal — Session credential path. Returns user_id on hit, null on
 * absence (no active session or no user_id in session). Exits with 403
 * invalid_csrf if the session is valid but the request is mutating and
 * the CSRF token is missing or wrong.
 */
function _v2_try_session(bool $requireCsrf): ?int {
    // Only start a session if one isn't already active. Avoids the
    // "session already started" notice on setups where auto_start or a
    // prior include has already opened it.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        return null;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isMutating = ($method !== 'GET' && $method !== 'HEAD');
    if ($requireCsrf && $isMutating) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? $_POST['csrf_token']
              ?? '';
        if (!validateCsrfToken($token)) {
            v2_error('invalid_csrf', 'Invalid or missing CSRF token', 403);
        }
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Verify the request has a valid credential. Returns the user ID on
 * success. On failure, sends a JSON error response and exits.
 *
 * @param bool $requireCsrfIfSession   If true (default), the session
 *   credential path enforces CSRF on mutating requests. Set to false
 *   only if the endpoint has its own CSRF handling.
 */
function v2_require_auth(PDO $pdo, bool $requireCsrfIfSession = true): int {
    $userId = _v2_try_bearer($pdo);
    if ($userId !== null) {
        return $userId;
    }
    $userId = _v2_try_session($requireCsrfIfSession);
    if ($userId !== null) {
        return $userId;
    }
    v2_error(
        'missing_token',
        'Authorization: Bearer <token> or valid session required',
        401
    );
}
