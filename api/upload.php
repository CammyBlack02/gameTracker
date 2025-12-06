<?php
/**
 * Image upload handler
 */

// Suppress error display for JSON responses
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start output buffering to catch any unexpected output
ob_start();

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    // Check authentication manually for API endpoints (don't redirect)
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        ob_clean();
        header('Content-Type: application/json');
        sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
    }
    
    // Clear any output that might have been generated
    ob_clean();
    
    header('Content-Type: application/json');
    
    $type = $_POST['type'] ?? 'cover'; // 'cover' or 'extra'
    $gameId = $_POST['game_id'] ?? null;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    if (!isset($_FILES['image'])) {
        sendJsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
    }
    
    $file = $_FILES['image'];
    
    // Debug: Log file info
    error_log("Upload attempt - File name: " . ($file['name'] ?? 'unknown') . ", Size: " . ($file['size'] ?? 0) . ", Type: " . ($file['type'] ?? 'unknown') . ", Tmp name: " . ($file['tmp_name'] ?? 'empty'));
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        $uploadError = $file['error'] ?? 'unknown';
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        $errorMsg = $errorMessages[$uploadError] ?? "Upload error code: $uploadError";
        error_log("File upload failed - tmp_name: " . ($file['tmp_name'] ?? 'not set') . ", error code: $uploadError ($errorMsg), name: " . ($file['name'] ?? 'unknown') . ", size: " . ($file['size'] ?? 0));
        sendJsonResponse(['success' => false, 'message' => "File upload failed: $errorMsg"], 400);
    }
    
    // Verify it's actually an uploaded file
    if (!is_uploaded_file($file['tmp_name'])) {
        error_log("Security check failed - file is not an uploaded file. tmp_name: " . $file['tmp_name']);
        sendJsonResponse(['success' => false, 'message' => 'Security check failed. File is not a valid upload.'], 400);
    }
    
    if (!isValidImage($file)) {
        // Get more details for error message
        $detectedMime = 'unknown';
        if (function_exists('finfo_open')) {
            try {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $detectedMime = finfo_file($finfo, $file['tmp_name']);
                    finfo_close($finfo);
                }
            } catch (Exception $e) {
                error_log("Error detecting MIME type: " . $e->getMessage());
            }
        }
        
        $errorMsg = 'Invalid image file. Detected type: ' . $detectedMime . ', File type: ' . ($file['type'] ?? 'unknown') . ', Size: ' . round(($file['size'] ?? 0) / 1024 / 1024, 2) . 'MB. Must be JPEG, PNG, GIF, WebP, or HEIC and under 5MB';
        error_log("Upload validation failed: " . $errorMsg);
        
        // Log security event
        logSecurityEvent('upload_validation_failed', [
            'filename' => $file['name'] ?? 'unknown',
            'mime_type' => $detectedMime,
            'size' => $file['size'] ?? 0
        ]);
        
        sendJsonResponse(['success' => false, 'message' => $errorMsg], 400);
    }
    
    // Additional security: Verify image dimensions are reasonable (prevent decompression bombs)
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo) {
        $maxDimension = 10000; // Maximum width or height
        if ($imageInfo[0] > $maxDimension || $imageInfo[1] > $maxDimension) {
            error_log("Upload rejected: Image dimensions too large: {$imageInfo[0]}x{$imageInfo[1]}");
            logSecurityEvent('upload_dimensions_too_large', [
                'filename' => $file['name'] ?? 'unknown',
                'dimensions' => "{$imageInfo[0]}x{$imageInfo[1]}"
            ]);
            sendJsonResponse(['success' => false, 'message' => 'Image dimensions are too large. Maximum size is 10000x10000 pixels.'], 400);
        }
    }
    
    // Determine upload directory
    if ($type === 'cover') {
        $uploadDir = COVERS_DIR;
    } else if ($type === 'extra') {
        if (!$gameId) {
            sendJsonResponse(['success' => false, 'message' => 'Game ID is required for extra images'], 400);
        }
        $uploadDir = EXTRAS_DIR;
    } else {
        sendJsonResponse(['success' => false, 'message' => 'Invalid upload type'], 400);
    }
    
    // Check if upload directory exists and is writable
    if (!is_dir($uploadDir)) {
        error_log("Upload directory does not exist: " . $uploadDir);
        sendJsonResponse(['success' => false, 'message' => 'Upload directory does not exist'], 500);
    }
    if (!is_writable($uploadDir)) {
        error_log("Upload directory is not writable: " . $uploadDir);
        sendJsonResponse(['success' => false, 'message' => 'Upload directory is not writable'], 500);
    }
    
    // Check if it's a HEIC/HEIF file and convert to JPEG
    // Store tmp_name before any operations that might affect it
    $tmpFilePath = $file['tmp_name'];
    
    $mimeType = null;
    try {
        if (!empty($tmpFilePath) && file_exists($tmpFilePath)) {
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                if ($finfo) {
                    $mimeType = finfo_file($finfo, $tmpFilePath);
                    finfo_close($finfo);
                }
            }
            if (!$mimeType && function_exists('mime_content_type')) {
                $mimeType = mime_content_type($tmpFilePath);
            }
        }
        if (!$mimeType) {
            $mimeType = $file['type'] ?? 'image/jpeg';
        }
    } catch (Exception $e) {
        error_log("Error detecting MIME type: " . $e->getMessage());
        $mimeType = $file['type'] ?? 'image/jpeg';
    }
    
    // Also check file extension as a fallback/verification
    $originalExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Log detected MIME type for debugging
    error_log("Detected MIME type: $mimeType, File extension: $originalExtension, Browser type: " . ($file['type'] ?? 'unknown'));
    
    // Only treat as HEIC if MIME type explicitly says so AND extension matches
    // Don't convert if it's already a JPEG (by extension or MIME type)
    $isHeic = false;
    if (in_array($mimeType, ['image/heic', 'image/heif'])) {
        // Double-check: if extension is jpeg/jpg, trust the extension over MIME type
        if (in_array($originalExtension, ['jpg', 'jpeg'])) {
            error_log("MIME type says HEIC but extension is JPEG - treating as JPEG");
            $mimeType = 'image/jpeg'; // Override MIME type
        } else {
            $isHeic = true;
        }
    }
    
    if ($isHeic) {
        // Generate filename with .jpg extension for HEIC files
        $basename = pathinfo($file['name'], PATHINFO_FILENAME);
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $basename);
        $filename = $basename . '_' . time() . '_' . uniqid() . '.jpg';
        
        // Ensure filename is unique
        $counter = 0;
        while (file_exists($uploadDir . $filename)) {
            $counter++;
            $filename = $basename . '_' . time() . '_' . uniqid() . '_' . $counter . '.jpg';
        }
        
        $targetPath = $uploadDir . $filename;
        
        // Convert HEIC to JPEG
        if (!convertHeicToJpeg($tmpFilePath, $targetPath)) {
            sendJsonResponse(['success' => false, 'message' => 'Failed to convert HEIC image. Please convert to JPEG first or install ImageMagick.'], 500);
        }
    } else {
        // Generate unique filename for regular images
        try {
            $filename = generateUniqueFilename($file['name'], $uploadDir);
            $targetPath = $uploadDir . $filename;
            
            // Move uploaded file
            if (!move_uploaded_file($tmpFilePath, $targetPath)) {
                $error = error_get_last();
                error_log("Failed to move uploaded file from $tmpFilePath to $targetPath. Error: " . ($error ? $error['message'] : 'Unknown error'));
                sendJsonResponse(['success' => false, 'message' => 'Failed to save file. Check server logs for details.'], 500);
            }
        } catch (Exception $e) {
            error_log("Error generating filename or moving file: " . $e->getMessage());
            sendJsonResponse(['success' => false, 'message' => 'Error processing file: ' . $e->getMessage()], 500);
        }
    }
    
    // If it's an extra image, save to database
    if ($type === 'extra' && $gameId) {
        global $pdo;
        $userId = $_SESSION['user_id'];
        
        // Verify game ownership
        $checkStmt = $pdo->prepare("SELECT user_id FROM games WHERE id = ?");
        $checkStmt->execute([$gameId]);
        $game = $checkStmt->fetch();
        
        if (!$game) {
            sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
        }
        
        $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
        if (!$isAdmin && $game['user_id'] != $userId) {
            sendJsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $stmt = $pdo->prepare("INSERT INTO game_images (game_id, user_id, image_path) VALUES (?, ?, ?)");
        $stmt->execute([$gameId, $userId, $filename]);
        $imageId = $pdo->lastInsertId();
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'image_path' => $filename,
            'image_id' => $imageId,
            'url' => '/uploads/extras/' . $filename
        ]);
    } else {
        // For cover images, just return the path
        sendJsonResponse([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'image_path' => $filename,
            'url' => '/uploads/covers/' . $filename
        ]);
    }
} catch (Throwable $e) {
    ob_clean();
    error_log("Upload error: " . $e->getMessage() . " - " . $e->getTraceAsString());
    
    // Make sure we can send JSON even if sendJsonResponse isn't available
    if (function_exists('sendJsonResponse')) {
        sendJsonResponse(['success' => false, 'message' => 'Upload error: ' . $e->getMessage()], 500);
    } else {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Upload error: ' . $e->getMessage()]);
        exit;
    }
}
