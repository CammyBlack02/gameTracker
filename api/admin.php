<?php
/**
 * Admin API endpoints
 * Only accessible to users with admin role
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
}

$action = $_GET['action'] ?? '';

// Check admin role only for admin-only actions
$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';

switch ($action) {
    case 'list':
        // All authenticated users can list users
        listUsers();
        break;
    
    case 'reset_password':
        // Admin only
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        }
        resetPassword();
        break;
    
    case 'delete':
        // Admin only
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        }
        deleteUser();
        break;
    
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function listUsers() {
    global $pdo;
    
    try {
        $stmt = $pdo->query("
            SELECT 
                u.id,
                u.username,
                u.role,
                u.email,
                u.created_at,
                COUNT(DISTINCT g.id) as game_count,
                COUNT(DISTINCT i.id) as item_count,
                COUNT(DISTINCT c.id) as completion_count
            FROM users u
            LEFT JOIN games g ON u.id = g.user_id
            LEFT JOIN items i ON u.id = i.user_id
            LEFT JOIN game_completions c ON u.id = c.user_id
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        sendJsonResponse([
            'success' => true,
            'users' => $users
        ]);
    } catch (PDOException $e) {
        error_log("Error listing users: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'Failed to list users'], 500);
    }
}

function resetPassword() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? 0;
    $newPassword = $data['password'] ?? '';
    
    if (!$userId) {
        sendJsonResponse(['success' => false, 'message' => 'User ID is required'], 400);
    }
    
    if (empty($newPassword)) {
        sendJsonResponse(['success' => false, 'message' => 'Password is required'], 400);
    }
    
    if (strlen($newPassword) < 6) {
        sendJsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['success' => false, 'message' => 'User not found'], 404);
    }
    
    // Hash new password
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    // Update password
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$passwordHash, $userId]);
    
    error_log("Admin " . $_SESSION['username'] . " reset password for user " . $user['username']);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Password reset successfully'
    ]);
}

function deleteUser() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $userId = $data['user_id'] ?? 0;
    
    if (!$userId) {
        sendJsonResponse(['success' => false, 'message' => 'User ID is required'], 400);
    }
    
    // Prevent deleting yourself
    if ($userId == $_SESSION['user_id']) {
        sendJsonResponse(['success' => false, 'message' => 'Cannot delete your own account'], 400);
    }
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        sendJsonResponse(['success' => false, 'message' => 'User not found'], 404);
    }
    
    // Delete user (CASCADE will delete all their data)
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    
    error_log("Admin " . $_SESSION['username'] . " deleted user " . $user['username']);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'User deleted successfully'
    ]);
}

