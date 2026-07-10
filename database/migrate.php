<?php
/**
 * Migration runner.
 *
 * Each file in database/migrations/ returns a closure that takes a PDO
 * and applies its changes. Migrations run in filename order.
 *
 * The schema_migrations table records which migrations have been applied
 * so re-running the runner is a no-op. Migrations should still be written
 * idempotently by convention (CREATE TABLE IF NOT EXISTS, ALTER TABLE in
 * try/catch), but the ledger is the source of truth.
 *
 * Usage:
 *   php database/migrate.php
 */

require_once __DIR__ . '/../includes/config.php';

if (!isset($pdo)) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

// Bootstrap the ledger table if it doesn't exist. This is the one place
// we do DDL outside a numbered migration — the ledger has to exist
// before we can check it. The 004 migration re-runs the same CREATE
// (idempotent) so a fresh install still records "004 applied."
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(255) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$appliedStmt = $pdo->query("SELECT name FROM schema_migrations");
$applied = array_flip($appliedStmt->fetchAll(PDO::FETCH_COLUMN));

$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.php');
sort($files);

$recordStmt = $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)");

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "Skipping $name (already applied)\n";
        continue;
    }
    echo "Applying $name... ";
    $migration = require $file;
    if (!is_callable($migration)) {
        fwrite(STDERR, "ERROR: $name did not return a callable\n");
        exit(1);
    }
    try {
        $migration($pdo);
        $recordStmt->execute([$name]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations applied.\n";
