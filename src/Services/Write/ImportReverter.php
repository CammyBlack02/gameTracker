<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\BadRequestException;
use GameTracker\Journal\JournalEntry;
use PDO;
use Throwable;

/**
 * Reverses an import.
 *
 * Import needs its own reverter because a CSV import spans games and items
 * while a JournalEntry carries a single resource. Splitting one import across
 * two entries would let `gt undo` revert half a job, so instead each journalled
 * row records the table it went into and this deletes accordingly.
 *
 * Import only ever INSERTs, so reversing is deleting — with the same guard
 * revertCreate uses: a row edited since the import is left alone unless forced,
 * because removing it would discard that edit.
 */
final class ImportReverter implements ResourceWriter
{
    private const TABLES = ['games', 'items'];

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        if ($entry->operation !== 'import') {
            throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' as an import"
            );
        }

        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $table = $row['table'] ?? null;

                // Never interpolate a table name read from a file on disk
                // without checking it against a fixed list first.
                if (!in_array($table, self::TABLES, true)) {
                    $skipped++;
                    continue;
                }

                $check = $pdo->prepare(
                    "SELECT `updated_at` FROM {$table} WHERE `id` = ? AND `user_id` = ?"
                );
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    // Already gone. Not an error.
                    $skipped++;
                    continue;
                }

                if (!$force && $current !== ($row['updated_at'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM {$table} WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute([$row['id'], $entry->userId]);
                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }
}
