<?php
/**
 * Migration runner.
 *
 * Each file in database/migrations/ returns a closure that takes a PDO
 * and applies its changes idempotently. Migrations are applied in
 * filename order. There's no migration ledger because every migration
 * is required to be idempotent — re-running is a no-op.
 *
 * Usage:
 *   php database/migrate.php
 */

require_once __DIR__ . '/../includes/config.php';

if (!isset($pdo)) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    echo "Applying $name... ";
    $migration = require $file;
    if (!is_callable($migration)) {
        fwrite(STDERR, "ERROR: $name did not return a callable\n");
        exit(1);
    }
    try {
        $migration($pdo);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations applied.\n";
