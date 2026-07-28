<?php
/**
 * GET /api/v2/cover-image.php?title=<title>&platform=<platform>
 *
 * Searches TheGamesDB for a cover image matching the given title and
 * (optionally) platform. Returns the CDN URL on success. Does NOT
 * download or persist the image — that's the caller's job, via
 * api/v2/external-image.php.
 *
 * Auth: Bearer OR session. This is a GET and it changes no state, so no
 * CSRF token is required (contrast external-image.php, which writes and
 * therefore calls v2_require_csrf_if_session()).
 *
 * Response shapes:
 *   200 { "data": { "image_url": "https://cdn.thegamesdb.net/..." } }
 *   400 { "error": "bad_request",         "message": "title is required" }
 *   401 { "error": "missing_token",       "message": "..." }
 *   404 { "error": "not_found",           "message": "Could not find cover image automatically" }
 *   404 { "error": "no_boxart",           "message": "Match found but no cover art available" }
 *   405 { "error": "method_not_allowed",  "message": "Use GET" }
 *   500 { "error": "api_key_missing",     "message": "TheGamesDB API key not configured" }
 *   502 { "error": "upstream_auth_failed","message": "TheGamesDB rejected the API key" }
 *
 * The upstream host is hardcoded, so this is not an SSRF surface and
 * does not need includes/http-fetch.php — that helper exists for
 * user-supplied URLs. Same split as includes/external-apis.php.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

v2_require_method('GET');

$userId = v2_require_auth($pdo);

$title    = trim($_GET['title']    ?? '');
$platform = trim($_GET['platform'] ?? '');

if ($title === '') {
    v2_error('bad_request', 'title is required', 400);
}

/**
 * Fetch + decode JSON from TheGamesDB. Returns the HTTP status alongside
 * the decoded body because the caller has to tell three cases apart: a
 * transport/HTTP failure (try the next title variation), an in-body
 * `code: 401` (bad API key — fail loudly), and a real result.
 *
 * Deliberately local rather than reusing gt_external_api_get_json():
 * that helper collapses every non-2xx to null, which would turn a
 * rejected API key into an indistinguishable "not found".
 *
 * @return array{http:int, json:mixed}
 */
function _cover_image_get_json(string $url): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        // Host is fixed, but pin the scheme anyway so a redirect can't
        // walk us onto file:// or another protocol. (CURLOPT_PROTOCOLS_STR
        // is the modern successor — kept on the constant form so this
        // doesn't require PHP >= 8.2. Neither warns on 8.3.)
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'GameTracker/1.0',
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    // No curl_close(): the handle frees itself when $ch goes out of
    // scope, and curl_close() is deprecated from PHP 8.5.

    if ($body === false) {
        error_log("cover-image: transport error for $url ($err)");
        return ['http' => $http, 'json' => null];
    }
    return ['http' => $http, 'json' => json_decode((string)$body, true)];
}

// --- API key resolution: env var first, then per-user setting.
$apiKey = getenv('THEGAMESDB_API_KEY') ?: '';
if ($apiKey === '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? AND user_id = ?");
    $stmt->execute(['thegamesdb_api_key', $userId]);
    $apiKey = (string)($stmt->fetchColumn() ?: '');
}
if ($apiKey === '') {
    v2_error('api_key_missing', 'TheGamesDB API key not configured', 500);
}

// --- Title cleanup + variations. Same rules as the v1 endpoint this
// replaces: strip [bracketed] and (parenthesised) qualifiers, then try
// a few shapes because TheGamesDB is fussy about colons and leading zeros.
$cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
$cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
$cleanTitle = trim($cleanTitle);

$titleVariations = array_values(array_unique(array_filter([
    $cleanTitle,
    str_replace(':', '', $cleanTitle),
    preg_replace('/^0+(\d+):/', '$1', $cleanTitle),
    preg_replace('/^0+(\d+)\s/', '$1 ', $cleanTitle),
], static fn($v) => is_string($v) && $v !== '')));

$platformMap = [
    'PlayStation' => 1, 'PlayStation 2' => 2, 'PlayStation 3' => 3,
    'PlayStation 4' => 4, 'PlayStation 5' => 5,
    'Xbox' => 6, 'Xbox 360' => 7, 'Xbox One' => 8, 'Xbox Series X' => 9,
    'Nintendo Switch' => 10, 'Wii' => 11, 'Wii U' => 12,
    'Nintendo 3DS' => 13, 'Nintendo DS' => 14,
    'PC' => 15, 'Steam' => 15, 'Windows' => 15,
    'GameCube' => 16, 'Nintendo 64' => 19, 'SNES' => 20,
    'Mega Drive' => 29, 'Sega Genesis' => 29,
    'Dreamcast' => 23, 'PS Vita' => 38,
];
$platformId = $platform !== '' ? ($platformMap[$platform] ?? null) : null;

// --- Search each variation until one matches.
$gameId = null;
foreach ($titleVariations as $searchTitle) {
    $searchUrl = 'https://api.thegamesdb.net/v1/Games/ByGameName?apikey=' . urlencode($apiKey)
               . '&name=' . urlencode($searchTitle);
    if ($platformId) {
        $searchUrl .= '&platform=' . $platformId;
    }

    $res = _cover_image_get_json($searchUrl);
    if ($res['http'] !== 200 || !is_array($res['json'])) {
        error_log("cover-image: search HTTP {$res['http']} for '$searchTitle'");
        continue;
    }

    // TheGamesDB answers a bad key with HTTP 200 and code:401 in the body.
    if (isset($res['json']['code']) && (int)$res['json']['code'] === 401) {
        error_log('cover-image: TheGamesDB rejected API key');
        v2_error('upstream_auth_failed', 'TheGamesDB rejected the API key', 502);
    }

    $games = $res['json']['data']['Games']
          ?? $res['json']['data']['games']
          ?? null;
    if (!empty($games)) {
        $gameId = $games[0]['id'] ?? null;
        if ($gameId !== null) {
            break;
        }
    }
}

if (!$gameId) {
    v2_error('not_found', 'Could not find cover image automatically', 404);
}

// --- Fetch images for the matched game.
$imagesUrl = 'https://api.thegamesdb.net/v1/Games/Images?apikey=' . urlencode($apiKey)
           . '&games_id=' . urlencode((string)$gameId);

$imgRes = _cover_image_get_json($imagesUrl);
if ($imgRes['http'] !== 200 || !is_array($imgRes['json'])) {
    error_log("cover-image: images HTTP {$imgRes['http']} for game $gameId");
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

$images = $imgRes['json']['data']['images'][$gameId]
       ?? $imgRes['json']['data']['Images'][$gameId]
       ?? null;
$boxart = $images['boxart']
       ?? $images['Boxart']
       ?? null;
if (!$boxart) {
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

// --- Extract the path from whichever boxart shape came back.
if (is_string($boxart)) {
    $imagePath = $boxart;
} elseif (is_array($boxart)) {
    $imagePath = $boxart['original']
              ?? $boxart[0]
              ?? (reset($boxart) ?: null);
    // A nested entry can itself be an object like {filename: "..."}.
    if (is_array($imagePath)) {
        $imagePath = $imagePath['filename'] ?? $imagePath['original'] ?? null;
    }
} else {
    $imagePath = null;
}

if (!is_string($imagePath) || $imagePath === '') {
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

if (substr($imagePath, 0, 1) !== '/') {
    $imagePath = '/' . $imagePath;
}

v2_ok(['image_url' => 'https://cdn.thegamesdb.net/images/original' . $imagePath]);
