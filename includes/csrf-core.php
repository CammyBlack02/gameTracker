<?php
/**
 * CSRF token primitives — deliberately dependency-free.
 *
 * This file exists so /api/v2/ can validate CSRF tokens WITHOUT pulling in
 * a v1 file. Including includes/csrf.php from v2 would transitively include
 * includes/auth.php (csrf.php:require_once) and drag requireUser(),
 * requireAdmin(), isAdmin(), currentUserId() and gt_is_api_route() into
 * every v2 request — re-establishing exactly the v1→v2 coupling Phase 2c
 * removed.
 *
 * Rules for this file:
 *   - No require/include of anything.
 *   - No session_start() — callers own the session lifecycle. In practice
 *     includes/config.php has already started it and enforced the idle
 *     timeout by the time these run.
 *   - Nothing here may know about users, roles or routing.
 *
 * includes/csrf.php includes this and adds the v1-facing helpers on top,
 * so every existing v1 caller keeps working unchanged.
 */

/**
 * Generate a CSRF token and store it in the session (idempotent — an
 * existing token is reused so multiple calls per request are safe).
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get the current CSRF token, minting one if absent.
 */
function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? generateCsrfToken();
}

/**
 * Validate a submitted CSRF token against the session's.
 * Uses hash_equals so the comparison is not timing-dependent.
 */
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
