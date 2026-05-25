<?php
/**
 * GET /api/metacritic.php?title=<title>&platform=<platform>
 *
 * Returns the Metacritic score for a game by looking it up on RAWG
 * (which exposes Metacritic ratings as a numeric `metacritic` field
 * on each game's detail record).
 *
 * Response shape (backwards-compatible with the previous scraper):
 *   { success: true,  rating: 87, message: "..." }
 *   { success: false, rating: null, message: "..." }
 *
 * Extra fields (used by callers that want both metacritic + description
 * from one round trip — game-metadata.php also wraps the same helper):
 *   description: string|null
 *   released:    "YYYY-MM-DD"|null
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/external-apis.php';

header('Content-Type: application/json');

$title    = $_GET['title']    ?? '';
$platform = $_GET['platform'] ?? '';

if ($title === '') {
    sendJsonResponse(['success' => false, 'message' => 'Title is required', 'rating' => null], 400);
}

$result = gt_rawg_fetch_game($title, $platform);

if ($result === null) {
    sendJsonResponse([
        'success' => false,
        'rating'  => null,
        'message' => gt_external_api_last_error() ?? 'RAWG lookup failed. Please enter manually.',
    ]);
}

if ($result['metacritic'] === null) {
    sendJsonResponse([
        'success'     => false,
        'rating'      => null,
        'description' => $result['description'],
        'released'    => $result['released'],
        'message'     => 'RAWG matched "' . ($result['name'] ?? $title) . '" but it has no Metacritic score.',
    ]);
}

sendJsonResponse([
    'success'     => true,
    'rating'      => $result['metacritic'],
    'description' => $result['description'],
    'released'    => $result['released'],
    'matched'     => $result['name'] ?? null,
    'message'     => 'Rating found',
]);
