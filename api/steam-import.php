<?php
/**
 * Steam Library Import API
 * Fetches games from Steam Web API and imports them into the database
 */

// Suppress error display and enable output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

// Load functions first so sendJsonResponse is available
require_once __DIR__ . '/../includes/functions.php';

try {
    // Load database configuration (MySQL)
    require_once __DIR__ . '/../includes/config.php';
    
    // Check if $pdo is available
    if (!isset($pdo)) {
        sendJsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);
    }
    
    // Manual authentication check for API (return JSON instead of redirect)
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
    }
    
    header('Content-Type: application/json');
} catch (Throwable $e) {
    ob_clean();
    error_log('Steam Import API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred: ' . $e->getMessage()], 500);
}

try {
    $action = $_GET['action'] ?? '';
    
switch ($action) {
    case 'import':
        importSteamLibrary();
        break;
    
    case 'test_connection':
        testSteamConnection();
        break;
    
    case 'delete_pc_games':
        deletePCGames();
        break;
    
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}
} catch (Throwable $e) {
    error_log('Steam Import API Error in action handler: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
}

/**
 * Test Steam API connection
 */
function testSteamConnection() {
    global $pdo;
    
    // Get Steam credentials from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    
    $stmt->execute(['steam_api_key']);
    $apiKey = $stmt->fetchColumn();
    
    $stmt->execute(['steam_user_id']);
    $steamId = $stmt->fetchColumn();
    
    if (empty($apiKey) || empty($steamId)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Steam API key and Steam ID must be configured in settings first'
        ], 400);
    }
    
    // Test API connection by fetching owned games
    $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$apiKey}&steamid={$steamId}&format=json&include_appinfo=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Connection error: ' . $curlError
        ], 500);
    }
    
    if ($httpCode !== 200) {
        sendJsonResponse([
            'success' => false,
            'message' => "Steam API returned HTTP {$httpCode}. Check your API key and Steam ID."
        ], 500);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['response']['games'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid response from Steam API. Check your credentials.'
        ], 500);
    }
    
    $gameCount = count($data['response']['games']);
    
    sendJsonResponse([
        'success' => true,
        'message' => "Connection successful! Found {$gameCount} games in your Steam library.",
        'game_count' => $gameCount
    ]);
}

/**
 * Import games from Steam library
 */
function importSteamLibrary() {
    global $pdo;
    
    // Clean any output before starting
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    // Increase execution time and memory for large imports
    set_time_limit(600); // 10 minutes
    ini_set('memory_limit', '512M');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    // Get Steam credentials from settings
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    
    $stmt->execute(['steam_api_key']);
    $apiKey = $stmt->fetchColumn();
    
    $stmt->execute(['steam_user_id']);
    $steamId = $stmt->fetchColumn();
    
    if (empty($apiKey) || empty($steamId)) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Steam API key and Steam ID must be configured in settings first'
        ], 400);
    }
    
    // Fetch owned games from Steam API
    $url = "https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key={$apiKey}&steamid={$steamId}&format=json&include_appinfo=1";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Connection error: ' . $curlError
        ], 500);
    }
    
    if ($httpCode !== 200) {
        sendJsonResponse([
            'success' => false,
            'message' => "Steam API returned HTTP {$httpCode}. Check your API key and Steam ID."
        ], 500);
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['response']['games'])) {
        sendJsonResponse([
            'success' => false,
            'message' => 'Invalid response from Steam API'
        ], 500);
    }
    
    $games = $data['response']['games'];
    $imported = 0;
    $skipped = 0;
    $errors = 0;
    $errorsList = [];
    $totalGames = count($games);
    
    // Process each game
    foreach ($games as $index => $steamGame) {
        try {
            // Clean output buffer periodically to prevent issues
            if ($index % 10 === 0 && ob_get_level() > 0) {
                ob_clean();
            }
            
            $appId = $steamGame['appid'];
            $name = $steamGame['name'] ?? 'Unknown Game';
            
            // Check if game already exists (by title and platform)
            $stmt = $pdo->prepare("SELECT id FROM games WHERE title = ? AND platform = 'PC'");
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $skipped++;
                continue;
            }
            
            // Fetch detailed game information
            $gameDetails = getSteamGameDetails($appId);
            
            // Prepare game data
            $gameData = [
                'title' => $name,
                'platform' => 'PC',
                'genre' => $gameDetails['genre'] ?? null,
                'description' => $gameDetails['description'] ?? null,
                'is_physical' => 0, // Steam games are always digital
                'digital_store' => 'Steam', // Mark as Steam
                'front_cover_image' => $gameDetails['vertical_cover'] ?? $gameDetails['header_image'] ?? null,
                'release_date' => $gameDetails['release_date'] ?? null,
                'played' => 0 // Set all imported games to not played
            ];
            
            // Insert game into database
            $stmt = $pdo->prepare("
                INSERT INTO games (
                    title, platform, genre, description, is_physical, digital_store,
                    front_cover_image, release_date, played
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $gameData['title'],
                $gameData['platform'],
                $gameData['genre'],
                $gameData['description'],
                $gameData['is_physical'],
                $gameData['digital_store'],
                $gameData['front_cover_image'],
                $gameData['release_date'],
                $gameData['played']
            ]);
            
            $imported++;
            
            // Small delay to avoid rate limiting (reduced for faster import)
            if ($index % 5 !== 0) {
                usleep(100000); // 0.1 seconds for most games
            } else {
                usleep(200000); // 0.2 seconds every 5th game
            }
            
        } catch (Exception $e) {
            $errors++;
            $errorMsg = "Error importing {$name}: " . $e->getMessage();
            $errorsList[] = $errorMsg;
            error_log("Steam import error for {$name}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        } catch (Throwable $e) {
            $errors++;
            $errorMsg = "Error importing {$name}: " . $e->getMessage();
            $errorsList[] = $errorMsg;
            error_log("Steam import error for {$name}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
    }
    
    // Clean output buffer before sending response
    if (ob_get_level() > 0) {
        ob_clean();
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => "Import completed: {$imported} imported, {$skipped} skipped, {$errors} errors",
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'errors_list' => array_slice($errorsList, 0, 10) // Limit error list to first 10
    ]);
}

/**
 * Get detailed game information from Steam Store API
 */
function getSteamGameDetails($appId) {
    $url = "https://store.steampowered.com/api/appdetails?appids={$appId}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return [];
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data[$appId]['success']) || !$data[$appId]['success']) {
        return [];
    }
    
    $gameData = $data[$appId]['data'];
    
    $result = [];
    
    // Extract genre
    if (isset($gameData['genres']) && is_array($gameData['genres']) && count($gameData['genres']) > 0) {
        $result['genre'] = $gameData['genres'][0]['description'] ?? null;
    }
    
    // Extract description (short description is usually better)
    if (isset($gameData['short_description'])) {
        $result['description'] = $gameData['short_description'];
    } elseif (isset($gameData['detailed_description'])) {
        // Strip HTML tags from detailed description
        $result['description'] = strip_tags($gameData['detailed_description']);
        // Limit length
        if (strlen($result['description']) > 1000) {
            $result['description'] = substr($result['description'], 0, 997) . '...';
        }
    }
    
    // Try to get vertical cover image (portrait orientation)
    // Steam library assets are vertical (600x900 pixels)
    // Priority: library_600x900.jpg > capsule_imagev5 > capsule_image > header_image
    $result['vertical_cover'] = "https://steamcdn-a.akamaihd.net/steam/apps/{$appId}/library_600x900.jpg";
    
    // Also check for capsule images (some may be vertical)
    if (isset($gameData['capsule_imagev5'])) {
        // Store as alternative, but prefer library asset
        $result['capsule_imagev5'] = $gameData['capsule_imagev5'];
    }
    if (isset($gameData['capsule_image'])) {
        $result['capsule_image'] = $gameData['capsule_image'];
    }
    
    // Keep header_image as fallback (landscape)
    if (isset($gameData['header_image'])) {
        $result['header_image'] = $gameData['header_image'];
    }
    
    // Extract release date
    if (isset($gameData['release_date']['date'])) {
        $releaseDate = $gameData['release_date']['date'];
        // Try to parse the date
        $timestamp = strtotime($releaseDate);
        if ($timestamp !== false) {
            $result['release_date'] = date('Y-m-d', $timestamp);
        }
    }
    
    return $result;
}

/**
 * Delete all PC platform games from the database
 */
function deletePCGames() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    try {
        // Count games before deletion
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM games WHERE platform = 'PC'");
        $stmt->execute();
        $result = $stmt->fetch();
        $count = $result['count'] ?? 0;
        
        // Delete all PC games (cascade will handle related images)
        $stmt = $pdo->prepare("DELETE FROM games WHERE platform = 'PC'");
        $stmt->execute();
        
        sendJsonResponse([
            'success' => true,
            'message' => "Deleted {$count} PC games",
            'deleted_count' => $count
        ]);
    } catch (Exception $e) {
        error_log('Error deleting PC games: ' . $e->getMessage());
        sendJsonResponse([
            'success' => false,
            'message' => 'Failed to delete PC games: ' . $e->getMessage()
        ], 500);
    }
}

