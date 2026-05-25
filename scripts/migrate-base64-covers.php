<?php
/**
 * One-off migration: convert base64-encoded data: URLs stored in image
 * columns to real files on disk. Legacy uploads (from the SQLite era,
 * before file-based storage was added) embedded the whole image as a
 * data URL directly in the DB column. The web dashboard's list
 * endpoint returns every row's image column inline, so a few hundred
 * legacy rows can balloon the JSON response into the hundreds of MB —
 * making the dashboard unusably slow.
 *
 * For each base64 image cell we:
 *   1. Parse the data URL: data:image/<mime>;base64,<payload>
 *   2. Decode the base64 payload
 *   3. Write the bytes to a uniquely-named file in the appropriate
 *      uploads/ subdirectory
 *   4. Generate the 512px thumbnail (same helper upload.php uses)
 *   5. UPDATE the row, replacing the data URL with just the filename
 *
 * Idempotent: skips any cell that doesn't start with `data:`. If a
 * file write or DB update fails for one row, the rest still migrate.
 *
 * Usage:
 *   php scripts/migrate-base64-covers.php            # dry-run (default)
 *   php scripts/migrate-base64-covers.php --execute  # actually migrate
 *
 * Suggested: take a `mysqldump` backup before running with --execute.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/thumbnail.php';

$dryRun = !in_array('--execute', $argv ?? [], true);

if ($dryRun) {
    echo "DRY RUN — no changes will be written. Pass --execute to apply.\n\n";
} else {
    echo "EXECUTE mode — changes WILL be written to the DB and disk.\n";
    echo "Consider a `mysqldump` backup first if you haven't already.\n";
    echo "Continuing in 3 seconds...\n\n";
    sleep(3);
}

// (table, column, target directory under <repo>/uploads/) tuples.
$jobs = [
    ['games',       'front_cover_image', __DIR__ . '/../uploads/covers'],
    ['games',       'back_cover_image',  __DIR__ . '/../uploads/covers'],
    ['items',       'front_image',       __DIR__ . '/../uploads/covers'],
    ['items',       'back_image',        __DIR__ . '/../uploads/covers'],
    ['game_images', 'image_path',        __DIR__ . '/../uploads/extras'],
    ['item_images', 'image_path',        __DIR__ . '/../uploads/extras'],
];

$totals = ['scanned' => 0, 'migrated' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($jobs as [$table, $column, $dir]) {
    if (!is_dir($dir)) {
        echo "Skipping $table.$column — target dir missing: $dir\n";
        continue;
    }

    echo "Scanning $table.$column → $dir\n";

    // Pull every row whose column begins with `data:`. Streaming
    // (unbuffered) keeps memory bounded even when individual rows
    // hold multi-megabyte base64 strings.
    $stmt = $pdo->prepare(
        "SELECT id, `$column` AS payload FROM `$table` WHERE `$column` LIKE 'data:%'"
    );
    $stmt->execute();

    $updateStmt = $pdo->prepare(
        "UPDATE `$table` SET `$column` = ? WHERE id = ?"
    );

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $totals['scanned']++;
        $id = (int)$row['id'];
        $dataUrl = $row['payload'];

        $filename = migrateDataUrlToFile($dataUrl, $dir, $table, $column, $id, $dryRun);
        if ($filename === null) {
            $totals['failed']++;
            continue;
        }
        if ($filename === '') {
            $totals['skipped']++;
            continue;
        }

        if (!$dryRun) {
            try {
                $updateStmt->execute([$filename, $id]);
            } catch (PDOException $e) {
                echo "  UPDATE failed for $table.id=$id: " . $e->getMessage() . "\n";
                $totals['failed']++;
                // Best effort: remove the file we just wrote so a
                // re-run doesn't accumulate orphans.
                @unlink($dir . '/' . $filename);
                continue;
            }
        }

        $totals['migrated']++;
        if ($totals['migrated'] % 25 === 0) {
            echo "  ...{$totals['migrated']} rows migrated so far\n";
        }
    }
}

echo "\nDone. Scanned: {$totals['scanned']}, "
   . "migrated: {$totals['migrated']}, "
   . "skipped: {$totals['skipped']}, "
   . "failed: {$totals['failed']}\n";

if ($dryRun) {
    echo "\nThat was a dry run. Re-run with --execute to apply.\n";
} else {
    echo "\nMigration complete. The dashboard's /api/games.php?action=list\n";
    echo "response should drop from hundreds of MB back to a few hundred KB.\n";
}

// -----------------------------------------------------------------------

/**
 * Returns:
 *   - the new filename (string) on success
 *   - "" if the row should be skipped (not actually a data: URL)
 *   - null on failure (caller increments the failed counter)
 */
function migrateDataUrlToFile(string $dataUrl, string $dir, string $table, string $column, int $id, bool $dryRun): ?string {
    // Expected shape: data:image/<mime>[;name=...];base64,<payload>
    if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+)[^,]*,(.+)$#s', $dataUrl, $m)) {
        // Not a recognised data URL — leave alone.
        return '';
    }

    $mime = strtolower($m[1]);
    $payload = $m[2];

    // Map MIME → extension. Default to .jpg.
    $ext = match ($mime) {
        'jpeg', 'jpg', 'pjpeg' => 'jpg',
        'png'                   => 'png',
        'gif'                   => 'gif',
        'webp'                  => 'webp',
        'heic', 'heif'          => 'heic',
        default                 => 'jpg',
    };

    $cleanBase64 = preg_replace('/\s+/', '', $payload);
    $bytes = base64_decode($cleanBase64, true);
    if ($bytes === false || strlen($bytes) === 0) {
        echo "  decode failed for $table.id=$id (mime=$mime, payload " . strlen($payload) . " chars)\n";
        return null;
    }

    // Filename pattern mirrors upload.php: <basename>_<time>_<uniqid>.<ext>
    $basename = "migrated_{$table}_{$column}_{$id}";
    $filename = $basename . '_' . time() . '_' . uniqid() . '.' . $ext;
    $targetPath = $dir . '/' . $filename;

    if ($dryRun) {
        echo "  [dry-run] would write $table.id=$id → $filename (" . strlen($bytes) . " bytes)\n";
        return $filename;
    }

    if (file_put_contents($targetPath, $bytes) === false) {
        echo "  write failed: $targetPath\n";
        return null;
    }

    // Best-effort thumbnail. upload.php treats failure as non-fatal so
    // we do the same here — the dashboard's <img onerror> handler will
    // fall back to the full-size image if a thumb never lands.
    gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

    return $filename;
}
