<?php
/**
 * GET /api/v2/games/store.php[?platform=<exact name>]
 *
 * The whole collection, slimmed to what a shelf-style client needs to draw a
 * box: title, platform, cover paths, and the fields that dress a case
 * (condition, rating, played, physical/digital). No description, no review, no
 * prices.
 *
 * Response:
 *   { "data": { "platforms": [{platform, platform_id, games}],
 *               "games":     [...],
 *               "total":     <int> } }
 *
 * Unpaged on purpose — a store loads its stock once at boot and then renders
 * offline; you cannot page a room. StoreService::MAX_STOCK caps it.
 *
 * A thin wrapper: no SQL and no business logic here. If this grows domain
 * logic, that logic belongs in StoreService instead.
 *
 * @see docs/superpowers/specs/2026-08-09-3d-collection-store-design.md
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../../src/autoload.php';

use GameTracker\Services\StoreService;

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$platform = isset($_GET['platform']) && $_GET['platform'] !== ''
    ? (string)$_GET['platform']
    : null;

$games = StoreService::stock($pdo, $userId, $platform);

$platforms = [];
foreach (StoreService::platforms($pdo, $userId) as $row) {
    $platforms[] = [
        'platform' => $row['platform'],
        'platform_id' => StoreService::platformId($row['platform']),
        'games' => $row['games'],
    ];
}

v2_ok([
    'platforms' => $platforms,
    'games' => $games,
    'total' => count($games),
]);
