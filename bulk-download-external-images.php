<?php
/**
 * Bulk download all external cover images and store them locally
 * This script will:
 * 1. Find all games with external URLs (http:// or https://)
 * 2. Download each image
 * 3. Update the database to point to the local file
 * 
 * Run from command line: php bulk-download-external-images.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

echo "==========================================\n";
echo "Bulk Download External Images\n";
echo "==========================================\n\n";

// Get all games with external URLs
$stmt = $pdo->query("
    SELECT id, title, platform, front_cover_image, back_cover_image
    FROM games 
    WHERE (front_cover_image LIKE 'http://%' OR front_cover_image LIKE 'https://%')
       OR (back_cover_image LIKE 'http://%' OR back_cover_image LIKE 'https://%')
    ORDER BY id
");
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalGames = count($games);
echo "Found $totalGames games with external image URLs\n\n";

if ($totalGames === 0) {
    echo "No games with external URLs found!\n";
    exit;
}

$successCount = 0;
$failCount = 0;
$skipCount = 0;

foreach ($games as $index => $game) {
    $gameNum = $index + 1;
    echo "[$gameNum/$totalGames] Processing: {$game['title']} ({$game['platform']})\n";
    
    $updated = false;
    
    // Download front cover if it's an external URL
    if ($game['front_cover_image'] && 
        (strpos($game['front_cover_image'], 'http://') === 0 || strpos($game['front_cover_image'], 'https://') === 0)) {
        echo "  Downloading front cover...\n";
        $filename = downloadExternalImage($game['front_cover_image'], $game['id'], 'front');
        if ($filename) {
            $stmt = $pdo->prepare("UPDATE games SET front_cover_image = ? WHERE id = ?");
            $stmt->execute([$filename, $game['id']]);
            echo "  ✓ Front cover downloaded: $filename\n";
            $updated = true;
        } else {
            echo "  ✗ Failed to download front cover\n";
            $failCount++;
        }
    }
    
    // Download back cover if it's an external URL
    if ($game['back_cover_image'] && 
        (strpos($game['back_cover_image'], 'http://') === 0 || strpos($game['back_cover_image'], 'https://') === 0)) {
        echo "  Downloading back cover...\n";
        $filename = downloadExternalImage($game['back_cover_image'], $game['id'], 'back');
        if ($filename) {
            $stmt = $pdo->prepare("UPDATE games SET back_cover_image = ? WHERE id = ?");
            $stmt->execute([$filename, $game['id']]);
            echo "  ✓ Back cover downloaded: $filename\n";
            $updated = true;
        } else {
            echo "  ✗ Failed to download back cover\n";
            $failCount++;
        }
    }
    
    if ($updated) {
        $successCount++;
    } else {
        $skipCount++;
    }
    
    // Small delay to avoid overwhelming servers
    usleep(500000); // 0.5 seconds
}

echo "\n==========================================\n";
echo "Download Complete\n";
echo "==========================================\n";
echo "Successfully downloaded: $successCount games\n";
echo "Failed: $failCount images\n";
echo "Skipped: $skipCount games\n";
echo "\n";

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

