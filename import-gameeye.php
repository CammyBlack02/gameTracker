<?php
/**
 * Import GameEye CSV backup into Game Tracker
 * Preserves formatting and handles all field mappings
 * 
 * Run from command line: php import-gameeye.php
 * Or access via web: http://localhost:8000/import-gameeye.php
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// Optional: Add authentication check for web access
// require_once __DIR__ . '/includes/auth-check.php';

echo "==========================================\n";
echo "GameEye CSV Import Script\n";
echo "==========================================\n\n";

$csvFile = __DIR__ . '/11_9_2025_ge_collection.csv';

if (!file_exists($csvFile)) {
    die("Error: CSV file not found: $csvFile\n");
}

// Read CSV file
$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Error: Could not open CSV file\n");
}

// Read header row
$headers = fgetcsv($handle);
if (!$headers) {
    die("Error: Could not read CSV headers\n");
}

// Map CSV column indices
$columnMap = [];
foreach ($headers as $index => $header) {
    $columnMap[trim($header)] = $index;
}

echo "CSV columns found: " . implode(', ', array_keys($columnMap)) . "\n\n";

// Platform name normalization (GameEye -> Game Tracker)
$platformMap = [
    'Sony PlayStation 4' => 'PlayStation 4',
    'Sony PlayStation 3' => 'PlayStation 3',
    'Sony PlayStation 2' => 'PlayStation 2',
    'Sony PlayStation' => 'PlayStation',
    'Sony PlayStation 5' => 'PlayStation 5',
    'Sony PS Vita' => 'PS Vita',
    'Microsoft Xbox One' => 'Xbox One',
    'Microsoft Xbox 360' => 'Xbox 360',
    'Microsoft Xbox' => 'Xbox',
    'Nintendo Switch' => 'Nintendo Switch',
    'Nintendo Wii U' => 'Wii U',
    'Nintendo Wii' => 'Wii',
    'Nintendo GameCube' => 'GameCube',
    'Nintendo 3DS' => 'Nintendo 3DS',
    'Nintendo DS' => 'Nintendo DS',
    'Nintendo Game Boy Advance' => 'Game Boy Advance',
    'Nintendo Game Boy Color' => 'Game Boy Color',
    'Nintendo Game Boy' => 'Game Boy',
    'Nintendo 64' => 'Nintendo 64',
    'SNES/Super Famicom' => 'SNES',
    'Sega Genesis/Mega Drive' => 'Mega Drive',
    'Sega Dreamcast' => 'Dreamcast',
    'PC' => 'PC',
    'Steam' => 'Steam',
    'Windows' => 'PC'
];

$imported = 0;
$importedItems = 0;
$skipped = 0;
$errors = 0;
$duplicates = 0;
$itemDuplicates = 0;

echo "Starting import...\n\n";

// Process each row
while (($row = fgetcsv($handle)) !== false) {
    // Get values by column name
    $getValue = function($colName) use ($row, $columnMap) {
        $index = $columnMap[$colName] ?? null;
        return $index !== null && isset($row[$index]) ? trim($row[$index]) : '';
    };
    
    $category = $getValue('Category');
    
    // Handle consoles and accessories
    if (in_array($category, ['Systems', 'Controllers', 'Game Accessories', 'Toys To Life'])) {
        // Import as item
        $title = $getValue('Title');
        $platformRaw = $getValue('Platform');
        
        if (empty($title)) {
            $errors++;
            continue;
        }
        
        // Normalize platform name
        $platform = $platformMap[$platformRaw] ?? $platformRaw;
        
        // Check for duplicates
        $checkStmt = $pdo->prepare("SELECT id FROM items WHERE title = ? AND category = ?");
        $checkStmt->execute([$title, $category]);
        if ($checkStmt->fetch()) {
            $itemDuplicates++;
            continue;
        }
        
        // Handle prices
        $pricePaid = $getValue('PricePaid');
        $pricePaid = ($pricePaid === '-1.0' || $pricePaid === '' || $pricePaid === '?') ? null : (float)$pricePaid;
        
        $priceCharting = $getValue('YourPrice');
        if ($priceCharting === '-1.0' || $priceCharting === '' || $priceCharting === '?') {
            $priceCharting = $getValue('PriceCIB');
        }
        $priceCharting = ($priceCharting === '-1.0' || $priceCharting === '' || $priceCharting === '?') ? null : (float)$priceCharting;
        
        // Condition
        $condition = $getValue('ItemCondition');
        if ($condition === '?' || $condition === '') {
            $condition = null;
        }
        
        // Description (from Notes)
        $description = $getValue('Notes');
        if (empty($description)) {
            $description = null;
        }
        
        // Insert into items table
        try {
            $stmt = $pdo->prepare("
                INSERT INTO items (
                    title, platform, category, description, condition,
                    price_paid, pricecharting_price, notes, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");
            
            $createdAt = $getValue('CreatedAt');
            $timestamp = null;
            if (!empty($createdAt)) {
                $parsed = strtotime($createdAt);
                if ($parsed !== false) {
                    $timestamp = date('Y-m-d H:i:s', $parsed);
                }
            }
            
            $stmt->execute([
                $title,
                $platform,
                $category,
                $description,
                $condition,
                $pricePaid,
                $priceCharting,
                $description, // Use description as notes too
                $timestamp ?? date('Y-m-d H:i:s')
            ]);
            
            $importedItems++;
            echo "  ✓ Imported item: $title ($category)\n";
            
        } catch (Exception $e) {
            $errors++;
            echo "  ✗ Error importing item $title: " . $e->getMessage() . "\n";
        }
        
        continue;
    }
    
    // Skip non-game items that aren't consoles/accessories
    if ($category !== 'Games') {
        $skipped++;
        continue;
    }
    
    // Skip wishlist items (only import owned)
    $recordType = $getValue('UserRecordType');
    if ($recordType !== 'Owned') {
        $skipped++;
        continue;
    }
    
    // Get basic info
    $title = $getValue('Title');
    $platformRaw = $getValue('Platform');
    
    if (empty($title) || empty($platformRaw)) {
        $errors++;
        echo "  ⚠ Skipping row with missing title/platform\n";
        continue;
    }
    
    // Normalize platform name
    $platform = $platformMap[$platformRaw] ?? $platformRaw;
    
    // Extract special edition from title
    $specialEdition = null;
    if (preg_match('/\[([^\]]+Edition[^\]]*)\]/i', $title, $matches)) {
        $specialEdition = $matches[1];
    } elseif (preg_match('/\(([^\)]+Edition[^\)]*)\)/i', $title, $matches)) {
        $specialEdition = $matches[1];
    }
    
    // Clean title (remove edition info from title if in special_edition)
    $cleanTitle = $title;
    if ($specialEdition) {
        $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $cleanTitle);
        $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
        $cleanTitle = trim($cleanTitle);
    }
    
    // Check for duplicates (title + platform) - use clean title
    $checkStmt = $pdo->prepare("SELECT id FROM games WHERE title = ? AND platform = ?");
    $checkStmt->execute([$cleanTitle, $platform]);
    if ($checkStmt->fetch()) {
        $duplicates++;
        echo "  ⊗ Duplicate: $cleanTitle ($platform)\n";
        continue;
    }
    
    // Determine if physical or digital
    $ownership = $getValue('Ownership');
    $releaseType = $getValue('ReleaseType');
    $isPhysical = 1; // Default to physical
    
    if ($releaseType === 'Digital' || 
        in_array(strtolower($ownership), ['digital', 'download'])) {
        $isPhysical = 0;
    }
    
    // Handle prices (-1.0 means N/A in GameEye)
    $pricePaid = $getValue('PricePaid');
    $pricePaid = ($pricePaid === '-1.0' || $pricePaid === '' || $pricePaid === '?') ? null : (float)$pricePaid;
    
    $priceCharting = $getValue('YourPrice');
    if ($priceCharting === '-1.0' || $priceCharting === '' || $priceCharting === '?') {
        // Try PriceCIB as fallback
        $priceCharting = $getValue('PriceCIB');
    }
    $priceCharting = ($priceCharting === '-1.0' || $priceCharting === '' || $priceCharting === '?') ? null : (float)$priceCharting;
    
    // Condition
    $condition = $getValue('ItemCondition');
    if ($condition === '?' || $condition === '') {
        $condition = null;
    }
    
    // Description (from Notes)
    $description = $getValue('Notes');
    if (empty($description)) {
        $description = null;
    }
    
    // Extract series from title if possible (e.g., "Call of Duty: Modern Warfare")
    $series = null;
    $seriesPatterns = [
        'Call of Duty',
        'FIFA',
        'Assassin\'s Creed',
        'Grand Theft Auto',
        'Halo',
        'Gears of War',
        'Uncharted',
        'God of War',
        'Spider-Man',
        'Batman',
        'Star Wars',
        'LEGO',
        'Tony Hawk',
        'Need for Speed',
        'Forza',
        'Mario',
        'Zelda',
        'Pokémon',
        'Sonic',
        'Resident Evil',
        'Silent Hill',
        'Dark Souls',
        'Elder Scrolls',
        'Fallout',
        'BioShock',
        'Borderlands',
        'Far Cry',
        'Watch Dogs',
        'The Sims',
        'SimCity'
    ];
    
    foreach ($seriesPatterns as $pattern) {
        if (stripos($title, $pattern) !== false) {
            $series = $pattern;
            break;
        }
    }
    
    // Insert into database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO games (
                title, platform, genre, description, series, special_edition,
                condition, review, star_rating, metacritic_rating, played,
                price_paid, pricecharting_price, is_physical,
                front_cover_image, back_cover_image, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");
        
        $createdAt = $getValue('CreatedAt');
        // Try to parse date, or use current timestamp
        $timestamp = null;
        if (!empty($createdAt)) {
            $parsed = strtotime($createdAt);
            if ($parsed !== false) {
                $timestamp = date('Y-m-d H:i:s', $parsed);
            }
        }
        
        $stmt->execute([
            $cleanTitle,
            $platform,
            null, // genre - not in GameEye CSV
            $description,
            $series,
            $specialEdition,
            $condition,
            null, // review - not in GameEye CSV
            null, // star_rating - not in GameEye CSV
            null, // metacritic_rating - not in GameEye CSV
            0, // played - default to not played
            $pricePaid,
            $priceCharting,
            $isPhysical,
            null, // front_cover_image - will be downloaded later
            null, // back_cover_image - will be downloaded later
            $timestamp ?? date('Y-m-d H:i:s')
        ]);
        
        $imported++;
        echo "  ✓ Imported: $cleanTitle ($platform)\n";
        
    } catch (Exception $e) {
        $errors++;
        echo "  ✗ Error importing $title: " . $e->getMessage() . "\n";
    }
}

fclose($handle);

echo "\n==========================================\n";
echo "Import Summary:\n";
echo "  ✓ Games Imported:  $imported\n";
echo "  ✓ Items Imported:  $importedItems\n";
echo "  ⊗ Game Duplicates: $duplicates\n";
echo "  ⊗ Item Duplicates: $itemDuplicates\n";
echo "  ⊘ Skipped:   $skipped (non-games/wishlist)\n";
echo "  ✗ Errors:    $errors\n";
echo "==========================================\n";
echo "\nImport complete! You can now run bulk-download-covers.php to fetch cover images.\n";

