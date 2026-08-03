<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterSet;
use GameTracker\Write\AssignmentSet;
use PDO;
use Throwable;

/**
 * Mutating operations on games.
 *
 * Rules, per docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md:
 *
 *   - Every statement is scoped by a bound user_id. Ownership is enforced here
 *     rather than in the caller, so no command can forget it.
 *   - Snapshot, journal, then mutate in a transaction, then mark the entry
 *     committed. A crash leaves an uncommitted entry that undo skips, so the
 *     failure mode is "cannot undo something that may not have happened"
 *     rather than "undo applies a change that never happened".
 *   - Column names come from AssignmentSet, which validated them against a
 *     WriteDefinition. Values are always bound.
 */
final class GamesWriter
{
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

        $snapStmt = $pdo->prepare("SELECT {$selectList} FROM games WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            // Nothing matched: no journal entry, because there is nothing to
            // undo and an empty entry would only clutter `gt undo --list`.
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
            'games',
            'set',
            false,
            null,
            $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE games SET ' . $assignments->setSql() . " WHERE {$where}"
            );
            $stmt->execute(array_merge($assignments->params(), $params));
            $changed = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Re-record updated_at AFTER the write. The baseline undo compares
        // against has to mean "has anything touched this row since *my* write",
        // and this write bumped updated_at itself — keeping the pre-write value
        // would make undo believe every row had been edited behind its back and
        // refuse to restore anything. The bug hid behind updated_at's
        // one-second resolution: pre and post coincide whenever a write lands
        // in the same second as the row's previous state.
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'games',
            'set',
            true,
            null,
            self::withCurrentTimestamps($pdo, $rows)
        ));

        return [
            'journal_id' => $id,
            'matched' => count($rows),
            // MySQL counts rows whose values actually changed, so a write that
            // assigns a column its existing value reports fewer than matched.
            // Reported separately rather than conflated.
            'changed' => $changed,
        ];
    }

    /**
     * Replace each snapshotted row's updated_at with the value the row holds
     * now, leaving the before-values untouched.
     *
     * @param list<array{id:int, updated_at:?string, before:array}> $rows
     * @return list<array{id:int, updated_at:?string, before:array}>
     */
    private static function withCurrentTimestamps(PDO $pdo, array $rows): array
    {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT `id`, `updated_at` FROM games WHERE `id` IN ({$placeholders})"
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
     * Restore the before-values recorded in a `set` entry.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertSet(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $check = $pdo->prepare('SELECT `updated_at` FROM games WHERE `id` = ? AND `user_id` = ?');
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    // The row no longer exists; nothing to restore into.
                    $skipped++;
                    continue;
                }

                // Something else changed this row since the write. Refuse
                // rather than discard that edit — unless explicitly forced.
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
                    "UPDATE games SET {$setSql} WHERE `id` = ? AND `user_id` = ?"
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

    /**
     * Ownership pre-check for a single-row target, so "belongs to another user"
     * is a clear domain error rather than a silent zero-row update.
     */
    public static function assertOwned(PDO $pdo, int $userId, int $gameId): void
    {
        $stmt = $pdo->prepare('SELECT `user_id` FROM games WHERE `id` = ?');
        $stmt->execute([$gameId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== false && (int)$owner !== $userId) {
            throw new AccessDeniedException("Game {$gameId} belongs to another user");
        }
    }
}
