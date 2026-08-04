<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterSet;
use GameTracker\Write\AssignmentSet;
use PDO;
use Throwable;

/**
 * Mutating operations on items.
 *
 * The rules are GamesWriter's, applied to a different table: bound user_id on
 * every statement, snapshot then journal then mutate then mark committed, and
 * the post-write updated_at recorded as undo's conflict baseline.
 */
final class ItemsWriter implements ResourceWriter
{
    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on items"
            ),
        };
    }

    /**
     * @return array{journal_id: ?string, matched: int, changed: int}
     */
    public static function applySet(
        PDO $pdo,
        int $userId,
        FilterSet $filters,
        AssignmentSet $assignments,
        JournalWriter $journal,
        array $argv
    ): array {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $columns = array_keys($assignments->columns);
        $selectList = '`id`, `updated_at`';
        foreach ($columns as $column) {
            $selectList .= ', `' . $column . '`';
        }

        $snapStmt = $pdo->prepare("SELECT {$selectList} FROM items WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            return ['journal_id' => null, 'matched' => 0, 'changed' => 0];
        }

        $rows = [];
        foreach ($snapshot as $row) {
            $before = [];
            foreach ($columns as $column) {
                $before[$column] = $row[$column];
            }

            $rows[] = [
                'id' => (int)$row['id'],
                'updated_at' => $row['updated_at'],
                'before' => $before,
            ];
        }

        $id = $journal->newId('set');
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'items',
            'set',
            false,
            null,
            $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE items SET ' . $assignments->setSql() . " WHERE {$where}"
            );
            $stmt->execute(array_merge($assignments->params(), $params));
            $changed = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Post-write timestamps: see the note in GamesWriter::applySet. A
        // pre-write baseline makes undo refuse every row it wrote.
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'items',
            'set',
            true,
            null,
            self::withCurrentTimestamps($pdo, $rows)
        ));

        return [
            'journal_id' => $id,
            'matched' => count($rows),
            'changed' => $changed,
        ];
    }

    /**
     * @param list<array{id:int, updated_at:?string, before:array}> $rows
     * @return list<array{id:int, updated_at:?string, before:array}>
     */
    private static function withCurrentTimestamps(PDO $pdo, array $rows): array
    {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT `id`, `updated_at` FROM items WHERE `id` IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $stamps = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stamps[(int)$row['id']] = $row['updated_at'];
        }

        return array_map(
            static fn(array $row): array => [
                'id' => $row['id'],
                'updated_at' => $stamps[$row['id']] ?? $row['updated_at'],
                'before' => $row['before'],
            ],
            $rows
        );
    }

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revertSet(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $check = $pdo->prepare('SELECT `updated_at` FROM items WHERE `id` = ? AND `user_id` = ?');
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    $skipped++;
                    continue;
                }

                if (!$force && $current !== $row['updated_at']) {
                    $skipped++;
                    continue;
                }

                $columns = array_keys($row['before']);
                $setSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '` = ?',
                    $columns
                ));

                $params = array_values($row['before']);
                $params[] = $row['id'];
                $params[] = $entry->userId;

                $stmt = $pdo->prepare(
                    "UPDATE items SET {$setSql} WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute($params);
                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    public static function assertOwned(PDO $pdo, int $userId, int $itemId): void
    {
        $stmt = $pdo->prepare('SELECT `user_id` FROM items WHERE `id` = ?');
        $stmt->execute([$itemId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== false && (int)$owner !== $userId) {
            throw new AccessDeniedException("Item {$itemId} belongs to another user");
        }
    }
}
