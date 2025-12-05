<?php
/**
 * Statistics API endpoints
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
    error_log('Stats API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred: ' . $e->getMessage()], 500);
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get':
            getStats();
            break;
        
        case 'update-top':
            updateTopItems();
            break;
        
        default:
            sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('Stats API Error in action handler: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
}

function getStats() {
    global $pdo;
    
    try {
        ini_set('memory_limit', '512M');
        
        // Get filters
        $platform = $_GET['platform'] ?? '';
        $isPhysical = $_GET['is_physical'] ?? null; // null = all, '1' = physical, '0' = digital
        
        // Build games query
        $gamesWhere = [];
        $gamesParams = [];
        
        if (!empty($platform)) {
            $gamesWhere[] = "platform = ?";
            $gamesParams[] = $platform;
        }
        
        if ($isPhysical !== null) {
            $gamesWhere[] = "is_physical = ?";
            $gamesParams[] = $isPhysical;
        }
        
        $gamesWhereClause = !empty($gamesWhere) ? "WHERE " . implode(" AND ", $gamesWhere) : "";
        
        // Total games
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM games $gamesWhereClause");
        $stmt->execute($gamesParams);
        $totalGames = $stmt->fetch()['count'];
        
        // Games played - need to add played condition
        $playedWhere = $gamesWhere;
        $playedWhere[] = "played = 1";
        $playedWhereClause = "WHERE " . implode(" AND ", $playedWhere);
        $playedParams = $gamesParams;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM games $playedWhereClause");
        $stmt->execute($playedParams);
        $gamesPlayed = $stmt->fetch()['count'];
        
        // Games unplayed
        $gamesUnplayed = $totalGames - $gamesPlayed;
        
        // Most expensive game
        $stmt = $pdo->prepare("
            SELECT id, title, platform, 
                   COALESCE(pricecharting_price, price_paid, 0) as price
            FROM games 
            $gamesWhereClause
            ORDER BY price DESC, created_at DESC
            LIMIT 1
        ");
        $stmt->execute($gamesParams);
        $mostExpensive = $stmt->fetch();
        if ($mostExpensive && $mostExpensive['price'] > 0) {
            $mostExpensive['price'] = (float)$mostExpensive['price'];
        } else {
            $mostExpensive = null;
        }
        
        // Newest game
        $stmt = $pdo->prepare("
            SELECT id, title, platform, front_cover_image, created_at
            FROM games 
            $gamesWhereClause
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute($gamesParams);
        $newestGame = $stmt->fetch();
        
        // Total consoles (check both 'Console' and 'Systems' for compatibility)
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE category = 'Console' OR category = 'Systems'");
        $totalConsoles = $stmt->fetch()['count'];
        
        // Total accessories by type (exclude both 'Console' and 'Systems')
        $stmt = $pdo->query("
            SELECT category, COUNT(*) as count 
            FROM items 
            WHERE category != 'Console' AND category != 'Systems'
            GROUP BY category
            ORDER BY count DESC
        ");
        $accessoryTypes = $stmt->fetchAll();
        
        // Total items (games + consoles + accessories)
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM items");
        $totalItems = $stmt->fetch()['count'];
        $totalCollection = $totalGames + $totalItems;
        
        // Get top 5 games
        $topGames = getTopItems('top_games');
        
        // Get top 5 consoles
        $topConsoles = getTopItems('top_consoles');
        
        // Get top 5 accessories
        $topAccessories = getTopItems('top_accessories');
        
        // Platform distribution for charts
        $platformStmt = $pdo->prepare("
            SELECT platform, COUNT(*) as count 
            FROM games 
            $gamesWhereClause
            GROUP BY platform 
            ORDER BY count DESC
        ");
        $platformStmt->execute($gamesParams);
        $platformDistribution = $platformStmt->fetchAll();
        
        // Genre distribution for charts
        $genreWhere = $gamesWhere;
        $genreWhere[] = "genre IS NOT NULL";
        $genreWhere[] = "genre != ''";
        $genreWhereClause = "WHERE " . implode(" AND ", $genreWhere);
        $genreStmt = $pdo->prepare("
            SELECT genre, COUNT(*) as count 
            FROM games 
            $genreWhereClause
            GROUP BY genre 
            ORDER BY count DESC
            LIMIT 10
        ");
        $genreStmt->execute($gamesParams);
        $genreDistribution = $genreStmt->fetchAll();
        
        // Recent additions (last 10 games)
        $recentStmt = $pdo->prepare("
            SELECT id, title, platform, front_cover_image, created_at
            FROM games 
            $gamesWhereClause
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $recentStmt->execute($gamesParams);
        $recentAdditions = $recentStmt->fetchAll();
        
        sendJsonResponse([
            'success' => true,
            'stats' => [
                'total_games' => (int)$totalGames,
                'games_played' => (int)$gamesPlayed,
                'games_unplayed' => (int)$gamesUnplayed,
                'most_expensive_game' => $mostExpensive,
                'newest_game' => $newestGame,
                'total_consoles' => (int)$totalConsoles,
                'accessory_types' => $accessoryTypes,
                'total_items' => (int)$totalItems,
                'total_collection' => (int)$totalCollection,
                'top_games' => $topGames,
                'top_consoles' => $topConsoles,
                'top_accessories' => $topAccessories,
                'platform_distribution' => $platformDistribution,
                'genre_distribution' => $genreDistribution,
                'recent_additions' => $recentAdditions
            ]
        ]);
    } catch (Throwable $e) {
        error_log('getStats Error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        sendJsonResponse(['success' => false, 'message' => 'Failed to load stats: ' . $e->getMessage()], 500);
    }
}

function getTopItems($settingKey) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$settingKey]);
        $result = $stmt->fetch();
        
        if (!$result || empty($result['setting_value'])) {
            return [];
        }
        
        $itemIds = json_decode($result['setting_value'], true);
        if (!is_array($itemIds) || empty($itemIds)) {
            return [];
        }
        
        // Validate that all IDs are numeric
        $itemIds = array_filter($itemIds, function($id) {
            return is_numeric($id);
        });
        
        if (empty($itemIds)) {
            return [];
        }
        
        // Re-index array
        $itemIds = array_values($itemIds);
    
    // Determine which table to query based on setting key
    $table = 'games';
    $idField = 'id';
    $titleField = 'title';
    $imageField = 'front_cover_image';
    $categoryFilter = null;
    
    if ($settingKey === 'top_consoles') {
        $table = 'items';
        $imageField = 'front_image';
        $categoryFilter = 'Systems'; // Use 'Systems' as that's what the UI uses
    } else if ($settingKey === 'top_accessories') {
        $table = 'items';
        $imageField = 'front_image';
        $categoryFilter = 'NOT Systems'; // Special marker for != 'Systems' and != 'Console'
    }
    
    // Build query with placeholders for IN clause
    if (count($itemIds) === 0) {
        return [];
    }
    
    $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
    
    // Build WHERE conditions
    $whereConditions = ["$idField IN ($placeholders)"];
    $params = array_values($itemIds); // Ensure array is indexed correctly
    
    if ($categoryFilter === 'Systems') {
        // Check for both 'Systems' and 'Console' for compatibility
        $whereConditions[] = "(category = ? OR category = ?)";
        $params[] = 'Systems';
        $params[] = 'Console';
    } else if ($categoryFilter === 'NOT Systems') {
        // Exclude both 'Systems' and 'Console'
        $whereConditions[] = "category != ? AND category != ?";
        $params[] = 'Systems';
        $params[] = 'Console';
    }
    
    $whereClause = "WHERE " . implode(" AND ", $whereConditions);
    
    $sql = "SELECT $idField as id, $titleField as title, $imageField as image, platform 
            FROM $table 
            $whereClause";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("SQL Error in getTopItems: " . $e->getMessage());
        error_log("SQL: " . $sql);
        error_log("Params: " . print_r($params, true));
        return [];
    }
    
    // Maintain order from saved IDs
    $orderedItems = [];
    foreach ($itemIds as $id) {
        foreach ($items as $item) {
            if ($item['id'] == $id) {
                $orderedItems[] = $item;
                break;
            }
        }
    }
    
        return $orderedItems;
    } catch (Throwable $e) {
        error_log("Error in getTopItems for $settingKey: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        return [];
    }
}

function updateTopItems() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $type = $data['type'] ?? ''; // 'games', 'consoles', 'accessories'
    $itemIds = $data['item_ids'] ?? [];
    
    if (!in_array($type, ['games', 'consoles', 'accessories'])) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid type'], 400);
    }
    
    if (!is_array($itemIds) || count($itemIds) > 5) {
        sendJsonResponse(['success' => false, 'message' => 'Invalid item IDs or more than 5 items'], 400);
    }
    
    $settingKey = 'top_' . $type;
    
    // Validate that all IDs exist in the appropriate table
    $table = ($type === 'games') ? 'games' : 'items';
    $idField = 'id';
    
    if (!empty($itemIds)) {
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $table WHERE $idField IN ($placeholders)");
        $stmt->execute($itemIds);
        $count = $stmt->fetch()['count'];
        
        if ($count != count($itemIds)) {
            sendJsonResponse(['success' => false, 'message' => 'Some item IDs are invalid'], 400);
        }
    }
    
    // Insert or update setting (SQLite syntax)
    $jsonValue = json_encode($itemIds);
    
    // Check if setting exists
    $checkStmt = $pdo->prepare("SELECT id FROM settings WHERE setting_key = ?");
    $checkStmt->execute([$settingKey]);
    $exists = $checkStmt->fetch();
    
    if ($exists) {
        // Update
        $stmt = $pdo->prepare("UPDATE settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP WHERE setting_key = ?");
        $stmt->execute([$jsonValue, $settingKey]);
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$settingKey, $jsonValue]);
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Top items updated successfully'
    ]);
}

