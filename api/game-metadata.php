<?php
/**
 * Fetch game metadata (genre and description) from TheGamesDB API
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

$apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';

// Clean title - try multiple variations
$cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
$cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
$cleanTitle = trim($cleanTitle);

// Try different title variations for better matching
$titleVariations = [
    $cleanTitle,
    str_replace(':', '', $cleanTitle),
    preg_replace('/^0+(\d+):/', '$1', $cleanTitle),
    preg_replace('/^0+(\d+)\s/', '$1 ', $cleanTitle),
];

// Platform mapping
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
    'Sega Genesis' => 29,
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
        continue;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['code']) && $data['code'] == 401) {
        sendJsonResponse([
            'success' => false,
            'message' => 'TheGamesDB API key is invalid.',
            'genre' => null,
            'description' => null
        ]);
    }
    
    // Try both 'Games' and 'games'
    $games = null;
    if (isset($data['data']['Games']) && !empty($data['data']['Games'])) {
        $games = $data['data']['Games'];
    } elseif (isset($data['data']['games']) && !empty($data['data']['games'])) {
        $games = $data['data']['games'];
    }
    
    if (!empty($games)) {
        $game = $games[0];
        $gameId = $game['id'];
        break;
    }
}

if (!$gameId || !$game) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Game not found in TheGamesDB',
        'genre' => null,
        'description' => null
    ]);
}

// Get detailed game information including genres and overview
$detailsUrl = 'https://api.thegamesdb.net/v1/Games/ByGameID?apikey=' . urlencode($apiKey) . '&id=' . $gameId . '&fields=players,publishers,genres,overview,last_updated,rating,platform,coop,youtube,os,processor,ram,hdd,video,sound,alternates,release_date';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $detailsUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');

$detailsResponse = curl_exec($ch);
$detailsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($detailsHttpCode !== 200 || !$detailsResponse) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Could not fetch game details',
        'genre' => null,
        'description' => null
    ]);
}

$detailsData = json_decode($detailsResponse, true);

// Try both 'Games' (capital) and 'games' (lowercase)
$gameDetails = null;
if (isset($detailsData['data']['Games'][$gameId])) {
    $gameDetails = $detailsData['data']['Games'][$gameId];
} elseif (isset($detailsData['data']['games'][$gameId])) {
    $gameDetails = $detailsData['data']['games'][$gameId];
} elseif (isset($detailsData['data']['Games']) && is_array($detailsData['data']['Games'])) {
    // Maybe it's an array with numeric keys
    foreach ($detailsData['data']['Games'] as $key => $game) {
        if (isset($game['id']) && $game['id'] == $gameId) {
            $gameDetails = $game;
            break;
        }
    }
} elseif (isset($detailsData['data']['games']) && is_array($detailsData['data']['games'])) {
    // Maybe it's an array with numeric keys
    foreach ($detailsData['data']['games'] as $key => $game) {
        if (isset($game['id']) && $game['id'] == $gameId) {
            $gameDetails = $game;
            break;
        }
    }
}

if (!$gameDetails) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Game details not found',
        'genre' => null,
        'description' => null
    ]);
}

// Extract genre
$genre = null;
if (isset($gameDetails['genres'])) {
    $genres = $gameDetails['genres'];
    
    // TheGamesDB can return genres as an array of genre IDs or objects
    if (is_array($genres) && !empty($genres)) {
        // Check if we need to look up genre names from the included data
        if (isset($detailsData['include']['genres'])) {
            $genreMap = $detailsData['include']['genres'];
            $genreIds = is_array($genres) ? $genres : [$genres];
            $genreNames = [];
            
            foreach ($genreIds as $genreId) {
                if (isset($genreMap[$genreId])) {
                    $genreNames[] = $genreMap[$genreId]['name'] ?? $genreMap[$genreId];
                }
            }
            
            if (!empty($genreNames)) {
                $genre = implode(', ', $genreNames);
            }
        } else {
            // Genres might be direct strings or objects
            $genreNames = [];
            foreach ($genres as $genreItem) {
                if (is_string($genreItem)) {
                    $genreNames[] = $genreItem;
                } elseif (is_array($genreItem) && isset($genreItem['name'])) {
                    $genreNames[] = $genreItem['name'];
                } elseif (is_numeric($genreItem)) {
                    // It's a genre ID, but we don't have the mapping
                    // We'll skip it for now
                }
            }
            
            if (!empty($genreNames)) {
                $genre = implode(', ', $genreNames);
            }
        }
    }
}

// Extract description (overview)
$description = null;
if (isset($gameDetails['overview'])) {
    $description = trim($gameDetails['overview']);
    if (empty($description)) {
        $description = null;
    }
}

sendJsonResponse([
    'success' => true,
    'message' => 'Game metadata found',
    'genre' => $genre,
    'description' => $description
]);

