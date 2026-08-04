<?php

namespace GameTracker\Images;

/**
 * Compares what the database references against what is on disk.
 *
 * Pure: no filesystem, no database. The caller gathers both sides, so this can
 * be tested exhaustively without fixture trees, and the expensive I/O happens
 * once at the edge rather than inside the logic.
 */
final class Reconciler
{
    /**
     * @param list<string> $referenced filenames only — the caller must have
     *                                 filtered through StorageMode::isFile first
     * @param list<string> $onDisk     source files, excluding thumbnails
     * @param list<string> $thumbs     thumbnail files, named after their source
     *
     * @return array{orphans: list<string>, missing: list<string>, prunableThumbs: list<string>, keptThumbs: int}
     */
    public static function reconcile(array $referenced, array $onDisk, array $thumbs): array
    {
        $referencedSet = array_flip($referenced);
        $onDiskSet = array_flip($onDisk);

        $orphans = array_values(array_filter(
            $onDisk,
            static fn(string $file): bool => !isset($referencedSet[$file])
        ));

        $missing = array_values(array_filter(
            $referenced,
            static fn(string $file): bool => !isset($onDiskSet[$file])
        ));

        // A thumbnail is derived and referenced by no row, so it cannot be
        // judged on its own — it follows its source. 1,089 of the 1,187
        // thumbnails in production have a referenced source, so a sweep that
        // asked "is this thumbnail referenced?" would answer no for every one
        // of them and delete the lot.
        $prunableThumbs = [];
        $keptThumbs = 0;

        foreach ($thumbs as $thumb) {
            if (isset($referencedSet[$thumb])) {
                $keptThumbs++;
            } else {
                $prunableThumbs[] = $thumb;
            }
        }

        return [
            'orphans' => $orphans,
            'missing' => $missing,
            'prunableThumbs' => $prunableThumbs,
            'keptThumbs' => $keptThumbs,
        ];
    }
}
