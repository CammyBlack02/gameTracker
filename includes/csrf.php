<?php
/**
 * CSRF Protection Helper Functions (v1-facing).
 *
 * The token primitives — generateCsrfToken(), getCsrfToken(),
 * validateCsrfToken() — now live in includes/csrf-core.php, which has no
 * dependencies of its own. /api/v2/ includes that file directly so it never
 * inherits a v1 include chain. This file is the v1 surface and is otherwise
 * unchanged: every existing caller of csrf.php keeps working.
 *
 * isAdmin() / requireAdmin() moved to includes/auth.php in Phase 2a.
 * Any legacy caller that included csrf.php expecting those functions
 * still works — we re-export them via the require_once below.
 */

require_once __DIR__ . '/csrf-core.php';
require_once __DIR__ . '/auth.php';

/**
 * Enforce CSRF token on a mutating request. Reads the token from either
 * the X-CSRF-Token header (sent by js/api.js's apiPost* helpers) or the
 * `csrf_token` form field (for legacy multipart submits like
 * change-admin-credentials.php).
 *
 * Sends 403 + JSON error and exits if the token is missing or invalid.
 * Callers should invoke this AFTER the REQUEST_METHOD !== 'POST' check
 * and BEFORE any state change.
 *
 * Introduced dormant in phase 4h/01; enforcement landed across the v1
 * mutating endpoints in 4h/02 and 4h/03, so this is live now.
 */
function requireCsrfToken() {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? $_POST['csrf_token']
        ?? '';
    if (!validateCsrfToken($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Invalid or missing CSRF token. Please refresh the page and try again.',
        ]);
        exit;
    }
}
