<?php
/**
 * Game Completions API endpoints
 */

// Suppress error display and enable output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);
ob_start();

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
    error_log('Completions API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred: ' . $e->getMessage()], 500);
}

try {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'list':
            listCompletions();
            break;
        
        case 'get':
            getCompletion();
            break;
        
        case 'create':
            createCompletion();
            break;
        
        case 'update':
            updateCompletion();
            break;
        
        case 'delete':
            deleteCompletion();
            break;
        
        case 'link':
            linkCompletion();
            break;
        
        default:
            sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
    }
} catch (Throwable $e) {
    error_log('Completions API Error in action handler: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
}

function listCompletions() {
    global $pdo;
    
    try {
        $year = $_GET['year'] ?? null;
        $status = $_GET['status'] ?? 'all'; // 'all', 'completed', 'in_progress'
        
        $where = [];
        $params = [];
        
        if ($year) {
            $where[] = "completion_year = ?";
            $params[] = $year;
        }
        
        if ($status === 'completed') {
            $where[] = "date_completed IS NOT NULL";
        } else if ($status === 'in_progress') {
            $where[] = "date_completed IS NULL";
        }
        
        $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";
        
        $sql = "
            SELECT c.*, 
                   g.title as game_title,
                   g.front_cover_image,
                   g.platform as game_platform
            FROM game_completions c
            LEFT JOIN games g ON c.game_id = g.id
            $whereClause
            ORDER BY 
                COALESCE(c.date_completed, c.date_started, c.created_at) ASC,
                c.date_started ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $completions = $stmt->fetchAll();
        
        sendJsonResponse(['success' => true, 'completions' => $completions]);
    } catch (Throwable $e) {
        error_log('listCompletions Error: ' . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Failed to load completions: ' . $e->getMessage()], 500);
    }
}

function getCompletion() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Completion ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("
        SELECT c.*, 
               g.title as game_title,
               g.front_cover_image,
               g.platform as game_platform
        FROM game_completions c
        LEFT JOIN games g ON c.game_id = g.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $completion = $stmt->fetch();
    
    if (!$completion) {
        sendJsonResponse(['success' => false, 'message' => 'Completion not found'], 404);
    }
    
    sendJsonResponse(['success' => true, 'completion' => $completion]);
}

function createCompletion() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['title'])) {
        sendJsonResponse(['success' => false, 'message' => 'Title is required'], 400);
    }
    
    // Extract year from completion date or use current year
    $completionYear = date('Y');
    if (!empty($data['date_completed'])) {
        $year = date('Y', strtotime($data['date_completed']));
        if ($year) {
            $completionYear = (int)$year;
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO game_completions (
            game_id, title, platform, time_taken,
            date_started, date_completed, completion_year, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $data['game_id'] ?? null,
        $data['title'] ?? '',
        $data['platform'] ?? null,
        $data['time_taken'] ?? null,
        !empty($data['date_started']) ? $data['date_started'] : null,
        !empty($data['date_completed']) ? $data['date_completed'] : null,
        $completionYear,
        $data['notes'] ?? null
    ]);
    
    $completionId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Completion added successfully',
        'completion_id' => $completionId
    ]);
}

function updateCompletion() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Completion ID is required'], 400);
    }
    
    // Extract year from completion date
    $completionYear = null;
    if (!empty($data['date_completed'])) {
        $year = date('Y', strtotime($data['date_completed']));
        if ($year) {
            $completionYear = (int)$year;
        }
    }
    
    $stmt = $pdo->prepare("
        UPDATE game_completions SET
            game_id = ?,
            title = ?,
            platform = ?,
            time_taken = ?,
            date_started = ?,
            date_completed = ?,
            completion_year = ?,
            notes = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $stmt->execute([
        $data['game_id'] ?? null,
        $data['title'] ?? '',
        $data['platform'] ?? null,
        $data['time_taken'] ?? null,
        !empty($data['date_started']) ? $data['date_started'] : null,
        !empty($data['date_completed']) ? $data['date_completed'] : null,
        $completionYear,
        $data['notes'] ?? null,
        $id
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Completion updated successfully'
    ]);
}

function deleteCompletion() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Completion ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("DELETE FROM game_completions WHERE id = ?");
    $stmt->execute([$id]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Completion deleted successfully'
    ]);
}

function linkCompletion() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $completionId = $data['completion_id'] ?? 0;
    $gameId = $data['game_id'] ?? null;
    
    if (!$completionId) {
        sendJsonResponse(['success' => false, 'message' => 'Completion ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("UPDATE game_completions SET game_id = ? WHERE id = ?");
    $stmt->execute([$gameId, $completionId]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Completion linked successfully'
    ]);
}

