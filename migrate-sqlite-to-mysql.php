<?php
/**
 * Migration script to transfer data from SQLite to MySQL
 * Run from command line: php migrate-sqlite-to-mysql.php
 */

// Suppress session warnings for CLI
if (php_sapi_name() === 'cli') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL & ~E_WARNING);
}

// SQLite database path (on your Mac)
$sqlitePath = __DIR__ . '/database/games.db';

if (!file_exists($sqlitePath)) {
    die("SQLite database not found at: $sqlitePath\n");
}

echo "==========================================\n";
echo "SQLite to MySQL Migration\n";
echo "==========================================\n\n";

// Connect to SQLite
try {
    $sqlite = new PDO('sqlite:' . $sqlitePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to SQLite database\n";
} catch (PDOException $e) {
    die("Failed to connect to SQLite: " . $e->getMessage() . "\n");
}

// Connect to MySQL (using the config)
require_once __DIR__ . '/includes/config.php';

if (!isset($pdo)) {
    die("Failed to connect to MySQL database\n");
}

echo "✓ Connected to MySQL database\n\n";

// Start transaction
$pdo->beginTransaction();

try {
    $totalGames = 0;
    $totalItems = 0;
    $totalUsers = 0;
    $totalSettings = 0;
    $totalGameImages = 0;
    $totalItemImages = 0;
    $totalCompletions = 0;
    
    // Migrate users
    echo "Migrating users...\n";
    $users = $sqlite->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $userMap = []; // Map old IDs to new IDs
    
    // First, get existing users in MySQL to map IDs
    $existingUsers = $pdo->query("SELECT id, username FROM users")->fetchAll(PDO::FETCH_ASSOC);
    $usernameToId = [];
    foreach ($existingUsers as $existing) {
        $usernameToId[$existing['username']] = $existing['id'];
    }
    
    foreach ($users as $user) {
        $oldId = $user['id'];
        $username = $user['username'];
        
        // Check if user already exists
        if (isset($usernameToId[$username])) {
            $userMap[$oldId] = $usernameToId[$username];
            echo "  - Skipped existing user: $username\n";
            continue;
        }
        
        unset($user['id']);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$user['username'], $user['password_hash'], $user['created_at']]);
        $newId = $pdo->lastInsertId();
        $userMap[$oldId] = $newId;
        $usernameToId[$username] = $newId;
        $totalUsers++;
    }
    echo "  ✓ Migrated $totalUsers users\n\n";
    
    // Migrate games
    echo "Migrating games...\n";
    $games = $sqlite->query("SELECT * FROM games")->fetchAll(PDO::FETCH_ASSOC);
    $gameMap = []; // Map old IDs to new IDs
    foreach ($games as $game) {
        $oldId = $game['id'];
        unset($game['id']);
        
        $stmt = $pdo->prepare("
            INSERT INTO games (
                title, platform, genre, description, series, special_edition,
                `condition`, review, star_rating, metacritic_rating, played,
                price_paid, pricecharting_price, is_physical, digital_store,
                front_cover_image, back_cover_image, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $game['title'],
            $game['platform'],
            $game['genre'] ?? null,
            $game['description'] ?? null,
            $game['series'] ?? null,
            $game['special_edition'] ?? null,
            $game['condition'] ?? null,
            $game['review'] ?? null,
            $game['star_rating'] ?? null,
            $game['metacritic_rating'] ?? null,
            $game['played'] ?? 0,
            $game['price_paid'] ?? null,
            $game['pricecharting_price'] ?? null,
            $game['is_physical'] ?? 1,
            $game['digital_store'] ?? null,
            $game['front_cover_image'] ?? null,
            $game['back_cover_image'] ?? null,
            $game['created_at'] ?? date('Y-m-d H:i:s'),
            $game['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        $newId = $pdo->lastInsertId();
        $gameMap[$oldId] = $newId;
        $totalGames++;
        
        if ($totalGames % 100 === 0) {
            echo "  ... Migrated $totalGames games\n";
        }
    }
    echo "  ✓ Migrated $totalGames games\n\n";
    
    // Migrate items
    echo "Migrating items...\n";
    $items = $sqlite->query("SELECT * FROM items")->fetchAll(PDO::FETCH_ASSOC);
    $itemMap = []; // Map old IDs to new IDs
    foreach ($items as $item) {
        $oldId = $item['id'];
        unset($item['id']);
        
        $stmt = $pdo->prepare("
            INSERT INTO items (
                title, platform, category, description, `condition`,
                price_paid, pricecharting_price, front_image, back_image,
                notes, quantity, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $item['title'],
            $item['platform'] ?? null,
            $item['category'],
            $item['description'] ?? null,
            $item['condition'] ?? null,
            $item['price_paid'] ?? null,
            $item['pricecharting_price'] ?? null,
            $item['front_image'] ?? null,
            $item['back_image'] ?? null,
            $item['notes'] ?? null,
            $item['quantity'] ?? 1,
            $item['created_at'] ?? date('Y-m-d H:i:s'),
            $item['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        $newId = $pdo->lastInsertId();
        $itemMap[$oldId] = $newId;
        $totalItems++;
    }
    echo "  ✓ Migrated $totalItems items\n\n";
    
    // Migrate game_images
    echo "Migrating game images...\n";
    $gameImages = $sqlite->query("SELECT * FROM game_images")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($gameImages as $img) {
        if (!isset($gameMap[$img['game_id']])) {
            continue; // Skip if game doesn't exist
        }
        
        $stmt = $pdo->prepare("INSERT INTO game_images (game_id, image_path, uploaded_at) VALUES (?, ?, ?)");
        $stmt->execute([
            $gameMap[$img['game_id']],
            $img['image_path'],
            $img['uploaded_at'] ?? date('Y-m-d H:i:s')
        ]);
        $totalGameImages++;
    }
    echo "  ✓ Migrated $totalGameImages game images\n\n";
    
    // Migrate item_images
    echo "Migrating item images...\n";
    $itemImages = $sqlite->query("SELECT * FROM item_images")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($itemImages as $img) {
        if (!isset($itemMap[$img['item_id']])) {
            continue; // Skip if item doesn't exist
        }
        
        $stmt = $pdo->prepare("INSERT INTO item_images (item_id, image_path, uploaded_at) VALUES (?, ?, ?)");
        $stmt->execute([
            $itemMap[$img['item_id']],
            $img['image_path'],
            $img['uploaded_at'] ?? date('Y-m-d H:i:s')
        ]);
        $totalItemImages++;
    }
    echo "  ✓ Migrated $totalItemImages item images\n\n";
    
    // Migrate settings
    echo "Migrating settings...\n";
    $settings = $sqlite->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings as $setting) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)");
        $stmt->execute([
            $setting['setting_key'],
            $setting['setting_value'] ?? null,
            $setting['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        $totalSettings++;
    }
    echo "  ✓ Migrated $totalSettings settings\n\n";
    
    // Migrate game_completions
    echo "Migrating game completions...\n";
    $completions = $sqlite->query("SELECT * FROM game_completions")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($completions as $completion) {
        $gameId = null;
        if ($completion['game_id'] && isset($gameMap[$completion['game_id']])) {
            $gameId = $gameMap[$completion['game_id']];
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO game_completions (
                game_id, title, platform, time_taken, date_started, date_completed,
                completion_year, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $gameId,
            $completion['title'],
            $completion['platform'] ?? null,
            $completion['time_taken'] ?? null,
            $completion['date_started'] ?? null,
            $completion['date_completed'] ?? null,
            $completion['completion_year'] ?? null,
            $completion['notes'] ?? null,
            $completion['created_at'] ?? date('Y-m-d H:i:s'),
            $completion['updated_at'] ?? date('Y-m-d H:i:s')
        ]);
        $totalCompletions++;
    }
    echo "  ✓ Migrated $totalCompletions game completions\n\n";
    
    // Commit transaction
    $pdo->commit();
    
    echo "==========================================\n";
    echo "Migration Complete!\n";
    echo "==========================================\n";
    echo "  Users:         $totalUsers\n";
    echo "  Games:         $totalGames\n";
    echo "  Items:         $totalItems\n";
    echo "  Game Images:   $totalGameImages\n";
    echo "  Item Images:   $totalItemImages\n";
    echo "  Settings:      $totalSettings\n";
    echo "  Completions:   $totalCompletions\n";
    echo "==========================================\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n✗ Error during migration: " . $e->getMessage() . "\n";
    echo "Rolled back all changes.\n";
    exit(1);
}

