<?php
/**
 * Migration script to add release_date field to games table
 * Run this once to add the new column
 */

require_once __DIR__ . '/includes/config.php';

echo "Adding release_date field to games table...\n";

try {
    // Check if column already exists
    $stmt = $pdo->query("PRAGMA table_info(games)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasReleaseDate = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'release_date') {
            $hasReleaseDate = true;
            break;
        }
    }
    
    if ($hasReleaseDate) {
        echo "✓ release_date column already exists.\n";
    } else {
        // Add the column
        $pdo->exec("ALTER TABLE games ADD COLUMN release_date DATE");
        echo "✓ release_date column added successfully!\n";
    }
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}

echo "\nMigration complete!\n";

