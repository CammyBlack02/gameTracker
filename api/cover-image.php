<?php
/**
 * Cover image fetching API
 * Uses TheGamesDB API (free, no auth required) to find game cover images
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

$title = $_GET['title'] ?? '';
$platform = $_GET['platform'] ?? '';

if (empty($title)) {
    sendJsonResponse(['success' => false, 'message' => 'Title is required'], 400);
}

// Try multiple APIs for cover images
// Method 1: TheGamesDB API (v1 - working version)
// Use the same API key as release dates
$apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';

// Clean title - try multiple variations (same as release date script)
$cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
$cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
$cleanTitle = trim($cleanTitle);

// Try different title variations for better matching
$titleVariations = [
    $cleanTitle,
    str_replace(':', '', $cleanTitle), // Remove colons (e.g., "007: Agent" -> "007 Agent")
    preg_replace('/^0+(\d+):/', '$1', $cleanTitle), // "007: Agent" -> "7: Agent"
    preg_replace('/^0+(\d+)\s/', '$1 ', $cleanTitle), // "007 Agent" -> "7 Agent"
];

// Map common platform names to TheGamesDB platform IDs
$platformMap = [
    'PlayStation' => 1,
    'PlayStation 2' => 2,
    'PlayStation 3' => 3,
    'PlayStation 4' => 4,
    'PlayStation 5' => 5,
    'Xbox' => 6,
    'Xbox 360' => 7,
    'Xbox One' => 8,
    'Xbox Series X' => 9,
    'Nintendo Switch' => 10,
    'Wii' => 11,
    'Wii U' => 12,
    'Nintendo 3DS' => 13,
    'Nintendo DS' => 14,
    'PC' => 15,
    'Steam' => 15,
    'Windows' => 15,
    'GameCube' => 16,
    'Nintendo 64' => 19,
    'SNES' => 20,
    'Mega Drive' => 29,
    'Sega Genesis' => 29, // Also support US naming
    'Dreamcast' => 23,
    'PS Vita' => 38,
];

$platformId = !empty($platform) ? ($platformMap[$platform] ?? null) : null;

// Try each title variation
$gameId = null;
$game = null;

foreach ($titleVariations as $searchTitle) {
    $searchUrl = 'https://api.thegamesdb.net/v1/Games/ByGameName?apikey=' . urlencode($apiKey) . '&name=' . urlencode($searchTitle);
    
    if ($platformId) {
        $searchUrl .= '&platform=' . $platformId;
    }
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        continue; // Try next variation
    }
    
    $data = json_decode($response, true);
    
    // Check for API key error
    if (isset($data['code']) && $data['code'] == 401) {
        sendJsonResponse([
            'success' => false,
            'message' => 'TheGamesDB API key is invalid. Please check your API key.',
            'image_url' => null
        ]);
    }
    
    // Try both 'Games' (capital) and 'games' (lowercase)
    $games = null;
    if (isset($data['data']['Games']) && !empty($data['data']['Games'])) {
        $games = $data['data']['Games'];
    } elseif (isset($data['data']['games']) && !empty($data['data']['games'])) {
        $games = $data['data']['games'];
    }
    
    if (!empty($games)) {
        $game = $games[0];
        $gameId = $game['id'];
        break; // Found a match!
    }
}

// If we found a game, get its images
if ($gameId && $game) {
    
    // Get game images using v1 API
    $imagesUrl = 'https://api.thegamesdb.net/v1/Games/Images?apikey=' . urlencode($apiKey) . '&games_id=' . $gameId;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $imagesUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
    
    $imagesResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $imagesResponse) {
        $imagesData = json_decode($imagesResponse, true);
        
        // TheGamesDB v1 can return images in different structures
        // Try: data.images[gameId] or data.Images[gameId]
        $images = null;
        if (isset($imagesData['data']['images'][$gameId])) {
            $images = $imagesData['data']['images'][$gameId];
        } elseif (isset($imagesData['data']['Images'][$gameId])) {
            $images = $imagesData['data']['Images'][$gameId];
        }
        
        if ($images) {
            // Look for boxart (front cover)
            // TheGamesDB v1 can return boxart as:
            // - A string path: "/boxart/front/..."
            // - An object with 'original' key
            // - An array with different resolutions
            $boxart = null;
            
            if (isset($images['boxart'])) {
                $boxart = $images['boxart'];
            } elseif (isset($images['Boxart'])) {
                $boxart = $images['Boxart'];
            }
            
            if ($boxart) {
                // Handle different boxart formats
                if (is_string($boxart)) {
                    // Direct path string
                    $imagePath = $boxart;
                } elseif (is_array($boxart)) {
                    // Array format - look for 'original' or first available
                    if (isset($boxart['original'])) {
                        $imagePath = $boxart['original'];
                    } elseif (isset($boxart[0])) {
                        $imagePath = $boxart[0];
                    } else {
                        $imagePath = reset($boxart); // Get first value
                    }
                } else {
                    $imagePath = null;
                }
                
                if ($imagePath) {
                    // Ensure path starts with /
                    if (substr($imagePath, 0, 1) !== '/') {
                        $imagePath = '/' . $imagePath;
                    }
                    
                    // Get the original resolution image
                    $imageUrl = 'https://cdn.thegamesdb.net/images/original' . $imagePath;
                    
                    sendJsonResponse([
                        'success' => true,
                        'image_url' => $imageUrl,
                        'message' => 'Cover image found'
                    ]);
                }
            }
        }
    }
}

// Fallback: No cover image found
// Return failure - user can upload manually
sendJsonResponse([
    'success' => false,
    'message' => 'Could not find cover image automatically. TheGamesDB may not have this game in their database. Please upload manually.',
    'image_url' => null
]);

