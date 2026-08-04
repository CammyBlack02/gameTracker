<?php

namespace GameTracker\Services\Write;

use GameTracker\Import\ImportRow;
use GameTracker\Import\Source;
use GameTracker\Import\TitleKey;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Write\GamesWrites;
use GameTracker\Write\ItemsWrites;
use PDO;
use Throwable;

/**
 * Turns a Source's candidate rows into database rows.
 *
 * The only unit in this sub-project that writes. Planning is separated from
 * applying so the preview and the real run share one matcher — a dry run that
 * used different logic from the apply would be worse than no dry run at all.
 */
final class Importer
{
    /**
     * Decide which candidates are new, without writing anything.
     *
     * @return array{candidates: list<ImportRow>, matched: int, skipped: int, byTable: array<string,int>}
     */
    public static function plan(PDO $pdo, int $userId, Source $source): array
    {
        $existing = self::existingKeys($pdo, $userId);

        $candidates = [];
        $matched = 0;
        $byTable = ['games' => 0, 'items' => 0];
        $seen = [];

        foreach ($source->rows() as $row) {
            $key = self::keyFor($row->table, $row->title(), $row->platform());

            // Guard against duplicates inside the source itself, not just
            // against the database. A CSV listing the same game twice would
            // otherwise import it twice.
            if (isset($existing[$key]) || isset($seen[$key])) {
                $matched++;
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $row;
            $byTable[$row->table] = ($byTable[$row->table] ?? 0) + 1;
        }

        return [
            'candidates' => $candidates,
            'matched' => $matched,
            // Only meaningful once the generator has been drained, which the
            // foreach above guarantees.
            'skipped' => $source->skipped(),
            'byTable' => $byTable,
        ];
    }

    /**
     * Insert the candidates in one transaction and journal them as one entry.
     *
     * @param list<ImportRow> $candidates
     * @return array{journal_id: ?string, inserted: int, ids: list<array{table:string,id:int,coverUrl:?string}>}
     */
    public static function apply(
        PDO $pdo,
        int $userId,
        array $candidates,
        JournalWriter $journal,
        array $argv
    ): array {
        if ($candidates === []) {
            // Nothing to undo, and an empty entry would only clutter
            // `gt undo --list`.
            return ['journal_id' => null, 'inserted' => 0, 'ids' => []];
        }

        $id = $journal->newId('import');
        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'import', 'import', false, null, []
        ));

        $inserted = [];

        $pdo->beginTransaction();

        try {
            foreach ($candidates as $row) {
                $columns = self::writableOnly($row);

                if ($columns === []) {
                    continue;
                }

                $columnSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '`',
                    array_keys($columns)
                ));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                // The table name comes from ImportRow, whose only possible
                // values are set by the profile — never from user input.
                $table = $row->table === 'items' ? 'items' : 'games';

                $stmt = $pdo->prepare(
                    "INSERT INTO {$table} ({$columnSql}, `user_id`) "
                    . "VALUES ({$placeholders}, ?)"
                );
                $stmt->execute(array_merge(array_values($columns), [$userId]));

                $inserted[] = [
                    'table' => $table,
                    'id' => (int)$pdo->lastInsertId(),
                    'coverUrl' => $row->coverUrl,
                ];
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $rows = [];
        foreach ($inserted as $item) {
            $stamp = $pdo->prepare("SELECT `updated_at` FROM {$item['table']} WHERE `id` = ?");
            $stamp->execute([$item['id']]);
            $updatedAt = $stamp->fetchColumn();

            $rows[] = [
                'table' => $item['table'],
                'id' => $item['id'],
                'updated_at' => $updatedAt === false ? null : $updatedAt,
                'before' => [],
            ];
        }

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'import', 'import', true, null, $rows
        ));

        return ['journal_id' => $id, 'inserted' => count($inserted), 'ids' => $inserted];
    }

    /**
     * Drop anything the resource does not permit writing.
     *
     * A --map can name any column, so this is the boundary that stops a
     * mapping from reaching a column the resource never offered.
     *
     * @return array<string, mixed>
     */
    private static function writableOnly(ImportRow $row): array
    {
        $def = $row->table === 'items'
            ? ItemsWrites::definition()
            : GamesWrites::definition();

        $columns = [];
        foreach ($row->columns as $column => $value) {
            if ($def->isWritable($column)) {
                $columns[$column] = $value;
            }
        }

        return $columns;
    }

    /**
     * Every (table, normalised title, platform) the user already owns.
     *
     * Loaded once per run rather than a query per candidate: a 300-game Steam
     * import would otherwise issue 300 round trips. At this scale — the largest
     * library is ~1,300 rows — one pass is cheaper than any index would be,
     * and it avoids storing a normalised column that could go stale.
     *
     * @return array<string, true>
     */
    private static function existingKeys(PDO $pdo, int $userId): array
    {
        $keys = [];

        foreach (['games', 'items'] as $table) {
            $stmt = $pdo->prepare("SELECT `title`, `platform` FROM {$table} WHERE `user_id` = ?");
            $stmt->execute([$userId]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $keys[self::keyFor($table, (string)$row['title'], $row['platform'])] = true;
            }
        }

        return $keys;
    }

    private static function keyFor(string $table, string $title, ?string $platform): string
    {
        return $table . "\0" . TitleKey::normalise($title)
             . "\0" . TitleKey::normalise((string)$platform);
    }
}
