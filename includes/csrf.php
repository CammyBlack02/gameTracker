<?php
/**
 * CSRF Protection Helper Functions
 */

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
 * Check if user is admin
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Require admin role - redirects if not admin
 */
function requireAdmin() {
    if (!isAdmin()) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            // API context - return JSON
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Admin access required']);
            exit;
        } else {
            // Page context - redirect
            header('Location: dashboard.php');
            exit;
        }
    }
}

