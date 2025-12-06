<?php 
require_once __DIR__ . '/includes/auth-check.php';

$profileUserId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$profileUserId) {
    header('Location: users.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - User Profile</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1 id="profileUsername">User Profile</h1>
            <div class="header-actions">
                <a href="users.php" class="btn btn-secondary">Back to Users</a>
                <a href="dashboard.php" class="btn btn-secondary">My Dashboard</a>
                <?php if (($_SESSION['role'] ?? 'user') === 'admin'): ?>
                    <a href="admin-dashboard.php" class="btn btn-secondary">Admin</a>
                <?php endif; ?>
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <div class="content-container">
            <div id="userInfo" class="user-info-section" style="margin-bottom: 20px;">
                <div class="loading">Loading user info...</div>
            </div>
            
            <!-- Tabs -->
            <div class="tabs">
                <button class="tab-button active" data-tab="games">Games</button>
                <button class="tab-button" data-tab="consoles">Consoles</button>
                <button class="tab-button" data-tab="accessories">Accessories</button>
            </div>
            
            <div id="gamesContainer" class="games-container grid-view"></div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script>
        // Set flag BEFORE loading games.js to prevent auto-load
        window.IS_USER_PROFILE_PAGE = true;
        window.PROFILE_USER_ID = <?php echo $profileUserId; ?>;
        
        // Override loadGames to prevent it from running on profile page
        window.loadGames = function() {
            console.log('loadGames: Blocked on user profile page (override)');
            return Promise.resolve();
        };
    </script>
    <script src="js/games.js"></script>
    <script src="js/items.js"></script>
    <script>
        const profileUserId = window.PROFILE_USER_ID;
        const isViewingOwnProfile = profileUserId == <?php echo $_SESSION['user_id']; ?>;
        
        // Override loadGames again after games.js loads to ensure it's blocked
        const originalLoadGames = window.loadGames;
        window.loadGames = function() {
            console.log('loadGames: Blocked on user profile page (post-override)');
            return Promise.resolve();
        };
        
        // Setup dark mode
        setupDarkMode();
        
        // Load user info
        loadUserInfo();
        
        // Load games for this user profile
        loadUserGames();
        
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
        
        // Tab switching
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                
                const tab = this.dataset.tab;
                if (tab === 'games') {
                    loadUserGames();
                } else if (tab === 'consoles') {
                    loadUserItems('Console');
                } else if (tab === 'accessories') {
                    loadUserItems('Accessory');
                }
            });
        });
        
        async function loadUserInfo() {
            try {
                const response = await fetch('api/admin.php?action=list');
                const data = await response.json();
                
                if (data.success) {
                    const user = data.users.find(u => u.id == profileUserId);
                    if (user) {
                        document.getElementById('profileUsername').textContent = user.username + "'s Collection";
                        document.getElementById('userInfo').innerHTML = 
                            '<div class="user-profile-card">' +
                            '<h2>' + escapeHtml(user.username) + '</h2>' +
                            '<div class="user-profile-stats">' +
                                '<div class="stat-item"><span class="stat-label">Games:</span> <span class="stat-value">' + user.game_count + '</span></div>' +
                                '<div class="stat-item"><span class="stat-label">Items:</span> <span class="stat-value">' + user.item_count + '</span></div>' +
                                '<div class="stat-item"><span class="stat-label">Completions:</span> <span class="stat-value">' + user.completion_count + '</span></div>' +
                                '<div class="stat-item"><span class="stat-label">Joined:</span> <span class="stat-value">' + new Date(user.created_at).toLocaleDateString() + '</span></div>' +
                            '</div>' +
                            '</div>';
                    } else {
                        document.getElementById('userInfo').innerHTML = '<div class="error-message">User not found</div>';
                    }
                }
            } catch (error) {
                console.error('Error loading user info:', error);
            }
        }
        
        async function loadUserGames() {
            console.log('loadUserGames: Loading games for user', profileUserId);
            try {
                const response = await fetch(`api/games.php?action=list&user_id=${profileUserId}&page=1&per_page=500`);
                const data = await response.json();
                
                console.log('loadUserGames: Response received', data.success, data.games?.length || 0, 'games');
                
                if (data.success && data.games) {
                    // Set allGames so filters work correctly, but prevent loadGames from being called
                    if (typeof allGames !== 'undefined') {
                        allGames = data.games;
                        console.log('loadUserGames: Set allGames to', allGames.length, 'games');
                    }
                    if (typeof window.allGames !== 'undefined') {
                        window.allGames = data.games;
                        console.log('loadUserGames: Set window.allGames to', window.allGames.length, 'games');
                    }
                    // Use existing displayGames function but make it read-only
                    console.log('loadUserGames: Calling displayGames with', data.games.length, 'games');
                    displayGames(data.games);
                } else {
                    // Clear allGames if no games found
                    if (typeof allGames !== 'undefined') {
                        allGames = [];
                    }
                    if (typeof window.allGames !== 'undefined') {
                        window.allGames = [];
                    }
                    document.getElementById('gamesContainer').innerHTML = 
                        '<div class="empty-state">No games found</div>';
                }
            } catch (error) {
                console.error('Error loading games:', error);
                document.getElementById('gamesContainer').innerHTML = 
                    '<div class="error-message">Error loading games</div>';
            }
        }
        
        async function loadUserItems(category) {
            try {
                const response = await fetch(`api/items.php?action=list&user_id=${profileUserId}&category=${category}`);
                const data = await response.json();
                
                if (data.success && data.items) {
                    const container = document.getElementById('gamesContainer');
                    container.className = 'games-container grid-view';
                    displayItems(data.items, container);
                } else {
                    document.getElementById('gamesContainer').innerHTML = 
                        '<div class="empty-state">No items found</div>';
                }
            } catch (error) {
                console.error('Error loading items:', error);
                document.getElementById('gamesContainer').innerHTML = 
                    '<div class="error-message">Error loading items</div>';
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Override displayGames to hide edit buttons when viewing other users
        const originalDisplayGames = window.displayGames;
        window.displayGames = function(games) {
            originalDisplayGames(games);
            if (!isViewingOwnProfile) {
                // Hide all edit buttons
                document.querySelectorAll('.game-card-actions, .edit-game-btn, .delete-game-btn').forEach(btn => {
                    btn.style.display = 'none';
                });
            }
        };
    </script>
    <style>
        .user-info-section {
            padding: 20px;
        }
        
        .user-profile-card {
            background: var(--card-background, #fff);
            border: 1px solid var(--border-color, #ddd);
            border-radius: 8px;
            padding: 20px;
            color: var(--text-color, #333);
            box-shadow: var(--shadow, 0 2px 4px rgba(0,0,0,0.1));
        }
        
        .user-profile-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .stat-item {
            display: flex;
            flex-direction: column;
        }
        
        .stat-label {
            font-size: 0.85em;
            color: var(--text-light, #666);
            margin-bottom: 5px;
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            color: var(--text-color, #333);
        }
    </style>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

