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
        
        // Get user_id from session or optional parameter (for admin viewing other users)
        $currentUserId = $_SESSION['user_id'];
        $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
        $targetUserId = isset($_GET['user_id']) && $isAdmin ? (int)$_GET['user_id'] : $currentUserId;
        
        // Get total count
        $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT g.id) as total FROM games g WHERE g.user_id = ?");
        $countStmt->execute([$targetUserId]);
        $totalGames = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = $totalGames > 0 ? ceil($totalGames / $perPage) : 1;
        
        error_log("listGames: Page $page of $totalPages (showing $perPage games, offset $offset) for user_id: $targetUserId");
        
        // Re-execute query with pagination
        $stmt = $pdo->prepare("
            SELECT g.id,
                   g.title,
                   g.platform,
                   g.genre,
                   g.series,
                   g.special_edition,
                   g.`condition`,
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
            WHERE g.user_id = ?
            GROUP BY g.id
            ORDER BY g.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $stmt->execute([$targetUserId, $perPage, $offset]);
        
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
    $currentUserId = $_SESSION['user_id'];
    $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT * FROM games WHERE id = ?");
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    
    if (!$game) {
        sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
    }
    
    // Verify ownership (unless admin)
    if (!$isAdmin && $game['user_id'] != $currentUserId) {
        sendJsonResponse(['success' => false, 'message' => 'Access denied'], 403);
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

/**
 * Find matching game with fuzzy title and exact platform match
 * Returns game data if found, null otherwise
 */
function findMatchingGame($title, $platform) {
    global $pdo;
    
    if (empty($title) || empty($platform)) {
        return null;
    }
    
    // Normalize title for fuzzy matching (remove special chars, lowercase)
    $normalizedTitle = strtolower(trim($title));
    $normalizedTitle = preg_replace('/[^a-z0-9\s]/', '', $normalizedTitle);
    $normalizedTitle = preg_replace('/\s+/', ' ', $normalizedTitle);
    
    // Get all games with matching platform
    $stmt = $pdo->prepare("
        SELECT id, title, front_cover_image, back_cover_image
        FROM games
        WHERE platform = ?
        AND front_cover_image IS NOT NULL
        AND front_cover_image != ''
    ");
    $stmt->execute([$platform]);
    $games = $stmt->fetchAll();
    
    if (empty($games)) {
        return null;
    }
    
    // Find best match using fuzzy matching
    $bestMatch = null;
    $bestScore = 0;
    
    foreach ($games as $game) {
        // Normalize game title
        $gameTitle = strtolower(trim($game['title']));
        $gameTitle = preg_replace('/[^a-z0-9\s]/', '', $gameTitle);
        $gameTitle = preg_replace('/\s+/', ' ', $gameTitle);
        
        // Calculate similarity using similar_text
        similar_text($normalizedTitle, $gameTitle, $percent);
        
        // Also check if one title contains the other (for partial matches)
        if (strpos($normalizedTitle, $gameTitle) !== false || strpos($gameTitle, $normalizedTitle) !== false) {
            $percent = max($percent, 85); // Boost partial matches
        }
        
        if ($percent > $bestScore && $percent >= 80) { // 80% similarity threshold
            $bestScore = $percent;
            $bestMatch = $game;
        }
    }
    
    // Check if best match has local images (not external URLs)
    if ($bestMatch) {
        $frontCover = $bestMatch['front_cover_image'] ?? null;
        $backCover = $bestMatch['back_cover_image'] ?? null;
        
        // Only return if at least one cover is a local file (not external URL)
        $hasLocalFront = $frontCover && !preg_match('/^https?:\/\//', $frontCover);
        $hasLocalBack = $backCover && !preg_match('/^https?:\/\//', $backCover);
        
        if ($hasLocalFront || $hasLocalBack) {
            return [
                'front_cover_image' => $hasLocalFront ? $frontCover : null,
                'back_cover_image' => $hasLocalBack ? $backCover : null
            ];
        }
    }
    
    return null;
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
    
    $userId = $_SESSION['user_id'];
    
    // Check for matching game to reuse images (only if user hasn't provided images)
    $frontCover = $data['front_cover_image'] ?? null;
    $backCover = $data['back_cover_image'] ?? null;
    
    if (empty($frontCover) || empty($backCover)) {
        $matchingGame = findMatchingGame($data['title'], $data['platform']);
        
        if ($matchingGame) {
            // Use matching images only if user hasn't provided them
            if (empty($frontCover) && !empty($matchingGame['front_cover_image'])) {
                $frontCover = $matchingGame['front_cover_image'];
                error_log("Reusing front cover from matching game for: {$data['title']} ({$data['platform']})");
            }
            
            if (empty($backCover) && !empty($matchingGame['back_cover_image'])) {
                $backCover = $matchingGame['back_cover_image'];
                error_log("Reusing back cover from matching game for: {$data['title']} ({$data['platform']})");
            }
        }
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO games (
            user_id, title, platform, genre, description, series, special_edition,
            `condition`, review, star_rating, metacritic_rating, played,
            price_paid, pricecharting_price, is_physical, digital_store,
            front_cover_image, back_cover_image, release_date
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");
    
    $stmt->execute([
        $userId,
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
        $frontCover,
        $backCover,
        !empty($data['release_date']) ? $data['release_date'] : null
    ]);
    
    $gameId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Game created successfully',
        'game_id' => $gameId
    ]);
}

/**
 * Download external image and return local filename
 */
function downloadExternalImage($imageUrl, $gameId = null, $type = 'front') {
    // Validate URL
    $parsedUrl = @parse_url($imageUrl);
    if ($parsedUrl === false || empty($parsedUrl['scheme']) || empty($parsedUrl['host'])) {
        return false;
    }
    
    // Only allow HTTPS
    if ($parsedUrl['scheme'] !== 'https') {
        return false;
    }
    
    // Block local/internal IPs
    $host = $parsedUrl['host'] ?? '';
    if (preg_match('/^(localhost|127\.0\.0\.1|::1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)) {
        return false;
    }
    
    // Download image using cURL
    $ch = curl_init($imageUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'GameTracker/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: image/jpeg,image/png,image/gif,image/webp,*/*'
        ]
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error || $httpCode !== 200 || empty($imageData)) {
        error_log("Failed to download image from $imageUrl: $error (HTTP $httpCode)");
        return false;
    }
    
    // Validate it's actually an image (check magic bytes)
    $magicBytes = substr($imageData, 0, 4);
    $magicBytesHex = bin2hex($magicBytes);
    $isValidImage = false;
    
    // JPEG: FF D8 FF
    if (substr($magicBytesHex, 0, 6) === 'ffd8ff') {
        $isValidImage = true;
        $extension = 'jpg';
    }
    // PNG: 89 50 4E 47
    elseif ($magicBytesHex === '89504e47') {
        $isValidImage = true;
        $extension = 'png';
    }
    // GIF: 47 49 46 38
    elseif (substr($magicBytesHex, 0, 8) === '47494638') {
        $isValidImage = true;
        $extension = 'gif';
    }
    // WebP: Check for RIFF...WEBP
    elseif (substr($magicBytesHex, 0, 8) === '52494646' && strpos($imageData, 'WEBP') !== false) {
        $isValidImage = true;
        $extension = 'webp';
    }
    
    if (!$isValidImage) {
        // Try to get extension from content type or URL
        if (stripos($contentType, 'png') !== false) {
            $extension = 'png';
        } elseif (stripos($contentType, 'gif') !== false) {
            $extension = 'gif';
        } elseif (stripos($contentType, 'webp') !== false) {
            $extension = 'webp';
        } else {
            $urlPath = parse_url($imageUrl, PHP_URL_PATH);
            $urlExtension = pathinfo($urlPath, PATHINFO_EXTENSION);
            if (in_array(strtolower($urlExtension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = strtolower($urlExtension);
            } else {
                $extension = 'jpg'; // Default
            }
        }
    }
    
    // Generate unique filename
    $filename = generateUniqueFilename('cover_' . time() . '_' . uniqid() . '.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;
    
    // Save image
    if (!file_put_contents($targetPath, $imageData)) {
        error_log("Failed to save image to: $targetPath");
        return false;
    }
    
    return $filename;
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
    
    $currentUserId = $_SESSION['user_id'];
    $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
    
    // Check if game exists and verify ownership
    $stmt = $pdo->prepare("SELECT id, user_id FROM games WHERE id = ?");
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    
    if (!$game) {
        sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
    }
    
    // Verify ownership (unless admin)
    if (!$isAdmin && $game['user_id'] != $currentUserId) {
        sendJsonResponse(['success' => false, 'message' => 'Access denied'], 403);
    }
    
    // Get current game data to check for missing images
    $currentStmt = $pdo->prepare("SELECT title, platform, front_cover_image, back_cover_image FROM games WHERE id = ?");
    $currentStmt->execute([$id]);
    $currentGame = $currentStmt->fetch(PDO::FETCH_ASSOC);
    
    // Determine final image values
    // If explicitly set in data, use that; otherwise preserve current value
    $frontCover = isset($data['front_cover_image']) ? $data['front_cover_image'] : $currentGame['front_cover_image'];
    $backCover = isset($data['back_cover_image']) ? $data['back_cover_image'] : $currentGame['back_cover_image'];
    
    // If images are missing (empty or null), try to find matching games to reuse images
    if (empty($frontCover) || empty($backCover)) {
        $gameTitle = $data['title'] ?? $currentGame['title'];
        $gamePlatform = $data['platform'] ?? $currentGame['platform'];
        
        $matchingGame = findMatchingGame($gameTitle, $gamePlatform);
        
        if ($matchingGame) {
            // Use matching images only if current image is missing
            if (empty($frontCover) && !empty($matchingGame['front_cover_image'])) {
                $frontCover = $matchingGame['front_cover_image'];
                error_log("Reusing front cover from matching game for existing game: $gameTitle ($gamePlatform)");
            }
            
            if (empty($backCover) && !empty($matchingGame['back_cover_image'])) {
                $backCover = $matchingGame['back_cover_image'];
                error_log("Reusing back cover from matching game for existing game: $gameTitle ($gamePlatform)");
            }
        }
    }
    
    // Update data array with final image values
    $data['front_cover_image'] = $frontCover;
    $data['back_cover_image'] = $backCover;
    
    // Auto-download external URLs and convert to local files
    // Check JSON data first, then POST/GET, default to true
    $autoDownload = $data['auto_download'] ?? $_POST['auto_download'] ?? $_GET['auto_download'] ?? true;
    
    if ($autoDownload) {
        // Download front cover if it's an external URL
        if (isset($data['front_cover_image']) && 
            (strpos($data['front_cover_image'], 'http://') === 0 || strpos($data['front_cover_image'], 'https://') === 0)) {
            $downloaded = downloadExternalImage($data['front_cover_image'], $id, 'front');
            if ($downloaded) {
                $data['front_cover_image'] = $downloaded;
                error_log("Auto-downloaded front cover for game $id: $downloaded");
            } else {
                error_log("Failed to auto-download front cover for game $id, keeping URL");
            }
        }
        
        // Download back cover if it's an external URL
        if (isset($data['back_cover_image']) && 
            (strpos($data['back_cover_image'], 'http://') === 0 || strpos($data['back_cover_image'], 'https://') === 0)) {
            $downloaded = downloadExternalImage($data['back_cover_image'], $id, 'back');
            if ($downloaded) {
                $data['back_cover_image'] = $downloaded;
                error_log("Auto-downloaded back cover for game $id: $downloaded");
            } else {
                error_log("Failed to auto-download back cover for game $id, keeping URL");
            }
        }
    }
    
    $stmt = $pdo->prepare("
        UPDATE games SET
            title = ?,
            platform = ?,
            genre = ?,
            description = ?,
            series = ?,
            special_edition = ?,
            `condition` = ?,
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
    
    try {
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
        
        // Verify what was actually saved
        $verifyStmt = $pdo->prepare("SELECT front_cover_image FROM games WHERE id = ?");
        $verifyStmt->execute([$id]);
        $saved = $verifyStmt->fetch(PDO::FETCH_ASSOC);
        if ($saved && isset($data['front_cover_image'])) {
            $savedLength = strlen($saved['front_cover_image'] ?? '');
            $sentLength = strlen($data['front_cover_image']);
            if ($savedLength !== $sentLength) {
                error_log("WARNING: Front cover image length mismatch - Sent: $sentLength, Saved: $savedLength");
            } else {
                error_log("Front cover image saved successfully - Length: $savedLength");
            }
        }
    } catch (PDOException $e) {
        error_log("Error updating game: " . $e->getMessage());
        if (strpos($e->getMessage(), 'Data too long') !== false) {
            sendJsonResponse(['success' => false, 'message' => 'Cover image is too large. Please use a smaller image or an external URL.'], 400);
        } else {
            sendJsonResponse(['success' => false, 'message' => 'Failed to update game: ' . $e->getMessage()], 500);
        }
        return;
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Game updated successfully'
    ]);
}

function deleteGame() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    $currentUserId = $_SESSION['user_id'];
    $isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    // Get game to verify ownership and delete images
    $stmt = $pdo->prepare("SELECT user_id, front_cover_image, back_cover_image FROM games WHERE id = ?");
    $stmt->execute([$id]);
    $game = $stmt->fetch();
    
    if (!$game) {
        sendJsonResponse(['success' => false, 'message' => 'Game not found'], 404);
    }
    
    // Verify ownership (unless admin)
    if (!$isAdmin && $game['user_id'] != $currentUserId) {
        sendJsonResponse(['success' => false, 'message' => 'Access denied'], 403);
    }
    
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

