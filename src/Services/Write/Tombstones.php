<?php

namespace GameTracker\Services\Write;

use PDO;

/**
 * Removes the tombstones a delete produced.
 *
 * Migration 002_deletions.php installs trg_<table>_after_delete on games,
 * items, game_images, item_images and game_completions; each writes a row into
 * `deletions`, which is how the iOS app learns about deletions during delta
 * sync. Restoring a deleted row without removing its tombstone would leave a
 * marker for a row that exists again — the next sync would delete it on the
 * phone, and undo would look like it had silently failed there.
 *
 * `deletions` has no trigger of its own, so clearing is silent.
 */
final class Tombstones
{
    /**
     * @param list<int> $serverIds
     * @return int rows removed
     */
    public static function clear(PDO $pdo, int $userId, string $table, array $serverIds): int
    {
        if ($serverIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($serverIds), '?'));

        // $table is a caller-supplied constant, never user input, but it is
        // bound rather than interpolated because table_name is a data column
        // here — not an identifier.
        $stmt = $pdo->prepare(
            "DELETE FROM deletions
             WHERE `user_id` = ? AND `table_name` = ? AND `server_id` IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$userId, $table], $serverIds));

        return $stmt->rowCount();
    }
}
