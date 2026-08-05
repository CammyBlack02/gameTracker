<?php
/**
 * GET /api/v2/metacritic.php?title=<title>&platform=<platform>
 *
 * Metacritic auto-fetch is no longer supported. Every free source tried
 * either broke on a redesign, moved behind a paid plan, or required a
 * signup that could not be completed: Metacritic scraping, TheGamesDB,
 * RAWG and OpenCritic. Users enter Metacritic scores manually instead.
 *
 * This endpoint stays so existing callers don't 404; it always returns
 * an "unavailable" error the client can gracefully skip past. iOS's
 * AddGameView runs it alongside the price lookup and ignores the result
 * (`ProxiesAPI.swift`), which is why a clean answer matters more than a
 * correct one.
 *
 * No session-faking, no `require` of the v1 file (removed in Phase 2c).
 * The v1 stub `api/metacritic.php` carried this explanation until it was
 * deleted in phase 5/06 as the last unreferenced v1 endpoint; the text
 * moved here so the rationale outlives it.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

// Read-only endpoint: GET only. Explicit guard so the surface a browser
// session can reach (new, via dual-auth) is deliberate rather than incidental.
v2_require_method('GET');

v2_require_auth($pdo);

v2_error(
    'unavailable',
    'Metacritic auto-fetch is no longer supported — please enter the score manually.',
    200
);
