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
    $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
    $maxSize = 5 * 1024 * 1024; // 5MB
    
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        error_log("isValidImage: File not uploaded or tmp_name not set");
        return false;
    }
    
    // Get MIME type using multiple methods
    $mimeType = null;
    
    // Method 1: Use finfo if available
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
    
    // Method 2: Use mime_content_type as fallback
    if (!$mimeType && function_exists('mime_content_type')) {
        $mimeType = mime_content_type($file['tmp_name']);
    }
    
    // Method 3: Use browser-provided type
    if (!$mimeType && isset($file['type']) && !empty($file['type'])) {
        $mimeType = $file['type'];
    }
    
    // Also check the file extension as a fallback
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $extensionMap = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'heif' => 'image/heif'
    ];
    
    error_log("isValidImage: Detected MIME type: $mimeType, Extension: $extension, Browser type: " . ($file['type'] ?? 'none'));
    
    // Check MIME type first
    if (!$mimeType || !in_array($mimeType, $allowedTypes)) {
        // If MIME type doesn't match, try extension-based check
        if (isset($extensionMap[$extension])) {
            $mimeType = $extensionMap[$extension];
            error_log("isValidImage: MIME type mismatch, using extension-based type: $mimeType for file: " . $file['name']);
        } else {
            error_log("isValidImage: Invalid MIME type: $mimeType for file: " . $file['name'] . " (extension: $extension)");
            return false;
        }
    }
    
    // Double-check it's in allowed types
    if (!in_array($mimeType, $allowedTypes)) {
        error_log("isValidImage: Final MIME type check failed: $mimeType");
        return false;
    }
    
    if ($file['size'] > $maxSize) {
        error_log("isValidImage: File too large: " . $file['size'] . " bytes");
        return false;
    }
    
    return true;
}

/**
 * Convert HEIC/HEIF image to JPEG
 * Returns the path to the converted JPEG file, or false on failure
 */
function convertHeicToJpeg($sourcePath, $targetPath) {
    // Try ImageMagick first (most reliable)
    if (extension_loaded('imagick')) {
        try {
            $imagick = new Imagick($sourcePath);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality(85);
            $imagick->writeImage($targetPath);
            $imagick->clear();
            $imagick->destroy();
            return true;
        } catch (Exception $e) {
            error_log("ImageMagick conversion failed: " . $e->getMessage());
        }
    }
    
    // Try using sips command (macOS built-in)
    if (PHP_OS === 'Darwin' && file_exists('/usr/bin/sips')) {
        $command = sprintf(
            '/usr/bin/sips -s format jpeg -s formatOptions 85 "%s" --out "%s" 2>&1',
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && file_exists($targetPath)) {
            return true;
        }
    }
    
    // Try using heif-convert if available
    if (file_exists('/usr/local/bin/heif-convert') || file_exists('/opt/homebrew/bin/heif-convert')) {
        $heifConvert = file_exists('/opt/homebrew/bin/heif-convert') 
            ? '/opt/homebrew/bin/heif-convert' 
            : '/usr/local/bin/heif-convert';
        $command = sprintf(
            '%s "%s" "%s" 2>&1',
            escapeshellarg($heifConvert),
            escapeshellarg($sourcePath),
            escapeshellarg($targetPath)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && file_exists($targetPath)) {
            return true;
        }
    }
    
    return false;
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

