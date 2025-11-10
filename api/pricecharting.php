<?php
/**
 * Pricecharting API integration
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

// Pricecharting API - Note: This API requires authentication
// For now, we'll try scraping the search page as a fallback
// Users can get a free API key from pricecharting.com if needed

// Try direct search page scraping first (more reliable)
$searchUrl = 'https://www.pricecharting.com/search-products?q=' . urlencode($title . ' ' . $platform);
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $searchUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$price = null;

// Try to extract price from HTML
if ($httpCode === 200 && $html) {
    // Look for price patterns in the HTML
    // Pricecharting shows prices like "$XX.XX" or "£XX.XX"
    if (preg_match('/[£$](\d+\.?\d*)/', $html, $matches)) {
        $price = (float)$matches[1];
    }
    
    // Try more specific patterns
    if ($price === null && preg_match('/price["\']?\s*[:=]\s*[£$]?(\d+\.?\d*)/i', $html, $matches)) {
        $price = (float)$matches[1];
    }
}

// If scraping worked, return it
if ($price !== null) {
    sendJsonResponse([
        'success' => true,
        'price' => $price,
        'message' => 'Price found'
    ]);
}

// Fallback: Try API endpoint (requires token)
$url = 'https://www.pricecharting.com/api/products?t=' . urlencode($title);

if (!empty($platform)) {
    // Normalize platform name for Pricecharting
    $platformMap = [
        'PlayStation' => 'playstation',
        'PlayStation 2' => 'playstation-2',
        'PlayStation 3' => 'playstation-3',
        'PlayStation 4' => 'playstation-4',
        'PlayStation 5' => 'playstation-5',
        'Xbox' => 'xbox',
        'Xbox 360' => 'xbox-360',
        'Xbox One' => 'xbox-one',
        'Xbox Series X' => 'xbox-series-x',
        'Nintendo Switch' => 'switch',
        'Wii' => 'wii',
        'Wii U' => 'wii-u',
        'Nintendo 3DS' => '3ds',
        'Nintendo DS' => 'ds',
        'PC' => 'pc',
        'Steam' => 'pc',
        'Windows' => 'pc'
    ];
    
    $normalizedPlatform = $platformMap[strtolower($platform)] ?? strtolower($platform);
    $url .= '&console=' . urlencode($normalizedPlatform);
}

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// API requires authentication, so this will likely fail
// But we already tried scraping above, so if we get here, both methods failed
if ($httpCode !== 200 || !$response) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Could not find price. Pricecharting requires an API key for their API, or the game may not be in their database. You can get a free API key at pricecharting.com',
        'price' => null
    ]);
}

$data = json_decode($response, true);

// Parse Pricecharting response
// The API response structure may vary - try multiple formats
$price = null;

if (is_array($data)) {
    // Try array format
    if (isset($data[0])) {
        $item = $data[0];
        if (isset($item['price']) && is_numeric($item['price'])) {
            $price = (float)$item['price'];
        } else if (isset($item['used-price']) && is_numeric($item['used-price'])) {
            $price = (float)$item['used-price'];
        } else if (isset($item['loosePrice']) && is_numeric($item['loosePrice'])) {
            $price = (float)$item['loosePrice'];
        }
    }
    
    // Try direct array access
    if ($price === null && isset($data['price']) && is_numeric($data['price'])) {
        $price = (float)$data['price'];
    }
    
    // Try used-price
    if ($price === null && isset($data['used-price']) && is_numeric($data['used-price'])) {
        $price = (float)$data['used-price'];
    }
}

// If still no price, try scraping the Pricecharting page as fallback
if ($price === null) {
    $searchUrl = 'https://www.pricecharting.com/search-products?q=' . urlencode($title . ' ' . $platform);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36');
    
    $html = curl_exec($ch);
    curl_close($ch);
    
    // Try to extract price from HTML (basic regex)
    if (preg_match('/\$(\d+\.?\d*)/', $html, $matches)) {
        $price = (float)$matches[1];
    }
}

sendJsonResponse([
    'success' => $price !== null,
    'price' => $price,
    'message' => $price !== null ? 'Price found' : 'Could not find price. Pricecharting API may require authentication or the game may not be in their database.',
    'data' => $data
]);

