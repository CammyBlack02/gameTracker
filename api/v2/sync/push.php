<?php
/**
 * POST /api/v2/sync/push.php
 * Content-Type: application/json
 *
 * Body shape:
 *   {
 *     "games": {
 *       "new":      [ {client_id, title, platform, ...} ],
 *       "modified": [ {server_id, last_synced_at, title, ...} ],
 *       "deleted":  [ {server_id} ]
 *     },
 *     "items":            { ... },
 *     "game_completions": { ... },
 *     "game_images":      { ... },
 *     "item_images":      { ... }
 *   }
 *
 * For modified rows, the phone includes its `last_synced_at` — the
 * server's updated_at value the last time the phone successfully read
 * this row. If the server's current updated_at is newer, that means
 * the row was edited elsewhere since the phone last saw it: a conflict.
 *
 * Response: same shape as input, but each row replaced with a result:
 *   accepted: {client_id?, server_id, updated_at, result:"accepted"}
 *   conflict: {server_id, server_version:{...full row...}, result:"conflict"}
 */
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');
$userId = v2_require_auth($pdo);
$body = v2_json_body();

// Columns that are user-writable per table. Any other field in the
// request body is silently ignored — defence in depth against a
// malicious client trying to set, say, user_id or created_at.
$writable = [
    'games' => ['title', 'platform', 'genre', 'description', 'series', 'special_edition',
                'condition', 'review', 'star_rating', 'metacritic_rating', 'played',
                'price_paid', 'pricecharting_price', 'is_physical', 'digital_store',
                'front_cover_image', 'back_cover_image', 'release_date'],
    'items' => ['title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'front_image', 'back_image',
                'notes', 'quantity'],
    'game_completions' => ['game_id', 'title', 'platform', 'time_taken',
                           'date_started', 'date_completed', 'completion_year', 'notes'],
    'game_images' => ['game_id', 'image_path'],
    'item_images' => ['item_id', 'image_path'],
];

function fetchUpdatedAtUtc(PDO $pdo, string $table, int $id): ?string {
    // Returns the row's updated_at as ISO 8601 UTC (e.g., 2026-05-15T14:32:00Z),
    // applying the same CONVERT_TZ pattern used in sync/changes.php for consistency.
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(
        CONVERT_TZ(updated_at, @@session.time_zone, '+00:00'),
        '%Y-%m-%dT%H:%i:%sZ'
    ) AS updated_at_utc FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $v = $stmt->fetchColumn();
    return $v ?: null;
}

function process_new(PDO $pdo, int $userId, string $table, array $cols, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $clientId = $row['client_id'] ?? null;
        $values = ['user_id' => $userId];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) $values[$c] = $row[$c];
        }
        $colList = implode(',', array_map(fn($c) => "`$c`", array_keys($values)));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($colList) VALUES ($placeholders)");
        $stmt->execute(array_values($values));
        $newId = (int)$pdo->lastInsertId();
        $results[] = [
            'client_id'  => $clientId,
            'server_id'  => $newId,
            'updated_at' => fetchUpdatedAtUtc($pdo, $table, $newId),
            'result'     => 'accepted',
        ];
    }
    return $results;
}

function process_modified(PDO $pdo, int $userId, string $table, array $cols, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $serverId = (int)($row['server_id'] ?? 0);
        $lastSynced = $row['last_synced_at'] ?? null;
        if ($serverId <= 0 || $lastSynced === null) {
            $results[] = ['server_id' => $serverId, 'result' => 'rejected',
                          'reason' => 'server_id and last_synced_at required'];
            continue;
        }
        // Conflict check: fetch the server's current row and updated_at (UTC).
        $check = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND user_id = ?");
        $check->execute([$serverId, $userId]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $results[] = ['server_id' => $serverId, 'result' => 'not_found'];
            continue;
        }
        $serverUtc = fetchUpdatedAtUtc($pdo, $table, $serverId);
        // Normalize lastSynced to UTC for string comparison.
        try {
            $phoneSeenDt = new DateTime($lastSynced);
            $phoneSeenDt->setTimezone(new DateTimeZone('UTC'));
            $phoneSeen = $phoneSeenDt->format('Y-m-d\TH:i:s\Z');
        } catch (Exception $e) {
            $results[] = ['server_id' => $serverId, 'result' => 'rejected',
                          'reason' => 'invalid last_synced_at'];
            continue;
        }
        if ($serverUtc !== null && strcmp($serverUtc, $phoneSeen) > 0) {
            $results[] = [
                'server_id'      => $serverId,
                'server_version' => $current,
                'result'         => 'conflict',
            ];
            continue;
        }
        // No conflict — apply the update.
        $sets = [];
        $values = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) {
                $sets[] = "`$c` = ?";
                $values[] = $row[$c];
            }
        }
        if (!$sets) {
            $results[] = ['server_id' => $serverId, 'result' => 'accepted',
                          'updated_at' => $serverUtc];
            continue;
        }
        $values[] = $serverId;
        $values[] = $userId;
        $upd = $pdo->prepare("UPDATE $table SET " . implode(',', $sets)
            . " WHERE id = ? AND user_id = ?");
        $upd->execute($values);
        $results[] = [
            'server_id'  => $serverId,
            'updated_at' => fetchUpdatedAtUtc($pdo, $table, $serverId),
            'result'     => 'accepted',
        ];
    }
    return $results;
}

function process_deleted(PDO $pdo, int $userId, string $table, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $serverId = (int)($row['server_id'] ?? 0);
        if ($serverId <= 0) {
            $results[] = ['server_id' => $serverId, 'result' => 'rejected'];
            continue;
        }
        $del = $pdo->prepare("DELETE FROM $table WHERE id = ? AND user_id = ?");
        $del->execute([$serverId, $userId]);
        $results[] = ['server_id' => $serverId, 'result' => 'accepted'];
    }
    return $results;
}

$response = [];
foreach ($writable as $table => $cols) {
    $bucket = $body[$table] ?? [];
    $tableResults = array_merge(
        process_new($pdo, $userId, $table, $cols, $bucket['new'] ?? []),
        process_modified($pdo, $userId, $table, $cols, $bucket['modified'] ?? []),
        process_deleted($pdo, $userId, $table, $bucket['deleted'] ?? [])
    );
    $response[$table] = $tableResults;
}

v2_ok($response);
