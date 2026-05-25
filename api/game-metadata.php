<?php
/**
 * GET /api/game-metadata.php?title=<title>&platform=<platform>
 *
 * Returns the genre and description for a game by looking it up on
 * RAWG. Replaces the previous TheGamesDB scraper, whose hardcoded
 * key had silently gone stale (and whose response shape varied
 * unpredictably between game records).
 *
 * Response shape (backwards-compatible with the previous endpoint):
 *   { success: true,  genre: "Action, Adventure",
 *                     description: "Long text...",
 *                     released: "2023-10-20",     // (new)
 *                     matched:  "Game name",      // (new)
 *                     message: "Game metadata found" }
 *   { success: false, genre: null, description: null, message: "..." }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/external-apis.php';

header('Content-Type: application/json');

$title    = $_GET['title']    ?? '';
$platform = $_GET['platform'] ?? '';

if ($title === '') {
    sendJsonResponse([
        'success'     => false,
        'message'     => 'Title is required',
        'genre'       => null,
        'description' => null,
    ], 400);
}

$result = gt_rawg_fetch_game($title, $platform);

if ($result === null) {
    sendJsonResponse([
        'success'     => false,
        'message'     => gt_external_api_last_error() ?? 'RAWG lookup failed',
        'genre'       => null,
        'description' => null,
    ]);
}

$genre = !empty($result['genres']) ? implode(', ', $result['genres']) : null;

sendJsonResponse([
    'success'     => true,
    'message'     => 'Game metadata found',
    'genre'       => $genre,
    'description' => $result['description'],
    'released'    => $result['released'],
    'matched'     => $result['name'] ?? null,
]);
