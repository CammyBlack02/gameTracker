<?php
/**
 * Unit tests for the RomM shim's shape mapping (api/romm/_shape.php) and
 * StoreService's pure helpers.
 *
 * Runs WITHOUT a database or a web server — _shape.php deliberately has no
 * config.php dependency — so these assertions execute anywhere `php` does,
 * unlike the tests/v2 shell suite which needs MySQL.
 *
 * Every key asserted here is one halcyon-video/src/romm.ts reads by name.
 * None of them announce themselves when wrong: a mistyped key does not throw,
 * it silently drops a platform or paints a broken face onto every case. That
 * is what makes this worth pinning rather than eyeballing.
 *
 * Run: php tests/cli/test_romm_shape.php
 */

require_once __DIR__ . '/../../src/autoload.php';
require_once __DIR__ . '/../../api/romm/_shape.php';

use GameTracker\Services\StoreService;

$failures = 0;
$checks = 0;

function check(string $label, $got, $want): void
{
    global $failures, $checks;
    $checks++;
    if ($got === $want) {
        echo "  \033[32mok\033[0m   $label\n";
        return;
    }
    $failures++;
    echo "  \033[31mFAIL\033[0m $label\n";
    echo "       got:  " . var_export($got, true) . "\n";
    echo "       want: " . var_export($want, true) . "\n";
}

function section(string $name): void
{
    echo "\n\033[34m$name\033[0m\n";
}

// Deterministic origin so asset URLs are assertable.
putenv('GT_PUBLIC_BASE_URL=https://games.example.org');

/** A StoreService::stock() row, fully populated. */
function sampleGame(array $overrides = []): array
{
    return array_merge([
        'id' => 17,
        'title' => 'Final Fantasy X',
        'platform' => 'PlayStation 2',
        'platform_id' => StoreService::platformId('PlayStation 2'),
        'genre' => 'RPG',
        'series' => 'Final Fantasy',
        'condition' => 'Complete in box',
        'star_rating' => 5,
        'metacritic_rating' => 92,
        'played' => true,
        'is_physical' => true,
        'digital_store' => null,
        'release_year' => 2001,
        'front_cover' => '/uploads/covers/ffx-front.jpg',
        'back_cover' => '/uploads/covers/ffx-back.jpg',
    ], $overrides);
}

section('romm_base_url / romm_asset_url');

check('base url honours GT_PUBLIC_BASE_URL', romm_base_url(), 'https://games.example.org');
check('asset url absolutises a local path',
    romm_asset_url('/uploads/covers/x.jpg'), 'https://games.example.org/uploads/covers/x.jpg');
check('asset url passes an external URL through',
    romm_asset_url('https://cdn.example.com/x.jpg'), 'https://cdn.example.com/x.jpg');
check('asset url null stays null', romm_asset_url(null), null);
check('asset url empty stays null', romm_asset_url(''), null);

section('romm_platform');

$p = romm_platform('PlayStation 2', StoreService::platformId('PlayStation 2'), 42);
// romm.ts filters on `typeof p.id === 'number'` — a string id silently drops
// the platform and its aisle never builds.
check('platform id is an int', is_int($p['id']), true);
check('platform name is verbatim', $p['name'], 'PlayStation 2');
check('platform slug is kebab', $p['slug'], 'playstation-2');
// romm.ts filters on `romCount !== 0` — same silent-drop failure mode.
check('rom_count is an int', is_int($p['rom_count']), true);
check('rom_count is carried', $p['rom_count'], 42);
check('slug strips trademark punctuation', romm_slug('Pokémon: Let\'s Go!'), 'pok-mon-let-s-go');

section('romm_rom — required keys');

$rom = romm_rom(sampleGame());
check('id is carried', $rom['id'], 17);
check('name is the title', $rom['name'], 'Final Fantasy X');
check('fs_name mirrors the title', $rom['fs_name'], 'Final Fantasy X');
check('fs_name_no_ext mirrors the title', $rom['fs_name_no_ext'], 'Final Fantasy X');
check('platform_id is carried', $rom['platform_id'], StoreService::platformId('PlayStation 2'));

section('romm_rom — rating scale');

// star_rating is 1-5; romm.ts derives criticRating as rating * 10, so the
// value it wants is 0-10. 5 stars must land on 10, not 5.
check('5 stars becomes 10', romm_rom(sampleGame(['star_rating' => 5]))['rating'], 10);
check('3 stars becomes 6', romm_rom(sampleGame(['star_rating' => 3]))['rating'], 6);
check('1 star becomes 2', romm_rom(sampleGame(['star_rating' => 1]))['rating'], 2);
// Null, not 0: romm.ts sorts on `(b.rating || 0) - (a.rating || 0)`, and an
// unrated game should sort with the unrated, not tie with a genuine zero.
check('no rating stays null', romm_rom(sampleGame(['star_rating' => null]))['rating'], null);

section('romm_rom — release date');

$dated = romm_rom(sampleGame(['release_year' => 2001]));
check('first_release_date is present', isset($dated['first_release_date']), true);
// romm.ts treats values under 1e11 as SECONDS. A milliseconds value here would
// be read as a year far in the future and discarded by its 1970..2100 guard.
check('first_release_date is seconds, not ms', $dated['first_release_date'] < 1e11, true);
check('first_release_date round-trips to the year',
    (int)gmdate('Y', $dated['first_release_date']), 2001);
check('no release year omits the key',
    array_key_exists('first_release_date', romm_rom(sampleGame(['release_year' => null]))), false);

section('romm_rom — cover art');

check('front cover is absolute',
    $rom['path_cover_l'], 'https://games.example.org/uploads/covers/ffx-front.jpg');
check('back cover lands in ss_metadata',
    $rom['ss_metadata']['box2d_back_path'], 'https://games.example.org/uploads/covers/ffx-back.jpg');

// romm.ts skips a face only when the key is ABSENT. An empty string would be
// absolutised into a real-looking URL and painted onto every case.
$noArt = romm_rom(sampleGame(['front_cover' => null, 'back_cover' => null]));
check('missing front cover omits path_cover_l', array_key_exists('path_cover_l', $noArt), false);
check('missing back cover omits ss_metadata', array_key_exists('ss_metadata', $noArt), false);

section('romm_rom — the spine decision');

// We hold no spine art. Emitting either key (even empty) would stop romm.ts
// falling back to its own generated spine. See the design doc, Section 4.
foreach ([sampleGame(), sampleGame(['back_cover' => null])] as $i => $g) {
    $encoded = json_encode(romm_rom($g));
    check("case $i emits no spine key", strpos($encoded, 'box2d_side') === false, true);
}

section('StoreService pure helpers');

check('platformId is an int', is_int(StoreService::platformId('PlayStation 2')), true);
check('platformId is stable',
    StoreService::platformId('PlayStation 2'), StoreService::platformId('PlayStation 2'));
check('platformId is positive', StoreService::platformId('PlayStation 2') > 0, true);
check('platformId fits in 31 bits',
    StoreService::platformId('PlayStation 2') <= 0x7FFFFFFF, true);
check('platformId separates platforms',
    StoreService::platformId('PlayStation 2') !== StoreService::platformId('Nintendo 64'), true);

check('imagePath maps a bare filename',
    StoreService::imagePath('ffx.jpg'), '/uploads/covers/ffx.jpg');
check('imagePath tolerates a leading slash',
    StoreService::imagePath('/ffx.jpg'), '/uploads/covers/ffx.jpg');
check('imagePath passes an external URL through',
    StoreService::imagePath('https://cdn.example.com/x.jpg'), 'https://cdn.example.com/x.jpg');
check('imagePath null stays null', StoreService::imagePath(null), null);
check('imagePath empty stays null', StoreService::imagePath(''), null);
// A data: URI must never reach the store: it pulls the whole collection at
// once, and inlined base64 is the ~113MB mistake the cover migration undid.
check('imagePath drops a data URI',
    StoreService::imagePath('data:image/png;base64,AAAA'), null);

echo "\n";
if ($failures > 0) {
    echo "\033[31m$failures of $checks checks failed\033[0m\n";
    exit(1);
}
echo "\033[32mAll $checks checks passed\033[0m\n";
exit(0);
