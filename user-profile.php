<?php
require_once __DIR__ . '/includes/auth.php';
requireUser();

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
<body data-profile-user-id="<?php echo (int)$profileUserId; ?>" data-session-user-id="<?php echo (int)$_SESSION['user_id']; ?>">
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
            
            <!-- Games Toolbar with Filters -->
            <div class="toolbar" id="gamesToolbar">
                <div class="search-container">
                    <input type="text" id="searchInput" placeholder="Search games..." class="search-input">
                </div>
                
                <div class="filter-container">
                    <select id="platformFilter" class="filter-select">
                        <option value="">All Platforms</option>
                    </select>
                    
                    <select id="genreFilter" class="filter-select">
                        <option value="">All Genres</option>
                    </select>
                    
                    <select id="typeFilter" class="filter-select">
                        <option value="">All Types</option>
                        <option value="physical">Physical</option>
                        <option value="digital">Digital</option>
                    </select>
                    
                    <select id="playedFilter" class="filter-select">
                        <option value="">All Games</option>
                        <option value="1">Played</option>
                        <option value="0">Not Played</option>
                    </select>
                    
                    <select id="sortSelect" class="filter-select">
                        <option value="newest">Newest First</option>
                        <option value="oldest">Oldest First</option>
                        <option value="release-newest">Release Date (Newest)</option>
                        <option value="release-oldest">Release Date (Oldest)</option>
                        <option value="title-asc">Title (A-Z)</option>
                        <option value="title-desc">Title (Z-A)</option>
                        <option value="price-low">Price (Low to High)</option>
                        <option value="price-high">Price (High to Low)</option>
                        <option value="rating-high">Rating (High to Low)</option>
                        <option value="rating-low">Rating (Low to High)</option>
                    </select>
                </div>
                
                <div class="view-toggle">
                    <button id="listViewBtn" class="view-btn active" title="List View">
                        <span>☰</span>
                    </button>
                    <button id="gridViewBtn" class="view-btn" title="Grid View">
                        <span>⊞</span>
                    </button>
                    <button id="coverFlowViewBtn" class="view-btn" title="Cover Flow">
                        <span>🎮</span>
                    </button>
                </div>
            </div>
            
            <!-- Items Toolbar (for consoles/accessories) -->
            <div class="toolbar" id="itemsToolbar" style="display: none;">
                <div class="search-container">
                    <input type="text" id="itemsSearchInput" placeholder="Search items..." class="search-input">
                </div>
                
                <div class="view-toggle">
                    <button id="itemsListViewBtn" class="view-btn active" title="List View">
                        <span>☰</span>
                    </button>
                    <button id="itemsGridViewBtn" class="view-btn" title="Grid View">
                        <span>⊞</span>
                    </button>
                </div>
            </div>
            
            <div id="gamesContainer" class="games-container grid-view"></div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    
    <script src="js/api.js"></script>
    <script src="js/user-profile-init.js"></script>
    <script src="js/image-split.js"></script>
    <script src="js/games.js"></script>
    <script src="js/render/coverflow.js"></script>
    <script src="js/render/grid.js"></script>
    <script src="js/render/list.js"></script>
    <script src="js/items.js"></script>
    <script src="js/filters.js"></script>
    <script src="js/user-profile.js"></script>
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

