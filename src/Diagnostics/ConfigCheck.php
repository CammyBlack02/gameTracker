<?php

namespace GameTracker\Diagnostics;

/**
 * Asserts that the live includes/config.php has the properties that matter.
 *
 * This is the check the 2026-08-04 session most wanted and did not have.
 * config.php is gitignored, so three separate fixes landed in
 * config.php.example and never reached the live file: the removal of
 * per-request DDL, the GT_CLI session guard, and the GT_CLI connection-error
 * handling. Each went unnoticed for months.
 *
 * Deliberately NOT a diff against the template. The two files legitimately
 * differ by credentials, so a diff would false-positive forever and be muted
 * within a week. Asserting specific known-good properties stays honest.
 */
final class ConfigCheck
{
    /**
     * @return list<Check>
     */
    public static function run(?string $path = null): array
    {
        $path ??= dirname(__DIR__, 2) . '/includes/config.php';

        if (!is_file($path) || !is_readable($path)) {
            return [Check::fail(
                'config-readable',
                "cannot read {$path}",
                'check the file exists and is readable by this user'
            )];
        }

        $source = (string)file_get_contents($path);

        return [
            self::perRequestDdl($source),
            self::cliSessionGuard($source),
            self::cliConnectionError($source),
        ];
    }

    /**
     * Per-request DDL fired ~20 CREATE/ALTER statements on every single page
     * view. Every visitor paid for it.
     */
    private static function perRequestDdl(string $source): Check
    {
        if (preg_match('/function\s+initializeDatabase\s*\(/', $source)) {
            return Check::fail(
                'per-request-ddl',
                'config.php still defines initializeDatabase() — DDL runs on every request',
                'refresh includes/config.php from config.php.example, carrying the credentials across'
            );
        }

        return Check::pass('per-request-ddl', 'no per-request DDL');
    }

    /**
     * Without the guard, every `gt` invocation starts a PHP session and writes
     * a junk session file — a CLI process has no browser and no cookie jar.
     */
    private static function cliSessionGuard(string $source): Check
    {
        if (preg_match("/!\s*defined\s*\(\s*'GT_CLI'\s*\)/", $source)) {
            return Check::pass('cli-session-guard', 'session_start is guarded for CLI');
        }

        return Check::fail(
            'cli-session-guard',
            'session_start is not guarded by !defined(\'GT_CLI\') — every gt run writes a junk session file',
            'refresh includes/config.php from config.php.example'
        );
    }

    /**
     * die() exits with status 0, so `gt` would report success on a failed
     * database connection and any script checking $? would carry on happily.
     */
    private static function cliConnectionError(string $source): Check
    {
        if (preg_match("/defined\s*\(\s*'GT_CLI'\s*\)/", $source)
            && preg_match('/throw\s+new\s+RuntimeException/', $source)) {
            return Check::pass('cli-connection-error', 'CLI throws on a failed connection');
        }

        return Check::fail(
            'cli-connection-error',
            'a failed connection die()s, which exits 0 — gt would report success',
            'refresh includes/config.php from config.php.example'
        );
    }
}
