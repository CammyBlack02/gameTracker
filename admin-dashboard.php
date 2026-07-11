<?php
require_once __DIR__ . '/includes/auth.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - Admin Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body data-session-user-id="<?php echo (int)$_SESSION['user_id']; ?>">
    <div class="app-container">
        <header class="app-header">
            <h1>Admin Dashboard</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <a href="users.php" class="btn btn-secondary">Browse Users</a>
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <div class="content-container">
            <div class="admin-section">
                <div class="admin-info-box">
                    <h3 style="margin-top: 0;">Admin Account</h3>
                    <p>Change your admin username and password:</p>
                    <a href="change-admin-credentials.php" class="btn btn-primary">Change Admin Credentials</a>
                </div>
                
                <h2>User Management</h2>
                <div id="usersList" class="users-list">
                    <div class="loading">Loading users...</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reset Password Modal -->
    <div id="resetPasswordModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Reset Password</h2>
                <button class="modal-close" onclick="hideModal('resetPasswordModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="resetPasswordForm">
                    <input type="hidden" id="resetPasswordUserId">
                    <div class="form-group">
                        <label for="resetPasswordNew">New Password</label>
                        <input type="password" id="resetPasswordNew" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="resetPasswordConfirm">Confirm Password</label>
                        <input type="password" id="resetPasswordConfirm" required minlength="6">
                    </div>
                    <div id="resetPasswordError" class="error-message" style="display: none;"></div>
                    <div class="modal-actions">
                        <button type="button" class="btn btn-secondary" onclick="hideModal('resetPasswordModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Reset Password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Delete User Modal -->
    <div id="deleteUserModal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Delete User</h2>
                <button class="modal-close" onclick="hideModal('deleteUserModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete user <strong id="deleteUserName"></strong>?</p>
                <p style="color: #d32f2f; font-weight: bold;">This will permanently delete all their games, items, and completions!</p>
                <input type="hidden" id="deleteUserId">
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="hideModal('deleteUserModal')">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="confirmDeleteUser()">Delete User</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script src="js/admin-dashboard.js"></script>
    <style>
        .admin-section {
            padding: 20px;
        }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: var(--card-background);
            color: var(--text-color);
        }
        
        .admin-table th,
        .admin-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .admin-table th {
            background: var(--background-color);
            font-weight: bold;
            color: var(--text-color);
        }
        
        .admin-table tr:hover {
            background: var(--background-color);
        }
        
        .badge-admin {
            background-color: var(--danger-color);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        
        .badge-user {
            background-color: var(--secondary-color);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 0.85em;
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: var(--danger-color);
            opacity: 0.9;
        }
    </style>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

