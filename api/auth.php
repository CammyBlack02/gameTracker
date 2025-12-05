<?php
/**
 * Authentication API endpoints
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

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
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        sendJsonResponse(['success' => false, 'message' => 'Username and password are required'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'] ?? 'user';
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
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
        sendJsonResponse(['success' => false, 'message' => 'Invalid username or password'], 401);
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
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
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

