<?php
/**
 * CSRF Protection Helper Functions
 *
 * isAdmin() / requireAdmin() moved to includes/auth.php in Phase 2a.
 * Any legacy caller that included csrf.php expecting those functions
 * still works — we re-export them via the require_once below.
 */

require_once __DIR__ . '/auth.php';

/**
 * Generate a CSRF token and store it in session
 */
function generateCsrfToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Get the current CSRF token
 */
function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? generateCsrfToken();
}

/**
 * Validate CSRF token
 */
function validateCsrfToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

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
 * Introduced in phase 4h/01 but not yet CALLED from any endpoint — the
 * enforcement rollout is staged per follow-up PRs. This function is
 * dormant until then.
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
