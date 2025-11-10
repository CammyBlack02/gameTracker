<?php
/**
 * Metacritic scraping (optional)
 * Note: This is a basic implementation and may break if Metacritic changes their HTML structure
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

// Build Metacritic search URL
$searchQuery = urlencode($title . ' ' . $platform);
$url = 'https://www.metacritic.com/search/' . $searchQuery . '/';

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

$html = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$html) {
    sendJsonResponse([
        'success' => false,
        'message' => 'Failed to fetch data from Metacritic',
        'rating' => null
    ]);
}

// Parse HTML to find rating
$rating = null;

// Try to find the first search result link
if (preg_match('/href="([^"]*game[^"]*)"[^>]*>.*?<span[^>]*class="[^"]*metascore[^"]*"[^>]*>(\d+)/is', $html, $matches)) {
    // Found a game link, try to get the actual game page
    $gameUrl = 'https://www.metacritic.com' . $matches[1];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $gameUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    $gameHtml = curl_exec($ch);
    curl_close($ch);
    
    // Look for the metascore in the game page
    if (preg_match('/<span[^>]*class="[^"]*metascore[^"]*"[^>]*>(\d+)/i', $gameHtml, $scoreMatch)) {
        $rating = (int)$scoreMatch[1];
    }
} else if (preg_match('/<span[^>]*class="[^"]*metascore[^"]*"[^>]*>(\d+)/i', $html, $matches)) {
    // Found rating directly in search results
    $rating = (int)$matches[1];
}

if ($rating !== null && $rating >= 0 && $rating <= 100) {
    sendJsonResponse([
        'success' => true,
        'rating' => $rating,
        'message' => 'Rating found'
    ]);
} else {
    sendJsonResponse([
        'success' => false,
        'message' => 'Could not find Metacritic rating. Please enter manually.',
        'rating' => null
    ]);
}

