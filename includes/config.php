<?php
/**
 * Configuration file for Game Tracker
 * Database connection and settings
 */

// Helper function to convert memory limit string to bytes
if (!function_exists('return_bytes')) {
    function return_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int)$val;
        switch($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }
}

// Increase memory limit for large collections (only if not already set higher)
$currentMemoryLimit = ini_get('memory_limit');
if ($currentMemoryLimit !== '-1') {
    $currentBytes = return_bytes($currentMemoryLimit);
    $targetBytes = return_bytes('512M');
    if ($currentBytes < $targetBytes) {
        @ini_set('memory_limit', '512M');
    }
}
@ini_set('max_execution_time', '300');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'gameTracker');
define('DB_USER', 'CammyBlack02');
define('DB_PASS', 'RetroTinker87!');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('COVERS_DIR', UPLOAD_DIR . 'covers/');
define('EXTRAS_DIR', UPLOAD_DIR . 'extras/');

// Create directories if they don't exist
if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}
if (!file_exists(COVERS_DIR)) {
    mkdir(COVERS_DIR, 0755, true);
}
if (!file_exists(EXTRAS_DIR)) {
    mkdir(EXTRAS_DIR, 0755, true);
}

// Database connection
try {
    // MySQL connection
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_TIMEOUT => 30,
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Initialize database tables
    initializeDatabase($pdo);
} catch (PDOException $e) {
    // Check if we're in an API context (output buffering active and JSON header expected)
    if (ob_get_level() > 0 && (php_sapi_name() === 'cli-server' || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false)) {
        // In API context, throw exception instead of die()
        throw new Exception("Database connection failed: " . $e->getMessage());
    } else {
        // In regular page context, use die()
        die("Database connection failed: " . $e->getMessage());
    }
}

/**
 * Initialize database tables if they don't exist
 */
function initializeDatabase($pdo) {
    // Enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT PRIMARY KEY AUTO_INCREMENT,
        username VARCHAR(255) UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Games table
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title TEXT NOT NULL,
        platform VARCHAR(255) NOT NULL,
        genre VARCHAR(255),
        description TEXT,
        series VARCHAR(255),
        special_edition VARCHAR(255),
        `condition` VARCHAR(255),
        review TEXT,
        star_rating INT,
        metacritic_rating INT,
        played INT DEFAULT 0,
        price_paid DECIMAL(10,2),
        pricecharting_price DECIMAL(10,2),
        is_physical INT DEFAULT 1,
        digital_store VARCHAR(255),
        front_cover_image TEXT,
        back_cover_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add digital_store column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN digital_store VARCHAR(255)");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Game images table (for extra photos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_images (
        id INT PRIMARY KEY AUTO_INCREMENT,
        game_id INT NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Consoles and accessories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        title TEXT NOT NULL,
        platform VARCHAR(255),
        category VARCHAR(255) NOT NULL,
        description TEXT,
        `condition` VARCHAR(255),
        price_paid DECIMAL(10,2),
        pricecharting_price DECIMAL(10,2),
        front_image TEXT,
        back_image TEXT,
        notes TEXT,
        quantity INT DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add quantity column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN quantity INT DEFAULT 1");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Item images table (for extra photos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_images (
        id INT PRIMARY KEY AUTO_INCREMENT,
        item_id INT NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Settings table (for background image, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(255) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Game completions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_completions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        game_id INT,
        title TEXT NOT NULL,
        platform VARCHAR(255),
        time_taken VARCHAR(255),
        date_started DATE,
        date_completed DATE,
        completion_year INT,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Create default admin user if no users exist
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        // Default username: admin, password: admin (change this after first login!)
        $username = 'admin';
        $password = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->execute([$username, $password]);
    }
}

