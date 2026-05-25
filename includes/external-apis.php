<?php
/**
 * Shared external-API helpers used by the proxy endpoints
 * (api/metacritic.php, api/game-metadata.php, api/pricecharting.php).
 *
 * Both endpoints used to scrape the source HTML, which was fragile and
 * silently broke whenever the source site redesigned. These helpers
 * call the official JSON APIs instead:
 *
 *   - RAWG          → https://rawg.io/apidocs           (free key)
 *   - PriceCharting → https://www.pricecharting.com/api (free token)
 *
 * Each helper returns either a normalized assoc array on success, or
 * `null` when the lookup failed (no result, network error, or the
 * required API key isn't configured). Callers can distinguish "no
 * data" from "misconfigured" via `gt_external_api_last_error()`.
 *
 * Configure by adding to `includes/config.php`:
 *
 *   define('RAWG_API_KEY', '...');
 *   define('PRICECHARTING_API_KEY', '...');
 */

// Most-recent error string, surfaced by the helpers when they return null.
$GLOBALS['gt_external_api_last_error'] = null;

function gt_external_api_last_error(): ?string {
    return $GLOBALS['gt_external_api_last_error'] ?? null;
}

/**
 * Fetch the top-matching game from RAWG by title + (optional) platform.
 *
 * Returns null on failure. On success returns:
 *   [
 *     'slug'        => 'spider-man-2',
 *     'name'        => 'Marvel''s Spider-Man 2',
 *     'description' => 'Long description text, plain HTML stripped...',
 *     'metacritic'  => 87,    // null if RAWG doesn't have one for this title
 *     'genres'      => ['Action', 'Adventure'],
 *     'released'    => '2023-10-20',
 *     'background_image' => 'https://...',
 *   ]
 */
function gt_rawg_fetch_game(string $title, string $platform = ''): ?array {
    $GLOBALS['gt_external_api_last_error'] = null;

    if (!defined('RAWG_API_KEY') || RAWG_API_KEY === '') {
        $GLOBALS['gt_external_api_last_error'] = 'RAWG_API_KEY is not configured in includes/config.php';
        return null;
    }
    $key = RAWG_API_KEY;

    // Step 1: search. RAWG's search returns a list ranked by relevance.
    // We don't try to filter by platform — RAWG's platform_id mapping is
    // a moving target, and the title match alone is usually accurate.
    $searchUrl = 'https://api.rawg.io/api/games?key=' . urlencode($key)
        . '&search=' . urlencode($title)
        . '&page_size=5';

    $list = gt_external_api_get_json($searchUrl);
    if (!$list || empty($list['results'])) {
        $GLOBALS['gt_external_api_last_error'] = 'No RAWG results for "' . $title . '"';
        return null;
    }

    // Prefer a result whose platforms include something matching the
    // user's platform string. Falls back to the top hit if nothing matches.
    $pick = gt_rawg_choose_best_match($list['results'], $platform);

    // Step 2: pull the full detail for the chosen slug. The list response
    // only includes summary fields; description lives on the detail page.
    $detailUrl = 'https://api.rawg.io/api/games/' . urlencode($pick['slug'])
        . '?key=' . urlencode($key);
    $detail = gt_external_api_get_json($detailUrl);
    if (!$detail) {
        $GLOBALS['gt_external_api_last_error'] = 'RAWG detail fetch failed for slug ' . $pick['slug'];
        return null;
    }

    $description = isset($detail['description_raw']) && is_string($detail['description_raw'])
        ? trim($detail['description_raw'])
        : null;
    if ($description === '') { $description = null; }

    $genres = [];
    if (!empty($detail['genres']) && is_array($detail['genres'])) {
        foreach ($detail['genres'] as $g) {
            if (isset($g['name'])) { $genres[] = $g['name']; }
        }
    }

    return [
        'slug'             => $detail['slug'] ?? $pick['slug'],
        'name'             => $detail['name'] ?? ($pick['name'] ?? $title),
        'description'      => $description,
        'metacritic'       => isset($detail['metacritic']) && is_numeric($detail['metacritic'])
            ? (int)$detail['metacritic']
            : null,
        'genres'           => $genres,
        'released'         => $detail['released'] ?? null,
        'background_image' => $detail['background_image'] ?? null,
    ];
}

/**
 * Pick the search result whose platforms list best matches the user's
 * platform string. Loose substring match in both directions — RAWG calls
 * "Nintendo Switch" "Nintendo Switch" but users sometimes just type
 * "Switch", and vice versa.
 */
function gt_rawg_choose_best_match(array $results, string $platform): array {
    $platformLower = strtolower(trim($platform));
    if ($platformLower !== '') {
        foreach ($results as $r) {
            if (empty($r['platforms']) || !is_array($r['platforms'])) continue;
            foreach ($r['platforms'] as $p) {
                $pname = strtolower($p['platform']['name'] ?? '');
                if ($pname === '') continue;
                if (str_contains($pname, $platformLower) || str_contains($platformLower, $pname)) {
                    return $r;
                }
            }
        }
    }
    return $results[0];
}

/**
 * Look up a price for the (title, platform) on PriceCharting. Returns
 * the loose-cartridge / disc-only price (the most common "what's this
 * game worth?" metric) in dollars as a float, or null on failure.
 */
function gt_pricecharting_lookup(string $title, string $platform = ''): ?array {
    $GLOBALS['gt_external_api_last_error'] = null;

    if (!defined('PRICECHARTING_API_KEY') || PRICECHARTING_API_KEY === '') {
        $GLOBALS['gt_external_api_last_error'] = 'PRICECHARTING_API_KEY is not configured in includes/config.php';
        return null;
    }
    $token = PRICECHARTING_API_KEY;

    // PriceCharting's API expects "platform name product name" as a
    // single `q` parameter on the product-lookup endpoint.
    $q = trim($platform . ' ' . $title);
    $url = 'https://www.pricecharting.com/api/product'
        . '?t=' . urlencode($token)
        . '&q=' . urlencode($q);

    $data = gt_external_api_get_json($url);
    if (!$data) {
        $GLOBALS['gt_external_api_last_error'] = 'PriceCharting request failed';
        return null;
    }

    // PriceCharting wraps errors as { status: "error", error-message: "..." }.
    if (($data['status'] ?? '') === 'error') {
        $GLOBALS['gt_external_api_last_error'] = $data['error-message'] ?? 'PriceCharting error';
        return null;
    }

    // Prices are returned as integers in cents (e.g. 2499 = $24.99).
    $loose = isset($data['loose-price'])    ? (int)$data['loose-price']    / 100.0 : null;
    $cib   = isset($data['cib-price'])      ? (int)$data['cib-price']      / 100.0 : null;
    $new   = isset($data['new-price'])      ? (int)$data['new-price']      / 100.0 : null;

    if ($loose === null && $cib === null && $new === null) {
        $GLOBALS['gt_external_api_last_error'] = 'No price returned for "' . $q . '"';
        return null;
    }

    return [
        'product_name' => $data['product-name'] ?? null,
        'console_name' => $data['console-name'] ?? null,
        'loose_price'  => $loose,
        'cib_price'    => $cib,
        'new_price'    => $new,
        'id'           => $data['id'] ?? null,
    ];
}

/**
 * Internal: fetch + json_decode a URL with sensible defaults. Returns
 * the decoded array on 2xx + valid JSON, or null on anything else.
 */
function gt_external_api_get_json(string $url): ?array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'GameTracker/2.0 (+https://github.com/CammyBlack02/gameTracker)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        error_log("external-apis: HTTP $status for $url ($curlErr)");
        return null;
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        error_log("external-apis: non-JSON body from $url");
        return null;
    }
    return $decoded;
}
