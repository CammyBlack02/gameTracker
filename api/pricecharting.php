<?php
/**
 * GET /api/pricecharting.php?title=<title>&platform=<platform>
 *
 * Returns a price estimate for a game by scraping PriceCharting's
 * public product pages. Replaces the previous regex-based scraper,
 * which grabbed whichever dollar amount appeared first in the page
 * markup (often an ad or a related-item price).
 *
 * The `price` field surfaced to the UI is PriceCharting's "loose"
 * price (cartridge / disc only, no box or manual) — the closest
 * analogue to "what's a game I own actually worth?". The response
 * also includes complete-in-box and brand-new prices for callers
 * that want them.
 *
 * Response shape (backwards-compatible with the previous scraper):
 *   { success: true,  price: 24.99, message: "Price found",
 *                     loose_price: 24.99, cib_price: 39.99, new_price: 79.99,
 *                     matched: "Marvel's Spider-Man 2",
 *                     product_url: "https://www.pricecharting.com/game/..." }
 *   { success: false, price: null,  message: "..." }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/external-apis.php';
$userId = requireUser();

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
$price = $result['loose_price'] ?? $result['cib_price'] ?? $result['new_price'];

if ($price === null) {
    sendJsonResponse([
        'success' => false,
        'price'   => null,
        'message' => 'PriceCharting matched the title but returned no prices',
    ]);
}

sendJsonResponse([
    'success'     => true,
    'price'       => $price,
    'loose_price' => $result['loose_price'],
    'cib_price'   => $result['cib_price'],
    'new_price'   => $result['new_price'],
    'matched'     => $result['matched'],
    'product_url' => $result['product_url'],
    'message'     => 'Price found',
]);
