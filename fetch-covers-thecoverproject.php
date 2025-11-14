<?php
/**
 * Fetch game covers from TheCoverProject.net for Xbox 360 games
 * Stores cover image URLs in the database (no local storage)
 * Autosplit function will be available in the UI when viewing/editing games
 * 
 * Run from command line: php fetch-covers-thecoverproject.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Test mode: set to true to only process first 5 games
$testMode = false;
$testLimit = 5;

echo "==========================================\n";
echo "TheCoverProject Cover Fetch Script\n";
echo "==========================================\n";
echo "Note: This script stores URLs only, not local images\n";
echo "Autosplit will be available in the UI when viewing/editing games\n";
echo "==========================================\n\n";

if ($testMode) {
    echo "*** TEST MODE: Processing first $testLimit games only ***\n\n";
}

// Get all Xbox 360 games (for testing, you can filter to games without covers)
$query = "
    SELECT id, title, platform, front_cover_image, back_cover_image
    FROM games 
    WHERE platform = 'Xbox 360'
    ORDER BY title
";

if ($testMode) {
    $query .= " LIMIT " . (int)$testLimit;
}

$stmt = $pdo->query($query);
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalGames = count($games);
echo "Found $totalGames Xbox 360 games\n\n";

if ($totalGames === 0) {
    echo "No Xbox 360 games found!\n";
    exit;
}

$successCount = 0;
$failCount = 0;
$skipCount = 0;

/**
 * Check if a URL exists (returns 200)
 */
function urlExists($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode === 200;
}

/**
 * Search TheCoverProject for a game cover
 * Returns the cover image URL or null if not found
 */
function searchTheCoverProject($title, $platform = 'Xbox 360') {
    // Base URL for TheCoverProject CDN
    $cdnBase = 'https://coverproject.sfo2.cdn.digitaloceanspaces.com';
    $platformFolder = 'xbox_360';
    
    // Clean title for filename construction
    // Remove brackets, parentheses, special characters
    $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
    $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
    $cleanTitle = preg_replace('/[^a-zA-Z0-9\s]/', '', $cleanTitle);
    $cleanTitle = trim($cleanTitle);
    
    // Generate title variations to try
    // TheCoverProject uses lowercase, no spaces, no special chars
    $baseVariation = preg_replace('/\s+/', '', strtolower($cleanTitle));
    
    $titleVariations = [
        $baseVariation, // e.g., "callofdutyblackops"
    ];
    
    // Try removing common words/prefixes
    $wordsToRemove = ['the', 'a', 'an', 'of', 'and', 'or'];
    foreach ($wordsToRemove as $word) {
        $variation = preg_replace('/\b' . $word . '\b/', '', $baseVariation);
        $variation = preg_replace('/\s+/', '', $variation);
        if ($variation !== $baseVariation && strlen($variation) > 3) {
            $titleVariations[] = $variation;
        }
    }
    
    // Try just the last significant word(s) for games like "Call of Duty: Black Ops"
    $words = explode(' ', strtolower($cleanTitle));
    if (count($words) > 2) {
        // Try last 2 words
        $lastTwo = implode('', array_slice($words, -2));
        if (strlen($lastTwo) > 3) {
            $titleVariations[] = $lastTwo;
        }
        // Try last word
        $lastOne = end($words);
        if (strlen($lastOne) > 3) {
            $titleVariations[] = $lastOne;
        }
    }
    
    // Remove duplicates and empty values
    $titleVariations = array_filter(array_unique($titleVariations), function($v) {
        return !empty($v) && strlen($v) > 2;
    });
    
    // Try direct URL construction first (much faster!)
    foreach ($titleVariations as $titleVar) {
        if (empty($titleVar)) continue;
        
        // Try full-size first (without _thumb)
        $url = "$cdnBase/$platformFolder/x360_{$titleVar}.jpg";
        if (urlExists($url)) {
            return $url;
        }
        
        // Try with _thumb suffix
        $url = "$cdnBase/$platformFolder/x360_{$titleVar}_thumb.jpg";
        if (urlExists($url)) {
            // Remove _thumb to get full-size if available
            $fullSizeUrl = "$cdnBase/$platformFolder/x360_{$titleVar}.jpg";
            if (urlExists($fullSizeUrl)) {
                return $fullSizeUrl;
            }
            return $url;
        }
    }
    
    // Fallback to HTML scraping if direct URL construction fails
    $baseUrl = 'https://www.thecoverproject.net';
    
    // Try searching the Xbox 360 category (cat_id=10 for Xbox 360)
    $searchUrl = $baseUrl . '/view.php?cat_id=10';
    
    // Initialize cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200 || !$html) {
        return null;
    }
    
    // Parse HTML to find game cover
    $titleLower = strtolower($cleanTitle);
    $titleWords = array_filter(explode(' ', $titleLower), function($word) {
        return strlen($word) > 2;
    });
    
    // Look for game links - TheCoverProject uses various formats
    // Try: view.php?game_id=XXXX or view.php?cover_id=XXXX
    preg_match_all('/view\.php\?(?:game_id|cover_id)=(\d+)[^>]*>([^<]+)</i', $html, $matches, PREG_SET_ORDER);
    
    $bestMatch = null;
    $bestScore = 0;
    
    foreach ($matches as $match) {
        $gameId = $match[1];
        $gameTitle = strtolower(trim(strip_tags($match[2])));
        
        // Calculate match score
        $matchScore = 0;
        foreach ($titleWords as $word) {
            if (strpos($gameTitle, $word) !== false) {
                $matchScore++;
            }
        }
        
        // Also check for exact title match (higher score)
        if (strpos($gameTitle, $titleLower) !== false || strpos($titleLower, $gameTitle) !== false) {
            $matchScore += 5;
        }
        
        if ($matchScore > $bestScore) {
            $bestScore = $matchScore;
            $bestMatch = $gameId;
        }
    }
    
    // If we have a good match, get the cover image
    if ($bestMatch && $bestScore >= 2) {
        // Get the game detail page
        $gameUrl = $baseUrl . '/view.php?game_id=' . $bestMatch;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $gameUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $gameHtml = curl_exec($ch);
        curl_close($ch);
        
        if ($gameHtml) {
            // Skip common non-cover images
            $skipPatterns = [
                'header', 'logo', 'banner', 'nav', 'menu', 'icon', 'thumb', 
                'button', 'arrow', 'spacer', 'bg', 'background', 'ad', 'ads',
                'rss', 'feed', 'social', 'facebook', 'twitter', 'youtube'
            ];
            
            // Look for cover image URL - try multiple patterns
            // Pattern 1: Direct image tags with covers/xbox360 in path
            preg_match_all('/<img[^>]+src=["\']([^"\']*\.(?:jpg|jpeg|png))["\'][^>]*>/i', $gameHtml, $allImages);
            if (!empty($allImages[1])) {
                foreach ($allImages[1] as $imgUrl) {
                    $imgUrlLower = strtolower($imgUrl);
                    
                    // Skip header, logo, and other non-cover images
                    $shouldSkip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (strpos($imgUrlLower, $pattern) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        continue;
                    }
                    
                    // Prefer images with 'cover' or 'xbox' or '360' in the path
                    if (strpos($imgUrlLower, 'cover') !== false || 
                        strpos($imgUrlLower, 'xbox') !== false ||
                        strpos($imgUrlLower, '360') !== false ||
                        strpos($imgUrlLower, 'covers') !== false) {
                        if (strpos($imgUrl, 'http') !== 0) {
                            $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                        }
                        return $imgUrl;
                    }
                }
                
                // If no cover-specific image found, look for large images (likely covers)
                // Skip small images (thumbnails are usually < 200px references)
                foreach ($allImages[1] as $imgUrl) {
                    $imgUrlLower = strtolower($imgUrl);
                    
                    // Skip header, logo, and other non-cover images
                    $shouldSkip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (strpos($imgUrlLower, $pattern) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        continue;
                    }
                    
                    // Skip if it's clearly a thumbnail or very small image reference
                    if (strpos($imgUrlLower, 'thumb') !== false || 
                        preg_match('/\d{1,3}x\d{1,3}/', $imgUrlLower) ||
                        preg_match('/[_-](?:16|32|48|64|128)[x_-]/', $imgUrlLower)) {
                        continue;
                    }
                    
                    // Skip if filename is too short (likely icons/logos)
                    $filename = basename(parse_url($imgUrl, PHP_URL_PATH));
                    if (strlen($filename) < 10) {
                        continue;
                    }
                    
                    // This might be a cover - return it
                    if (strpos($imgUrl, 'http') !== 0) {
                        $imgUrl = $baseUrl . '/' . ltrim($imgUrl, '/');
                    }
                    return $imgUrl;
                }
            }
            
            // Pattern 2: Look for download links (often the full-size cover)
            preg_match_all('/<a[^>]+href=["\']([^"\']*\.(?:jpg|jpeg|png))["\']/i', $gameHtml, $linkMatches);
            if (!empty($linkMatches[1])) {
                foreach ($linkMatches[1] as $link) {
                    $linkLower = strtolower($link);
                    
                    // Skip thumbnails and icons
                    if (strpos($linkLower, 'thumb') !== false || 
                        strpos($linkLower, 'icon') !== false) {
                        continue;
                    }
                    
                    // Skip header/logo patterns
                    $shouldSkip = false;
                    foreach ($skipPatterns as $pattern) {
                        if (strpos($linkLower, $pattern) !== false) {
                            $shouldSkip = true;
                            break;
                        }
                    }
                    if ($shouldSkip) {
                        continue;
                    }
                    
                    if (strpos($link, 'http') !== 0) {
                        $link = $baseUrl . '/' . ltrim($link, '/');
                    }
                    return $link;
                }
            }
        }
    }
    
    return null;
}


// Process each game
foreach ($games as $game) {
    $gameId = $game['id'];
    $title = $game['title'];
    
    echo "Processing: $title...\n";
    
    // Skip if already has front cover (optional - remove this check if you want to update all)
    // if (!empty($game['front_cover_image'])) {
    //     echo "  -> Skipping (already has cover)\n";
    //     $skipCount++;
    //     continue;
    // }
    
    // Search for cover
    echo "  -> Searching TheCoverProject...\n";
    $coverUrl = searchTheCoverProject($title, 'Xbox 360');
    
    if (!$coverUrl) {
        echo "  -> Cover not found\n";
        $failCount++;
        continue;
    }
    
    echo "  -> Found cover: $coverUrl\n";
    
    // Store URL directly in database (no download or split needed)
    // The autosplit function will be available in the UI when viewing/editing
    echo "  -> Storing URL in database...\n";
    try {
        $updateStmt = $pdo->prepare("
            UPDATE games 
            SET front_cover_image = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $updateStmt->execute([$coverUrl, $gameId]);
        
        echo "  -> Success! URL stored. Autosplit available in UI.\n";
        $successCount++;
    } catch (PDOException $e) {
        echo "  -> Database error: " . $e->getMessage() . "\n";
        $failCount++;
    }
    
    // Small delay to avoid rate limiting
    usleep(500000); // 0.5 seconds
    echo "\n";
}

echo "==========================================\n";
echo "Summary:\n";
echo "  Success: $successCount\n";
echo "  Failed: $failCount\n";
echo "  Skipped: $skipCount\n";
echo "  Total: $totalGames\n";
echo "==========================================\n";

