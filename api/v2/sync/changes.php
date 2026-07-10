<?php
/**
 * GET /api/v2/sync/changes.php?since=<iso8601>
 *
 * Returns all rows (per synced table) belonging to the authenticated
 * user whose updated_at > since, plus all deletion tombstones since
 * that time.
 *
 * Response shape:
 *   {
 *     "data": {
 *       "games":            [ ...rows... ],
 *       "items":            [ ...rows... ],
 *       "game_completions": [ ...rows... ],
 *       "game_images":      [ ...rows... ],
 *       "item_images":      [ ...rows... ],
 *       "deletions":        [ { table_name, server_id, deleted_at } ],
 *       "server_now":       "2026-05-15T14:32:00Z"
 *     }
 *   }
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_auth.php';

// Streaming response keeps peak memory bounded. Previously the endpoint
// loaded every row of every synced table into PHP memory (via fetchAll),
// then json_encode built the entire response string at once, roughly
// doubling memory at the encode step. Large accounts (~800+ games) blew
// past 1G that way. Now each row is fetched + encoded + emitted
// individually, so peak memory is roughly "one row" rather than the
// whole payload.
ini_set('memory_limit', '256M');

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$since = $_GET['since'] ?? '1970-01-01T00:00:00Z';
// A bare '+' in a query string is decoded as a space by PHP's $_GET parser.
// ISO 8601 numeric offsets (e.g. +00:00) use '+', so restore it here.
$since = str_replace(' ', '+', $since);
// Validate ISO 8601 by attempting to parse it.
$sinceDt = DateTime::createFromFormat(DateTime::ATOM, $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $since);
if ($sinceDt === false) {
    v2_error('bad_request', 'since must be ISO 8601', 400);
}
// Convert to UTC epoch, then let MySQL convert to its own session timezone
// via CONVERT_TZ in the query so the comparison is always timezone-correct.
$sinceUtc = $sinceDt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

// Unbuffered queries: rows arrive one at a time from MySQL instead of the
// whole result set sitting in mysqlnd buffer. Only one unbuffered query
// can be live per connection at a time; we fully drain each before
// running the next.
$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);

header('Content-Type: application/json');

/**
 * MySQL DECIMAL columns are returned by PDO as strings (because PHP
 * float can't represent fixed-precision decimal exactly). iOS DTOs
 * declare these fields as Double?, which rejects strings. Cast per
 * row before encoding so the wire format is a JSON number.
 */
const DECIMAL_COLUMNS = [
    'games' => ['price_paid', 'pricecharting_price'],
    'items' => ['price_paid', 'pricecharting_price'],
];

/**
 * Stream a `[ {...}, {...}, ... ]` JSON array directly to output by
 * fetching and json_encoding one row at a time. Caller must have
 * already emitted the array's key (`"games":`) before this runs.
 */
function streamTable(PDO $pdo, string $table, int $userId, string $sinceUtc): void {
    $decimalCols = DECIMAL_COLUMNS[$table] ?? [];

    // CONVERT_TZ(updated_at, @@session.time_zone, '+00:00') converts the stored
    // local timestamp to UTC for comparison against the UTC since value.
    //
    // `>=` (not `>`) is deliberate. `server_now` is emitted with whole-second
    // precision, so a strict `>` misses any row whose updated_at rounds down
    // to the same second as the previous serverNow. On the client,
    // ChangeApplier looks up existing rows by server_id before insert/update,
    // making re-application of a row in the boundary second idempotent —
    // wasted work, but no lost writes. See Phase 3c in
    // docs/superpowers/plans/2026-07-10-phase3c-cursor-boundary.md.
    $stmt = $pdo->prepare("SELECT * FROM $table
        WHERE user_id = ?
          AND CONVERT_TZ(updated_at, @@session.time_zone, '+00:00') >= ?
        ORDER BY updated_at ASC");
    $stmt->execute([$userId, $sinceUtc]);

    echo '[';
    $first = true;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        foreach ($decimalCols as $col) {
            if (array_key_exists($col, $row) && $row[$col] !== null) {
                $row[$col] = (float)$row[$col];
            }
        }
        if ($first) {
            $first = false;
        } else {
            echo ',';
        }
        echo json_encode($row, JSON_UNESCAPED_SLASHES);
    }
    echo ']';
}

// Build the v2 envelope by hand so each table can stream independently.
echo '{"data":{';

echo '"games":';             streamTable($pdo, 'games',            $userId, $sinceUtc);
echo ',"items":';            streamTable($pdo, 'items',            $userId, $sinceUtc);
echo ',"game_completions":'; streamTable($pdo, 'game_completions', $userId, $sinceUtc);
echo ',"game_images":';      streamTable($pdo, 'game_images',      $userId, $sinceUtc);
echo ',"item_images":';      streamTable($pdo, 'item_images',      $userId, $sinceUtc);

// Deletions: different schema (no user-table updated_at; uses deleted_at).
echo ',"deletions":[';
// Same >= boundary as the row queries above — see the comment in streamTable().
$stmt = $pdo->prepare("SELECT table_name, server_id, deleted_at
    FROM deletions
    WHERE user_id = ?
      AND CONVERT_TZ(deleted_at, @@session.time_zone, '+00:00') >= ?
    ORDER BY deleted_at ASC");
$stmt->execute([$userId, $sinceUtc]);
$first = true;
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if ($first) {
        $first = false;
    } else {
        echo ',';
    }
    echo json_encode($row, JSON_UNESCAPED_SLASHES);
}
echo ']';

echo ',"server_now":' . json_encode(gmdate('Y-m-d\TH:i:s\Z'));
echo '}}';
exit;
