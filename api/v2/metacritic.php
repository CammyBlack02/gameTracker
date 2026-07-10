<?php
/**
 * GET /api/v2/metacritic.php?title=<title>&platform=<platform>
 *
 * Metacritic auto-fetch is no longer supported (every free source we
 * tried broke on a redesign or got paywalled — see api/metacritic.php
 * for the story). This endpoint stays so existing callers don't 404;
 * it always returns an "unavailable" error the client can gracefully
 * skip past.
 *
 * No session-faking, no `require` of the v1 file (removed in Phase 2c).
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

v2_require_auth($pdo);

v2_error(
    'unavailable',
    'Metacritic auto-fetch is no longer supported — please enter the score manually.',
    200
);
