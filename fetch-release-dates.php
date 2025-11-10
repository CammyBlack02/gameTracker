<?php
/**
 * Fetch release dates for all games (UK/PAL priority, then Europe)
 * Uses IGDB API (free tier available) or Wikipedia/MobyGames as fallback
 * 
 * Run from command line: php fetch-release-dates.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "==========================================\n";
echo "Release Date Fetch Script\n";
echo "==========================================\n\n";

// Ensure release_date column exists
try {
    $pdo->exec("ALTER TABLE games ADD COLUMN release_date DATE");
} catch (Exception $e) {
    // Column might already exist, that's fine
}

// Get all games without release dates
$stmt = $pdo->query("
    SELECT id, title, platform 
    FROM games 
    WHERE release_date IS NULL
    ORDER BY title
");
$games = $stmt->fetchAll();

$totalGames = count($games);
echo "Found $totalGames games without release dates\n\n";

if ($totalGames === 0) {
    echo "All games already have release dates!\n";
    exit;
}

$successCount = 0;
$failCount = 0;

// Platform name mapping for API searches
$platformMap = [
    'PlayStation 5' => 'PlayStation 5',
    'PlayStation 4' => 'PlayStation 4',
    'PlayStation 3' => 'PlayStation 3',
    'PlayStation 2' => 'PlayStation 2',
    'PlayStation' => 'PlayStation',
    'Xbox One' => 'Xbox One',
    'Xbox 360' => 'Xbox 360',
    'Xbox' => 'Xbox',
    'Nintendo Switch' => 'Nintendo Switch',
    'Wii U' => 'Wii U',
    'Wii' => 'Wii',
    'GameCube' => 'GameCube',
    'Nintendo 3DS' => 'Nintendo 3DS',
    'Nintendo DS' => 'Nintendo DS',
    'Game Boy Advance' => 'Game Boy Advance',
    'Game Boy Color' => 'Game Boy Color',
    'Game Boy' => 'Game Boy',
    'Nintendo 64' => 'Nintendo 64',
    'SNES' => 'Super Nintendo',
    'Mega Drive' => 'Sega Genesis', // UK/PAL name, but API uses US name
    'Sega Genesis' => 'Sega Genesis',
    'Dreamcast' => 'Dreamcast',
    'PC' => 'PC',
    'Steam' => 'PC'
];

// Test mode: set to true to test with first game only and show debug info
$testMode = isset($argv[1]) && $argv[1] === '--test';

if ($testMode) {
    echo "=== TEST MODE: Testing with first game only ===\n\n";
    $games = array_slice($games, 0, 1);
    $totalGames = 1;
}

foreach ($games as $index => $game) {
    $gameNum = $index + 1;
    echo "[$gameNum/$totalGames] Fetching: {$game['title']} ({$game['platform']})... ";
    
    if ($testMode) {
        echo "\n";
    }
    
    $releaseDate = fetchReleaseDate($game['title'], $game['platform'], $platformMap[$game['platform']] ?? $game['platform'], $testMode);
    
    if ($releaseDate) {
        // Update database (even in test mode so you can see it work)
        $updateStmt = $pdo->prepare("UPDATE games SET release_date = ? WHERE id = ?");
        $updateStmt->execute([$releaseDate, $game['id']]);
        
        echo "SUCCESS ✓ ($releaseDate)\n";
        $successCount++;
    } else {
        echo "NOT FOUND\n";
        $failCount++;
    }
    
    // Delay to avoid rate limiting
    if (!$testMode) {
        usleep(1000000); // 1 second delay between requests
    }
}

echo "\n==========================================\n";
echo "Summary:\n";
echo "  Success: $successCount\n";
echo "  Failed:  $failCount\n";
echo "  Total:   $totalGames\n";
echo "==========================================\n";

/**
 * Fetch release date for a game (UK/PAL priority, then Europe)
 */
function fetchReleaseDate($title, $platform, $platformName, $debug = false) {
    // Only use TheGamesDB API
    $date = null;
    
    if ($debug) {
        echo "  Trying TheGamesDB API...\n";
    }
    
    $date = fetchFromTheGamesDB($title, $platform, $debug);
    if ($date) {
        if ($debug) echo "  ✓ Found on TheGamesDB: $date\n";
        return $date;
    }
    if ($debug) echo "  ✗ TheGamesDB: No results\n";
    
    return null;
}

/**
 * Fetch release date from TheGamesDB API
 * TheGamesDB has excellent release date data with regional information
 */
function fetchFromTheGamesDB($title, $platform, $debug = false) {
    // TheGamesDB Public API Key (IP-based limit)
    $apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';
    
    // Clean title - try multiple variations
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
    
    // Platform mapping for TheGamesDB
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
        'GameCube' => 16,
        'Nintendo 64' => 19,
        'SNES' => 20,
        'Mega Drive' => 29,
        'Sega Genesis' => 29, // Also support US naming
        'Dreamcast' => 23,
        'PS Vita' => 38,
    ];
    
    $platformId = $platformMap[$platform] ?? null;
    
    // Try each title variation
    foreach ($titleVariations as $searchTitle) {
        // Search for the game
        $searchUrl = 'https://api.thegamesdb.net/v1/Games/ByGameName?apikey=' . urlencode($apiKey) . '&name=' . urlencode($searchTitle);
        if ($platformId) {
            $searchUrl .= '&platform=' . $platformId;
        }
        
        if ($debug) {
            echo "    Searching TheGamesDB for: $searchTitle" . ($platformId ? " (Platform ID: $platformId)" : "") . "\n";
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            if ($debug) echo "    TheGamesDB API error: HTTP $httpCode\n";
            continue; // Try next title variation
        }
        
        $data = json_decode($response, true);
        
        if ($debug) {
            echo "    TheGamesDB API response: HTTP $httpCode\n";
            echo "    Response keys: " . implode(', ', array_keys($data ?? [])) . "\n";
            if (isset($data['data'])) {
                echo "    Data keys: " . implode(', ', array_keys($data['data'] ?? [])) . "\n";
            }
            if (isset($data['data']['Games'])) {
                echo "    Found " . count($data['data']['Games']) . " results\n";
            } elseif (isset($data['data']['games'])) {
                echo "    Found " . count($data['data']['games']) . " results (lowercase 'games')\n";
            } else {
                echo "    No results in response\n";
                if (isset($data['code'])) {
                    echo "    Error code: " . $data['code'] . "\n";
                }
                if (isset($data['status'])) {
                    echo "    Status: " . $data['status'] . "\n";
                }
                if (isset($data['message'])) {
                    echo "    Message: " . $data['message'] . "\n";
                }
                // Show first 500 chars of response for debugging
                echo "    Response preview: " . substr($response, 0, 500) . "\n";
            }
        }
        
        // Check for API key error
        if (isset($data['code']) && $data['code'] == 401) {
            if ($debug) echo "    TheGamesDB API requires a valid API key\n";
            return null; // Don't try other variations if API key is invalid
        }
        
        // Try both 'Games' (capital) and 'games' (lowercase)
        $games = null;
        if (isset($data['data']['Games']) && !empty($data['data']['Games'])) {
            $games = $data['data']['Games'];
        } elseif (isset($data['data']['games']) && !empty($data['data']['games'])) {
            $games = $data['data']['games'];
        }
        
        if (!empty($games)) {
            // Found results! Break out of loop
            break;
        }
    }
    
    if (empty($games)) {
        return null;
    }
    
    // Get the first matching game
    $game = $games[0];
    $gameId = $game['id'];
    
    if ($debug) {
        echo "    Found game: " . ($game['game_title'] ?? 'Unknown') . " (ID: $gameId)\n";
    }
    
    // Get detailed game information including release dates
    $detailsUrl = 'https://api.thegamesdb.net/v1/Games/ByGameID?apikey=' . urlencode($apiKey) . '&id=' . $gameId . '&fields=players,publishers,genres,overview,last_updated,rating,platform,coop,youtube,os,processor,ram,hdd,video,sound,alternates,release_date';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $detailsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $detailsResponse = curl_exec($ch);
    $detailsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($detailsHttpCode !== 200 || !$detailsResponse) {
        if ($debug) echo "    Could not fetch game details\n";
        return null;
    }
    
    $detailsData = json_decode($detailsResponse, true);
    
    if ($debug) {
        echo "    Details response keys: " . implode(', ', array_keys($detailsData ?? [])) . "\n";
        if (isset($detailsData['data'])) {
            echo "    Details data keys: " . implode(', ', array_keys($detailsData['data'] ?? [])) . "\n";
        }
        // Show response preview
        echo "    Details response preview: " . substr($detailsResponse, 0, 1000) . "\n";
    }
    
    // Try both 'Games' (capital) and 'games' (lowercase)
    $gameDetails = null;
    if (isset($detailsData['data']['Games'][$gameId])) {
        $gameDetails = $detailsData['data']['Games'][$gameId];
    } elseif (isset($detailsData['data']['games'][$gameId])) {
        $gameDetails = $detailsData['data']['games'][$gameId];
    } elseif (isset($detailsData['data']['Games']) && is_array($detailsData['data']['Games'])) {
        // Maybe it's an array with numeric keys, find the game by ID
        foreach ($detailsData['data']['Games'] as $key => $game) {
            if (isset($game['id']) && $game['id'] == $gameId) {
                $gameDetails = $game;
                break;
            }
        }
    } elseif (isset($detailsData['data']['games']) && is_array($detailsData['data']['games'])) {
        // Maybe it's an array with numeric keys, find the game by ID
        foreach ($detailsData['data']['games'] as $key => $game) {
            if (isset($game['id']) && $game['id'] == $gameId) {
                $gameDetails = $game;
                break;
            }
        }
    }
    
    if (empty($gameDetails)) {
        if ($debug) {
            echo "    No game details found for ID: $gameId\n";
            if (isset($detailsData['data']['Games'])) {
                if (is_array($detailsData['data']['Games'])) {
                    echo "    Available game IDs (Games): " . implode(', ', array_keys($detailsData['data']['Games'])) . "\n";
                    // Show first game structure if it's an array
                    if (!empty($detailsData['data']['Games'])) {
                        $firstGame = reset($detailsData['data']['Games']);
                        echo "    First game structure: " . json_encode(array_keys($firstGame ?? [])) . "\n";
                    }
                }
            }
            if (isset($detailsData['data']['games'])) {
                if (is_array($detailsData['data']['games'])) {
                    echo "    Available game IDs (games): " . implode(', ', array_keys($detailsData['data']['games'])) . "\n";
                    // Show first game structure if it's an array
                    if (!empty($detailsData['data']['games'])) {
                        $firstGame = reset($detailsData['data']['games']);
                        echo "    First game structure: " . json_encode(array_keys($firstGame ?? [])) . "\n";
                        if (isset($firstGame['id'])) {
                            echo "    First game ID: " . $firstGame['id'] . "\n";
                        }
                    }
                }
            }
        }
        return null;
    }
    
    // TheGamesDB returns release dates in various formats
    // Can be a simple string like "2002-01-01" or an object with regional dates
    if (isset($gameDetails['release_date'])) {
        $releaseDates = $gameDetails['release_date'];
        
        // Helper function to parse and return a date string
        $parseDate = function($dateStr, $region = 'default') use ($debug) {
            if (empty($dateStr) || !is_string($dateStr)) {
                return null;
            }
            
            // Parse date - TheGamesDB typically returns YYYY-MM-DD or YYYY-MM or YYYY
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $matches)) {
                // Full date
                if ($debug) echo "    Found $region release date: $dateStr\n";
                return $dateStr;
            } elseif (preg_match('/^(\d{4})-(\d{2})$/', $dateStr, $matches)) {
                // Year-Month only, use first day of month
                if ($debug) echo "    Found $region release date: $dateStr (using 1st of month)\n";
                return $dateStr . '-01';
            } elseif (preg_match('/^(\d{4})$/', $dateStr, $matches)) {
                // Year only, use January 1st
                if ($debug) echo "    Found $region release date: $dateStr (using Jan 1st)\n";
                return $dateStr . '-01-01';
            }
            return null;
        };
        
        // Check if release_date is a simple string (not regional object)
        if (is_string($releaseDates)) {
            $result = $parseDate($releaseDates, 'default');
            if ($result !== null) {
                return $result;
            }
        } elseif (is_array($releaseDates)) {
            // It's an array/object with regional dates
            // Priority: UK > PAL > EU > Europe (prefer these if available)
            $priorityRegions = ['UK', 'PAL', 'EU', 'Europe'];
            
            foreach ($priorityRegions as $region) {
                if (isset($releaseDates[$region]) && !empty($releaseDates[$region])) {
                    $result = $parseDate($releaseDates[$region], $region);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
            
            // If no priority region found, accept ANY available date (Worldwide, NA, US, JP, etc.)
            foreach ($releaseDates as $region => $dateStr) {
                if (is_string($dateStr)) {
                    $result = $parseDate($dateStr, $region);
                    if ($result !== null) {
                        return $result;
                    }
                }
            }
        }
    }
    
    return null;
}

/**
 * Fetch release date from RAWG API (free tier available)
 * Get a free API key from https://rawg.io/apidocs
 */
function fetchFromRAWG($title, $platform, $debug = false) {
    // RAWG API key
    $apiKey = '7f86c90515244898b243a70e1afb75cd';
    
    // Clean title - try multiple variations
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Try different search queries
    $searchQueries = [
        $cleanTitle,
        str_replace(':', '', $cleanTitle), // Remove colons (e.g., "007: Agent" -> "007 Agent")
        preg_replace('/^0+(\d+):/', '$1', $cleanTitle), // "007: Agent" -> "7: Agent"
    ];
    
    // Platform mapping for RAWG
    $platformMap = [
        'PlayStation 5' => 'playstation-5',
        'PlayStation 4' => 'playstation-4',
        'PlayStation 3' => 'playstation-3',
        'PlayStation 2' => 'playstation-2',
        'PlayStation' => 'playstation',
        'Xbox One' => 'xbox-one',
        'Xbox 360' => 'xbox-360',
        'Xbox' => 'xbox-old',
        'Nintendo Switch' => 'switch',
        'Wii U' => 'wii-u',
        'Wii' => 'wii',
        'GameCube' => 'gamecube',
        'Nintendo 3DS' => '3ds',
        'Nintendo DS' => 'ds',
        'PC' => 'pc'
    ];
    
    $rawgPlatform = $platformMap[$platform] ?? null;
    
    // Try each search query
    foreach ($searchQueries as $searchQuery) {
        $searchUrl = 'https://api.rawg.io/api/games?search=' . urlencode($searchQuery);
        if ($rawgPlatform) {
            $searchUrl .= '&platforms=' . $rawgPlatform;
        }
        if ($apiKey) {
            $searchUrl .= '&key=' . urlencode($apiKey);
        }
        
        if ($debug) {
            echo "    Searching RAWG for: $searchQuery\n";
        }
    
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            continue;
        }
        
        $data = json_decode($response, true);
        
        if ($debug) {
            echo "    RAWG API response: HTTP $httpCode\n";
            if (isset($data['results'])) {
                echo "    Found " . count($data['results']) . " results\n";
            } else {
                echo "    No results in response\n";
                if (isset($data['error'])) {
                    echo "    Error: " . $data['error'] . "\n";
                }
            }
        }
        
        if (empty($data['results'])) {
            continue; // Try next search query
        }
        
        // Get the first result
        $game = $data['results'][0];
        
        if ($debug) {
            echo "    Found game: " . ($game['name'] ?? 'Unknown') . "\n";
        }
        
        // RAWG returns release dates, but we need UK/PAL specifically
        // The API returns a released date, but we'd need to check platforms for region-specific dates
        // For now, use the main released date
        if (isset($game['released']) && $game['released']) {
            if ($debug) echo "    Found release date: " . $game['released'] . "\n";
            return $game['released']; // Format: YYYY-MM-DD
        }
    }
    
    return null;
}

/**
 * Fetch release date from GameFAQs
 */
function fetchFromGameFAQs($title, $platform) {
    // Clean title
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    $searchQuery = urlencode($cleanTitle);
    $url = "https://www.gamefaqs.com/search?game=$searchQuery";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return null;
    }
    
    // GameFAQs shows release dates in search results
    // Look for UK/PAL dates
    if (preg_match('/UK[^<]*:?\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $html, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        return "$year-$month-$day";
    }
    
    if (preg_match('/PAL[^<]*:?\s*(\d{1,2})\/(\d{1,2})\/(\d{4})/i', $html, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        return "$year-$month-$day";
    }
    
    return null;
}

/**
 * Fetch release date from Wikipedia using their free API
 * No authentication required!
 */
function fetchFromWikipedia($title, $platform, $debug = false) {
    // Clean title - remove special edition info
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Try multiple search queries
    $searchQueries = [
        $cleanTitle . ' ' . $platform . ' video game',
        $cleanTitle . ' (' . $platform . ')',
        $cleanTitle . ' ' . $platform
    ];
    
    foreach ($searchQueries as $searchQuery) {
        // Wikipedia API - search for the page
        $searchUrl = 'https://en.wikipedia.org/w/api.php?action=query&format=json&list=search&srsearch=' . urlencode($searchQuery) . '&srlimit=5';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $searchUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $searchResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$searchResponse) {
            continue;
        }
        
        $searchData = json_decode($searchResponse, true);
        
        if ($debug) {
            echo "    Wikipedia search response: " . (isset($searchData['query']['search']) ? count($searchData['query']['search']) . " results" : "no results") . "\n";
        }
        
        if (empty($searchData['query']['search'])) {
            continue;
        }
        
        // Try each search result
        foreach ($searchData['query']['search'] as $result) {
            $pageTitle = $result['title'];
            
            if ($debug) {
                echo "    Checking page: $pageTitle\n";
            }
            
            // Use Wikipedia API to get wikitext (source markup) - much easier to parse!
            $apiUrl = 'https://en.wikipedia.org/w/api.php?action=query&format=json&titles=' . urlencode($pageTitle) . '&prop=revisions&rvprop=content&rvsection=0';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                continue;
            }
            
            $data = json_decode($response, true);
            $pages = $data['query']['pages'] ?? [];
            
            if (empty($pages)) {
                continue;
            }
            
            $page = reset($pages);
            $wikitext = $page['revisions'][0]['*'] ?? '';
            
            if (empty($wikitext)) {
                continue;
            }
            
            if ($debug) {
                // Show a snippet of the wikitext
                $snippet = substr($wikitext, 0, 500);
                echo "      Wikitext snippet: " . substr($snippet, 0, 200) . "...\n";
            }
            
            // Extract infobox from wikitext (starts with {{Infobox)
            // Wikitext format: {{Infobox video game|...|release = ...|...}}
            $infobox = null;
            
            // Try to match the full infobox - it can span multiple lines
            // Pattern: {{Infobox ... }} - need to match nested braces correctly
            if (preg_match('/\{\{Infobox[^}]*video game[^}]*\s*\n(.*?)\}\}\}/is', $wikitext, $infoboxMatch)) {
                $infobox = $infoboxMatch[0]; // Get the whole infobox
            } elseif (preg_match('/\{\{Infobox[^}]*\s*\n(.*?)\}\}\}/is', $wikitext, $infoboxMatch)) {
                $infobox = $infoboxMatch[0];
            }
            
            // If still no infobox, try simpler pattern - just get everything between {{Infobox and }}
            if (!$infobox) {
                // Find the start of infobox
                $startPos = strpos($wikitext, '{{Infobox');
                if ($startPos !== false) {
                    // Find matching closing braces - count braces
                    $braceCount = 0;
                    $pos = $startPos;
                    $infoboxStart = $pos;
                    while ($pos < strlen($wikitext)) {
                        if (substr($wikitext, $pos, 2) === '{{') {
                            $braceCount++;
                            $pos += 2;
                        } elseif (substr($wikitext, $pos, 2) === '}}') {
                            $braceCount--;
                            $pos += 2;
                            if ($braceCount === 0) {
                                $infobox = substr($wikitext, $infoboxStart, $pos - $infoboxStart);
                                break;
                            }
                        } else {
                            $pos++;
                        }
                    }
                }
            }
            
            // If no infobox, use the whole wikitext (but focus on first section)
            if (!$infobox) {
                // Get first 3000 characters (usually contains infobox)
                $infobox = substr($wikitext, 0, 3000);
            }
            
            if ($debug) {
                echo "      Searching wikitext for release dates...\n";
                // Show a snippet of the infobox around "release" or "EU"
                if (stripos($infobox, 'release') !== false) {
                    $pos = stripos($infobox, 'release');
                    $snippet = substr($infobox, max(0, $pos - 100), 400);
                    echo "      Infobox snippet around 'release': " . substr($snippet, 0, 300) . "...\n";
                } elseif (stripos($infobox, 'EU') !== false) {
                    $pos = stripos($infobox, 'EU');
                    $snippet = substr($infobox, max(0, $pos - 100), 400);
                    echo "      Infobox snippet around 'EU': " . substr($snippet, 0, 300) . "...\n";
                } else {
                    echo "      Infobox snippet (first 500 chars): " . substr($infobox, 0, 500) . "...\n";
                }
            }
            
            // Normalize platform name for matching
            $platformLower = strtolower($platform);
            $platformVariants = [
                'playstation 2' => ['playstation 2', 'ps2', 'ps 2'],
                'playstation 3' => ['playstation 3', 'ps3', 'ps 3'],
                'playstation 4' => ['playstation 4', 'ps4', 'ps 4'],
                'playstation 5' => ['playstation 5', 'ps5', 'ps 5'],
                'xbox' => ['xbox'],
                'xbox 360' => ['xbox 360', 'xbox360'],
                'xbox one' => ['xbox one', 'xboxone'],
                'nintendo switch' => ['nintendo switch', 'switch'],
                'wii u' => ['wii u', 'wiiu'],
                'wii' => ['wii'],
                'gamecube' => ['gamecube', 'game cube', 'gc'],
            ];
            
            $platformMatches = $platformVariants[$platformLower] ?? [$platformLower, $platform];
            
            // Parse wikitext for release dates
            // Wikitext format: |release = PlayStation 2\nNA: 13 November 2001\nEU: 30 November 2001
            // or: |released = PlayStation 2\nNA: 13 November 2001\nEU: 30 November 2001
            
            // Look for platform-specific EU date in release field
            foreach ($platformMatches as $platformMatch) {
                // Pattern: Platform name, then newline, then EU: date
                // Wikitext uses | for field separators and \n for newlines
                $pattern = '/' . preg_quote($platformMatch, '/') . '[^|]*\n[^|]*(?:NA[^|]*\n[^|]*)?(?:EU|UK|PAL|Europe)[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/is';
                if (preg_match($pattern, $infobox, $matches)) {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $month = date('m', strtotime($matches[2]));
                    $year = $matches[3];
                    if ($debug) echo "      Found platform-specific EU date: $year-$month-$day\n";
                    return "$year-$month-$day";
                }
            }
            
            // Look for EU: date in |release = field
            // Pattern: |release = ... EU: 30 November 2001
            if (preg_match('/\|\s*release\s*=\s*[^|]*(?:EU|UK|PAL|Europe)[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/is', $infobox, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = date('m', strtotime($matches[2]));
                $year = $matches[3];
                if ($debug) echo "      Found EU date in release field: $year-$month-$day\n";
                return "$year-$month-$day";
            }
            
            // Try |released = field
            if (preg_match('/\|\s*released\s*=\s*[^|]*(?:EU|UK|PAL|Europe)[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/is', $infobox, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = date('m', strtotime($matches[2]));
                $year = $matches[3];
                if ($debug) echo "      Found EU date in released field: $year-$month-$day\n";
                return "$year-$month-$day";
            }
            
            // Try UK: date
            if (preg_match('/UK[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $infobox, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = date('m', strtotime($matches[2]));
                $year = $matches[3];
                if ($debug) echo "      Found UK date: $year-$month-$day\n";
                return "$year-$month-$day";
            }
            
            // Try EU: date anywhere in wikitext (last resort)
            if (preg_match('/EU[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $infobox, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = date('m', strtotime($matches[2]));
                $year = $matches[3];
                if ($debug) echo "      Found EU date anywhere: $year-$month-$day\n";
                return "$year-$month-$day";
            }
            
            // Try PAL/Europe
            if (preg_match('/(?:PAL|Europe)[^|]*:?\s*(\d{1,2})\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{4})/i', $infobox, $matches)) {
                $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                $month = date('m', strtotime($matches[2]));
                $year = $matches[3];
                if ($debug) echo "      Found PAL date: $year-$month-$day\n";
                return "$year-$month-$day";
            }
        }
    }
    
    return null;
}

/**
 * Fetch release date from MobyGames
 */
function fetchFromMobyGames($title, $platform) {
    // Clean title
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    $searchQuery = urlencode($cleanTitle);
    $url = "https://www.mobygames.com/search/quick?q=$searchQuery";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return null;
    }
    
    // Try to find game page link and fetch it
    if (preg_match('/<a[^>]+href="(\/game\/[^"]+)"[^>]*>' . preg_quote($cleanTitle, '/') . '/i', $html, $matches)) {
        $gameUrl = 'https://www.mobygames.com' . $matches[1];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gameUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $gameHtml = curl_exec($ch);
        curl_close($ch);
        
        if ($gameHtml) {
            // Look for UK release date
            if (preg_match('/United Kingdom[^<]*(\d{4})-(\d{2})-(\d{2})/i', $gameHtml, $matches)) {
                return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            }
            
            // Look for PAL release date
            if (preg_match('/PAL[^<]*(\d{4})-(\d{2})-(\d{2})/i', $gameHtml, $matches)) {
                return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            }
            
            // Look for Europe release date
            if (preg_match('/Europe[^<]*(\d{4})-(\d{2})-(\d{2})/i', $gameHtml, $matches)) {
                return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
            }
        }
    }
    
    return null;
}

/**
 * Fetch release date from IGDB API (requires API key)
 * Uncomment and configure if you have IGDB credentials
 */
/*
function fetchFromIGDB($title, $platform) {
    // You need to get a free API key from https://api.igdb.com/
    $clientId = 'YOUR_CLIENT_ID';
    $clientSecret = 'YOUR_CLIENT_SECRET';
    
    // Get access token
    $tokenUrl = 'https://id.twitch.tv/oauth2/token?client_id=' . $clientId . '&client_secret=' . $clientSecret . '&grant_type=client_credentials';
    $tokenResponse = file_get_contents($tokenUrl);
    $tokenData = json_decode($tokenResponse, true);
    
    if (!isset($tokenData['access_token'])) {
        return null;
    }
    
    $accessToken = $tokenData['access_token'];
    
    // Search for game
    $searchUrl = 'https://api.igdb.com/v4/games';
    $searchData = 'search "' . addslashes($title) . '"; fields name,release_dates; limit 1;';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $searchData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Client-ID: ' . $clientId,
        'Authorization: Bearer ' . $accessToken
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $games = json_decode($response, true);
    
    if (empty($games)) {
        return null;
    }
    
    // Get release dates for UK (region 2) or Europe (region 1)
    // This would require additional API calls to get release date details
    // IGDB API is more complex but very accurate
    
    return null;
}
*/

