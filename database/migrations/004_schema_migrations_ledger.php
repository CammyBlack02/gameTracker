<?php
/**
 * Migration 004: schema_migrations ledger table.
 *
 * Records which migrations have been applied. The runner
 * (database/migrate.php) uses this to skip already-applied migrations
 * rather than relying on the "every migration must be idempotent"
 * convention — which works until it doesn't.
 *
 * The runner also creates this table itself as a bootstrap step (see
 * database/migrate.php) — the migration here exists so that a fresh
 * install's ledger reflects that "004 has been applied," matching the
 * convention that every table has a migration file.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
