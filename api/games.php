<?php
/**
 * Games CRUD API endpoints
 */

// Suppress error display and enable output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '300');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Don't start output buffering here - we'll handle it per-endpoint
// ob_start();

// Register shutdown handler to catch fatal errors
register_shutdown_function(function() {
    // Don't interfere if we're streaming a response
    if (defined('STREAMING_RESPONSE_ACTIVE')) {
        return;
    }
    
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Only send error if no output has been sent yet
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false, 
                'message' => 'Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']
            ]);
        }
    }
});

// Load functions first so sendJsonResponse is available
require_once __DIR__ . '/../includes/functions.php';

try {
    // Check if database exists first
    $dbPath = __DIR__ . '/../database/games.db';
    if (!file_exists($dbPath)) {
        sendJsonResponse(['success' => false, 'message' => 'Database not found'], 500);
    }
    
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
    error_log('Games API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred: ' . $e->getMessage()], 500);
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'list':
            listGames();
            // listGames() uses exit(), so this won't be reached
            break;
        
        case 'get':
            getGame();
            break;
        
        case 'create':
            createGame();
            break;
        
        case 'update':
            updateGame();
            break;
        
        case 'delete':
            deleteGame();
            break;
        
        default:
            sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('Games API Error in action handler: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
}

function listGames() {
    global $pdo;
    
    // Increase memory and execution time limits BEFORE loading data
    ini_set('memory_limit', '1024M');
    ini_set('max_execution_time', '300');
    
    try {
        if (!isset($pdo)) {
            error_log('listGames: Database connection not available');
            sendJsonResponse(['success' => false, 'message' => 'Database connection not available'], 500);
            return;
        }
        
        // Get pagination parameters
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = isset($_GET['per_page']) ? max(1, min(1000, (int)$_GET['per_page'])) : 500; // Max 1000 per page
        $offset = ($page - 1) * $perPage;
        
        // Get total count
        $countStmt = $pdo->query("SELECT COUNT(DISTINCT g.id) as total FROM games g");
        $totalGames = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = $totalGames > 0 ? ceil($totalGames / $perPage) : 1;
        
        error_log("listGames: Page $page of $totalPages (showing $perPage games, offset $offset)");
        
        // Re-execute query with pagination
        $stmt = $pdo->prepare("
            SELECT g.id,
                   g.title,
                   g.platform,
                   g.genre,
                   g.series,
                   g.special_edition,
                   g.condition,
                   g.star_rating,
                   g.metacritic_rating,
                   g.played,
                   g.price_paid,
                   g.pricecharting_price,
                   g.is_physical,
                   g.digital_store,
                   g.front_cover_image,
                   g.back_cover_image,
                   g.created_at,
                   g.updated_at,
                   COUNT(gi.id) as extra_image_count
            FROM games g
            LEFT JOIN game_images gi ON g.id = gi.game_id
            GROUP BY g.id
            ORDER BY g.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$perPage, $offset]);
        
        // Collect games for this page
        $games = [];
        while ($game = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Convert boolean values
            $game['played'] = (bool)$game['played'];
            $game['is_physical'] = (bool)$game['is_physical'];
            $game['star_rating'] = $game['star_rating'] !== null ? (int)$game['star_rating'] : null;
            $game['metacritic_rating'] = $game['metacritic_rating'] !== null ? (int)$game['metacritic_rating'] : null;
            if (empty($game['genre'])) $game['genre'] = null;
            if (empty($game['series'])) $game['series'] = null;
            if (empty($game['special_edition'])) $game['special_edition'] = null;
            
            $games[] = $game;
        }
        
        error_log("listGames: Loaded " . count($games) . " games for page $page");
        
        // Send response with pagination info
        sendJsonResponse([
            'success' => true, 
            'games' => $games,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $totalGames,
                'total_pages' => $totalPages,
                'has_more' => $page < $totalPages
            ]
        ]);
    } catch (Throwable $e) {
        error_log('listGames Error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        
        // Clean any output and send error response
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to load games: ' . $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}

function getGame() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    
    if (!$game) {
        sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
    }
    
    // Get extra images
    $stmt = $pdo->prepare("SELECT * FROM game_images WHERE game_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$id]);
    $game['extra_images'] = $stmt->fetchAll();
    
    // Convert boolean values
    $game['played'] = (bool)$game['played'];
    $game['is_physical'] = (bool)$game['is_physical'];
    $game['star_rating'] = $game['star_rating'] !== null ? (int)$game['star_rating'] : null;
    $game['metacritic_rating'] = $game['metacritic_rating'] !== null ? (int)$game['metacritic_rating'] : null;
    
    sendJsonResponse(['success' => true, 'game' => $game]);
}

function createGame() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['title']) || empty($data['platform'])) {
        sendJsonResponse(['success' => false, 'message' => 'Title and platform are required'], 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO games (
            title, platform, genre, description, series, special_edition,
            condition, review, star_rating, metacritic_rating, played,
            price_paid, pricecharting_price, is_physical, digital_store,
            front_cover_image, back_cover_image, release_date
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");
    
    $stmt->execute([
        $data['title'] ?? '',
        $data['platform'] ?? '',
        $data['genre'] ?? null,
        $data['description'] ?? null,
        $data['series'] ?? null,
        $data['special_edition'] ?? null,
        $data['condition'] ?? null,
        $data['review'] ?? null,
        isset($data['star_rating']) ? (int)$data['star_rating'] : null,
        isset($data['metacritic_rating']) ? (int)$data['metacritic_rating'] : null,
        isset($data['played']) ? (int)$data['played'] : 0,
        isset($data['price_paid']) ? (float)$data['price_paid'] : null,
        isset($data['pricecharting_price']) ? (float)$data['pricecharting_price'] : null,
        isset($data['is_physical']) ? (int)$data['is_physical'] : 1,
        $data['digital_store'] ?? null,
        $data['front_cover_image'] ?? null,
        $data['back_cover_image'] ?? null,
        !empty($data['release_date']) ? $data['release_date'] : null
    ]);
    
    $gameId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Game created successfully',
        'game_id' => $gameId
    ]);
}

function updateGame() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    // Check if game exists
    $stmt = $pdo->prepare("SELECT id FROM games WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
    }
    
    $stmt = $pdo->prepare("
        UPDATE games SET
            title = ?,
            platform = ?,
            genre = ?,
            description = ?,
            series = ?,
            special_edition = ?,
            condition = ?,
            review = ?,
            star_rating = ?,
            metacritic_rating = ?,
            played = ?,
            price_paid = ?,
            pricecharting_price = ?,
            is_physical = ?,
            digital_store = ?,
            front_cover_image = ?,
            back_cover_image = ?,
            release_date = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $stmt->execute([
        $data['title'] ?? '',
        $data['platform'] ?? '',
        $data['genre'] ?? null,
        $data['description'] ?? null,
        $data['series'] ?? null,
        $data['special_edition'] ?? null,
        $data['condition'] ?? null,
        $data['review'] ?? null,
        isset($data['star_rating']) ? (int)$data['star_rating'] : null,
        isset($data['metacritic_rating']) ? (int)$data['metacritic_rating'] : null,
        isset($data['played']) ? (int)$data['played'] : 0,
        isset($data['price_paid']) ? (float)$data['price_paid'] : null,
        isset($data['pricecharting_price']) ? (float)$data['pricecharting_price'] : null,
        isset($data['is_physical']) ? (int)$data['is_physical'] : 1,
        $data['digital_store'] ?? null,
        $data['front_cover_image'] ?? null,
        $data['back_cover_image'] ?? null,
        !empty($data['release_date']) ? $data['release_date'] : null,
        $id
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Game updated successfully'
    ]);
}

function deleteGame() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    // Get game to delete images
    $stmt = $pdo->prepare("SELECT front_cover_image, back_cover_image FROM games WHERE id = ?");
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    
    // Get extra images
    $stmt = $pdo->prepare("SELECT image_path FROM game_images WHERE game_id = ?");
    $stmt->execute([$id]);
    $extraImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Delete game (cascade will delete game_images)
    $stmt = $pdo->prepare("DELETE FROM games WHERE id = ?");
    $stmt->execute([$id]);
    
    // Delete image files
    if ($game) {
        if ($game['front_cover_image'] && file_exists(COVERS_DIR . basename($game['front_cover_image']))) {
            unlink(COVERS_DIR . basename($game['front_cover_image']));
        }
        if ($game['back_cover_image'] && file_exists(COVERS_DIR . basename($game['back_cover_image']))) {
            unlink(COVERS_DIR . basename($game['back_cover_image']));
        }
    }
    
    foreach ($extraImages as $imagePath) {
        if (file_exists(EXTRAS_DIR . basename($imagePath))) {
            unlink(EXTRAS_DIR . basename($imagePath));
        }
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Game deleted successfully'
    ]);
}

