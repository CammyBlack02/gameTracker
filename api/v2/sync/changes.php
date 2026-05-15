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

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$since = $_GET['since'] ?? '1970-01-01T00:00:00Z';
// Validate ISO 8601 by attempting to parse it.
$sinceDt = DateTime::createFromFormat(DateTime::ATOM, $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $since);
if ($sinceDt === false) {
    v2_error('bad_request', 'since must be ISO 8601', 400);
}
// Convert to UTC epoch, then let MySQL convert to its own session timezone
// via CONVERT_TZ in the query so the comparison is always timezone-correct.
$sinceUtc = $sinceDt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

function fetchChanges(PDO $pdo, string $table, int $userId, string $sinceUtc): array {
    // CONVERT_TZ(updated_at, @@session.time_zone, '+00:00') converts the stored
    // local timestamp to UTC for comparison against the UTC since value.
    $stmt = $pdo->prepare("SELECT * FROM $table
        WHERE user_id = ?
          AND CONVERT_TZ(updated_at, @@session.time_zone, '+00:00') > ?
        ORDER BY updated_at ASC");
    $stmt->execute([$userId, $sinceUtc]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$data = [
    'games'            => fetchChanges($pdo, 'games',            $userId, $sinceUtc),
    'items'            => fetchChanges($pdo, 'items',            $userId, $sinceUtc),
    'game_completions' => fetchChanges($pdo, 'game_completions', $userId, $sinceUtc),
    'game_images'      => fetchChanges($pdo, 'game_images',      $userId, $sinceUtc),
    'item_images'      => fetchChanges($pdo, 'item_images',      $userId, $sinceUtc),
];

// Deletion tombstones.
$stmt = $pdo->prepare("SELECT table_name, server_id, deleted_at
    FROM deletions
    WHERE user_id = ?
      AND CONVERT_TZ(deleted_at, @@session.time_zone, '+00:00') > ?
    ORDER BY deleted_at ASC");
$stmt->execute([$userId, $sinceUtc]);
$data['deletions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data['server_now'] = gmdate('Y-m-d\TH:i:s\Z');

v2_ok($data);
