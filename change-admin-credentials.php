<?php
/**
 * Script to change admin username and password
 * 
 * Usage from command line:
 *   php change-admin-credentials.php
 * 
 * Or access via web browser (requires admin login):
 *   https://yourdomain.com/change-admin-credentials.php
 */

require_once __DIR__ . '/includes/config.php';

// Check if running from command line
$isCLI = (php_sapi_name() === 'cli');

if ($isCLI) {
    // Command line mode
    echo "==========================================\n";
    echo "Change Admin Credentials\n";
    echo "==========================================\n\n";
    
    // Get current admin user
    $stmt = $pdo->query("SELECT id, username FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    $admin = $stmt->fetch();
    
    if (!$admin) {
        die("Error: No admin user found.\n");
    }
    
    echo "Current admin user: " . $admin['username'] . " (ID: " . $admin['id'] . ")\n\n";
    
    // Get new username
    echo "Enter new username (or press Enter to keep current): ";
    $newUsername = trim(fgets(STDIN));
    if (empty($newUsername)) {
        $newUsername = $admin['username'];
    }
    
    // Validate username
    if (strlen($newUsername) < 3) {
        die("Error: Username must be at least 3 characters.\n");
    }
    
    // Check if username already exists (if changing)
    if ($newUsername !== $admin['username']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$newUsername, $admin['id']]);
        if ($stmt->fetch()) {
            die("Error: Username '$newUsername' already exists.\n");
        }
    }
    
    // Get new password
    echo "Enter new password (or press Enter to skip password change): ";
    $newPassword = trim(fgets(STDIN));
    
    // Update username if changed
    if ($newUsername !== $admin['username']) {
        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
        $stmt->execute([$newUsername, $admin['id']]);
        echo "✓ Username changed to: $newUsername\n";
    } else {
        echo "✓ Username unchanged: $newUsername\n";
    }
    
    // Update password if provided
    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            die("Error: Password must be at least 6 characters.\n");
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $admin['id']]);
        echo "✓ Password changed successfully\n";
    } else {
        echo "✓ Password unchanged\n";
    }
    
    echo "\n==========================================\n";
    echo "Admin credentials updated successfully!\n";
    echo "New username: $newUsername\n";
    if (!empty($newPassword)) {
        echo "Password: [CHANGED]\n";
    } else {
        echo "Password: [UNCHANGED]\n";
    }
    echo "==========================================\n";
    
} else {
    // Web mode - require admin login
    require_once __DIR__ . '/includes/auth-check.php';
    
    // Check admin role
    if (($_SESSION['role'] ?? 'user') !== 'admin') {
        header('Location: dashboard.php');
        exit;
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF protection
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page and try again.']);
            exit;
        }
        
        $newUsername = trim($_POST['username'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Get current admin user
        $adminId = $_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id = ? AND role = 'admin'");
        $stmt->execute([$adminId]);
        $admin = $stmt->fetch();
        
        if (!$admin) {
            die(json_encode(['success' => false, 'message' => 'Admin user not found']));
        }
        
        $errors = [];
        
        // Validate username
        if (empty($newUsername)) {
            $errors[] = 'Username is required';
        } elseif (strlen($newUsername) < 3) {
            $errors[] = 'Username must be at least 3 characters';
        } elseif ($newUsername !== $admin['username']) {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $adminId]);
            if ($stmt->fetch()) {
                $errors[] = 'Username already exists';
            }
        }
        
        // Validate password if provided
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) {
                $errors[] = 'Password must be at least 6 characters';
            } elseif ($newPassword !== $confirmPassword) {
                $errors[] = 'Passwords do not match';
            }
        }
        
        if (!empty($errors)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
            exit;
        }
        
        // Update username if changed
        if ($newUsername !== $admin['username']) {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            $stmt->execute([$newUsername, $adminId]);
            $_SESSION['username'] = $newUsername; // Update session
            error_log("SECURITY: Admin user ID $adminId changed username from '{$admin['username']}' to '$newUsername'");
        }
        
        // Update password if provided
        if (!empty($newPassword)) {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$passwordHash, $adminId]);
            error_log("SECURITY: Admin user ID $adminId changed password");
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Credentials updated successfully']);
        exit;
    }
    
    // Get current admin info
    $adminId = $_SESSION['user_id'];
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Change Admin Credentials</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body>
        <div class="app-container">
            <header class="app-header">
                <h1>Change Admin Credentials</h1>
                <div class="header-actions">
                    <a href="admin-dashboard.php" class="btn btn-secondary">Back to Admin</a>
                    <a href="dashboard.php" class="btn btn-secondary">Dashboard</a>
                    <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                </div>
            </header>
            
            <div class="content-container">
                <div style="max-width: 600px; margin: 40px auto; padding: 20px;">
                    <form id="changeCredentialsForm">
                        <input type="hidden" id="csrf_token" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken()); ?>">
                        <div class="form-group">
                            <label for="username">New Username</label>
                            <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($admin['username']); ?>" required minlength="3">
                        </div>
                        
                        <div class="form-group">
                            <label for="password">New Password (leave blank to keep current)</label>
                            <input type="password" id="password" name="password" minlength="6">
                        </div>
                        
                        <div class="form-group">
                            <label for="confirm_password">Confirm Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" minlength="6">
                        </div>
                        
                        <div id="errorMessage" class="error-message" style="display: none;"></div>
                        <div id="successMessage" class="success-message" style="display: none;"></div>
                        
                        <button type="submit" class="btn btn-primary">Update Credentials</button>
                    </form>
                </div>
            </div>
        </div>
        
        <script src="js/main.js"></script>
        <script>
            setupDarkMode();
            
            document.getElementById('changeCredentialsForm').addEventListener('submit', async function(e) {
                e.preventDefault();
                
                const username = document.getElementById('username').value;
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const errorDiv = document.getElementById('errorMessage');
                const successDiv = document.getElementById('successMessage');
                
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                
                if (password && password !== confirmPassword) {
                    errorDiv.textContent = 'Passwords do not match';
                    errorDiv.style.display = 'block';
                    return;
                }
                
                const formData = new FormData();
                formData.append('csrf_token', document.getElementById('csrf_token').value);
                formData.append('username', username);
                if (password) {
                    formData.append('password', password);
                    formData.append('confirm_password', confirmPassword);
                }
                
                try {
                    const response = await fetch('change-admin-credentials.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        successDiv.textContent = data.message || 'Credentials updated successfully!';
                        successDiv.style.display = 'block';
                        // Clear password fields
                        document.getElementById('password').value = '';
                        document.getElementById('confirm_password').value = '';
                        // Update session username if changed
                        if (username !== '<?php echo htmlspecialchars($admin['username']); ?>') {
                            setTimeout(() => {
                                alert('Username changed. You will be logged out. Please log in with your new username.');
                                window.location.href = 'api/auth.php?action=logout';
                            }, 2000);
                        }
                    } else {
                        errorDiv.textContent = data.message || 'Failed to update credentials';
                        errorDiv.style.display = 'block';
                    }
                } catch (error) {
                    errorDiv.textContent = 'An error occurred. Please try again.';
                    errorDiv.style.display = 'block';
                }
            });
        </script>
        <?php include __DIR__ . '/includes/footer.php'; ?>
    </body>
    </html>
    <?php
}
?>

