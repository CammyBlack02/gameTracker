<?php
/**
 * GET /api/v2/games/get.php?id=<id>
 *
 * One game with its extra images, if it belongs to the authenticated user.
 * Thin wrapper over GamesService::get — see list.php for why.
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../../src/autoload.php';

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Services\GamesService;

v2_require_method('GET');
$userId = v2_require_auth($pdo);

try {
    v2_ok(GamesService::get($pdo, $userId, (int)($_GET['id'] ?? 0)));
} catch (BadRequestException $e) {
    v2_error('bad_request', $e->getMessage(), 400);
} catch (NotFoundException $e) {
    v2_error('not_found', $e->getMessage(), 404);
} catch (AccessDeniedException $e) {
    // 404 rather than 403: telling a caller a row exists but is not theirs is
    // an enumeration oracle. The service already refuses it either way.
    v2_error('not_found', 'No such game', 404);
}
