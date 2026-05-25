<?php
/**
 * GET /api/game-metadata.php?title=<title>&platform=<platform>
 *
 * Returns the description for a game by looking it up on Wikipedia
 * (plain-text summary of the lead paragraph). Replaces the previous
 * TheGamesDB scraper, whose hardcoded key had silently gone stale.
 *
 * Genre is no longer auto-fetched — Wikipedia summaries don't expose
 * a clean structured genre field, and there's no free alternative that
 * doesn't require account signup. Users enter genre manually.
 *
 * Response shape (backwards-compatible — `genre` stays in the payload
 * but is always null now):
 *   { success: true,  genre: null,
 *                     description: "Long text...",
 *                     matched: "Wikipedia page title",
 *                     url:     "https://en.wikipedia.org/wiki/...",
 *                     message: "Description found" }
 *   { success: false, genre: null, description: null, message: "..." }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/external-apis.php';

header('Content-Type: application/json');

$title = $_GET['title'] ?? '';

if ($title === '') {
    sendJsonResponse([
        'success'     => false,
        'message'     => 'Title is required',
        'genre'       => null,
        'description' => null,
    ], 400);
}

$result = gt_wikipedia_description($title);

if ($result === null) {
    sendJsonResponse([
        'success'     => false,
        'message'     => gt_external_api_last_error() ?? 'Description lookup failed',
        'genre'       => null,
        'description' => null,
    ]);
}

sendJsonResponse([
    'success'     => true,
    'message'     => 'Description found',
    'genre'       => null,
    'description' => $result['description'],
    'matched'     => $result['title'] ?? null,
    'url'         => $result['url']   ?? null,
]);
