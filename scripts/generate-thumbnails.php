<?php
/**
 * One-off backfill: generate thumbnails for every existing cover and
 * extra image. Safe to re-run — skips images that already have a thumb.
 *
 * Usage:
 *   php scripts/generate-thumbnails.php
 */

require_once __DIR__ . '/../includes/thumbnail.php';

$dirs = [
    __DIR__ . '/../uploads/covers',
    __DIR__ . '/../uploads/extras',
];

$total = 0;
$created = 0;
$skipped = 0;
$failed = 0;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Skipping missing dir: $dir\n";
        continue;
    }
    // Recurse one level for items/<userid>/ subfolders that may exist.
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        // Skip files already inside a 'thumbs' folder.
        if (strpos($path, '/thumbs/') !== false) continue;
        // Skip non-images.
        if (!@getimagesize($path)) continue;

        $total++;
        $thumbPath = gt_thumbnail_path($path);
        if (file_exists($thumbPath)) {
            $skipped++;
            continue;
        }
        if (gt_generate_thumbnail($path, $thumbPath, 512)) {
            $created++;
            if ($created % 50 === 0) echo "  ...$created created\n";
        } else {
            $failed++;
            echo "  FAILED: $path\n";
        }
    }
}

echo "Done. Total scanned: $total, created: $created, skipped (already had thumb): $skipped, failed: $failed\n";
