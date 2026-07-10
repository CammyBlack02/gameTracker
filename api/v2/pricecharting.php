<?php
/**
 * GET /api/v2/pricecharting.php?title=<title>&platform=<platform>
 *
 * Returns a PriceCharting price estimate for the (title, platform) pair.
 * Bearer-token auth; underlying scrape logic lives in
 * includes/external-apis.php.
 *
 * No session-faking, no `require` of the v1 file (removed in Phase 2c).
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../../includes/external-apis.php';

v2_require_auth($pdo);

$title    = trim((string)($_GET['title'] ?? ''));
$platform = trim((string)($_GET['platform'] ?? ''));

if ($title === '') {
    v2_error('bad_request', 'title is required', 400);
}

$result = gt_pricecharting_lookup($title, $platform);
if ($result === null) {
    v2_error('lookup_failed', gt_external_api_last_error() ?? 'PriceCharting lookup failed', 404);
}

// Prefer the loose (used) price for the user-facing default, falling back to
// complete-in-box then new. Matches the v1 endpoint's behaviour.
$price = $result['loose_price'] ?? $result['cib_price'] ?? $result['new_price'];
if ($price === null) {
    v2_error('lookup_failed', 'PriceCharting matched the title but returned no prices', 404);
}

v2_ok([
    'price'       => $price,
    'loose_price' => $result['loose_price'],
    'cib_price'   => $result['cib_price'],
    'new_price'   => $result['new_price'],
    'matched'     => $result['matched'],
    'product_url' => $result['product_url'],
]);
