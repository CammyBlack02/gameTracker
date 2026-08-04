<?php

namespace GameTracker\Images;

use PDO;

/**
 * Gathers both sides of the reconciliation: what rows reference, and what is on
 * disk. Read-only — this lives in src/Images/, where the read-only guard
 * forbids write SQL.
 */
final class ImageIndex
{
    /** Every table/column pair holding an image reference. */
    private const COLUMNS = [
        ['games', 'front_cover_image'],
        ['games', 'back_cover_image'],
        ['items', 'front_image'],
        ['items', 'back_image'],
        ['game_images', 'image_path'],
        ['item_images', 'image_path'],
    ];

    /**
     * @return array{filenames: list<string>, byMode: array<string, array<string,int>>}
     */
    public static function referencedFor(PDO $pdo, ?int $userId = null): array
    {
        $filenames = [];
        $byMode = [];

        foreach (self::COLUMNS as [$table, $column]) {
            $sql = "SELECT `{$column}` AS v FROM {$table} "
                 . "WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''";
            $params = [];

            if ($userId !== null) {
                $sql .= ' AND `user_id` = ?';
                $params[] = $userId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $counts = [
                StorageMode::DATA_URI => 0,
                StorageMode::URL => 0,
                StorageMode::FILENAME => 0,
            ];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = (string)$row['v'];
                $mode = StorageMode::of($value);

                if (isset($counts[$mode])) {
                    $counts[$mode]++;
                }

                if ($mode === StorageMode::FILENAME) {
                    // basename() is applied ONLY to a value already known to be
                    // a filename. Applying it before classifying is the bug
                    // that produced the wrong 2026-08-03 figures.
                    $filenames[] = basename($value);
                }
            }

            $byMode["{$table}.{$column}"] = $counts;
        }

        return [
            'filenames' => array_values(array_unique($filenames)),
            'byMode' => $byMode,
        ];
    }

    /**
     * @return array{sources: list<string>, thumbs: list<string>}
     */
    public static function onDisk(string $uploadsDir): array
    {
        $sources = [];
        $thumbs = [];

        foreach (['covers', 'extras'] as $sub) {
            $dir = rtrim($uploadsDir, '/') . '/' . $sub;

            foreach (self::filesIn($dir) as $entry) {
                $sources[] = $entry;
            }

            foreach (self::filesIn($dir . '/thumbs') as $entry) {
                $thumbs[] = $entry;
            }
        }

        return [
            'sources' => array_values(array_unique($sources)),
            'thumbs' => array_values(array_unique($thumbs)),
        ];
    }

    /** @return list<string> */
    private static function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($dir . '/' . $entry)) {
                $found[] = $entry;
            }
        }

        return $found;
    }
}
