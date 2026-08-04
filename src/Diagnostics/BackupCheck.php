<?php

namespace GameTracker\Diagnostics;

/**
 * Checks the backup artifact, never the log line.
 *
 * ~/backup-gameTracker.sh logged "Backup completed" while writing 0-byte dumps
 * for its entire existence — the credentials in ~/.my.cnf had gone stale, the
 * script had no `set -e`, and nothing checked mysqldump's exit status. The
 * config tarball was never produced at all.
 *
 * So this opens the file. The completion marker is the check that matters: a
 * truncated dump still has CREATE TABLE and a plausible size, and only the
 * absence of mysqldump's trailing marker gives it away.
 */
final class BackupCheck
{
    private const MAX_AGE_HOURS = 48;
    private const MIN_BYTES = 1024;

    /**
     * @return list<Check>
     */
    public static function run(?string $dir = null): array
    {
        $dir = rtrim($dir ?? '/var/backups/gameTracker', '/');

        $dumps = glob($dir . '/database_*.sql.gz') ?: [];

        if ($dumps === []) {
            return [Check::fail(
                'backup-present',
                "no database dump found in {$dir}",
                'run ~/backup-gameTracker.sh and check the artifact, not the log'
            )];
        }

        // Newest by mtime rather than by name: a clock change or a manual copy
        // would break name ordering, and this must not quietly grade an old
        // dump as current.
        usort($dumps, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $latest = $dumps[0];
        $name = basename($latest);

        $checks = [Check::pass('backup-present', "{$name} present")];

        $ageHours = (time() - filemtime($latest)) / 3600;
        $checks[] = $ageHours > self::MAX_AGE_HOURS
            ? Check::fail(
                'backup-fresh',
                sprintf('newest dump is %.0f hours old', $ageHours),
                'check the nightly cron ran — but note this server is a laptop that is often off'
            )
            : Check::pass('backup-fresh', sprintf('newest dump is %.0f hours old', $ageHours));

        $size = filesize($latest) ?: 0;
        $checks[] = $size < self::MIN_BYTES
            ? Check::fail(
                'backup-size',
                sprintf('%s is only %d bytes', $name, $size),
                'the dump is empty — check mysqldump credentials'
            )
            : Check::pass('backup-size', sprintf('%s is %s', $name, self::human($size)));

        $body = self::readGz($latest);

        if ($body === null) {
            $checks[] = Check::fail('backup-readable', "{$name} could not be decompressed");

            return $checks;
        }

        $checks[] = str_contains($body, 'CREATE TABLE')
            ? Check::pass('backup-schema', 'dump contains CREATE TABLE')
            : Check::fail('backup-schema', 'dump contains no CREATE TABLE', 'the dump is not a schema dump');

        // The one that catches a truncated file. Everything above can pass on
        // a dump that was cut off part-way.
        $checks[] = str_contains($body, 'Dump completed')
            ? Check::pass('backup-complete', "mysqldump's completion marker present")
            : Check::fail(
                'backup-complete',
                'dump has no "Dump completed" marker — it is truncated',
                'rerun the backup; a partial dump will restore silently and incompletely'
            );

        return $checks;
    }

    /**
     * Read the whole dump. These are ~250 KB now that the base64 is out of the
     * database; if that changes, read the tail instead of the whole file.
     */
    private static function readGz(string $path): ?string
    {
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        $body = '';
        while (!gzeof($handle)) {
            $chunk = gzread($handle, 262144);
            if ($chunk === false) {
                break;
            }
            $body .= $chunk;
        }
        gzclose($handle);

        return $body === '' ? null : $body;
    }

    private static function human(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return sprintf('%.1f MB', $bytes / 1048576);
        }

        return sprintf('%.0f KB', $bytes / 1024);
    }
}
