// Setup dark mode for register page
setupDarkMode();

// Handle registration form
document.getElementById('registerForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const errorDiv = document.getElementById('errorMessage');

    errorDiv.style.display = 'none';

    // Client-side validation
    if (password !== confirmPassword) {
        errorDiv.textContent = 'Passwords do not match';
        errorDiv.style.display = 'block';
        return;
    }

    if (username.length < 3) {
        errorDiv.textContent = 'Username must be at least 3 characters';
        errorDiv.style.display = 'block';
        return;
    }

    if (password.length < 6) {
        errorDiv.textContent = 'Password must be at least 6 characters';
        errorDiv.style.display = 'block';
        return;
    }

    if (!/^[a-zA-Z0-9_-]+$/.test(username)) {
        errorDiv.textContent = 'Username can only contain letters, numbers, underscores, and hyphens';
        errorDiv.style.display = 'block';
        return;
    }

    const formData = new FormData();
    formData.append('username', username);
    formData.append('password', password);
    formData.append('confirm_password', confirmPassword);

    try {
        const response = await fetch('api/auth.php?action=register', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            // Redirect to dashboard
            window.location.href = 'dashboard.php';
        } else {
            errorDiv.textContent = data.message || 'Registration failed';
            errorDiv.style.display = 'block';
        }
    } catch (error) {
        errorDiv.textContent = 'An error occurred. Please try again.';
        errorDiv.style.display = 'block';
    }
});
