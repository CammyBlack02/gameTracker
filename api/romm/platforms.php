<?php
/**
 * GET <mount>/api/platforms — RomM-compatible platform list.
 *
 * Returns a BARE array (RomM's shape), one entry per platform the
 * authenticated user owns games on:
 *
 *   [ { "id": <int>, "name": "PlayStation 2",
 *       "slug": "playstation-2", "rom_count": 42 }, ... ]
 *
 * Contract notes, all load-bearing in halcyon-video/src/romm.ts:
 *
 *  - `id` MUST be a number. It filters on `typeof p.id === 'number'`, so a
 *    string id silently drops the platform and its aisle never builds.
 *  - `rom_count` MUST be non-zero, for the same reason (`romCount !== 0`).
 *    StoreService::platforms only returns platforms that have games, so this
 *    is naturally satisfied.
 *  - `name` is what the signboards read.
 *
 * @see api/romm/_shim.php for what this shim is and how long it is meant to live
 */
require_once __DIR__ . '/_shim.php';

use GameTracker\Services\StoreService;

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$out = [];
foreach (StoreService::platforms($pdo, $userId) as $row) {
    $name = $row['platform'];
    $out[] = romm_platform($name, StoreService::platformId($name), $row['games']);
}

romm_json($out);
