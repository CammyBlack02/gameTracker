<?php
/**
 * Migration script to convert single-user database to multi-user
 * Assigns all existing data to the admin user (user_id = 1)
 * Sets admin user's role to 'admin'
 * 
 * Run from command line: php migrate-to-multiuser.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Suppress session warnings for CLI
if (php_sapi_name() === 'cli') {
    @session_start();
}

echo "==========================================\n";
echo "Multi-User Migration Script\n";
echo "==========================================\n\n";

try {
    // Get admin user ID (should be user_id = 1)
    $stmt = $pdo->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
    $adminUser = $stmt->fetch();
    
    if (!$adminUser) {
        die("Error: Admin user not found. Please ensure admin user exists.\n");
    }
    
    $adminUserId = $adminUser['id'];
    echo "Found admin user with ID: $adminUserId\n\n";
    
    // Set admin user's role to 'admin'
    echo "Setting admin user role...\n";
    try {
        $pdo->exec("UPDATE users SET role = 'admin' WHERE id = $adminUserId");
        echo "✓ Admin role set\n\n";
    } catch (PDOException $e) {
        echo "⚠ Could not set admin role (may already be set): " . $e->getMessage() . "\n\n";
    }
    
    // Disable foreign key checks temporarily for migration
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Migrate games
    echo "Migrating games...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM games WHERE user_id IS NULL OR user_id = 0");
        $result = $stmt->fetch();
        $gamesToMigrate = $result['count'];
        
        if ($gamesToMigrate > 0) {
            $pdo->exec("UPDATE games SET user_id = $adminUserId WHERE user_id IS NULL OR user_id = 0");
            echo "✓ Migrated $gamesToMigrate games to admin user\n";
        } else {
            echo "✓ No games to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating games: " . $e->getMessage() . "\n";
    }
    
    // Migrate items
    echo "\nMigrating items...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE user_id IS NULL OR user_id = 0");
        $result = $stmt->fetch();
        $itemsToMigrate = $result['count'];
        
        if ($itemsToMigrate > 0) {
            $pdo->exec("UPDATE items SET user_id = $adminUserId WHERE user_id IS NULL OR user_id = 0");
            echo "✓ Migrated $itemsToMigrate items to admin user\n";
        } else {
            echo "✓ No items to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating items: " . $e->getMessage() . "\n";
    }
    
    // Migrate game_completions
    echo "\nMigrating game completions...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM game_completions WHERE user_id IS NULL OR user_id = 0");
        $result = $stmt->fetch();
        $completionsToMigrate = $result['count'];
        
        if ($completionsToMigrate > 0) {
            $pdo->exec("UPDATE game_completions SET user_id = $adminUserId WHERE user_id IS NULL OR user_id = 0");
            echo "✓ Migrated $completionsToMigrate completions to admin user\n";
        } else {
            echo "✓ No completions to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating completions: " . $e->getMessage() . "\n";
    }
    
    // Migrate game_images (via game ownership)
    echo "\nMigrating game images...\n";
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM game_images gi
            LEFT JOIN games g ON gi.game_id = g.id
            WHERE gi.user_id IS NULL OR gi.user_id = 0
        ");
        $result = $stmt->fetch();
        $imagesToMigrate = $result['count'];
        
        if ($imagesToMigrate > 0) {
            $pdo->exec("
                UPDATE game_images gi
                INNER JOIN games g ON gi.game_id = g.id
                SET gi.user_id = g.user_id
                WHERE gi.user_id IS NULL OR gi.user_id = 0
            ");
            echo "✓ Migrated $imagesToMigrate game images to admin user\n";
        } else {
            echo "✓ No game images to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating game images: " . $e->getMessage() . "\n";
    }
    
    // Migrate item_images (via item ownership)
    echo "\nMigrating item images...\n";
    try {
        $stmt = $pdo->query("
            SELECT COUNT(*) as count 
            FROM item_images ii
            LEFT JOIN items i ON ii.item_id = i.id
            WHERE ii.user_id IS NULL OR ii.user_id = 0
        ");
        $result = $stmt->fetch();
        $imagesToMigrate = $result['count'];
        
        if ($imagesToMigrate > 0) {
            $pdo->exec("
                UPDATE item_images ii
                INNER JOIN items i ON ii.item_id = i.id
                SET ii.user_id = i.user_id
                WHERE ii.user_id IS NULL OR ii.user_id = 0
            ");
            echo "✓ Migrated $imagesToMigrate item images to admin user\n";
        } else {
            echo "✓ No item images to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating item images: " . $e->getMessage() . "\n";
    }
    
    // Migrate settings
    echo "\nMigrating settings...\n";
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM settings WHERE user_id IS NULL OR user_id = 0");
        $result = $stmt->fetch();
        $settingsToMigrate = $result['count'];
        
        if ($settingsToMigrate > 0) {
            $pdo->exec("UPDATE settings SET user_id = $adminUserId WHERE user_id IS NULL OR user_id = 0");
            echo "✓ Migrated $settingsToMigrate settings to admin user\n";
        } else {
            echo "✓ No settings to migrate (all already have user_id)\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Error migrating settings: " . $e->getMessage() . "\n";
    }
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Verify migration
    echo "\n==========================================\n";
    echo "Verification\n";
    echo "==========================================\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM games WHERE user_id IS NULL OR user_id = 0");
    $result = $stmt->fetch();
    $orphanedGames = $result['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE user_id IS NULL OR user_id = 0");
    $result = $stmt->fetch();
    $orphanedItems = $result['count'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM game_completions WHERE user_id IS NULL OR user_id = 0");
    $result = $stmt->fetch();
    $orphanedCompletions = $result['count'];
    
    if ($orphanedGames == 0 && $orphanedItems == 0 && $orphanedCompletions == 0) {
        echo "✓ Migration successful! All data assigned to admin user.\n";
    } else {
        echo "⚠ Warning: Some data still has no user_id:\n";
        if ($orphanedGames > 0) echo "  - $orphanedGames games\n";
        if ($orphanedItems > 0) echo "  - $orphanedItems items\n";
        if ($orphanedCompletions > 0) echo "  - $orphanedCompletions completions\n";
    }
    
    // Show summary
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM games WHERE user_id = $adminUserId");
    $result = $stmt->fetch();
    echo "\nAdmin user now has:\n";
    echo "  - {$result['count']} games\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM items WHERE user_id = $adminUserId");
    $result = $stmt->fetch();
    echo "  - {$result['count']} items\n";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM game_completions WHERE user_id = $adminUserId");
    $result = $stmt->fetch();
    echo "  - {$result['count']} completions\n";
    
    echo "\n==========================================\n";
    echo "Migration Complete!\n";
    echo "==========================================\n";
    
} catch (PDOException $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

