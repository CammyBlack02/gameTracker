<?php
/**
 * Router script for PHP built-in server
 * Helps handle requests properly from external devices
 */

// Get the requested URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// If it's a file that exists, serve it
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false; // Serve the file as-is
}

// For directory requests, try index.php
if (is_dir(__DIR__ . $uri) && file_exists(__DIR__ . $uri . '/index.php')) {
    $_SERVER['SCRIPT_NAME'] = $uri . '/index.php';
    require __DIR__ . $uri . '/index.php';
    return true;
}

// Route to index.php for root and other requests
if ($uri === '/' || !file_exists(__DIR__ . $uri)) {
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    require __DIR__ . '/index.php';
    return true;
}

// Default: serve the file
return false;

