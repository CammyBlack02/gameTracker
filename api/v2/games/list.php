<?php
/**
 * GET /api/v2/games/list.php[?<filters>][&page=N][&limit=N][&sort=col]
 *
 * Lists the authenticated user's games. Accepts the same filter vocabulary as
 * `gt games list` — platform, genre, series, condition, digital-store,
 * title-like, played/unplayed, physical/digital, rating-min/max,
 * added-since/before, missing, sort, page, limit.
 *
 * A thin wrapper. No SQL and no business logic here: the filters compile
 * through the same FilterCompiler the CLI uses, and GamesService::list is the
 * single implementation both transports share. If this file ever grows domain
 * logic, that logic belongs in the service instead — the triplication it
 * exists to end is what made a bug need fixing in three places.
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../../src/autoload.php';

use GameTracker\Cli\UsageException;
use GameTracker\Query\ArrayOptions;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;

v2_require_method('GET');
$userId = v2_require_auth($pdo);

try {
    $filters = FilterCompiler::compile(GamesFilters::definition(), new ArrayOptions($_GET));
} catch (UsageException $e) {
    // The CLI exits 2 for these; over HTTP the equivalent is 400. Same
    // rejection, same message, transport-appropriate code.
    v2_error('bad_request', $e->getMessage(), 400);
}

v2_ok(GamesService::list($pdo, $userId, $filters));
