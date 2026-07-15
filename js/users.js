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
        const data = await apiGet('api/admin.php?action=list');

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

// escapeHtml is defined in js/main.js (loaded above).
