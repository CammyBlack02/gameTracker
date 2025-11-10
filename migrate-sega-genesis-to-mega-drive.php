<?php
/**
 * Migration script to update "Sega Genesis" to "Mega Drive" (UK/PAL naming)
 * Run from command line: php migrate-sega-genesis-to-mega-drive.php
 */

require_once __DIR__ . '/includes/config.php';

echo "==========================================\n";
echo "Sega Genesis -> Mega Drive Migration\n";
echo "==========================================\n\n";

// Count games with "Sega Genesis"
$stmt = $pdo->query("SELECT COUNT(*) as count FROM games WHERE platform = 'Sega Genesis'");
$count = $stmt->fetch()['count'];

echo "Found $count games with 'Sega Genesis' platform\n\n";

if ($count === 0) {
    echo "No games to migrate!\n";
    exit;
}

// Update all games
$updateStmt = $pdo->prepare("UPDATE games SET platform = 'Mega Drive' WHERE platform = 'Sega Genesis'");
$updateStmt->execute();

$affected = $updateStmt->rowCount();

echo "✓ Updated $affected games from 'Sega Genesis' to 'Mega Drive'\n";
echo "\nMigration complete!\n";

