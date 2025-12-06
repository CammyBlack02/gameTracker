<?php
/**
 * GameEye CSV Import API
 * Handles CSV file upload and import
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$userId = $_SESSION['user_id'];

// Check if file was uploaded
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    sendJsonResponse(['success' => false, 'message' => 'No CSV file uploaded or upload error'], 400);
}

$uploadedFile = $_FILES['csv_file'];

// Validate file type
$fileExtension = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    sendJsonResponse(['success' => false, 'message' => 'Invalid file type. Please upload a CSV file.'], 400);
}

// Validate file size (max 10MB)
if ($uploadedFile['size'] > 10 * 1024 * 1024) {
    sendJsonResponse(['success' => false, 'message' => 'File too large. Maximum size is 10MB.'], 400);
}

// Move uploaded file to temp location
$tempFile = sys_get_temp_dir() . '/gameeye_import_' . uniqid() . '.csv';
if (!move_uploaded_file($uploadedFile['tmp_name'], $tempFile)) {
    sendJsonResponse(['success' => false, 'message' => 'Failed to save uploaded file'], 500);
}

try {
    // Read CSV file
    $handle = fopen($tempFile, 'r');
    if (!$handle) {
        unlink($tempFile);
        sendJsonResponse(['success' => false, 'message' => 'Could not open CSV file'], 500);
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        unlink($tempFile);
        sendJsonResponse(['success' => false, 'message' => 'Could not read CSV headers'], 400);
    }
    
    // Map CSV column indices
    $columnMap = [];
    foreach ($headers as $index => $header) {
        $columnMap[trim($header)] = $index;
    }
    
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
    $errorMessages = [];
    
    // Process each row
    while (($row = fgetcsv($handle)) !== false) {
        // Get values by column name
        $getValue = function($colName) use ($row, $columnMap) {
            $index = $columnMap[$colName] ?? null;
            return $index !== null && isset($row[$index]) ? trim($row[$index]) : '';
        };
        
        $category = $getValue('Category');
        
        // Skip wishlist items
        if ($category === 'Wishlist') {
            $skipped++;
            continue;
        }
        
        // Handle consoles and accessories
        if (in_array($category, ['Systems', 'Controllers', 'Game Accessories', 'Toys To Life'])) {
            $title = $getValue('Title');
            $platformRaw = $getValue('Platform');
            
            if (empty($title)) {
                $errors++;
                continue;
            }
            
            $platform = $platformMap[$platformRaw] ?? $platformRaw;
            
            // Check for existing item (smart merge)
            $checkStmt = $pdo->prepare("SELECT id FROM items WHERE title = ? AND category = ? AND user_id = ?");
            $checkStmt->execute([$title, $category, $userId]);
            $existingItem = $checkStmt->fetch();
            
            if ($existingItem) {
                $itemDuplicates++;
                continue;
            }
            
            // Determine category
            $itemCategory = 'Console';
            if (in_array($category, ['Controllers', 'Game Accessories', 'Toys To Life'])) {
                $itemCategory = 'Accessory';
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
            
            // Description
            $description = $getValue('Notes');
            if (empty($description)) {
                $description = null;
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO items (
                        user_id, title, platform, category, description, `condition`,
                        price_paid, pricecharting_price
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $title,
                    $platform,
                    $itemCategory,
                    $description,
                    $condition,
                    $pricePaid,
                    $priceCharting
                ]);
                
                $importedItems++;
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Item '$title': " . $e->getMessage();
            }
            
            continue;
        }
        
        // Handle games
        if ($category === 'Games') {
            $title = $getValue('Title');
            $platformRaw = $getValue('Platform');
            
            if (empty($title) || empty($platformRaw)) {
                $errors++;
                continue;
            }
            
            // Clean title (remove special edition markers that might be in brackets)
            $cleanTitle = $title;
            $specialEdition = null;
            
            // Check for special edition markers
            if (preg_match('/\[([^\]]+)\]/', $title, $matches)) {
                $specialEdition = $matches[1];
                $cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $cleanTitle);
            }
            if (preg_match('/\(([^\)]+)\)/', $title, $matches)) {
                if (!$specialEdition) {
                    $specialEdition = $matches[1];
                }
                $cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
            }
            $cleanTitle = trim($cleanTitle);
            
            // Normalize platform
            $platform = $platformMap[$platformRaw] ?? $platformRaw;
            
            // Check for existing game (smart merge - match by title, platform, and user_id)
            $checkStmt = $pdo->prepare("SELECT id FROM games WHERE title = ? AND platform = ? AND user_id = ?");
            $checkStmt->execute([$cleanTitle, $platform, $userId]);
            $existingGame = $checkStmt->fetch();
            
            if ($existingGame) {
                // Update existing game
                $gameId = $existingGame['id'];
                
                // Determine if physical or digital
                $ownership = $getValue('Ownership');
                $releaseType = $getValue('ReleaseType');
                $isPhysical = 1;
                
                if ($releaseType === 'Digital' || in_array(strtolower($ownership), ['digital', 'download'])) {
                    $isPhysical = 0;
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
                
                // Description
                $description = $getValue('Notes');
                if (empty($description)) {
                    $description = null;
                }
                
                // Extract series
                $series = null;
                $seriesPatterns = [
                    'Call of Duty', 'FIFA', 'Assassin\'s Creed', 'Grand Theft Auto', 'Halo',
                    'Gears of War', 'Uncharted', 'God of War', 'Spider-Man', 'Batman',
                    'Star Wars', 'LEGO', 'Tony Hawk', 'Need for Speed', 'Forza',
                    'Mario', 'Zelda', 'Pokémon', 'Sonic', 'Resident Evil',
                    'Silent Hill', 'Dark Souls', 'Elder Scrolls', 'Fallout', 'BioShock',
                    'Borderlands', 'Far Cry', 'Watch Dogs', 'The Sims', 'SimCity'
                ];
                
                foreach ($seriesPatterns as $pattern) {
                    if (stripos($cleanTitle, $pattern) !== false) {
                        $series = $pattern;
                        break;
                    }
                }
                
                try {
                    $updateStmt = $pdo->prepare("
                        UPDATE games SET
                            special_edition = ?,
                            `condition` = ?,
                            description = ?,
                            series = ?,
                            price_paid = ?,
                            pricecharting_price = ?,
                            is_physical = ?,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    
                    $updateStmt->execute([
                        $specialEdition,
                        $condition,
                        $description,
                        $series,
                        $pricePaid,
                        $priceCharting,
                        $isPhysical,
                        $gameId
                    ]);
                    
                    $duplicates++;
                } catch (PDOException $e) {
                    $errors++;
                    $errorMessages[] = "Update '$cleanTitle': " . $e->getMessage();
                }
                
                continue;
            }
            
            // Insert new game
            // Determine if physical or digital
            $ownership = $getValue('Ownership');
            $releaseType = $getValue('ReleaseType');
            $isPhysical = 1;
            
            if ($releaseType === 'Digital' || in_array(strtolower($ownership), ['digital', 'download'])) {
                $isPhysical = 0;
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
            
            // Description
            $description = $getValue('Notes');
            if (empty($description)) {
                $description = null;
            }
            
            // Extract series
            $series = null;
            $seriesPatterns = [
                'Call of Duty', 'FIFA', 'Assassin\'s Creed', 'Grand Theft Auto', 'Halo',
                'Gears of War', 'Uncharted', 'God of War', 'Spider-Man', 'Batman',
                'Star Wars', 'LEGO', 'Tony Hawk', 'Need for Speed', 'Forza',
                'Mario', 'Zelda', 'Pokémon', 'Sonic', 'Resident Evil',
                'Silent Hill', 'Dark Souls', 'Elder Scrolls', 'Fallout', 'BioShock',
                'Borderlands', 'Far Cry', 'Watch Dogs', 'The Sims', 'SimCity'
            ];
            
            foreach ($seriesPatterns as $pattern) {
                if (stripos($cleanTitle, $pattern) !== false) {
                    $series = $pattern;
                    break;
                }
            }
            
            // Created date
            $createdAt = $getValue('CreatedAt');
            $timestamp = null;
            if (!empty($createdAt)) {
                $parsed = strtotime($createdAt);
                if ($parsed !== false) {
                    $timestamp = date('Y-m-d H:i:s', $parsed);
                }
            }
            
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO games (
                        user_id, title, platform, genre, description, series, special_edition,
                        `condition`, review, star_rating, metacritic_rating, played,
                        price_paid, pricecharting_price, is_physical,
                        front_cover_image, back_cover_image, created_at
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");
                
                $stmt->execute([
                    $userId,
                    $cleanTitle,
                    $platform,
                    null, // genre
                    $description,
                    $series,
                    $specialEdition,
                    $condition,
                    null, // review
                    null, // star_rating
                    null, // metacritic_rating
                    0, // played
                    $pricePaid,
                    $priceCharting,
                    $isPhysical,
                    null, // front_cover_image
                    null, // back_cover_image
                    $timestamp ?? date('Y-m-d H:i:s')
                ]);
                
                $imported++;
            } catch (PDOException $e) {
                $errors++;
                $errorMessages[] = "Game '$cleanTitle': " . $e->getMessage();
            }
        }
    }
    
    fclose($handle);
    unlink($tempFile);
    
    // Build response
    $message = "Import completed! ";
    $message .= "Imported: $imported games, $importedItems items. ";
    if ($duplicates > 0) {
        $message .= "Updated: $duplicates existing games. ";
    }
    if ($skipped > 0) {
        $message .= "Skipped: $skipped items (wishlist/non-games). ";
    }
    if ($errors > 0) {
        $message .= "Errors: $errors. ";
    }
    
    sendJsonResponse([
        'success' => true,
        'message' => trim($message),
        'imported' => $imported,
        'imported_items' => $importedItems,
        'updated' => $duplicates,
        'skipped' => $skipped,
        'errors' => $errors,
        'error_messages' => array_slice($errorMessages, 0, 10) // Limit to first 10 errors
    ]);
    
} catch (Exception $e) {
    if (isset($tempFile) && file_exists($tempFile)) {
        unlink($tempFile);
    }
    error_log("GameEye import error: " . $e->getMessage());
    sendJsonResponse(['success' => false, 'message' => 'Import failed: ' . $e->getMessage()], 500);
}

