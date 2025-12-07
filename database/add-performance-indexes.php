<?php
/**
 * Performance Optimization Migration Script
 * Adds missing database indexes to improve query performance
 * 
 * Run this script once to add indexes to your existing database:
 * php database/add-performance-indexes.php
 */

require_once __DIR__ . '/../includes/config.php';

echo "Adding performance indexes...\n\n";

try {
    // Add index on game_images.game_id (critical for JOIN queries)
    try {
        $pdo->exec("ALTER TABLE game_images ADD INDEX idx_game_id (game_id)");
        echo "✓ Added index idx_game_id on game_images.game_id\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⊘ Index idx_game_id on game_images.game_id already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add index on games.platform (used in findMatchingGame)
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_platform (platform)");
        echo "✓ Added index idx_platform on games.platform\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⊘ Index idx_platform on games.platform already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add index on games.created_at (used for ORDER BY in listGames)
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_created_at (created_at)");
        echo "✓ Added index idx_created_at on games.created_at\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⊘ Index idx_created_at on games.created_at already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add composite index on games.platform and user_id (optimizes findMatchingGame with user filtering)
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_platform_user_id (platform, user_id)");
        echo "✓ Added composite index idx_platform_user_id on games(platform, user_id)\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
            echo "⊘ Index idx_platform_user_id on games(platform, user_id) already exists\n";
        } else {
            throw $e;
        }
    }
    
    echo "\n✓ Performance indexes migration completed successfully!\n";
    echo "\nPerformance improvements:\n";
    echo "  - Faster game listing queries (indexed JOINs)\n";
    echo "  - Faster game matching (indexed platform searches)\n";
    echo "  - Faster sorting by creation date\n";
    
} catch (PDOException $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}

