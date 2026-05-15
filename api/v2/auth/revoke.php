<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED);
/**
 * POST /api/v2/auth/revoke
 *
 * Marks the *currently used* Bearer token as revoked. The client is
 * expected to discard the token after this call.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');

$raw = v2_extract_bearer();
if ($raw === null) {
    v2_error('missing_token', 'Authorization: Bearer <token> required', 401);
}
$hash = v2_hash_token($raw);

$stmt = $pdo->prepare("UPDATE api_tokens SET revoked_at = NOW()
    WHERE token_hash = ? AND revoked_at IS NULL");
$stmt->execute([$hash]);

v2_ok(['revoked' => $stmt->rowCount() > 0]);
