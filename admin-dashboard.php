<?php 
require_once __DIR__ . '/includes/auth-check.php';

// Check admin role
if (($_SESSION['role'] ?? 'user') !== 'admin') {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Tracker - Admin Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
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
    <script>
        // Setup dark mode
        setupDarkMode();
        
        // Load users on page load
        loadUsers();
        
        // Setup logout
        document.getElementById('logoutBtn').addEventListener('click', async function() {
            try {
                const response = await fetch('api/auth.php?action=logout', { method: 'POST' });
                window.location.href = 'index.php';
            } catch (error) {
                console.error('Logout error:', error);
                window.location.href = 'index.php';
            }
        });
        
        async function loadUsers() {
            try {
                const response = await fetch('api/admin.php?action=list');
                const data = await response.json();
                
                if (data.success) {
                    displayUsers(data.users);
                } else {
                    document.getElementById('usersList').innerHTML = 
                        '<div class="error-message">Error loading users: ' + (data.message || 'Unknown error') + '</div>';
                }
            } catch (error) {
                console.error('Error loading users:', error);
                document.getElementById('usersList').innerHTML = 
                    '<div class="error-message">Error loading users. Please refresh the page.</div>';
            }
        }
        
        function displayUsers(users) {
            const container = document.getElementById('usersList');
            
            if (users.length === 0) {
                container.innerHTML = '<div class="empty-state">No users found</div>';
                return;
            }
            
            let html = '<table class="admin-table"><thead><tr>' +
                '<th>Username</th>' +
                '<th>Role</th>' +
                '<th>Email</th>' +
                '<th>Games</th>' +
                '<th>Items</th>' +
                '<th>Completions</th>' +
                '<th>Joined</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody>';
            
            users.forEach(user => {
                const isCurrentUser = user.id == <?php echo $_SESSION['user_id']; ?>;
                html += '<tr>' +
                    '<td><a href="user-profile.php?id=' + user.id + '">' + escapeHtml(user.username) + '</a></td>' +
                    '<td><span class="badge ' + (user.role === 'admin' ? 'badge-admin' : 'badge-user') + '">' + escapeHtml(user.role) + '</span></td>' +
                    '<td>' + (user.email || '-') + '</td>' +
                    '<td>' + user.game_count + '</td>' +
                    '<td>' + user.item_count + '</td>' +
                    '<td>' + user.completion_count + '</td>' +
                    '<td>' + new Date(user.created_at).toLocaleDateString() + '</td>' +
                    '<td>' +
                        '<button class="btn btn-small btn-secondary" onclick="resetPassword(' + user.id + ', \'' + escapeHtml(user.username) + '\')">Reset Password</button> ' +
                        (isCurrentUser ? '' : '<button class="btn btn-small btn-danger" onclick="deleteUser(' + user.id + ', \'' + escapeHtml(user.username) + '\')">Delete</button>') +
                    '</td>' +
                    '</tr>';
            });
            
            html += '</tbody></table>';
            container.innerHTML = html;
        }
        
        function resetPassword(userId, username) {
            document.getElementById('resetPasswordUserId').value = userId;
            document.getElementById('resetPasswordForm').reset();
            document.getElementById('resetPasswordError').style.display = 'none';
            showModal('resetPasswordModal');
        }
        
        function deleteUser(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = username;
            showModal('deleteUserModal');
        }
        
        function confirmDeleteUser() {
            const userId = document.getElementById('deleteUserId').value;
            
            fetch('api/admin.php?action=delete', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ user_id: parseInt(userId) })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hideModal('deleteUserModal');
                    loadUsers();
                    showNotification('User deleted successfully', 'success');
                } else {
                    alert('Error: ' + (data.message || 'Failed to delete user'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting user. Please try again.');
            });
        }
        
        document.getElementById('resetPasswordForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const userId = document.getElementById('resetPasswordUserId').value;
            const newPassword = document.getElementById('resetPasswordNew').value;
            const confirmPassword = document.getElementById('resetPasswordConfirm').value;
            const errorDiv = document.getElementById('resetPasswordError');
            
            if (newPassword !== confirmPassword) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }
            
            if (newPassword.length < 6) {
                errorDiv.textContent = 'Password must be at least 6 characters';
                errorDiv.style.display = 'block';
                return;
            }
            
            try {
                const response = await fetch('api/admin.php?action=reset_password', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        user_id: parseInt(userId),
                        password: newPassword
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    hideModal('resetPasswordModal');
                    showNotification('Password reset successfully', 'success');
                } else {
                    errorDiv.textContent = data.message || 'Failed to reset password';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Error:', error);
                errorDiv.textContent = 'Error resetting password. Please try again.';
                errorDiv.style.display = 'block';
            }
        });
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function showNotification(message, type) {
            // Simple notification - you can enhance this later
            alert(message);
        }
    </script>
    <style>
        .admin-section {
            padding: 20px;
        }
        
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .admin-table th,
        .admin-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .admin-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        
        .admin-table tr:hover {
            background-color: #f9f9f9;
        }
        
        .badge-admin {
            background-color: #d32f2f;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.85em;
        }
        
        .badge-user {
            background-color: #666;
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
            background-color: #d32f2f;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b71c1c;
        }
    </style>
</body>
</html>

