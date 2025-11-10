<?php
/**
 * Helper functions for Game Tracker
 */

/**
 * Sanitize output to prevent XSS attacks
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate image file
 */
function isValidImage($file) {
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return false;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedTypes)) {
        return false;
    }
    
    if ($file['size'] > $maxSize) {
        return false;
    }
    
    return true;
}

/**
 * Generate unique filename for uploaded image
 */
function generateUniqueFilename($originalName, $directory) {
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $basename = pathinfo($originalName, PATHINFO_FILENAME);
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
    $filename = $basename . '_' . time() . '_' . uniqid() . '.' . $extension;
    
    // Ensure filename is unique
    $counter = 0;
    while (file_exists($directory . $filename)) {
        $counter++;
        $filename = $basename . '_' . time() . '_' . uniqid() . '_' . $counter . '.' . $extension;
    }
    
    return $filename;
}

/**
 * Send JSON response
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get all unique platforms from games
 */
function getUniquePlatforms($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT platform FROM games WHERE platform IS NOT NULL AND platform != '' ORDER BY platform");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all unique genres from games
 */
function getUniqueGenres($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT genre FROM games WHERE genre IS NOT NULL AND genre != '' ORDER BY genre");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

