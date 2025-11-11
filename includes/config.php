<?php
/**
 * Configuration file for Game Tracker
 * Database connection and settings
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_PATH', __DIR__ . '/../database/games.db');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('COVERS_DIR', UPLOAD_DIR . 'covers/');
define('EXTRAS_DIR', UPLOAD_DIR . 'extras/');

// Create directories if they don't exist
if (!file_exists(__DIR__ . '/../database')) {
    mkdir(__DIR__ . '/../database', 0755, true);
}
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
    $pdo = new PDO('sqlite:' . DB_PATH);
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
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Games table
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        platform TEXT NOT NULL,
        genre TEXT,
        description TEXT,
        series TEXT,
        special_edition TEXT,
        condition TEXT,
        review TEXT,
        star_rating INTEGER,
        metacritic_rating INTEGER,
        played INTEGER DEFAULT 0,
        price_paid DECIMAL(10,2),
        pricecharting_price DECIMAL(10,2),
        is_physical INTEGER DEFAULT 1,
        digital_store TEXT,
        front_cover_image TEXT,
        back_cover_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add digital_store column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN digital_store TEXT");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Game images table (for extra photos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    )");
    
    // Consoles and accessories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        platform TEXT,
        category TEXT NOT NULL,
        description TEXT,
        condition TEXT,
        price_paid DECIMAL(10,2),
        pricecharting_price DECIMAL(10,2),
        front_image TEXT,
        back_image TEXT,
        notes TEXT,
        quantity INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add quantity column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN quantity INTEGER DEFAULT 1");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Item images table (for extra photos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS item_images (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        item_id INTEGER NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE
    )");
    
    // Settings table (for background image, etc.)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        setting_key TEXT UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Game completions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_completions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER,
        title TEXT NOT NULL,
        platform TEXT,
        time_taken TEXT,
        date_started DATE,
        date_completed DATE,
        completion_year INTEGER,
        notes TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL
    )");
    
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

