<?php require_once __DIR__ . '/includes/auth.php'; requireUser(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
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
    
    <script src="js/api.js"></script>
    <script src="js/users.js"></script>
    <style>
        .users-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            padding: 20px;
        }
        
        .user-card {
            background: var(--card-background, #fff);
            border: 1px solid var(--border-color, #ddd);
            border-radius: 8px;
            padding: 20px;
            box-shadow: var(--shadow, 0 2px 4px rgba(0,0,0,0.1));
            color: var(--text-color, #333);
            transition: var(--transition, all 0.3s ease);
        }
        
        .user-card:hover {
            box-shadow: var(--shadow-hover, 0 4px 12px rgba(0,0,0,0.15));
            transform: translateY(-2px);
        }
        
        .user-card h3 {
            margin: 0 0 15px 0;
            color: var(--text-color, #333);
        }
        
        .user-card h3 a {
            color: var(--text-color, #333);
            text-decoration: none;
        }
        
        .user-card h3 a:hover {
            text-decoration: underline;
            color: var(--primary-color, #4a90e2);
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
            color: var(--text-light, #666);
        }
        
        .stat-value {
            font-weight: bold;
            color: var(--text-color, #333);
        }
        
        .user-meta {
            font-size: 0.85em;
            color: var(--text-light, #666);
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border-color, #eee);
        }
    </style>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

