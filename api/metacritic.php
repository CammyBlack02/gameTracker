<?php
/**
 * GET /api/metacritic.php?title=<title>&platform=<platform>
 *
 * Auto-fetch of Metacritic scores is no longer supported. Every free
 * source we tried (Metacritic scraping, TheGamesDB, RAWG, OpenCritic)
 * either broke on a redesign, locked behind a paid plan, or required
 * a signup the user couldn't complete. Users now enter Metacritic
 * scores manually.
 *
 * This endpoint is kept so existing callers (the iOS AddGameView's
 * fetchMetadata() runs this alongside the price lookup, plus the web
 * dashboard's old "Fetch" button if any markup still references it)
 * don't error out — they just get a clean "not supported" response
 * and the metacritic field stays at whatever value the user typed.
 */

require_once __DIR__ . '/../includes/auth.php';
$userId = requireUser();

header('Content-Type: application/json');

sendJsonResponse([
    'success' => false,
    'rating'  => null,
    'message' => 'Metacritic auto-fetch is no longer supported — please enter the score manually.',
]);
