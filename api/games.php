<?php

require_once __DIR__ . '/../src/autoload.php';

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Query\ArrayOptions;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;
/**
 * Games CRUD API endpoints
 */

// Suppress error display and enable output buffering
error_reporting(E_ALL);
ini_set('display_errors', 0);
@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '300');

// Load functions first so sendJsonResponse is available
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/thumbnail.php';

// Load database configuration (MySQL) - this also handles session configuration
require_once __DIR__ . '/../includes/config.php';

// Session is now started by config.php with proper cookie parameters
// No need to start it again here

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

try {
    // Check if $pdo is available
    if (!isset($pdo)) {
        sendJsonResponse(['success' => false, 'message' => 'Database connection failed'], 500);
    }
    
    // Session auth via the shared helper (returns JSON 401 on failure).
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/csrf.php';
    $userId = requireUser();
    
    header('Content-Type: application/json');
} catch (Throwable $e) {
    ob_clean();
    error_log('Games API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
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
        
        case 'platforms':
            getPlatforms();
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

    if (!isset($pdo)) {
        error_log('listGames: Database connection not available');
        sendJsonResponse(['success' => false, 'message' => 'Database connection not available'], 500);
        return;
    }

    // Always scope to the caller. A cross-user ?user_id= override was an IDOR
    // (Fable §1); GamesService takes an explicit $userId so that scoping is
    // structural rather than remembered.
    $userId = $_SESSION['user_id'];

    // v1 spells paging `per_page`; the shared FilterCompiler reads `per-page`.
    // Translate rather than change the wire format — js/games.js sends the
    // underscore, and silently ignoring it would drop the web app back to a
    // fixed 100 rows a page with nothing to show for it.
    $options = [
        'page' => isset($_GET['page']) ? (string)max(1, (int)$_GET['page']) : '1',
        'per-page' => isset($_GET['per_page'])
            ? (string)max(1, min(1000, (int)$_GET['per_page']))
            : '100',
    ];

    try {
        $filters = FilterCompiler::compile(GamesFilters::definition(), new ArrayOptions($options));

        // ['games' => [...], 'pagination' => [...]] — already the exact shape
        // js/games.js consumes, so the only thing v1 adds is `success`.
        sendJsonResponse(['success' => true] + GamesService::list($pdo, $userId, $filters));
    } catch (Throwable $e) {
        error_log('listGames failed: ' . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Failed to load games'], 500);
    }
}

function getGame() {
    global $pdo;

    $id = (int)($_GET['id'] ?? 0);

    try {
        // isAdmin() reproduces v1's admin override, passed explicitly so the
        // escalation stays visible at the call site rather than being resolved
        // from ambient state inside the service.
        $game = GamesService::get($pdo, $_SESSION['user_id'], $id, isAdmin());

        sendJsonResponse(['success' => true, 'game' => $game]);
    } catch (BadRequestException $e) {
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    } catch (NotFoundException $e) {
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 404);
    } catch (AccessDeniedException $e) {
        // v1 answered 403 here and the message is unchanged, so the frontend's
        // handling is untouched.
        sendJsonResponse(['success' => false, 'message' => $e->getMessage()], 403);
    }
}

function findMatchingGame($title, $platform) {
    global $pdo;
    
    if (empty($title) || empty($platform)) {
        return null;
    }
    
    // Normalize title for matching (remove special chars, lowercase)
    $normalizedTitle = strtolower(trim($title));
    $normalizedTitle = preg_replace('/[^a-z0-9\s]/', '', $normalizedTitle);
    $normalizedTitle = preg_replace('/\s+/', ' ', $normalizedTitle);
    
    // Extract key words from title (first 2-3 words) for initial database filtering
    $titleWords = explode(' ', $normalizedTitle);
    $keyWords = array_slice($titleWords, 0, min(3, count($titleWords)));
    $searchPattern = '%' . implode('%', $keyWords) . '%';
    
    // First, use database LIKE to filter down to potential matches
    // This dramatically reduces the dataset before PHP fuzzy matching
    $stmt = $pdo->prepare("
        SELECT id, title, front_cover_image, back_cover_image
        FROM games
        WHERE platform = ?
        AND front_cover_image IS NOT NULL
        AND front_cover_image != ''
        AND (
            LOWER(REPLACE(REPLACE(REPLACE(REPLACE(title, '-', ' '), ':', ' '), '!', ' '), '.', ' ')) LIKE ?
            OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(title, '-', ' '), ':', ' '), '!', ' '), '.', ' ')) LIKE ?
        )
        LIMIT 50
    ");
    
    // Try with first word, then first two words
    $firstWordPattern = '%' . ($titleWords[0] ?? '') . '%';
    $stmt->execute([$platform, $searchPattern, $firstWordPattern]);
    $games = $stmt->fetchAll();
    
    if (empty($games)) {
        return null;
    }
    
    // Find best match using fuzzy matching on the filtered results
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
    requireCsrfToken();
    
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
    
    // create localised nothing before this: a data: URI or an http URL supplied
    // at creation time was stored verbatim, which is why 51 rows hold a URL and
    // 42 hold an inlined image.
    $createAutoDownload = $data['auto_download'] ?? $_POST['auto_download'] ?? $_GET['auto_download'] ?? true;

    list($frontCover, $frontError) = normaliseImageValue($frontCover, null, 'front', (bool)$createAutoDownload);
    if ($frontError !== null) {
        sendJsonResponse(['success' => false, 'message' => $frontError], 400);
    }

    list($backCover, $backError) = normaliseImageValue($backCover, null, 'back', (bool)$createAutoDownload);
    if ($backError !== null) {
        sendJsonResponse(['success' => false, 'message' => $backError], 400);
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
/**
 * Decode a data: URI into a real file and return its bare filename.
 *
 * Mirrors downloadExternalImage's contract — bare filename on success, false on
 * failure — so both localisation branches at the call sites stay symmetrical.
 *
 * This exists because an image column may hold a filename or a URL and never an
 * image. Storing the URI verbatim had put ~113 MB of base64 into the games
 * table by 2026-08-04, most of the database, and it ships in full to the phone
 * on every delta sync because api/v2/sync/changes.php selects whole rows.
 *
 * @return string|false
 */
function storeDataUriImage($dataUri, $gameId = null, $type = 'front') {
    if (!is_string($dataUri) || stripos($dataUri, 'data:') !== 0) {
        return false;
    }

    $comma = strpos($dataUri, ',');
    if ($comma === false) {
        return false;
    }

    $header  = substr($dataUri, 5, $comma - 5);   // e.g. "image/png;base64"
    $payload = substr($dataUri, $comma + 1);

    if (stripos($header, 'base64') === false) {
        return false;
    }

    // Strict decode: reject anything outside the base64 alphabet rather than
    // silently turning a truncated payload into a corrupt file.
    $binary = base64_decode($payload, true);
    if ($binary === false || $binary === '') {
        return false;
    }

    // Magic bytes, not the declared MIME — the header is client-supplied.
    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        return false;
    }

    $byType = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $extension = $byType[$info[2]] ?? null;
    if ($extension === null) {
        return false;
    }

    $filename   = generateUniqueFilename('cover.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;

    if (file_put_contents($targetPath, $binary) === false) {
        error_log("storeDataUriImage: could not write $targetPath");
        return false;
    }

    require_once __DIR__ . '/../includes/thumbnail.php';
    gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

    return $filename;
}

/**
 * Normalise whatever the client sent for an image column into a stored value.
 *
 * One helper for create and update, which had drifted: update localised http(s)
 * URLs while create localised nothing at all, which is why 51 rows hold a URL
 * rather than a file.
 *
 * A data: URI is converted regardless of $autoDownload. That flag is a
 * preference about fetching *remote* images; inlining an image into the
 * database is not something it should be able to opt into.
 *
 * @return array{0: string|null, 1: string|null} [value to store, error or null]
 */
function normaliseImageValue($value, $gameId, $type, $autoDownload = true) {
    if (!is_string($value) || $value === '') {
        return [$value, null];
    }

    if (stripos($value, 'data:') === 0) {
        $stored = storeDataUriImage($value, $gameId, $type);

        // Deliberately an error rather than a fallback: storing the URI is the
        // behaviour being removed, so failing loudly is the whole point.
        return $stored === false
            ? [null, 'Could not decode the supplied image data']
            : [$stored, null];
    }

    if ($autoDownload
        && (stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0)) {
        $downloaded = downloadExternalImage($value, $gameId, $type);

        // A URL that will not fetch stays a URL — pre-existing behaviour, and
        // the column legitimately holds URLs.
        return [$downloaded === false ? $value : $downloaded, null];
    }

    return [$value, null];
}

function downloadExternalImage($imageUrl, $gameId = null, $type = 'front') {
    require_once __DIR__ . '/../includes/http-fetch.php';
    try {
        $result = gt_safe_http_fetch($imageUrl, [
            'accept' => 'image/jpeg,image/png,image/gif,image/webp,*/*',
        ]);
    } catch (GtSsrfException $e) {
        error_log("games.php SSRF blocked for cover URL $imageUrl: {$e->getMessage()}");
        return false;
    } catch (GtFetchException $e) {
        error_log("games.php cover fetch failed for $imageUrl: {$e->getMessage()}");
        return false;
    }

    $imageData   = $result['data'];
    $contentType = $result['content_type'];
    
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
    
    // Generate unique filename. Pass just "cover.<ext>" so generateUniqueFilename
    // appends ONE _time_uniqid — passing a pre-uniquified name caused a doubled
    // suffix bug (issue #63): cover_TS1_HASH1_TS2_HASH2.jpg
    $filename = generateUniqueFilename('cover.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;
    
    // Save image
    if (!file_put_contents($targetPath, $imageData)) {
        error_log("Failed to save image to: $targetPath");
        return false;
    }

    // Generate thumbnail (best-effort; failure is non-fatal). Matches the
    // upload + external-image-service paths — without this, list/grid views
    // that request the _thumb variant get 404s. Root cause of #59 + #63.
    gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

    return $filename;
}

function updateGame() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    requireCsrfToken();
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Game ID is required'], 400);
    }
    
    $currentUserId = $_SESSION['user_id'];
    $isAdmin = isAdmin();
    
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
    
    // Normalise both image columns. A data: URI is always converted to a file;
    // an http(s) URL is only fetched when auto-download is on. Note this runs
    // regardless of $autoDownload — see normaliseImageValue().
    foreach (['front_cover_image' => 'front', 'back_cover_image' => 'back'] as $column => $face) {
        if (!isset($data[$column])) {
            continue;
        }

        list($stored, $error) = normaliseImageValue($data[$column], $id, $face, (bool)$autoDownload);

        if ($error !== null) {
            sendJsonResponse(['success' => false, 'message' => $error], 400);
        }

        $data[$column] = $stored;
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
            sendJsonResponse(['success' => false, 'message' => 'Failed to update game'], 500);
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

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    requireCsrfToken();

    $id = $_GET['id'] ?? 0;
    $currentUserId = $_SESSION['user_id'];
    $isAdmin = isAdmin();

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

function getPlatforms() {
    global $pdo;

    // Always scope to the caller, and ignore ?user_id= rather than rejecting
    // it — the same contract as listGames.
    //
    // This NARROWS the response. v1 returned every user's platform names when
    // no user_id was given, "for dropdown suggestions", and let ?user_id= pivot
    // to any chosen user — the IDOR pattern Fable §1 removed from the list
    // endpoint. The add-game datalist in js/games.js consequently suggests only
    // platforms the caller already owns; the other three platform dropdowns
    // build their lists client-side from allGames and are unaffected.
    $userId = $_SESSION['user_id'];

    try {
        sendJsonResponse([
            'success' => true,
            'platforms' => GamesService::platforms($pdo, $userId),
        ]);
    } catch (Throwable $e) {
        error_log('getPlatforms failed: ' . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Failed to get platforms'], 500);
    }
}

