<?php
/**
 * Pure shape-mapping for the RomM-compatibility shim: gameTracker rows in,
 * RomM-shaped arrays out.
 *
 * Deliberately free of config.php, $pdo and auth so it can be exercised
 * without a database (tests/cli/test_romm_shape.php). The bootstrap half
 * lives in _shim.php; this half is the part with contract risk, because every
 * key here is one halcyon-video/src/romm.ts reads by name and none of them
 * announce themselves when wrong — a mistyped key does not error, it just
 * makes a shelf quietly never build.
 *
 * @see api/romm/_shim.php for what this shim is and how long it is meant to live
 */

/**
 * Public origin for absolute asset URLs, e.g. "https://games.example.org".
 *
 * Cover URLs handed back here MUST be absolute. romm.ts resolves a relative
 * path against its configured `romm_url` — which points at this shim's mount
 * path, not the document root — so a leading-slash path would resolve to
 * <mount>/uploads/... and 404. An absolute URL is passed through untouched.
 *
 * GT_PUBLIC_BASE_URL overrides. Without it the origin is derived from the
 * request, which is fine here: the result is only ever handed to the
 * authenticated caller, pointing at that caller's own covers.
 */
function romm_base_url(): string
{
    $configured = getenv('GT_PUBLIC_BASE_URL');
    if (is_string($configured) && $configured !== '') {
        return rtrim($configured, '/');
    }

    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    return $scheme . '://' . $host;
}

/**
 * Absolute URL for a StoreService image path, or null.
 *
 * StoreService::imagePath has already resolved the stored column to either an
 * external URL or a /uploads/... path (and dropped data: URIs).
 */
function romm_asset_url(?string $path): ?string
{
    if ($path === null || $path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return romm_base_url() . $path;
}

/**
 * Slugify a platform name for RomM's `slug` field.
 */
function romm_slug(string $name): string
{
    return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
}

/**
 * One StoreService platform row as a RomM `platform` object.
 *
 * Two fields are load-bearing in romm.ts and fail silently when wrong:
 *   id         MUST be a number — it filters on `typeof p.id === 'number'`
 *   rom_count  MUST be non-zero — it filters on `romCount !== 0`
 * Either mistake drops the platform and its aisle never builds.
 */
function romm_platform(string $name, int $platformId, int $games): array
{
    return [
        'id' => $platformId,
        'name' => $name,
        'slug' => romm_slug($name),
        'rom_count' => $games,
    ];
}

/**
 * One StoreService game row as a RomM `rom` object.
 *
 * Only the keys romm.ts reads are emitted:
 *
 *   id                  numeric; romm.ts keys shelf identity on `game_<id>`
 *   name / fs_name      title (fs_name also drives its multi-disc detection,
 *                       which never fires here — no "(Disc 1)" tags)
 *   platform_id         cross-checked against the requested platform
 *   rating              0–10; drives shelf ranking, communityRating, and
 *                       criticRating (= rating × 10). star_rating is 1–5, so
 *                       it doubles.
 *   first_release_date  unix SECONDS (romm.ts divides >1e11 as ms)
 *   path_cover_l        front cover, absolute
 *   ss_metadata         back/spine/label scans, absolute
 *
 * `box2d_side_path` is deliberately absent: we hold no spine art, and omitting
 * both the _path and _url keys is what makes romm.ts skip the face and keep
 * its own generated spine. That is the decision recorded in the design doc —
 * do not emit an empty string here, it would be treated as a real URL.
 */
function romm_rom(array $game): array
{
    $rom = [
        'id' => $game['id'],
        'name' => $game['title'],
        'fs_name' => $game['title'],
        'fs_name_no_ext' => $game['title'],
        'platform_id' => $game['platform_id'],
        // 1–5 stars -> RomM's 0–10. Null stays null so an unrated game sorts
        // last rather than tying with a genuine zero.
        'rating' => $game['star_rating'] !== null ? $game['star_rating'] * 2 : null,
    ];

    if ($game['release_year'] !== null) {
        // Midday UTC on 1 January: romm.ts only ever reads the year back out,
        // and midday keeps that year stable under any timezone shift.
        $rom['first_release_date'] = gmmktime(12, 0, 0, 1, 1, $game['release_year']);
    }

    $front = romm_asset_url($game['front_cover']);
    if ($front !== null) {
        $rom['path_cover_l'] = $front;
    }

    $back = romm_asset_url($game['back_cover']);
    if ($back !== null) {
        // ss_metadata is where romm.ts looks for non-front faces; it skips the
        // whole block when the key is missing.
        $rom['ss_metadata'] = ['box2d_back_path' => $back];
    }

    return $rom;
}
