<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - Register</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0;">Game Tracker</h1>
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode" style="padding: 8px 12px;">🌙</button>
            </div>
            <p class="subtitle">Create your account</p>
            
            <form id="registerForm">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus minlength="3" maxlength="50" pattern="[a-zA-Z0-9_-]+" title="Letters, numbers, underscores, and hyphens only">
                    <small style="color: #666; font-size: 0.85em;">3-50 characters, letters, numbers, underscores, and hyphens only</small>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required minlength="6">
                    <small style="color: #666; font-size: 0.85em;">At least 6 characters</small>
                </div>
                
                <div class="form-group">
                    <label for="confirmPassword">Confirm Password</label>
                    <input type="password" id="confirmPassword" name="confirm_password" required minlength="6">
                </div>
                
                <div id="errorMessage" class="error-message" style="display: none;"></div>
                
                <button type="submit" class="btn btn-primary">Create Account</button>
            </form>
            
            <p style="margin-top: 20px; text-align: center;">
                <a href="index.php" style="color: #4a90e2; text-decoration: none;">Already have an account? Login</a>
            </p>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script src="js/register.js"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

