<?php
/**
 * Migration 000: baseline schema.
 *
 * Every CREATE TABLE / ALTER TABLE that used to live in
 * includes/config.php's initializeDatabase() — moved here so it no
 * longer runs on every request (Fable §6 latency win).
 *
 * All statements are idempotent by construction: CREATE TABLE IF NOT
 * EXISTS for tables, ALTER TABLE in a swallowed try/catch for columns
 * and indexes. Safe to re-run against the existing prod schema.
 *
 * Naming: `000_` prefix guarantees this sorts before 001_api_tokens,
 * which foreign-keys `users` — MySQL needs users to exist first.
 */
return function (PDO $pdo): void {
    // Enable foreign key checks (idempotent — MySQL default is 1 anyway).
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

    // Add role and email columns if they don't exist (for existing databases).
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') DEFAULT 'user'");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE users ADD INDEX idx_username (username)");
    } catch (PDOException $e) {
        // Index already exists, ignore.
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

    // Bring older databases up to schema — column adds + FK backfill.
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE games ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE games ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN digital_store VARCHAR(255)");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games ADD COLUMN release_date DATE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    // Upgrade image columns from TEXT to MEDIUMTEXT if needed.
    try {
        $pdo->exec("ALTER TABLE games MODIFY COLUMN front_cover_image MEDIUMTEXT");
    } catch (PDOException $e) {
        // Column might not exist or already be MEDIUMTEXT, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games MODIFY COLUMN back_cover_image MEDIUMTEXT");
    } catch (PDOException $e) {
        // Column might not exist or already be MEDIUMTEXT, ignore.
    }

    // Game images table (for extra photos).
    $pdo->exec("CREATE TABLE IF NOT EXISTS game_images (
        id INT PRIMARY KEY AUTO_INCREMENT,
        game_id INT NOT NULL,
        user_id INT NOT NULL,
        image_path TEXT NOT NULL,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_user_id (user_id),
        INDEX idx_game_id (game_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $pdo->exec("ALTER TABLE game_images ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE game_images ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE game_images ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }

    // Performance indexes (previously in database/add-performance-indexes.php).
    try {
        $pdo->exec("ALTER TABLE game_images ADD INDEX idx_game_id (game_id)");
    } catch (PDOException $e) {
        // Index already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_platform (platform)");
    } catch (PDOException $e) {
        // Index already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_created_at (created_at)");
    } catch (PDOException $e) {
        // Index already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE games ADD INDEX idx_platform_user_id (platform, user_id)");
    } catch (PDOException $e) {
        // Index already exists, ignore.
    }

    // Consoles and accessories table.
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

    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE items ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE items ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }
    try {
        $pdo->exec("ALTER TABLE items ADD COLUMN quantity INT DEFAULT 1");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }

    // Item images table (for extra photos).
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

    try {
        $pdo->exec("ALTER TABLE item_images ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE item_images ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE item_images ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }

    // Settings table (per-user).
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

    try {
        $pdo->exec("ALTER TABLE settings ADD COLUMN user_id INT NOT NULL");
        // Drop the old unique index if it exists (pre-multi-user shape).
        try {
            $pdo->exec("ALTER TABLE settings DROP INDEX setting_key");
        } catch (PDOException $e) {
            // Index doesn't exist, ignore.
        }
        $pdo->exec("ALTER TABLE settings ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE settings ADD UNIQUE KEY unique_user_setting (user_id, setting_key)");
        $pdo->exec("ALTER TABLE settings ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }

    // Game completions table.
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

    try {
        $pdo->exec("ALTER TABLE game_completions ADD COLUMN user_id INT NOT NULL");
        $pdo->exec("ALTER TABLE game_completions ADD INDEX idx_user_id (user_id)");
        $pdo->exec("ALTER TABLE game_completions ADD FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
    } catch (PDOException $e) {
        // Column already exists, ignore.
    }

    // Seed the default admin user if the users table is empty. Idempotent
    // by construction: only runs when count == 0.
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    if ($result['count'] == 0) {
        // Default username: admin, password: admin — CHANGE AFTER FIRST LOGIN.
        $username = 'admin';
        $password = password_hash('admin', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'admin')");
        $stmt->execute([$username, $password]);
    } else {
        // Ensure the existing admin user actually has admin role.
        try {
            $pdo->exec("UPDATE users SET role = 'admin' WHERE username = 'admin' AND (role IS NULL OR role = '')");
        } catch (PDOException $e) {
            // Role column may not exist yet — will be handled above on next re-run.
        }
    }
};
