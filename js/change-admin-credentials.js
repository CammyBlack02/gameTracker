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
        const data = await apiPostForm('change-admin-credentials.php', formData);

        if (data.success) {
            successDiv.textContent = data.message || 'Credentials updated successfully!';
            successDiv.style.display = 'block';
            // Clear password fields
            document.getElementById('password').value = '';
            document.getElementById('confirm_password').value = '';
            // Update session username if changed
            const originalUsername = this.dataset.currentUsername;
            if (username !== originalUsername) {
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
