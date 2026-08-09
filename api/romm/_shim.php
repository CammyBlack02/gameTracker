<?php
/**
 * Bootstrap for the RomM-compatibility shim (`api/romm/*`).
 *
 * WHAT THIS IS
 *
 * Halcyon Video (https://github.com/halcyon-video/halcyon-video) renders a
 * walkable 3D shop from a media library, and reads video games from RomM's
 * REST API. This shim answers the two RomM endpoints Halcyon actually calls,
 * from gameTracker's own collection, so stock Halcyon can be pointed at this
 * server with no fork and no changes on its side.
 *
 * It is a VIEW, not a port. It implements exactly the fields
 * `halcyon-video/src/romm.ts` reads and nothing else — this is not a faithful
 * RomM and must not be advertised as one.
 *
 * DELIBERATELY SHORT-LIVED
 *
 * This is Step 1 of the ladder in the design doc: prove the shop looks right
 * with a real collection in it before committing to a fork. Step 2 replaces it
 * with a native adapter inside the fork, reading /api/v2/games/store.php in
 * its own shape. The endpoint underneath is the part meant to survive; these
 * files are the disposable half.
 *
 * AUTH
 *
 * Bearer only, via the existing v2 token path. Set Halcyon's `romm_apikey` to
 * a gameTracker API token from /api/v2/auth/token.php.
 *
 * Do NOT use a `user:password` value there: romm.ts sends anything containing
 * a colon as HTTP Basic, which this shim does not accept. A token with no
 * colon is sent as `Authorization: Bearer <token>`, which v2_require_auth
 * already understands.
 *
 * SHAPE
 *
 * Bare JSON — no `{data: …}` envelope. That is v2's convention and this is not
 * a v2 endpoint; it has to look like RomM to the client reading it. The
 * mapping itself lives in _shape.php, which is dependency-free so it can be
 * tested without a database.
 *
 * @see docs/superpowers/specs/2026-08-09-3d-collection-store-design.md
 */

require_once __DIR__ . '/../v2/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../v2/_auth.php';
require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/_shape.php';

/**
 * Emit a bare JSON body (RomM's shape) and exit.
 *
 * Not v2_ok(): that wraps in `{"data": …}`, and romm.ts parses the response
 * as the payload itself.
 */
function romm_json($payload): void
{
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
