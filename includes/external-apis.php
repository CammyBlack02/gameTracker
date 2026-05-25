<?php
/**
 * Shared external-API helpers used by the proxy endpoints
 * (api/game-metadata.php, api/pricecharting.php).
 *
 * Earlier iterations tried using RAWG + the paid PriceCharting API,
 * but RAWG's signup gates on social-auth providers the user didn't
 * have linked, and the PriceCharting API requires a paid subscription.
 * Both lookups now use sources that need no API key:
 *
 *   - Wikipedia    → public REST + opensearch APIs (description only)
 *   - PriceCharting → scrape the product page (their HTML uses stable
 *                     class names like `td.price.numeric.used_price`)
 *
 * Helpers return null on failure and stash a human-readable reason in
 * gt_external_api_last_error() that callers can surface to the UI.
 */

$GLOBALS['gt_external_api_last_error'] = null;

function gt_external_api_last_error(): ?string {
    return $GLOBALS['gt_external_api_last_error'] ?? null;
}

/**
 * Wikipedia summary lookup. Two-step:
 *   1. opensearch?q=<title> video game → grab the top page title
 *   2. /api/rest_v1/page/summary/<title> → grab the lead-paragraph extract
 *
 * The "video game" suffix biases the disambiguation toward the article
 * about the game rather than a movie / book / album that shares a name.
 *
 * Returns:
 *   ['title' => 'Marvel''s Spider-Man 2', 'description' => 'Plain-text...']
 *   or null on failure.
 */
function gt_wikipedia_description(string $title): ?array {
    $GLOBALS['gt_external_api_last_error'] = null;

    $query = trim($title) . ' video game';
    $searchUrl = 'https://en.wikipedia.org/w/api.php'
        . '?action=opensearch'
        . '&search=' . urlencode($query)
        . '&limit=1'
        . '&namespace=0'
        . '&format=json';

    $search = gt_external_api_get_json($searchUrl);
    if (!is_array($search) || empty($search[1][0])) {
        $GLOBALS['gt_external_api_last_error'] = 'No Wikipedia result for "' . $title . '"';
        return null;
    }
    $pageTitle = (string)$search[1][0];

    // The summary endpoint is happier with underscored titles in the URL path.
    $slug = rawurlencode(str_replace(' ', '_', $pageTitle));
    $summaryUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . $slug;

    $summary = gt_external_api_get_json($summaryUrl);
    if (!is_array($summary)) {
        $GLOBALS['gt_external_api_last_error'] = 'Wikipedia summary fetch failed for ' . $pageTitle;
        return null;
    }
    if (($summary['type'] ?? '') === 'disambiguation') {
        $GLOBALS['gt_external_api_last_error'] = 'Wikipedia hit a disambiguation page for ' . $pageTitle;
        return null;
    }
    $extract = isset($summary['extract']) && is_string($summary['extract'])
        ? trim($summary['extract']) : '';
    if ($extract === '') {
        $GLOBALS['gt_external_api_last_error'] = 'Wikipedia returned no extract for ' . $pageTitle;
        return null;
    }
    return [
        'title'       => $summary['title']    ?? $pageTitle,
        'description' => $extract,
        'url'         => $summary['content_urls']['desktop']['page'] ?? null,
    ];
}

/**
 * Look up a price for the (title, platform) by scraping PriceCharting's
 * public product page. Two steps:
 *   1. Hit /search-products?q=<query>&type=prices and pick the first
 *      product-link href (pattern: /game/<platform-slug>/<game-slug>).
 *   2. Fetch that product page and read the first row of the price
 *      table: `td.price.numeric.used_price > span.js-price` etc.
 *
 * The "used" / loose price is the user-facing default — closest to the
 * "what's a game I own actually worth?" metric most users want.
 *
 * Returns prices as floats in USD, or null on failure.
 */
function gt_pricecharting_lookup(string $title, string $platform = ''): ?array {
    $GLOBALS['gt_external_api_last_error'] = null;

    $query = trim($platform . ' ' . $title);
    $searchUrl = 'https://www.pricecharting.com/search-products'
        . '?q=' . urlencode($query)
        . '&type=prices';

    $searchHtml = gt_external_api_get_html($searchUrl);
    if ($searchHtml === null) {
        $GLOBALS['gt_external_api_last_error'] = 'PriceCharting search request failed';
        return null;
    }

    // Find the first product link in the search results.
    if (!preg_match('#href="(https?://www\.pricecharting\.com/game/[^"]+)"#', $searchHtml, $m)) {
        $GLOBALS['gt_external_api_last_error'] = 'No PriceCharting product matched "' . $query . '"';
        return null;
    }
    $productUrl = $m[1];

    $productHtml = gt_external_api_get_html($productUrl);
    if ($productHtml === null) {
        $GLOBALS['gt_external_api_last_error'] = 'PriceCharting product page request failed';
        return null;
    }

    $loose    = gt_pricecharting_extract_price($productHtml, 'used_price');
    $complete = gt_pricecharting_extract_price($productHtml, 'complete_price');
    $new      = gt_pricecharting_extract_price($productHtml, 'new_price');

    if ($loose === null && $complete === null && $new === null) {
        $GLOBALS['gt_external_api_last_error'] = 'PriceCharting product page had no readable prices';
        return null;
    }

    // Try to surface a friendly "matched X on Y console" label.
    $matched = null;
    if (preg_match('#<h1[^>]*id="product_name"[^>]*>(.+?)</h1>#is', $productHtml, $hm)) {
        $matched = trim(html_entity_decode(strip_tags($hm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    return [
        'matched'      => $matched,
        'loose_price'  => $loose,
        'cib_price'    => $complete,
        'new_price'    => $new,
        'product_url'  => $productUrl,
    ];
}

/**
 * Pull the first <td class="price numeric <slot>"> ... <span class="js-price">$X.XX</span>
 * out of the page HTML. The first match is the canonical price for the
 * product; subsequent matches in the same page are for related games /
 * regional variants / etc.
 */
function gt_pricecharting_extract_price(string $html, string $slot): ?float {
    $pattern = '#<td[^>]*class="[^"]*\b' . preg_quote($slot, '#') . '\b[^"]*"[^>]*>\s*'
             . '(?:<a[^>]*>\s*)?'
             . '<span[^>]*class="js-price"[^>]*>\s*\$([\d,]+(?:\.\d+)?)\s*</span>#is';
    if (preg_match($pattern, $html, $m)) {
        return (float)str_replace(',', '', $m[1]);
    }
    return null;
}

/**
 * Internal: fetch + json_decode a URL with sensible defaults. Returns
 * the decoded value on 2xx + valid JSON, or null on anything else.
 * Returns mixed because Wikipedia's opensearch endpoint hands back a
 * positional array, not an object.
 */
function gt_external_api_get_json(string $url) {
    $body = gt_external_api_get_raw($url, ['Accept: application/json']);
    if ($body === null) return null;
    $decoded = json_decode($body, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        error_log("external-apis: non-JSON body from $url");
        return null;
    }
    return $decoded;
}

/**
 * Internal: fetch a URL and return the body as a string. Used for
 * HTML scraping where we don't want JSON decoding.
 */
function gt_external_api_get_html(string $url): ?string {
    return gt_external_api_get_raw($url, []);
}

/**
 * Shared cURL with timeouts + redirect-follow + a non-empty UA (some
 * sites — PriceCharting included — refuse responses to bare cURL).
 */
function gt_external_api_get_raw(string $url, array $extraHeaders): ?string {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                                . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $extraHeaders,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    // (no curl_close — the handle is released when $ch goes out of scope;
    //  curl_close itself is deprecated from PHP 8.5.)

    if ($body === false || $status < 200 || $status >= 300) {
        error_log("external-apis: HTTP $status for $url ($err)");
        return null;
    }
    return (string)$body;
}
