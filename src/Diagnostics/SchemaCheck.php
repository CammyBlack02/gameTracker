<?php

namespace GameTracker\Diagnostics;

use PDO;

/**
 * Schema state: the ledger, and the foreign keys that make ON DELETE CASCADE
 * real.
 *
 * Production ran for months with no schema_migrations table and five missing
 * user_id foreign keys, which meant deleting a user would orphan their 1,261
 * games rather than removing them. Nothing reported it; it was found by hand.
 */
final class SchemaCheck
{
    /** Tables whose user_id must be constrained to users(id). */
    private const USER_SCOPED = [
        'games', 'items', 'game_images', 'item_images', 'game_completions', 'settings',
    ];

    /**
     * @return list<Check>
     */
    public static function run(PDO $pdo, string $dbName, ?string $migrationsDir = null): array
    {
        $checks = [];

        $migrationsDir ??= dirname(__DIR__, 2) . '/database/migrations';

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ?'
        );
        $stmt->execute([$dbName, 'schema_migrations']);
        $ledgerPresent = (int)$stmt->fetchColumn() > 0;

        if (!$ledgerPresent) {
            $checks[] = Check::fail(
                'schema-ledger',
                'schema_migrations does not exist — the schema has no recorded history',
                'php database/migrate.php'
            );
        } else {
            $applied = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
            $onDisk = count(glob($migrationsDir . '/*.php') ?: []);

            $checks[] = $applied >= $onDisk
                ? Check::pass('schema-ledger', "{$applied} of {$onDisk} migrations recorded")
                : Check::fail(
                    'schema-ledger',
                    "{$applied} of {$onDisk} migrations recorded — some have never run",
                    'php database/migrate.php'
                );
        }

        $checks[] = self::foreignKeys($pdo, $dbName);

        // function_exists rather than reading the file: if config.php defined
        // it, it is defined in this very process.
        $checks[] = function_exists('initializeDatabase')
            ? Check::fail(
                'schema-no-runtime-ddl',
                'initializeDatabase() is defined — DDL runs on every request',
                'refresh includes/config.php from config.php.example'
            )
            : Check::pass('schema-no-runtime-ddl', 'no DDL runs at request time');

        return $checks;
    }

    private static function foreignKeys(PDO $pdo, string $dbName): Check
    {
        $placeholders = implode(',', array_fill(0, count(self::USER_SCOPED), '?'));

        $stmt = $pdo->prepare(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE CONSTRAINT_SCHEMA = ?
               AND REFERENCED_TABLE_NAME = 'users'
               AND COLUMN_NAME = 'user_id'
               AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$dbName], self::USER_SCOPED));

        $have = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        // Only require a key for tables that actually exist in this database.
        $existing = $pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ({$placeholders})"
        );
        $existing->execute(array_merge([$dbName], self::USER_SCOPED));
        $present = $existing->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $missing = array_values(array_diff($present, $have));

        if ($missing === []) {
            return Check::pass(
                'schema-user-fks',
                count($have) . ' user_id foreign keys present'
            );
        }

        return Check::fail(
            'schema-user-fks',
            'missing user_id foreign keys on: ' . implode(', ', $missing)
                . ' — ON DELETE CASCADE does not exist for those tables',
            'php database/migrate.php'
        );
    }
}
