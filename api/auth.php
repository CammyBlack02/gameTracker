<?php
/**
 * Authentication API endpoints
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

try {
    require_once __DIR__ . '/../includes/config.php';
    require_once __DIR__ . '/../includes/functions.php';
    
    header('Content-Type: application/json');
} catch (Throwable $e) {
    error_log('Auth API initialization error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false, 
        'message' => 'Server error: ' . $e->getMessage() . ' (File: ' . basename($e->getFile()) . ' Line: ' . $e->getLine() . ')'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Rate limiting helper
 */
function checkRateLimit($key, $maxAttempts, $timeWindow) {
    global $pdo;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateLimitKey = $key . '_' . $ip;
    
    // Create rate_limits table if it doesn't exist
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            id INT PRIMARY KEY AUTO_INCREMENT,
            rate_key VARCHAR(255) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempts INT DEFAULT 1,
            first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            locked_until DATETIME NULL,
            INDEX idx_rate_key (rate_key, ip_address),
            INDEX idx_locked (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        // Table might already exist, continue
    }
    
    // Clean up old entries (older than time window)
    $pdo->exec("DELETE FROM rate_limits WHERE last_attempt < DATE_SUB(NOW(), INTERVAL $timeWindow SECOND)");
    
    // Check current rate limit
    $stmt = $pdo->prepare("SELECT attempts, first_attempt, locked_until FROM rate_limits WHERE rate_key = ? AND ip_address = ?");
    $stmt->execute([$rateLimitKey, $ip]);
    $rateLimit = $stmt->fetch();
    
    // Check if locked
    if ($rateLimit && $rateLimit['locked_until']) {
        $lockedUntil = strtotime($rateLimit['locked_until']);
        if (time() < $lockedUntil) {
            $minutesRemaining = ceil(($lockedUntil - time()) / 60);
            return [
                'allowed' => false,
                'message' => "Too many attempts. Please try again in $minutesRemaining minute(s).",
                'retry_after' => $lockedUntil - time()
            ];
        } else {
            // Lock expired, reset
            $stmt = $pdo->prepare("UPDATE rate_limits SET attempts = 0, locked_until = NULL WHERE rate_key = ? AND ip_address = ?");
            $stmt->execute([$rateLimitKey, $ip]);
        }
    }
    
    // Check if exceeded max attempts
    if ($rateLimit && $rateLimit['attempts'] >= $maxAttempts) {
        // Lock for 15 minutes
        $lockUntil = date('Y-m-d H:i:s', time() + 900);
        $stmt = $pdo->prepare("UPDATE rate_limits SET locked_until = ? WHERE rate_key = ? AND ip_address = ?");
        $stmt->execute([$lockUntil, $rateLimitKey, $ip]);
        
        return [
            'allowed' => false,
            'message' => "Too many attempts. Account locked for 15 minutes.",
            'retry_after' => 900
        ];
    }
    
    // Increment attempts or create new entry
    if ($rateLimit) {
        $stmt = $pdo->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = NOW() WHERE rate_key = ? AND ip_address = ?");
        $stmt->execute([$rateLimitKey, $ip]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO rate_limits (rate_key, ip_address, attempts) VALUES (?, ?, 1)");
        $stmt->execute([$rateLimitKey, $ip]);
    }
    
    return ['allowed' => true];
}

// logSecurityEvent is defined in includes/functions.php

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        handleLogin();
        break;
    
    case 'register':
        handleRegister();
        break;
    
    case 'logout':
        handleLogout();
        break;
    
    case 'check':
        checkAuth();
        break;
    
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function handleLogin() {
    global $pdo;
    
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
            return;
        }
        
        // Rate limiting: 5 attempts per 15 minutes
        $rateLimit = checkRateLimit('login', 5, 900);
        if (!$rateLimit['allowed']) {
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('login_rate_limit_exceeded', ['username' => $_POST['username'] ?? 'unknown']);
            }
            sendJsonResponse(['success' => false, 'message' => $rateLimit['message']], 429);
            return;
        }
        
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            sendJsonResponse(['success' => false, 'message' => 'Username and password are required'], 400);
            return;
        }
        
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password_hash'])) {
            // Successful login - reset rate limit
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $rateLimitKey = 'login_' . $ip;
            $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE rate_key = ? AND ip_address = ?");
            $stmt->execute([$rateLimitKey, $ip]);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'] ?? 'user';
            $_SESSION['last_activity'] = time();
            
            // Regenerate session ID for security
            session_regenerate_id(true);
            
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('login_success', ['user_id' => $user['id'], 'username' => $username]);
            }
            
            sendJsonResponse([
                'success' => true,
                'message' => 'Login successful',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $_SESSION['role']
                ]
            ]);
        } else {
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('login_failed', ['username' => $username]);
            }
            sendJsonResponse(['success' => false, 'message' => 'Invalid username or password'], 401);
        }
    } catch (Throwable $e) {
        error_log('handleLogin error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        sendJsonResponse([
            'success' => false, 
            'message' => 'Login error: ' . $e->getMessage() . ' (File: ' . basename($e->getFile()) . ' Line: ' . $e->getLine() . ')'
        ], 500);
    }
}

function handleLogout() {
    session_destroy();
    sendJsonResponse(['success' => true, 'message' => 'Logged out successfully']);
}

function handleRegister() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    // Rate limiting: 3 registrations per hour
    $rateLimit = checkRateLimit('register', 3, 3600);
    if (!$rateLimit['allowed']) {
        logSecurityEvent('register_rate_limit_exceeded');
        sendJsonResponse(['success' => false, 'message' => $rateLimit['message']], 429);
    }
    
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Validation
    if (empty($username) || empty($password)) {
        sendJsonResponse(['success' => false, 'message' => 'Username and password are required'], 400);
    }
    
    if ($password !== $confirmPassword) {
        sendJsonResponse(['success' => false, 'message' => 'Passwords do not match'], 400);
    }
    
    // Username validation
    if (strlen($username) < 3) {
        sendJsonResponse(['success' => false, 'message' => 'Username must be at least 3 characters'], 400);
    }
    
    if (strlen($username) > 50) {
        sendJsonResponse(['success' => false, 'message' => 'Username must be less than 50 characters'], 400);
    }
    
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        sendJsonResponse(['success' => false, 'message' => 'Username can only contain letters, numbers, underscores, and hyphens'], 400);
    }
    
    // Password validation
    if (strlen($password) < 6) {
        sendJsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
    }
    
    // Check if username already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        sendJsonResponse(['success' => false, 'message' => 'Username already exists'], 400);
    }
    
    // Create user
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, 'user')");
    
    try {
        $stmt->execute([$username, $passwordHash]);
        $userId = $pdo->lastInsertId();
        
        // Auto-login after registration
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'user';
        $_SESSION['last_activity'] = time();
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        logSecurityEvent('registration_success', ['user_id' => $userId, 'username' => $username]);
        
        sendJsonResponse([
            'success' => true,
            'message' => 'Registration successful',
            'user' => [
                'id' => $userId,
                'username' => $username,
                'role' => 'user'
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Registration error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Registration failed. Please try again.'], 500);
    }
}

function checkAuth() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        sendJsonResponse([
            'success' => true,
            'authenticated' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'],
                'role' => $_SESSION['role'] ?? 'user'
            ]
        ]);
    } else {
        sendJsonResponse([
            'success' => true,
            'authenticated' => false
        ]);
    }
}

