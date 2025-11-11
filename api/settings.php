<?php
/**
 * Settings API endpoints
 * Handles background image and other settings
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        getSettings();
        break;
    
    case 'set_background':
        setBackgroundImage();
        break;
    
    case 'remove_background':
        removeBackgroundImage();
        break;
    
    case 'set_steam':
        setSteamCredentials();
        break;
    
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function getSettings() {
    global $pdo;
    
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    sendJsonResponse(['success' => true, 'settings' => $settings]);
}

function setBackgroundImage() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    if (!isset($_FILES['background_image'])) {
        sendJsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
    }
    
    $file = $_FILES['background_image'];
    
    if (!isValidImage($file)) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid image file'], 400);
    }
    
    $filename = 'background_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
    $targetPath = UPLOAD_DIR . $filename;
    
    // Remove old background if exists
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'background_image'");
    $stmt->execute();
    $oldBackground = $stmt->fetchColumn();
    
    if ($oldBackground && file_exists(UPLOAD_DIR . $oldBackground)) {
        unlink(UPLOAD_DIR . $oldBackground);
    }
    
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        sendJsonResponse(['success' => false, 'message' => 'Failed to save file'], 500);
    }
    
    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES ('background_image', ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$filename, $filename]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Background image updated',
        'url' => '/uploads/' . $filename
    ]);
}

function removeBackgroundImage() {
    global $pdo;
    
    // Get current background
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'background_image'");
    $stmt->execute();
    $background = $stmt->fetchColumn();
    
    if ($background && file_exists(UPLOAD_DIR . $background)) {
        unlink(UPLOAD_DIR . $background);
    }
    
    // Remove from database
    $stmt = $pdo->prepare("DELETE FROM settings WHERE setting_key = 'background_image'");
    $stmt->execute();
    
    sendJsonResponse(['success' => true, 'message' => 'Background image removed']);
}

function setSteamCredentials() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $apiKey = $data['steam_api_key'] ?? '';
    $steamId = $data['steam_user_id'] ?? '';
    
    if (empty($apiKey) || empty($steamId)) {
        sendJsonResponse(['success' => false, 'message' => 'Steam API key and Steam ID are required'], 400);
    }
    
    // Save Steam API key
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES ('steam_api_key', ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$apiKey, $apiKey]);
    
    // Save Steam User ID
    $stmt = $pdo->prepare("
        INSERT INTO settings (setting_key, setting_value) 
        VALUES ('steam_user_id', ?)
        ON CONFLICT(setting_key) DO UPDATE SET setting_value = ?, updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$steamId, $steamId]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Steam credentials saved successfully'
    ]);
}

