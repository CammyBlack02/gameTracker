<?php
/**
 * Bulk fetch cover image URLs for all games using TheGamesDB API (with fallback to scraping)
 * Stores URLs directly in database instead of downloading images locally
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
    
    // TheGamesDB API is disabled (exhausted) - using fallbacks only
    $imageUrl = null;
    
    // Try The Covers Project first (high quality, often has both front/back)
    $imageUrl = fetchImageFromCoversProject($game['title'], $game['platform']);
    
    // Fallback to DuckDuckGo if Covers Project fails
    if (!$imageUrl) {
        $searchQuery = urlencode($game['title'] . ' ' . $game['platform'] . ' game cover');
        $imageUrl = fetchImageFromDuckDuckGo($searchQuery);
    }
    
    // Fallback to Google Images if DuckDuckGo fails
    if (!$imageUrl) {
        $imageUrl = fetchImageFromGoogle($searchQuery);
    }
    
    if (!$imageUrl) {
        echo "NOT FOUND\n";
        $failCount++;
        continue;
    }
    
    // Store the URL directly in the database (no download needed)
    // Update database with the image URL
    $updateStmt = $pdo->prepare("UPDATE games SET front_cover_image = ? WHERE id = ?");
    $updateStmt->execute([$imageUrl, $game['id']]);
    
    echo "SUCCESS ✓\n";
    $successCount++;
    
    // Minimal delay - fallback methods are less strict about rate limiting
    usleep(100000); // 0.1 second delay between requests (much faster)
}

echo "\n==========================================\n";
echo "Summary:\n";
echo "  Success: $successCount\n";
echo "  Failed:  $failCount\n";
echo "  Total:   $totalGames\n";
echo "==========================================\n";

/**
 * Fetch image URL from TheGamesDB API
 * @param bool $fastMode - If true, tries fewer variations for speed
 */
function fetchImageFromTheGamesDB($title, $platform, $fastMode = false) {
    $apiKey = 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3';
    
    // Clean title - try multiple variations
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Try different title variations - prioritize most likely matches first
    $titleVariations = [];
    
    if ($fastMode) {
        // Fast mode: only try the most likely variations
        $titleVariations = [
            $cleanTitle, // Original title first
            str_replace(':', ' ', $cleanTitle), // "007: Nightfire" -> "007 Nightfire"
        ];
        
        // For titles with colons, try the part after colon (often the actual game name)
        if (strpos($cleanTitle, ':') !== false) {
            $parts = explode(':', $cleanTitle, 2);
            if (count($parts) == 2 && trim($parts[1])) {
                $titleVariations[] = trim($parts[1]); // "Nightfire"
            }
        }
    } else {
        // Full mode: try all variations
        $titleVariations = [
            $cleanTitle,
            str_replace(':', ' ', $cleanTitle), // Replace colon with space: "007: Nightfire" -> "007 Nightfire"
            str_replace(':', '', $cleanTitle), // Remove colons: "007: Nightfire" -> "007 Nightfire"
            str_replace('-', ' ', $cleanTitle), // Replace hyphens with spaces
            preg_replace('/\s+/', ' ', $cleanTitle), // Normalize whitespace
        ];
        
        // For titles starting with numbers like "007", try without leading zeros
        if (preg_match('/^0+(\d+)/', $cleanTitle, $matches)) {
            $titleVariations[] = preg_replace('/^0+(\d+):/', $matches[1] . ':', $cleanTitle);
            $titleVariations[] = preg_replace('/^0+(\d+)\s/', $matches[1] . ' ', $cleanTitle);
        }
        
        // For titles with colons, try extracting the part after the colon
        if (strpos($cleanTitle, ':') !== false) {
            $parts = explode(':', $cleanTitle, 2);
            if (count($parts) == 2 && trim($parts[1])) {
                $titleVariations[] = trim($parts[1]); // "Nightfire"
                $titleVariations[] = trim($parts[0]) . ' ' . trim($parts[1]); // "007 Nightfire"
            }
        }
    }
    
    // Remove duplicates and empty strings
    $titleVariations = array_filter(array_unique($titleVariations));
    
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
    
    // Strategy: Try most likely matches first, with platform, then without
    // This reduces API calls and speeds up the search
    
    // First, try with platform filter using first few title variations (most likely to match)
    if ($platformId) {
        $variationsToTry = $fastMode ? array_slice($titleVariations, 0, 2) : array_slice($titleVariations, 0, 3);
        foreach ($variationsToTry as $searchTitle) {
            $result = tryTheGamesDBSearch($apiKey, $searchTitle, $platformId);
            if ($result) {
                return $result;
            }
            // Small delay between variations to avoid rate limiting
            usleep(200000); // 0.2 seconds
        }
    }
    
    // If platform search failed, try without platform filter (broader search)
    // But only try the first variation to save time
    $firstVariation = reset($titleVariations);
    if ($firstVariation) {
        usleep(200000); // Small delay
        $result = tryTheGamesDBSearch($apiKey, $firstVariation, null);
        if ($result) {
            return $result;
        }
    }
    
    // If still not found and not in fast mode, try one more variation without platform
    if (!$fastMode && count($titleVariations) > 1) {
        $secondVariation = $titleVariations[1] ?? null;
        if ($secondVariation) {
            usleep(200000); // Small delay
            $result = tryTheGamesDBSearch($apiKey, $secondVariation, null);
            if ($result) {
                return $result;
            }
        }
    }
    
    return null;
}

/**
 * Helper function to search TheGamesDB
 */
function tryTheGamesDBSearch($apiKey, $searchTitle, $platformId) {
    $searchUrl = 'https://api.thegamesdb.net/v1/Games/ByGameName?apikey=' . urlencode($apiKey) . '&name=' . urlencode($searchTitle);
    
    if ($platformId) {
        $searchUrl .= '&platform=' . $platformId;
    }
        
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Increased timeout
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$response) {
        // If we got rate limited (429) or server error (5xx), wait a bit
        if ($httpCode == 429 || ($httpCode >= 500 && $httpCode < 600)) {
            usleep(2000000); // Wait 2 seconds before retrying
            return null;
        }
        return null;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        return null; // Invalid JSON
    }
    
    // Check for API key issues
    if (isset($data['code'])) {
        if ($data['code'] == 401 || $data['code'] == 403) {
            // API key invalid or exhausted
            if (isset($data['remaining_monthly_allowance']) && $data['remaining_monthly_allowance'] == 0) {
                echo "\n\nERROR: API key monthly limit exhausted! Remaining: 0\n";
                echo "The API key has reached its monthly request limit.\n";
                echo "You can either:\n";
                echo "  1. Wait for the limit to reset (check allowance_refresh_timer)\n";
                echo "  2. Get a new API key from https://thegamesdb.net/\n";
                echo "  3. Continue with fallback methods (DuckDuckGo/Google) only\n\n";
                // Don't exit, just skip TheGamesDB and use fallbacks
                return null;
            }
            return null; // API key invalid
        }
        
        // Check for rate limiting in response
        if ($data['code'] == 429 || $data['code'] == 503) {
            usleep(2000000); // Wait 2 seconds
            return null;
        }
    }
    
    // Try both 'Games' and 'games'
    $games = null;
    if (isset($data['data']['Games']) && !empty($data['data']['Games'])) {
        $games = $data['data']['Games'];
    } elseif (isset($data['data']['games']) && !empty($data['data']['games'])) {
        $games = $data['data']['games'];
    }
    
    if (!empty($games)) {
        // Try to find the best match - look for exact title match first, then use first result
        $bestMatch = null;
        $searchTitleLower = strtolower($searchTitle);
        
        foreach ($games as $game) {
            $gameTitle = isset($game['game_title']) ? strtolower($game['game_title']) : '';
            if ($gameTitle === $searchTitleLower || 
                strpos($gameTitle, $searchTitleLower) !== false ||
                strpos($searchTitleLower, $gameTitle) !== false) {
                $bestMatch = $game;
                break;
            }
        }
        
        // If no exact match found, use first result
        $game = $bestMatch ?: $games[0];
        
        // Get game ID - handle different field names
        $gameId = null;
        if (isset($game['id'])) {
            $gameId = $game['id'];
        } elseif (isset($game['ID'])) {
            $gameId = $game['ID'];
        }
        
        if (!$gameId) {
            return null; // No valid game ID found
        }
        
        // Get game images
        $imagesUrl = 'https://api.thegamesdb.net/v1/Games/Images?apikey=' . urlencode($apiKey) . '&games_id=' . $gameId;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $imagesUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Increased timeout
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
        
        $imagesResponse = curl_exec($ch);
        $imagesHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($imagesHttpCode === 200 && $imagesResponse) {
            $imagesData = json_decode($imagesResponse, true);
            
            // TheGamesDB v1 API returns images in a new format:
            // data.images[gameId] is an array of image objects with type, side, filename
            $images = null;
            if (isset($imagesData['data']['images'][$gameId])) {
                $images = $imagesData['data']['images'][$gameId];
            } elseif (isset($imagesData['data']['Images'][$gameId])) {
                $images = $imagesData['data']['Images'][$gameId];
            }
            
            if ($images && is_array($images)) {
                // Look for front boxart in the new format
                $frontBoxart = null;
                foreach ($images as $image) {
                    if (isset($image['type']) && $image['type'] === 'boxart' && 
                        isset($image['side']) && $image['side'] === 'front') {
                        $frontBoxart = $image;
                        break;
                    }
                }
                
                if ($frontBoxart && isset($frontBoxart['filename'])) {
                    $imagePath = $frontBoxart['filename'];
                    if (substr($imagePath, 0, 1) !== '/') {
                        $imagePath = '/' . $imagePath;
                    }
                    return 'https://cdn.thegamesdb.net/images/original' . $imagePath;
                }
                
                // Fallback: try old format (for backward compatibility)
                if (isset($images['boxart'])) {
                    $boxart = $images['boxart'];
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
        
        // Found game but no image - return null to try other variations
        return null;
    }
    
    return null;
}

/**
 * Fetch image URL from The Covers Project
 * High quality covers, often includes both front and back in one image
 */
function fetchImageFromCoversProject($title, $platform) {
    // The Covers Project uses /view.php?searchstring= for search
    // Clean title for better matching
    $cleanTitle = preg_replace('/[^a-z0-9\s]/i', ' ', $title);
    $cleanTitle = preg_replace('/\s+/', ' ', trim($cleanTitle));
    $searchQuery = urlencode($cleanTitle);
    $searchUrl = 'https://www.thecoverproject.net/view.php?searchstring=' . $searchQuery;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$html) {
        return null;
    }
    
    // Debug: make sure we're getting different results for different games
    // If search returns no results or same results, skip
    
    // Look for cover_id links in search results: view.php?cover_id=XXXX
    // We need to match the title more carefully - extract all covers and find the best match
    $titleLower = strtolower($cleanTitle);
    $titleWords = array_filter(explode(' ', $titleLower));
    
    $bestMatch = null;
    $bestScore = 0;
    $allCovers = [];
    
    // Find all cover links in the search results with their titles
    // Pattern: <a href="view.php?cover_id=XXXX" title="Game Title">Game Title</a>
    if (preg_match_all('/view\.php\?cover_id=(\d+)[^>]*(?:title=["\']([^"\']+)["\']|>([^<]+)<)/i', $html, $allMatches, PREG_SET_ORDER)) {
        foreach ($allMatches as $match) {
            $coverId = $match[1];
            // Try title attribute first, then link text
            $coverTitle = '';
            if (isset($match[2]) && !empty(trim($match[2]))) {
                $coverTitle = trim($match[2]);
            } elseif (isset($match[3]) && !empty(trim($match[3]))) {
                $coverTitle = trim(strip_tags($match[3]));
            }
            
            if (empty($coverTitle)) {
                continue; // Skip if no title found
            }
            
            $coverTitleLower = strtolower($coverTitle);
            
            // Skip if we've already seen this cover_id
            if (in_array($coverId, $allCovers)) {
                continue;
            }
            $allCovers[] = $coverId;
            
            // Calculate match score
            $score = 0;
            $matchedWords = 0;
            foreach ($titleWords as $word) {
                if (strlen($word) > 2 && strpos($coverTitleLower, $word) !== false) {
                    $score += strlen($word) * 2; // Longer words = better match
                    $matchedWords++;
                }
            }
            
            // Require at least 1 word to match (more lenient)
            if ($matchedWords < 1) {
                // Still allow if title is very short (might be a single word game)
                if (strlen($titleLower) > 5) {
                    continue; // Skip if no words match for longer titles
                }
            }
            
            // Bonus for exact title match
            if ($coverTitleLower === $titleLower) {
                $score += 200;
            }
            
            // Bonus for title containing the search title
            if (strpos($coverTitleLower, $titleLower) !== false) {
                $score += 100;
            }
            
            // Bonus for partial match (first few words)
            $titleFirstWords = implode(' ', array_slice($titleWords, 0, 3));
            if (strpos($coverTitleLower, $titleFirstWords) !== false) {
                $score += 30;
            }
            
            // Penalty if title is very different in length (but not too harsh)
            $lengthDiff = abs(strlen($coverTitleLower) - strlen($titleLower));
            if ($lengthDiff > strlen($titleLower) * 0.8) {
                $score -= 10; // Smaller penalty
            }
            
            if ($score > $bestScore && $score > 2) { // Lower minimum score threshold
                $bestScore = $score;
                $bestMatch = $coverId;
            }
        }
    }
    
    // Only use fallback if we have a reasonable match
    // Lowered threshold to 2 to allow more matches
    if (!$bestMatch || $bestScore < 2) {
        return null; // Don't use random covers
    }
    
    $coverId = $bestMatch;
    
    // Fetch the cover detail page
    $coverUrl = 'https://www.thecoverproject.net/view.php?cover_id=' . $coverId;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $coverUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $coverPage = curl_exec($ch);
    curl_close($ch);
    
    if (!$coverPage) {
        return null;
    }
    
    // Look for the full-size cover image
    // The Covers Project typically has images in /uploads/ directory
    // IMPORTANT: Remove _thumb to get full size, and make sure we get the right image
    
    // First, try to find full-size image (not thumbnail)
    if (preg_match_all('/<img[^>]+src=["\']([^"\']*\/uploads\/[^"\']+\.(jpg|jpeg|png))["\']/i', $coverPage, $imgMatches, PREG_SET_ORDER)) {
        foreach ($imgMatches as $imgMatch) {
            $imageUrl = $imgMatch[1];
            
            // Skip thumbnails
            if (strpos($imageUrl, '_thumb') !== false) {
                continue;
            }
            
            // Make absolute URL if relative
            if (strpos($imageUrl, 'http') !== 0) {
                $imageUrl = 'https://www.thecoverproject.net' . $imageUrl;
            }
            
            // Verify it's not a thumbnail by checking the URL
            if (strpos($imageUrl, '_thumb') === false) {
                return $imageUrl;
            }
        }
    }
    
    // If only thumbnails found, remove _thumb to get full size
    if (preg_match('/<img[^>]+src=["\']([^"\']*\/uploads\/[^"\']+_thumb\.(jpg|jpeg|png))["\']/i', $coverPage, $imgMatches)) {
        $thumbUrl = $imgMatches[1];
        // Remove _thumb from the filename
        $imageUrl = str_replace('_thumb', '', $thumbUrl);
        
        // Make absolute URL if relative
        if (strpos($imageUrl, 'http') !== 0) {
            $imageUrl = 'https://www.thecoverproject.net' . $imageUrl;
        }
        
        return $imageUrl;
    }
    
    return null;
}

/**
 * Fetch image URL from DuckDuckGo (no API key needed)
 * Optimized for speed
 */
function fetchImageFromDuckDuckGo($query) {
    // DuckDuckGo image search API (no key required)
    $apiUrl = 'https://duckduckgo.com/i.js?q=' . $query . '&o=json';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Reduced timeout for speed
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
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
 * Optimized for speed
 */
function fetchImageFromGoogle($query) {
    // Google Images search URL
    $url = 'https://www.google.com/search?q=' . $query . '&tbm=isch&safe=active';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Reduced timeout for speed
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
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
    // Look for patterns like "ou":"https://..." in the HTML (most common pattern first)
    if (preg_match('/"ou":"([^"]+\.(jpg|jpeg|png|gif|webp))"/i', $html, $matches)) {
        return $matches[1];
    }
    
    // Alternative pattern
    if (preg_match('/\["(https?:\/\/[^"]+\.(jpg|jpeg|png|gif|webp))"/i', $html, $matches)) {
        return $matches[1];
    }
    
    // Try to find img tags with src (last resort, slower)
    if (preg_match('/<img[^>]+src=["\'](https?:\/\/[^"\']+\.(jpg|jpeg|png|gif|webp))["\']/i', $html, $matches)) {
        return $matches[1];
    }
    
    return null;
}

