<?php
/**
 * Fix completion dates that were parsed incorrectly (US format -> UK format)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';

// For CLI scripts, we don't need session authentication
if (php_sapi_name() !== 'cli') {
    if (!isset($_SESSION['user_id'])) {
        die("Please log in first.\n");
    }
}

echo "Fixing completion dates...\n\n";

// Function to parse UK date format correctly
function parseUKDate($dateStr) {
    if (empty($dateStr) || trim($dateStr) === '') {
        return null;
    }
    
    $dateStr = trim($dateStr);
    
    // Handle UK format dd/mm/yyyy (day/month/year)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = (int)$matches[3];
        
        // Validate date (UK format: day/month/year)
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Handle UK format dd/mm (no year, use 2025)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})$/', $dateStr, $matches)) {
        $day = (int)$matches[1];
        $month = (int)$matches[2];
        $year = 2025;
        
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    
    // Handle UK format with day name
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
    
    // Try DateTime with UK format
    $date = DateTime::createFromFormat('d/m/Y', $dateStr);
    if ($date !== false) {
        return $date->format('Y-m-d');
    }
    
    $date = DateTime::createFromFormat('d/m', $dateStr);
    if ($date !== false) {
        $date->setDate(2025, $date->format('m'), $date->format('d'));
        return $date->format('Y-m-d');
    }
    
    return null;
}

// Read CSV and get original dates
$csvFile = __DIR__ . '/Games Completed 2025.csv';
if (!file_exists($csvFile)) {
    die("CSV file not found: $csvFile\n");
}

$handle = fopen($csvFile, 'r');
if (!$handle) {
    die("Failed to open CSV file.\n");
}

// Read header
$header = fgetcsv($handle);
$columnMap = [];
foreach ($header as $index => $col) {
    $col = trim($col);
    if (stripos($col, 'name') !== false) $columnMap['name'] = $index;
    if (stripos($col, 'date') !== false && stripos($col, 'start') !== false) $columnMap['date_started'] = $index;
    if (stripos($col, 'date') !== false && stripos($col, 'complete') !== false) $columnMap['date_completed'] = $index;
}

$csvData = [];
while (($row = fgetcsv($handle)) !== false) {
    if (empty(array_filter($row))) continue;
    
    $getValue = function($key) use ($row, $columnMap) {
        $index = $columnMap[$key] ?? null;
        return $index !== null && isset($row[$index]) ? trim($row[$index]) : '';
    };
    
    $title = $getValue('name');
    if (empty($title) || stripos($title, 'THPS All Games') !== false) continue;
    
    $dateStarted = $getValue('date_started');
    $dateCompleted = $getValue('date_completed');
    
    $csvData[] = [
        'title' => $title,
        'date_started' => $dateStarted,
        'date_completed' => $dateCompleted
    ];
}
fclose($handle);

// Get all completions from database
$stmt = $pdo->query("SELECT id, title, date_started, date_completed FROM game_completions");
$completions = $stmt->fetchAll();

$fixed = 0;
$notFound = 0;

foreach ($completions as $completion) {
    // Find matching CSV row
    $csvRow = null;
    foreach ($csvData as $row) {
        if (strcasecmp(trim($completion['title']), trim($row['title'])) === 0) {
            $csvRow = $row;
            break;
        }
    }
    
    if (!$csvRow) {
        $notFound++;
        continue;
    }
    
    // Parse dates from CSV (UK format)
    $correctDateStarted = parseUKDate($csvRow['date_started']);
    $correctDateCompleted = parseUKDate($csvRow['date_completed']);
    
    // Check if dates need fixing
    $needsFix = false;
    if ($correctDateStarted && $completion['date_started'] !== $correctDateStarted) {
        $needsFix = true;
    }
    if ($correctDateCompleted && $completion['date_completed'] !== $correctDateCompleted) {
        $needsFix = true;
    }
    
    if ($needsFix) {
        // Extract year from completion date
        $completionYear = 2025;
        if ($correctDateCompleted) {
            $year = date('Y', strtotime($correctDateCompleted));
            if ($year) {
                $completionYear = (int)$year;
            }
        }
        
        $stmt = $pdo->prepare("
            UPDATE game_completions 
            SET date_started = ?, 
                date_completed = ?, 
                completion_year = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        $stmt->execute([
            $correctDateStarted,
            $correctDateCompleted,
            $completionYear,
            $completion['id']
        ]);
        
        echo "  ✓ Fixed: {$completion['title']}\n";
        echo "    Started: {$completion['date_started']} -> $correctDateStarted\n";
        echo "    Completed: {$completion['date_completed']} -> $correctDateCompleted\n";
        $fixed++;
    }
}

echo "\n==========================================\n";
echo "Fix Summary:\n";
echo "  ✓ Fixed: $fixed\n";
echo "  ⊗ Not Found in CSV: $notFound\n";
echo "\n";

