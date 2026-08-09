<?php
/**
 * GET <mount>/api/roms?platform_ids=<id>[&limit=N][&offset=N]
 *
 * RomM-compatible game list for one platform. Returns `{ "items": [...] }`.
 *
 * `platform_ids` is the CRC32-derived id from the platforms endpoint. RomM
 * accepts a comma list; halcyon-video/src/romm.ts only ever sends one, so
 * anything after the first is ignored rather than silently unioned — a
 * partial-looking answer is better than one that quietly widens the query.
 *
 * `order_by` / `order_dir` are accepted and IGNORED. romm.ts sends
 * `order_by=fs_name&order_dir=asc` because RomM 5.x mis-handles name ordering,
 * then re-sorts client-side by rating anyway. StoreService already returns
 * title order, which is the same intent.
 *
 * Paging is honoured because romm.ts's games-only path loops with an
 * increasing `offset` until a short page comes back. Returning the whole
 * platform every time would spin that loop forever on a platform larger than
 * one page.
 *
 * @see api/romm/_shim.php for what this shim is and how long it is meant to live
 */
require_once __DIR__ . '/_shim.php';

use GameTracker\Services\StoreService;

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$raw = (string)($_GET['platform_ids'] ?? '');
$first = trim(explode(',', $raw)[0]);
$platformId = ctype_digit($first) ? (int)$first : 0;

if ($platformId <= 0) {
    // RomM would answer across all platforms here. We do not: romm.ts carries
    // a defensive per-rom platform check precisely because that behaviour put
    // the wrong games under the wrong sign. An empty page is the honest answer.
    romm_json(['items' => []]);
}

$platform = StoreService::resolvePlatform($pdo, $userId, $platformId);
if ($platform === null) {
    romm_json(['items' => []]);
}

$limit = isset($_GET['limit']) && ctype_digit((string)$_GET['limit'])
    ? min((int)$_GET['limit'], StoreService::MAX_STOCK)
    : StoreService::MAX_STOCK;
$offset = isset($_GET['offset']) && ctype_digit((string)$_GET['offset'])
    ? (int)$_GET['offset']
    : 0;

$stock = StoreService::stock($pdo, $userId, $platform);
$page = array_slice($stock, $offset, $limit);

romm_json(['items' => array_map('romm_rom', $page)]);
