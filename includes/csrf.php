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
