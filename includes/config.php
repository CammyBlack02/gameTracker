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
    // Secure session configuration
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
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
        role ENUM('user', 'admin') DEFAULT 'user',
        email VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add role and email columns if they don't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') DEFAULT 'user'");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    // Add index on username if it doesn't exist
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_username (username)");
    } catch (PDOException $e) {
        // Index already exists, ignore error
    }
    
    // Games table
    $pdo->exec("CREATE TABLE IF NOT EXISTS games (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
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
        front_cover_image MEDIUMTEXT,
        back_cover_image MEDIUMTEXT,
        release_date DATE,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN user_id INT NOT NULL");
        // Add index
        $pdo->exec("ALTER TABLE games ADD INDEX idx_user_id (user_id)");
        // Add foreign key
        $pdo->exec("ALTER TABLE games ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add digital_store column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN digital_store VARCHAR(255)");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Add release_date column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN release_date DATE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Upgrade image columns from TEXT to MEDIUMTEXT if needed (for existing databases)
    try {
        $pdo->exec("ALTER TABLE games MODIFY COLUMN front_cover_image MEDIUMTEXT");
    } catch (PDOException $e) {
        // Column might not exist or already be MEDIUMTEXT, ignore error
    }
    try {
        $pdo->exec("ALTER TABLE games MODIFY COLUMN back_cover_image MEDIUMTEXT");
    } catch (PDOException $e) {
        // Column might not exist or already be MEDIUMTEXT, ignore error
    }
    
    // Game images table (for extra photos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_images (
        id INT PRIMARY KEY AUTO_INCREMENT,
        game_id INT NOT NULL,
        user_id INT NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE game_images ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE game_images ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE game_images ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Consoles and accessories table
    $pdo->exec("CREATE TABLE IF NOT EXISTS items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
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
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE items ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE items ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
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
        user_id INT NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (item_id) REFERENCES items(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE item_images ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE item_images ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE item_images ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Settings table (for background image, etc.) - per-user settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        setting_key VARCHAR(255) NOT NULL,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_user_setting (user_id, setting_key),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE settings ADD COLUMN user_id INT NOT NULL");
        // Drop old unique constraint if it exists
        try {
            $pdo->exec("ALTER TABLE settings DROP INDEX setting_key");
        } catch (PDOException $e) {
            // Index doesn't exist, ignore
        }
        $pdo->exec("ALTER TABLE settings ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE settings ADD UNIQUE KEY unique_user_setting (user_id, setting_key)");
        $pdo->exec("ALTER TABLE settings ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Game completions table
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_completions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
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
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE SET NULL,
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Add user_id column if it doesn't exist (for existing databases)
    try {
        $pdo->exec("ALTER TABLE game_completions ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE game_completions ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE game_completions ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore error
    }
    
    // Create default admin user if no users exist
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        // Default username: admin, password: admin (change this after first login!)
        $username = 'admin';
        $password = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$username, $password]);
    } else {
        // Ensure existing admin user has admin role
        try {
            $pdo->exec("UPDATE users SET role = 'admin' WHERE username = 'admin' AND (role IS NULL OR role = '')");
        } catch (PDOException $e) {
            // Ignore if role column doesn't exist yet (will be added by migration)
        }
    }
}

