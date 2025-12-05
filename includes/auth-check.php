<?php
/**
 * Authentication check - include this at the top of protected pages
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    // Redirect to login page
    header('Location: /index.php');
    exit;
}

