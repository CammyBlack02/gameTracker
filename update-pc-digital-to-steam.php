<?php
/**
 * Update all PC digital games to have "Steam" as their digital_store
 * Run from command line: php update-pc-digital-to-steam.php
 */

require_once __DIR__ . '/includes/config.php';

// For CLI scripts, we don't need session authentication
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['user_id'])) {
        die("Please log in first.\n");
    }
}

echo "==========================================\n";
echo "Update PC Digital Games to Steam\n";
echo "==========================================\n\n";

// Count PC digital games (platform = 'PC' and is_physical = 0)
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM games WHERE UPPER(TRIM(platform)) = 'PC' AND is_physical = 0");
$stmt->execute();
$count = $stmt->fetch()['count'];

echo "Found $count PC digital games\n\n";

if ($count === 0) {
    echo "No PC digital games to update!\n";
    exit;
}

// Show games that will be updated
$stmt = $pdo->prepare("
    SELECT id, title, platform, digital_store, is_physical 
    FROM games 
    WHERE UPPER(TRIM(platform)) = 'PC' AND is_physical = 0
    ORDER BY title
");
$stmt->execute();
$games = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Games to be updated:\n";
foreach ($games as $game) {
    $currentStore = $game['digital_store'] ?: '(none)';
    echo "  - {$game['title']} (Current: $currentStore)\n";
}

echo "\n";

// Ask for confirmation (only in CLI)
if (php_sapi_name() === 'cli') {
    echo "This will set digital_store to 'Steam' for all $count games.\n";
    echo "Press Enter to continue, or Ctrl+C to cancel...\n";
    fgets(STDIN);
}

// Update all PC digital games to have digital_store = 'Steam'
$updateStmt = $pdo->prepare("
    UPDATE games 
    SET digital_store = 'Steam' 
    WHERE UPPER(TRIM(platform)) = 'PC' AND is_physical = 0
");
$updateStmt->execute();

$affected = $updateStmt->rowCount();

echo "\n✓ Updated $affected games to have digital_store = 'Steam'\n";
echo "\nUpdate complete!\n";

