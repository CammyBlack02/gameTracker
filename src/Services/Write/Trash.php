<?php

namespace GameTracker\Services\Write;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Moves image files out of the uploads tree without deleting them.
 *
 * Pruning is the least recoverable operation in the system. A mysqldump
 * restores rows, not files, and there is no image backup on this box that
 * predates 2026-07-28 — the 45 files lost in the 2025-12-05 server rebuild are
 * gone permanently because nothing kept a copy. So nothing here unlinks:
 * files are moved, and getting them back is `--restore`.
 *
 * Trash lives outside the repository for the same reason the journal does —
 * everything under the document root is fetchable over HTTP.
 *
 * There is deliberately no retention policy and no empty command. Sweeping the
 * safety net on a timer defeats the point of having one; emptying it is a
 * manual act.
 */
final class Trash
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $dir ??= getenv('GT_TRASH_DIR') ?: null;

        if ($dir === null) {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                throw new RuntimeException('cannot locate a home directory for the trash');
            }
            $dir = $home . '/.gt/trash';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create trash directory {$dir}");
        }

        $this->dir = rtrim($dir, '/');
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function newId(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH-i-s-u\Z');
    }

    /**
     * Move files into a new trash batch.
     *
     * $files are paths relative to the uploads directory, e.g.
     * "covers/orphan.jpg" or "covers/thumbs/orphan.jpg". The relative path is
     * preserved inside the batch so restore can put everything back exactly
     * where it came from.
     *
     * @param list<string> $files
     * @return array{id: string, moved: int, failed: int}
     */
    public function move(array $files, string $uploadsDir, ?string $id = null): array
    {
        $id ??= $this->newId();
        $batch = $this->dir . '/' . $this->safeId($id);
        $uploadsDir = rtrim($uploadsDir, '/');

        $moved = 0;
        $failed = 0;

        foreach ($files as $relative) {
            $source = $uploadsDir . '/' . $relative;

            if (!is_file($source)) {
                $failed++;
                continue;
            }

            $target = $batch . '/' . $relative;
            $targetDir = dirname($target);

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
                $failed++;
                continue;
            }

            if ($this->relocate($source, $target)) {
                $moved++;
            } else {
                $failed++;
            }
        }

        return ['id' => $id, 'moved' => $moved, 'failed' => $failed];
    }

    /**
     * Move a batch's files back where they came from.
     *
     * @return array{restored: int, skipped: int}
     */
    public function restore(string $id, string $uploadsDir): array
    {
        $batch = $this->dir . '/' . $this->safeId($id);

        if (!is_dir($batch)) {
            throw new RuntimeException("no trash batch '{$id}'");
        }

        $uploadsDir = rtrim($uploadsDir, '/');
        $restored = 0;
        $skipped = 0;

        foreach ($this->filesUnder($batch) as $absolute) {
            $relative = ltrim(substr($absolute, strlen($batch)), '/');
            $target = $uploadsDir . '/' . $relative;

            // Never overwrite: something may legitimately occupy the name now,
            // and clobbering it to undo a prune would be its own data loss.
            if (is_file($target)) {
                $skipped++;
                continue;
            }

            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $skipped++;
                continue;
            }

            if ($this->relocate($absolute, $target)) {
                $restored++;
            } else {
                $skipped++;
            }
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /** @return list<array{id: string, files: int}> newest first */
    public function batches(): array
    {
        $entries = glob($this->dir . '/*', GLOB_ONLYDIR) ?: [];
        rsort($entries);

        $batches = [];
        foreach ($entries as $path) {
            $batches[] = [
                'id' => basename($path),
                'files' => count($this->filesUnder($path)),
            ];
        }

        return $batches;
    }

    /**
     * rename() where possible; copy-then-unlink across filesystems, where
     * rename() fails with EXDEV. The copy is verified before the original goes.
     */
    private function relocate(string $source, string $target): bool
    {
        if (@rename($source, $target)) {
            return true;
        }

        if (!@copy($source, $target)) {
            return false;
        }

        if (filesize($target) !== filesize($source)) {
            @unlink($target);
            return false;
        }

        return @unlink($source);
    }

    /** @return list<string> absolute paths */
    private function filesUnder(string $dir): array
    {
        $found = [];

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;

            if (is_dir($path)) {
                $found = array_merge($found, $this->filesUnder($path));
            } elseif (is_file($path)) {
                $found[] = $path;
            }
        }

        return $found;
    }

    /**
     * Batch ids reach the filesystem as a path component, and --restore takes
     * one from the caller, so refuse anything that could escape the directory.
     */
    private function safeId(string $id): string
    {
        if (!preg_match('/^[0-9A-Za-z:\-]+$/', $id)) {
            throw new RuntimeException("invalid trash id '{$id}'");
        }

        return $id;
    }
}
