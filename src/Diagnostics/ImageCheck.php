<?php

namespace GameTracker\Diagnostics;

use GameTracker\Images\ImageIndex;
use GameTracker\Images\Reconciler;
use GameTracker\Images\StorageMode;
use PDO;

/**
 * Image health, reusing #4b's index and reconciler rather than recomputing.
 *
 * Orphan files are reported as INFO, not FAIL. 99 unreferenced files are
 * untidy, not broken, and failing on them would leave `gt doctor` permanently
 * red — at which point nobody reads it.
 *
 * A data: URI, by contrast, IS a failure. The write path rejects them now, so
 * any recurrence means a new path was introduced that bypasses
 * normaliseImageValue() — exactly the regression that put 113 MB of base64
 * into the database in the first place.
 */
final class ImageCheck
{
    /**
     * @return list<Check>
     */
    public static function run(PDO $pdo): array
    {
        $referenced = ImageIndex::referencedFor($pdo, null);
        $uploads = ImageIndex::uploadsDir();
        $disk = ImageIndex::onDisk($uploads);

        $result = Reconciler::reconcile(
            $referenced['filenames'],
            $disk['sources'],
            $disk['thumbs']
        );

        $checks = [];

        $dataUris = 0;
        foreach ($referenced['byMode'] as $counts) {
            $dataUris += $counts[StorageMode::DATA_URI] ?? 0;
        }

        $checks[] = $dataUris === 0
            ? Check::pass('images-no-data-uris', 'no images stored inline in the database')
            : Check::fail(
                'images-no-data-uris',
                "{$dataUris} columns hold a data: URI — an image is being written into the database",
                'php scripts/migrate-base64-covers.php --execute, and find the write path that allowed it'
            );

        $missing = count($result['missing']);
        $referencedCount = count($referenced['filenames']);

        // The wrong-directory guard, same as gt images audit. If most files
        // look absent this is almost certainly the wrong uploads directory,
        // and reporting hundreds of broken images would be noise.
        if ($referencedCount > 20 && $missing > ($referencedCount / 2)) {
            $checks[] = Check::fail(
                'images-referenced-present',
                "{$missing} of {$referencedCount} referenced files absent from {$uploads}"
                    . ' — this usually means the wrong uploads directory, not a lost collection',
                'gt images audit'
            );

            return $checks;
        }

        $checks[] = $missing === 0
            ? Check::pass('images-referenced-present', 'every referenced file is on disk')
            : Check::fail(
                'images-referenced-present',
                "{$missing} referenced files are absent from disk",
                'gt games list --broken-cover'
            );

        $orphans = count($result['orphans']);
        $checks[] = $orphans === 0
            ? Check::pass('images-orphans', 'no unreferenced files on disk')
            : Check::info('images-orphans', "{$orphans} unreferenced files on disk (gt images prune)");

        return $checks;
    }
}
