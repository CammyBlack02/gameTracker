<?php
/**
 * GET /api/pricecharting.php?title=<title>&platform=<platform>
 *
 * Returns a price estimate for a game from PriceCharting's official
 * JSON API. Replaces the previous regex-based HTML scraper, which
 * grabbed whichever dollar amount appeared first in the page markup
 * (sometimes an ad, sometimes a related-item price, sometimes the
 * actual game — unreliable).
 *
 * The `price` field surfaced to the UI is PriceCharting's
 * "loose-price" (cartridge/disc only, no box or manual), in dollars.
 * This is the metric most users mean when they ask "what's it worth?".
 * The full response also includes complete-in-box and brand-new prices
 * for callers that want them.
 *
 * Response shape (backwards-compatible with the previous scraper):
 *   { success: true,  price: 24.99, message: "Price found",
 *                     loose_price: 24.99, cib_price: 39.99, new_price: 79.99,
 *                     matched: "Marvel's Spider-Man 2 (PlayStation 5)" }
 *   { success: false, price: null,  message: "..." }
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';
require_once __DIR__ . '/../includes/external-apis.php';

header('Content-Type: application/json');

$title    = $_GET['title']    ?? '';
$platform = $_GET['platform'] ?? '';

if ($title === '') {
    sendJsonResponse(['success' => false, 'message' => 'Title is required', 'price' => null], 400);
}

$result = gt_pricecharting_lookup($title, $platform);

if ($result === null) {
    sendJsonResponse([
        'success' => false,
        'price'   => null,
        'message' => gt_external_api_last_error() ?? 'PriceCharting lookup failed',
    ]);
}

// Choose the user-facing price in priority order: loose → CIB → new.
// Loose is what someone gets selling on eBay without packaging, which
// is the closest analogue to "current value of a game I own".
$price = $result['loose_price'] ?? $result['cib_price'] ?? $result['new_price'];

if ($price === null) {
    sendJsonResponse([
        'success' => false,
        'price'   => null,
        'message' => 'PriceCharting matched the title but returned no prices',
    ]);
}

$matched = trim(($result['product_name'] ?? '') . ' (' . ($result['console_name'] ?? '') . ')', ' ()');

sendJsonResponse([
    'success'     => true,
    'price'       => $price,
    'loose_price' => $result['loose_price'],
    'cib_price'   => $result['cib_price'],
    'new_price'   => $result['new_price'],
    'matched'     => $matched !== '' ? $matched : null,
    'message'     => 'Price found',
]);
