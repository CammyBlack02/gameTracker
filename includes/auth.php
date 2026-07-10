<?php
/**
 * Canonical authentication module for the v1 (session-cookie) API and
 * top-level HTML pages. v2 uses bearer tokens via api/v2/_auth.php —
 * do not include this file from v2 code paths.
 *
 * Every v1 endpoint should call requireUser() (or requireAdmin() where
 * appropriate) at the top of the file, immediately after config.php.
 * The helper returns the caller's user_id and calls exit() on failure —
 * JSON 401 for /api/ URIs, HTML redirect to /index.php otherwise.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Enforce that the request has a valid session. Returns user_id on
 * success; exits with 401/302 on failure.
 */
function requireUser(): int
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        if (gt_is_api_route()) {
            sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
        }
        header('Location: /index.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Enforce that the caller is an admin. Returns user_id on success;
 * exits with 401/302 for unauthenticated, 403/dashboard-redirect for
 * authenticated-but-not-admin.
 */
function requireAdmin(): int
{
    $userId = requireUser();
    if (!isAdmin()) {
        if (gt_is_api_route()) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        }
        header('Location: /dashboard.php');
        exit;
    }
    return $userId;
}

/**
 * True iff the current session is an admin. Does not exit.
 */
function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Convenience accessor. Assumes requireUser() was called first — no re-check.
 */
function currentUserId(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

/**
 * @internal — picks JSON-vs-HTML failure UX in requireUser/requireAdmin.
 */
function gt_is_api_route(): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}
