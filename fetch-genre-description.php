<?php
/**
 * Fetch genre and description for all games from TheGamesDB API
 * 
 * Run from command line: php fetch-genre-description.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "==========================================\n";
echo "Genre & Description Fetch Script\n";
echo "==========================================\n\n";

// Get all games without genre or description
$stmt = $pdo->query("
    SELECT id, title, platform, genre, description
    FROM games 
    WHERE genre IS NULL OR genre = '' OR description IS NULL OR description = ''
    ORDER BY title
");
$games = $stmt->fetchAll();

$totalGames = count($games);
echo "Found $totalGames games missing genre or description\n\n";

if ($totalGames === 0) {
    echo "All games already have genre and description!\n";
    exit;
}

$apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';

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

$successCount = 0;
$failCount = 0;
$updatedCount = 0;

foreach ($games as $index => $game) {
    $gameNum = $index + 1;
    echo "[$gameNum/$totalGames] Processing: {$game['title']} ({$game['platform']})... ";
    
    // Check what we need to fetch
    $needsGenre = empty($game['genre']);
    $needsDescription = empty($game['description']);
    
    // Clean title - try multiple variations
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $game['title']);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Try different title variations
    $titleVariations = [
        $cleanTitle,
        str_replace(':', '', $cleanTitle),
        preg_replace('/^0+(\d+):/', '$1', $cleanTitle),
        preg_replace('/^0+(\d+)\s/', '$1 ', $cleanTitle),
    ];
    
    $platformId = !empty($game['platform']) ? ($platformMap[$game['platform']] ?? null) : null;
    
    // Try each title variation
    $gameId = null;
    $foundGame = null;
    
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
            echo "API KEY ERROR\n";
            exit;
        }
        
        // Try both 'Games' and 'games'
        $gamesList = null;
        if (isset($data['data']['Games']) && !empty($data['data']['Games'])) {
            $gamesList = $data['data']['Games'];
        } elseif (isset($data['data']['games']) && !empty($data['data']['games'])) {
            $gamesList = $data['data']['games'];
        }
        
        if (!empty($gamesList)) {
            $foundGame = $gamesList[0];
            $gameId = $foundGame['id'];
            break;
        }
    }
    
    if (!$gameId || !$foundGame) {
        echo "NOT FOUND\n";
        $failCount++;
        continue;
    }
    
    // Get detailed game information
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
        echo "FAILED (details)\n";
        $failCount++;
        continue;
    }
    
    $detailsData = json_decode($detailsResponse, true);
    
    // Try both 'Games' (capital) and 'games' (lowercase)
    $gameDetails = null;
    if (isset($detailsData['data']['Games'][$gameId])) {
        $gameDetails = $detailsData['data']['Games'][$gameId];
    } elseif (isset($detailsData['data']['games'][$gameId])) {
        $gameDetails = $detailsData['data']['games'][$gameId];
    } elseif (isset($detailsData['data']['Games']) && is_array($detailsData['data']['Games'])) {
        foreach ($detailsData['data']['Games'] as $key => $g) {
            if (isset($g['id']) && $g['id'] == $gameId) {
                $gameDetails = $g;
                break;
            }
        }
    } elseif (isset($detailsData['data']['games']) && is_array($detailsData['data']['games'])) {
        foreach ($detailsData['data']['games'] as $key => $g) {
            if (isset($g['id']) && $g['id'] == $gameId) {
                $gameDetails = $g;
                break;
            }
        }
    }
    
    if (!$gameDetails) {
        echo "FAILED (no details)\n";
        $failCount++;
        continue;
    }
    
    // Extract genre
    $genre = null;
    if ($needsGenre && isset($gameDetails['genres'])) {
        $genres = $gameDetails['genres'];
        
        if (is_array($genres) && !empty($genres)) {
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
                $genreNames = [];
                foreach ($genres as $genreItem) {
                    if (is_string($genreItem)) {
                        $genreNames[] = $genreItem;
                    } elseif (is_array($genreItem) && isset($genreItem['name'])) {
                        $genreNames[] = $genreItem['name'];
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
    if ($needsDescription && isset($gameDetails['overview'])) {
        $description = trim($gameDetails['overview']);
        if (empty($description)) {
            $description = null;
        }
    }
    
    // Update database only if we got new data
    $updates = [];
    $values = [];
    
    if ($genre && $needsGenre) {
        $updates[] = 'genre = ?';
        $values[] = $genre;
    }
    
    if ($description && $needsDescription) {
        $updates[] = 'description = ?';
        $values[] = $description;
    }
    
    if (!empty($updates)) {
        $values[] = $game['id'];
        $updateStmt = $pdo->prepare("UPDATE games SET " . implode(', ', $updates) . " WHERE id = ?");
        $updateStmt->execute($values);
        
        $updatedCount++;
        $result = [];
        if ($genre) $result[] = "Genre: $genre";
        if ($description) $result[] = "Description: " . substr($description, 0, 50) . "...";
        echo "SUCCESS ✓ (" . implode(', ', $result) . ")\n";
        $successCount++;
    } else {
        echo "NO DATA\n";
        $failCount++;
    }
    
    // Delay to avoid rate limiting
    usleep(1000000); // 1 second delay between requests
}

echo "\n==========================================\n";
echo "Summary:\n";
echo "  Success: $successCount\n";
echo "  Updated: $updatedCount\n";
echo "  Failed:  $failCount\n";
echo "  Total:   $totalGames\n";
echo "==========================================\n";

