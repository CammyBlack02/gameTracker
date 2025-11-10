<?php
/**
 * Bulk download covers for all games using TheGamesDB API (with fallback to scraping)
 * Run from command line: php bulk-download-covers.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "==========================================\n";
echo "Bulk Cover Download Script\n";
echo "==========================================\n\n";

// Get all games without front cover images
$stmt = $pdo->query("
    SELECT id, title, platform 
    FROM games 
    WHERE front_cover_image IS NULL OR front_cover_image = ''
    ORDER BY title
");
$games = $stmt->fetchAll();

$totalGames = count($games);
echo "Found $totalGames games without cover images\n\n";

if ($totalGames === 0) {
    echo "All games already have cover images!\n";
    exit;
}

$successCount = 0;
$failCount = 0;

foreach ($games as $index => $game) {
    $gameNum = $index + 1;
    echo "[$gameNum/$totalGames] Processing: {$game['title']} ({$game['platform']})... ";
    
    // Try TheGamesDB API first (most reliable, best quality)
    $imageUrl = fetchImageFromTheGamesDB($game['title'], $game['platform']);
    
    // Fallback to DuckDuckGo if TheGamesDB fails
    if (!$imageUrl) {
        $searchQuery = urlencode($game['title'] . ' ' . $game['platform'] . ' game cover');
        $imageUrl = fetchImageFromDuckDuckGo($searchQuery);
    }
    
    // Fallback to Google Images if DuckDuckGo fails
    if (!$imageUrl) {
        $searchQuery = urlencode($game['title'] . ' ' . $game['platform'] . ' game cover');
        $imageUrl = fetchImageFromGoogle($searchQuery);
    }
    
    if (!$imageUrl) {
        echo "NOT FOUND\n";
        $failCount++;
        continue;
    }
    
    // Download the image
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: image/webp,image/apng,image/*,*/*;q=0.8',
        'Referer: https://www.google.com/'
    ]);
    
    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$imageData || strpos($contentType, 'image/') !== 0) {
        echo "FAILED (download error)\n";
        $failCount++;
        continue;
    }
    
    // Determine extension
    $extension = 'jpg';
    if (strpos($contentType, 'png') !== false) {
        $extension = 'png';
    } else if (strpos($contentType, 'gif') !== false) {
        $extension = 'gif';
    } else if (strpos($contentType, 'webp') !== false) {
        $extension = 'webp';
    }
    
    // Generate filename
    $filename = generateUniqueFilename($game['title'] . '_cover.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;
    
    // Save image
    if (!file_put_contents($targetPath, $imageData)) {
        echo "FAILED (save error)\n";
        $failCount++;
        continue;
    }
    
    // Update database
    $updateStmt = $pdo->prepare("UPDATE games SET front_cover_image = ? WHERE id = ?");
    $updateStmt->execute([$filename, $game['id']]);
    
    echo "SUCCESS ✓\n";
    $successCount++;
    
    // Delay to avoid rate limiting
    sleep(2); // 2 second delay between requests
}

echo "\n==========================================\n";
echo "Summary:\n";
echo "  Success: $successCount\n";
echo "  Failed:  $failCount\n";
echo "  Total:   $totalGames\n";
echo "==========================================\n";

/**
 * Fetch image URL from TheGamesDB API
 */
function fetchImageFromTheGamesDB($title, $platform) {
    $apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';
    
    // Clean title - try multiple variations
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Try different title variations
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
        'Sega Genesis' => 29, // Also support US naming
        'Dreamcast' => 23,
        'PS Vita' => 38,
    ];
    
    $platformId = !empty($platform) ? ($platformMap[$platform] ?? null) : null;
    
    // Try each title variation
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
            return null; // API key invalid
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
            
            // Get game images
            $imagesUrl = 'https://api.thegamesdb.net/v1/Games/Images?apikey=' . urlencode($apiKey) . '&games_id=' . $gameId;
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $imagesUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
            
            $imagesResponse = curl_exec($ch);
            $imagesHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($imagesHttpCode === 200 && $imagesResponse) {
                $imagesData = json_decode($imagesResponse, true);
                
                $images = null;
                if (isset($imagesData['data']['images'][$gameId])) {
                    $images = $imagesData['data']['images'][$gameId];
                } elseif (isset($imagesData['data']['Images'][$gameId])) {
                    $images = $imagesData['data']['Images'][$gameId];
                }
                
                if ($images) {
                    $boxart = null;
                    if (isset($images['boxart'])) {
                        $boxart = $images['boxart'];
                    } elseif (isset($images['Boxart'])) {
                        $boxart = $images['Boxart'];
                    }
                    
                    if ($boxart) {
                        $imagePath = null;
                        if (is_string($boxart)) {
                            $imagePath = $boxart;
                        } elseif (is_array($boxart)) {
                            if (isset($boxart['original'])) {
                                $imagePath = $boxart['original'];
                            } elseif (isset($boxart[0])) {
                                $imagePath = $boxart[0];
                            } else {
                                $imagePath = reset($boxart);
                            }
                        }
                        
                        if ($imagePath) {
                            if (substr($imagePath, 0, 1) !== '/') {
                                $imagePath = '/' . $imagePath;
                            }
                            return 'https://cdn.thegamesdb.net/images/original' . $imagePath;
                        }
                    }
                }
            }
            
            break; // Found game, but no image - don't try other variations
        }
    }
    
    return null;
}

/**
 * Fetch image URL from DuckDuckGo (no API key needed)
 */
function fetchImageFromDuckDuckGo($query) {
    // DuckDuckGo image search API (no key required)
    $apiUrl = 'https://duckduckgo.com/i.js?q=' . $query . '&o=json';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (isset($data['results'][0]['image'])) {
        return $data['results'][0]['image'];
    }
    
    return null;
}

/**
 * Fetch image URL from Google Images (scraping method)
 */
function fetchImageFromGoogle($query) {
    // Google Images search URL
    $url = 'https://www.google.com/search?q=' . $query . '&tbm=isch&safe=active';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Referer: https://www.google.com/'
    ]);
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return null;
    }
    
    // Google Images embeds image URLs in the page
    // Look for patterns like "ou":"https://..." in the HTML
    if (preg_match('/"ou":"([^"]+\.(jpg|jpeg|png|gif|webp))"/i', $html, $matches)) {
        return $matches[1];
    }
    
    // Alternative pattern
    if (preg_match('/\["(https?:\/\/[^"]+\.(jpg|jpeg|png|gif|webp))"/i', $html, $matches)) {
        return $matches[1];
    }
    
    // Try to find img tags with src
    if (preg_match('/<img[^>]+src=["\'](https?:\/\/[^"\']+\.(jpg|jpeg|png|gif|webp))["\']/i', $html, $matches)) {
        return $matches[1];
    }
    
    return null;
}

