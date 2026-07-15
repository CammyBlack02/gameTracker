// Setup dark mode for login page
setupDarkMode();

// Handle login form
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const errorDiv = document.getElementById('errorMessage');

    errorDiv.style.display = 'none';

    const formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);

    try {
        const data = await apiPostForm('api/auth.php?action=login', formData);

        if (data.success) {
            window.location.href = 'dashboard.php';
        } else {
            errorDiv.textContent = data.message || 'Login failed';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'An error occurred. Please try again.';
        errorDiv.style.display = 'block';
    }
});
