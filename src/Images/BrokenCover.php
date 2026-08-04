<?php

namespace GameTracker\Images;

/**
 * Finds rows whose image column names a file that is not on disk.
 *
 * This cannot be a filter like the others. Every filter in src/Query/ compiles
 * to SQL, and the filesystem is not something a WHERE clause can consult — so
 * this runs over rows already fetched.
 *
 * Two consequences the caller must honour:
 *
 *   1. It has to see every row, not one page. Filtering after LIMIT would
 *      report "3 broken on this page" and call it the answer.
 *   2. It must branch on StorageMode before touching the disk. 51 game covers
 *      and 58 item images are external URLs, and 81 columns hold inline base64;
 *      stat()ing those as paths is exactly the bug that invented 42 broken
 *      covers on 2026-08-03.
 */
final class BrokenCover
{
    /** table => the image columns worth checking */
    private const COLUMNS = [
        'games' => ['front_cover_image', 'back_cover_image'],
        'items' => ['front_image', 'back_image'],
    ];

    /** @return list<string> */
    public static function columnsFor(string $table): array
    {
        return self::COLUMNS[$table] ?? [];
    }

    /**
     * Keep only rows with at least one filename-backed image whose file is
     * absent from disk.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function filter(array $rows, string $table, string $uploadsDir): array
    {
        $columns = self::columnsFor($table);

        if ($columns === []) {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => self::isBroken($row, $columns, $uploadsDir)
        ));
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $columns
     */
    private static function isBroken(array $row, array $columns, string $uploadsDir): bool
    {
        foreach ($columns as $column) {
            $value = $row[$column] ?? null;

            // Only a filename can be missing from disk. A URL lives on someone
            // else's server and a data URI is the image itself.
            if (!StorageMode::isFile(is_string($value) ? $value : null)) {
                continue;
            }

            $name = basename((string)$value);

            if (!is_file($uploadsDir . '/covers/' . $name)
                && !is_file($uploadsDir . '/extras/' . $name)) {
                return true;
            }
        }

        return false;
    }
}
