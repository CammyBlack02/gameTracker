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
final class GamesWriter implements ResourceWriter
{
    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            'create' => self::revertCreate($pdo, $entry, $force),
            'delete' => self::revertDelete($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on games"
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
     * Insert one game owned by $userId.
     *
     * user_id is appended here rather than being assignable, so a create cannot
     * plant a row in someone else's collection. The journal entry is written
     * after the insert because the id does not exist until then; the ordering
     * still holds, since the pre-insert entry has nothing to protect — a crash
     * before the marker leaves a row that undo will not remove, which is the
     * safe direction.
     *
     * @return array{journal_id: string, id: int}
     */
    public static function applyCreate(
        PDO $pdo,
        int $userId,
        AssignmentSet $assignments,
        JournalWriter $journal,
        array $argv
    ): array {
        $id = $journal->newId('create');

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'create', false, null, []
        ));

        $pdo->beginTransaction();

        try {
            $sql = 'INSERT INTO games (' . $assignments->columnListSql() . ', `user_id`) '
                 . 'VALUES (' . $assignments->placeholders() . ', ?)';

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($assignments->params(), [$userId]));
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stamp = $pdo->prepare('SELECT `updated_at` FROM games WHERE `id` = ?');
        $stamp->execute([$newId]);
        $updatedAt = $stamp->fetchColumn();

        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'games',
            'create',
            true,
            null,
            [[
                'id' => $newId,
                'updated_at' => $updatedAt === false ? null : $updatedAt,
                'before' => [],
            ]]
        ));

        return ['journal_id' => $id, 'id' => $newId];
    }

    /**
     * Delete every matching game, journalling enough to put it all back.
     *
     * "Enough" is more than the parent row. game_images.game_id is ON DELETE
     * CASCADE, so those rows are destroyed outright; game_completions.game_id
     * is ON DELETE SET NULL, so the completion survives but its link is gone —
     * and because that is an UPDATE, no tombstone fires and iOS never hears
     * about it. Restoring the parent alone therefore returns a game whose
     * extra images vanished and whose completion history was silently
     * unlinked, which the governing constraint does not permit.
     *
     * Image files are never unlinked. Several production games share one image
     * path, so removing the file would break a surviving game's cover — and
     * leaving it is what makes this operation genuinely reversible.
     *
     * @return array{journal_id: ?string, deleted: int}
     */
    public static function applyDelete(
        PDO $pdo,
        int $userId,
        FilterSet $filters,
        JournalWriter $journal,
        array $argv
    ): array {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $snapStmt = $pdo->prepare("SELECT * FROM games WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            return ['journal_id' => null, 'deleted' => 0];
        }

        $rows = [];
        foreach ($snapshot as $row) {
            $gameId = (int)$row['id'];

            $imgStmt = $pdo->prepare('SELECT * FROM game_images WHERE `game_id` = ?');
            $imgStmt->execute([$gameId]);

            $compStmt = $pdo->prepare('SELECT `id` FROM game_completions WHERE `game_id` = ?');
            $compStmt->execute([$gameId]);

            $rows[] = [
                'id' => $gameId,
                'updated_at' => $row['updated_at'],
                // The entire row: a delete has to be reconstructable, so
                // unlike `set` this is not limited to changed columns.
                'before' => $row,
                'children' => [
                    'game_images' => $imgStmt->fetchAll(PDO::FETCH_ASSOC),
                    'game_completions' => array_map(
                        static fn(array $c): int => (int)$c['id'],
                        $compStmt->fetchAll(PDO::FETCH_ASSOC)
                    ),
                ],
            ];
        }

        $id = $journal->newId('delete');
        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'delete', false, null, $rows
        ));

        $pdo->beginTransaction();

        try {
            // Unlink the completions explicitly, before the delete.
            //
            // game_completions.game_id is ON DELETE SET NULL, so the FK would do
            // this anyway — but silently. An FK-driven SET NULL does not fire
            // `on update CURRENT_TIMESTAMP` (verified on MySQL 8.0.45), and being
            // an UPDATE it writes no tombstone either, so sync/changes.php never
            // returns the row and the phone keeps a completion pointing at a game
            // that is gone.
            //
            // Doing it here bumps updated_at, so the unlink reaches the phone. The
            // ids are already snapshotted above, so revertDelete still relinks them
            // on undo exactly as before.
            // Reuses the snapshot's ids rather than re-deriving them from the
            // filter, so the rows unlinked are exactly the rows journalled.
            $gameIds = array_map(static fn(array $r): int => (int)$r['id'], $rows);

            if ($gameIds !== []) {
                $placeholders = implode(',', array_fill(0, count($gameIds), '?'));
                $unlinkStmt = $pdo->prepare(
                    "UPDATE game_completions SET `game_id` = NULL WHERE `game_id` IN ({$placeholders})"
                );
                $unlinkStmt->execute($gameIds);
            }

            $stmt = $pdo->prepare("DELETE FROM games WHERE {$where}");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'delete', true, null, $rows
        ));

        return ['journal_id' => $id, 'deleted' => $deleted];
    }

    /**
     * Restore deleted games, their children, and clear the tombstones.
     *
     * The conflict check is different in kind from set's: the row is gone, so
     * there is no updated_at to compare. What can go wrong instead is that the
     * id has been taken — by a later insert, or by a restore that already ran.
     * Overwriting that row would destroy someone else's data to undo ours, so
     * a taken id is skipped unless forced.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertDelete(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM games WHERE `id` = ?');
                $exists->execute([$row['id']]);

                if ((int)$exists->fetchColumn() > 0 && !$force) {
                    $skipped++;
                    continue;
                }

                $before = $row['before'];

                // Ownership is not taken from the journal blindly: restoring a
                // row must not move it to a different owner than the entry
                // recorded acting as.
                $before['user_id'] = $entry->userId;

                // Drop updated_at so the column's DEFAULT CURRENT_TIMESTAMP
                // stamps the restore with now. Writing the original value back
                // would preserve history at the cost of correctness: iOS has
                // already deleted this row locally in response to the
                // tombstone, and it only re-fetches rows whose updated_at is
                // newer than its last sync. Restored with an old timestamp,
                // the row would exist on the server and stay missing on the
                // phone. created_at is kept — that one is genuinely history.
                unset($before['updated_at']);

                $columns = array_keys($before);
                $columnSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '`',
                    $columns
                ));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                // REPLACE rather than INSERT for the --force path: with an id
                // already taken, REPLACE deletes the squatter first. That
                // delete fires the tombstone trigger, which is exactly why
                // Tombstones::clear runs after this and not before.
                $stmt = $pdo->prepare(
                    "REPLACE INTO games ({$columnSql}) VALUES ({$placeholders})"
                );
                $stmt->execute(array_values($before));

                self::restoreChildren($pdo, $entry->userId, $row);

                Tombstones::clear($pdo, $entry->userId, 'games', [$row['id']]);

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
     * Put back what the cascade took: re-insert the destroyed game_images rows
     * under their original ids and clear their tombstones, and relink the
     * completions whose game_id was nulled.
     *
     * @param array{id:int, updated_at:?string, before:array, children?:array} $row
     */
    private static function restoreChildren(PDO $pdo, int $userId, array $row): void
    {
        $children = $row['children'] ?? [];

        $images = $children['game_images'] ?? [];
        $imageIds = [];

        foreach ($images as $image) {
            $image['user_id'] = $userId;
            $imageIds[] = (int)$image['id'];

            // Same reason as the parent row: sync/changes.php streams
            // game_images as its own table filtered on its own updated_at, so
            // a child restored with its original timestamp falls below the
            // phone's cursor and stays missing there forever.
            unset($image['updated_at']);

            $columns = array_keys($image);
            $columnSql = implode(', ', array_map(
                static fn(string $c): string => '`' . $c . '`',
                $columns
            ));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $stmt = $pdo->prepare(
                "REPLACE INTO game_images ({$columnSql}) VALUES ({$placeholders})"
            );
            $stmt->execute(array_values($image));
        }

        Tombstones::clear($pdo, $userId, 'game_images', $imageIds);

        // Completions were never deleted — SET NULL only broke the link, which
        // is why there is no tombstone to clear for them.
        $completionIds = $children['game_completions'] ?? [];

        if ($completionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($completionIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE game_completions SET `game_id` = ?
                 WHERE `id` IN ({$placeholders}) AND `user_id` = ? AND `game_id` IS NULL"
            );
            $stmt->execute(array_merge([$row['id']], $completionIds, [$userId]));
        }
    }

    /**
     * Undo a create by deleting the row it made.
     *
     * The delete fires trg_games_after_delete, leaving a tombstone. That is
     * correct and deliberately not cleaned up: if iOS already synced the
     * created row, it needs to hear that the row is gone.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertCreate(PDO $pdo, JournalEntry $entry, bool $force): array
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
                    // Already gone. Nothing to undo, and not an error.
                    $skipped++;
                    continue;
                }

                // Edited since it was created: removing it would discard that
                // edit, so refuse unless forced.
                if (!$force && $current !== $row['updated_at']) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare('DELETE FROM games WHERE `id` = ? AND `user_id` = ?');
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
