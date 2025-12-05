<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Tracker - Browse Users</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>Browse Users</h1>
            <div class="header-actions">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                    <a href="admin-dashboard.php" class="btn btn-secondary">Admin</a>
                <?php endif; ?>
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <div class="content-container">
            <div id="usersList" class="users-grid">
                <div class="loading">Loading users...</div>
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
            
            let html = '';
            users.forEach(user => {
                html += '<div class="user-card">' +
                    '<h3><a href="user-profile.php?id=' + user.id + '">' + escapeHtml(user.username) + '</a></h3>' +
                    '<div class="user-stats">' +
                        '<div class="user-stat"><span class="stat-label">Games:</span> <span class="stat-value">' + user.game_count + '</span></div>' +
                        '<div class="user-stat"><span class="stat-label">Items:</span> <span class="stat-value">' + user.item_count + '</span></div>' +
                        '<div class="user-stat"><span class="stat-label">Completions:</span> <span class="stat-value">' + user.completion_count + '</span></div>' +
                    '</div>' +
                    '<div class="user-meta">Joined: ' + new Date(user.created_at).toLocaleDateString() + '</div>' +
                    '</div>';
            });
            
            container.innerHTML = html;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
    <style>
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .user-card {
            background: var(--card-bg, #fff);
            border: 1px solid var(--border-color, #ddd);
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .user-card h3 {
            margin: 0 0 15px 0;
        }
        
        .user-card h3 a {
            color: var(--text-color, #333);
            text-decoration: none;
        }
        
        .user-card h3 a:hover {
            text-decoration: underline;
        }
        
        .user-stats {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        
        .user-stat {
            display: flex;
            justify-content: space-between;
        }
        
        .stat-label {
            color: var(--text-secondary, #666);
        }
        
        .stat-value {
            font-weight: bold;
        }
        
        .user-meta {
            font-size: 0.85em;
            color: var(--text-secondary, #666);
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color, #eee);
        }
    </style>
</body>
</html>

