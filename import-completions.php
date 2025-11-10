<?php
/**
 * Import game completions from CSV file
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// For CLI scripts, we don't need session authentication
// Just ensure we have database access
if (php_sapi_name() !== 'cli') {
    // If running from web, check authentication
    if (!isset($_SESSION['user_id'])) {
        die("Please log in first.\n");
    }
}

$csvFile = __DIR__ . '/Games Completed 2025.csv';

if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile\n");
}

echo "Importing game completions from CSV...\n\n";

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Failed to open CSV file.\n");
}

// Read header
$header = fgetcsv($handle);
if (!$header) {
    die("Failed to read CSV header.\n");
}

// Expected columns: Num, Name, Time Taken, Date Started, Date Completed, Platform
$columnMap = [];
foreach ($header as $index => $col) {
    $col = trim($col);
    if (stripos($col, 'num') !== false) $columnMap['num'] = $index;
    if (stripos($col, 'name') !== false) $columnMap['name'] = $index;
    if (stripos($col, 'time') !== false && stripos($col, 'taken') !== false) $columnMap['time'] = $index;
    if (stripos($col, 'date') !== false && stripos($col, 'start') !== false) $columnMap['date_started'] = $index;
    if (stripos($col, 'date') !== false && stripos($col, 'complete') !== false) $columnMap['date_completed'] = $index;
    if (stripos($col, 'platform') !== false) $columnMap['platform'] = $index;
}

echo "Column mapping:\n";
print_r($columnMap);
echo "\n";

$imported = 0;
$errors = 0;
$linked = 0;
$skipped = 0;

// Function to parse date from various formats (UK format support - dd/mm priority)
function parseDate($dateStr) {
    if (empty($dateStr) || trim($dateStr) === '') {
        return null;
    }
    
    $dateStr = trim($dateStr);
    
    // Handle UK format dd/mm/yyyy specifically (day/month/year)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];
        
        // Validate date (UK format: day/month/year)
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Handle UK format dd/mm/yy (2-digit year, assume 2000s)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = 2000 + (int)$matches[3];
        
        // Validate date (UK format: day/month/year)
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Handle UK format dd/mm (no year, use current year or 2025 for 2025 completions)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = 2025; // Default to 2025 for this import
        
        // Validate date (UK format: day/month/year)
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Handle UK format with day name: "Friday, 3 January 2025" or "Sunday, 5 January 2025"
    if (preg_match('/^\w+,\s*(\d{1,2})\s+(\w+)\s+(\d{4})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $monthName = $matches[2];
        $year = (int)$matches[3];
        
        $monthMap = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12
        ];
        
        $month = $monthMap[strtolower($monthName)] ?? null;
        if ($month && checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Try UK format first (d/m/Y) before other formats
    $date = DateTime::createFromFormat('d/m/Y', $dateStr);
    if ($date !== false) {
        return $date->format('Y-m-d');
    }
    
    $date = DateTime::createFromFormat('d/m/y', $dateStr);
    if ($date !== false) {
        return $date->format('Y-m-d');
    }
    
    $date = DateTime::createFromFormat('d/m', $dateStr);
    if ($date !== false) {
        // Use 2025 as default year
        $date->setDate(2025, $date->format('m'), $date->format('d'));
        return $date->format('Y-m-d');
    }
    
    // Try other formats
    $formats = [
        'd-m-Y',        // 26-03-2025
        'Y-m-d',        // ISO format
        'Y/m/d',        // ISO format with slashes
        'l, j F Y',     // "Friday, 3 January 2025"
        'j F Y',        // "3 January 2025"
    ];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateStr);
        if ($date !== false) {
            return $date->format('Y-m-d');
        }
    }
    
    // Last resort: try strtotime but be careful - it might interpret as US format
    // Only use if we haven't matched anything else
    $timestamp = strtotime($dateStr);
    if ($timestamp !== false) {
        // Double-check: if it looks like dd/mm, don't trust strtotime
        if (preg_match('/^\d{1,2}\/\d{1,2}/', $dateStr)) {
            // Already tried UK format above, so skip strtotime for slash dates
            return null;
        }
        return date('Y-m-d', $timestamp);
    }
    
    return null;
}

// Function to normalize platform name
function normalizePlatform($platform) {
    if (empty($platform)) return null;
    
    $platform = trim($platform);
    
    // Platform mappings
    $mappings = [
        'PC (Steam)' => 'PC',
        'PC (EA Play)' => 'PC',
        'PC (Steam VR)' => 'PC',
        'PC (Steam Family)' => 'PC',
        'PC (GOG)' => 'PC',
        'PC(Xbox)' => 'PC',
        'PS1 (Pirate)' => 'PlayStation',
        'PS2 (on PS3)' => 'PlayStation 2',
        'PS2' => 'PlayStation 2',
        'PS3' => 'PlayStation 3',
        'PS4' => 'PlayStation 4',
        'PS5' => 'PlayStation 5',
        'Xbox' => 'Xbox',
        'Xbox 360' => 'Xbox 360',
        'Xbox One' => 'Xbox One',
        'Wii U' => 'Wii U',
        'Gamecube' => 'GameCube',
        'GameCube' => 'GameCube',
    ];
    
    return $mappings[$platform] ?? $platform;
}

// Function to find matching game in database
function findMatchingGame($title, $platform, $pdo) {
    if (empty($title)) return null;
    
    // Clean title - remove extra spaces, special characters
    $cleanTitle = trim($title);
    $cleanTitle = preg_replace('/\s+/', ' ', $cleanTitle);
    
    // Try exact match first
    $stmt = $pdo->prepare("SELECT id FROM games WHERE LOWER(TRIM(title)) = LOWER(?) AND platform = ?");
    $stmt->execute([$cleanTitle, $platform]);
    $game = $stmt->fetch();
    if ($game) {
        return $game['id'];
    }
    
    // Try without platform
    $stmt = $pdo->prepare("SELECT id FROM games WHERE LOWER(TRIM(title)) = LOWER(?)");
    $stmt->execute([$cleanTitle]);
    $game = $stmt->fetch();
    if ($game) {
        return $game['id'];
    }
    
    // Try fuzzy match - remove special edition notes, etc.
    $fuzzyTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle); // Remove (100%), etc.
    $fuzzyTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $fuzzyTitle); // Remove [brackets]
    $fuzzyTitle = trim($fuzzyTitle);
    
    if ($fuzzyTitle !== $cleanTitle) {
        $stmt = $pdo->prepare("SELECT id FROM games WHERE LOWER(TRIM(title)) LIKE LOWER(?) AND platform = ?");
        $stmt->execute(["%$fuzzyTitle%", $platform]);
        $game = $stmt->fetch();
        if ($game) {
            return $game['id'];
        }
    }
    
    return null;
}

// Process each row
$rowNum = 0;
while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    
    // Skip empty rows
    if (empty(array_filter($row))) {
        continue;
    }
    
    // Get values
    $getValue = function($key) use ($row, $columnMap) {
        $index = $columnMap[$key] ?? null;
        return $index !== null && isset($row[$index]) ? trim($row[$index]) : '';
    };
    
    $title = $getValue('name');
    $num = $getValue('num');
    
    // Skip rows without a title or with header-like content
    if (empty($title) || stripos($title, 'THPS All Games') !== false) {
        $skipped++;
        continue;
    }
    
    $platform = normalizePlatform($getValue('platform'));
    $timeTaken = $getValue('time');
    $dateStarted = parseDate($getValue('date_started'));
    $dateCompleted = parseDate($getValue('date_completed'));
    
    // Extract year from completion date or use 2025
    $completionYear = 2025;
    if ($dateCompleted) {
        $year = date('Y', strtotime($dateCompleted));
        if ($year) {
            $completionYear = (int)$year;
        }
    }
    
    // Try to find matching game
    $gameId = null;
    if ($platform) {
        $gameId = findMatchingGame($title, $platform, $pdo);
        if ($gameId) {
            $linked++;
        }
    }
    
    // Check for duplicates
    $checkStmt = $pdo->prepare("
        SELECT id FROM game_completions 
        WHERE title = ? AND platform = ? AND date_started = ? AND date_completed = ?
    ");
    $checkStmt->execute([$title, $platform, $dateStarted, $dateCompleted]);
    if ($checkStmt->fetch()) {
        echo "  ⊗ Skipping duplicate: $title\n";
        $skipped++;
        continue;
    }
    
    // Insert completion
    try {
        $stmt = $pdo->prepare("
            INSERT INTO game_completions (
                game_id, title, platform, time_taken, 
                date_started, date_completed, completion_year, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $notes = $num ? "Imported #$num" : null;
        
        $stmt->execute([
            $gameId,
            $title,
            $platform,
            $timeTaken ?: null,
            $dateStarted,
            $dateCompleted,
            $completionYear,
            $notes
        ]);
        
        $status = $gameId ? " (linked to game #$gameId)" : " (not linked)";
        echo "  ✓ Imported: $title$status\n";
        $imported++;
        
    } catch (Exception $e) {
        echo "  ✗ Error importing $title: " . $e->getMessage() . "\n";
        $errors++;
    }
}

fclose($handle);

echo "\n==========================================\n";
echo "Import Summary:\n";
echo "  ✓ Completions Imported: $imported\n";
echo "  ✓ Games Linked: $linked\n";
echo "  ⊗ Skipped: $skipped\n";
echo "  ⊗ Errors: $errors\n";
echo "\n";

