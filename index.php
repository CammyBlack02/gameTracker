<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Tracker - Login</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin: 0;">Game Tracker</h1>
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode" style="padding: 8px 12px;">🌙</button>
            </div>
            <p class="subtitle">Manage your game collection</p>
            
            <form id="loginForm">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required autofocus>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required>
                </div>
                
                <div id="errorMessage" class="error-message" style="display: none;"></div>
                
                <button type="submit" class="btn btn-primary">Login</button>
            </form>
            
            <p style="margin-top: 20px; text-align: center;">
                <a href="register.php" style="color: #4a90e2; text-decoration: none;">Create an account</a>
            </p>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    
    <script src="js/api.js"></script>
    <script src="js/index.js"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

