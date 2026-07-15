const sessionUserId = Number(document.body.dataset.sessionUserId);

// Setup dark mode
setupDarkMode();

// Load users on page load
loadUsers();

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
        const isCurrentUser = user.id == sessionUserId;
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

async function confirmDeleteUser() {
    const userId = document.getElementById('deleteUserId').value;

    try {
        const data = await apiPostJson('api/admin.php?action=delete', { user_id: parseInt(userId) });
        if (data.success) {
            hideModal('deleteUserModal');
            loadUsers();
            showNotification('User deleted successfully', 'success');
        } else {
            alert('Error: ' + (data.message || 'Failed to delete user'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error deleting user. Please try again.');
    }
}

document.getElementById('resetPasswordForm').addEventListener('submit', async function (e) {
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
        const data = await apiPostJson('api/admin.php?action=reset_password', {
            user_id: parseInt(userId),
            password: newPassword
        });

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

// escapeHtml is defined in js/main.js (loaded above).

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
