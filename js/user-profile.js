const profileUserId = Number(document.body.dataset.profileUserId);
const sessionUserId = Number(document.body.dataset.sessionUserId);
const isViewingOwnProfile = profileUserId === sessionUserId;

// Override loadGames again after games.js loads to ensure it's blocked
const originalLoadGames = window.loadGames;
window.loadGames = function () {
    return Promise.resolve();
};

// Setup dark mode
setupDarkMode();

// Load user info
loadUserInfo();

// Load games for this user profile
loadUserGames();

// Setup logout
document.getElementById('logoutBtn').addEventListener('click', async function () {
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
    btn.addEventListener('click', function () {
        document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
        this.classList.add('active');

        const tab = this.dataset.tab;

        // Show/hide toolbars
        const gamesToolbar = document.getElementById('gamesToolbar');
        const itemsToolbar = document.getElementById('itemsToolbar');

        if (gamesToolbar) {
            gamesToolbar.style.display = tab === 'games' ? 'block' : 'none';
        }
        if (itemsToolbar) {
            itemsToolbar.style.display = (tab === 'consoles' || tab === 'accessories') ? 'block' : 'none';
        }

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
    try {
        const response = await fetch(`api/games.php?action=list&user_id=${profileUserId}&page=1&per_page=500`);
        const data = await response.json();

        if (data.success && data.games) {
            // Set allGames so filters work correctly, but prevent loadGames from being called
            if (typeof allGames !== 'undefined') {
                allGames = data.games;
            }
            if (typeof window.allGames !== 'undefined') {
                window.allGames = data.games;
            }

            // Populate filter dropdowns (using updateFilters from games.js)
            // This will populate platform and genre dropdowns based on the loaded games
            if (typeof updateFilters === 'function') {
                updateFilters();
            }

            // Use existing displayGames function but make it read-only
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

// escapeHtml is defined in js/main.js (loaded above).

// Override displayGames to hide edit buttons when viewing other users
const originalDisplayGames = window.displayGames;
window.displayGames = function (games) {
    originalDisplayGames(games);
    if (!isViewingOwnProfile) {
        // Hide all edit buttons
        document.querySelectorAll('.game-card-actions, .edit-game-btn, .delete-game-btn').forEach(btn => {
            btn.style.display = 'none';
        });
    }
};
