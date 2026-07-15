<?php require_once __DIR__ . '/includes/auth.php'; requireUser(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Settings - Game Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <a href="dashboard.php" class="back-link">← Back to Collection</a>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <h1>Settings</h1>
            </div>
        </header>
        
        <div class="settings-container">
            <div class="settings-section">
                <h2>Background Image</h2>
                <p class="settings-description">Upload a custom background image for the app.</p>
                
                <div class="current-background">
                    <div id="backgroundPreview" class="background-preview">
                        <p>No background image set</p>
                    </div>
                </div>
                
                <form id="backgroundForm">
                    <div class="form-group">
                        <label for="backgroundImage">Select Background Image</label>
                        <input type="file" id="backgroundImage" name="background_image" accept="image/*">
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="removeBackgroundBtn" class="btn btn-danger" style="display: none;">Remove Background</button>
                        <button type="submit" class="btn btn-primary">Upload Background</button>
                    </div>
                </form>
            </div>
            
            <div class="settings-section">
                <h2>Steam Integration</h2>
                <p class="settings-description">
                    Configure your Steam credentials to import your Steam library automatically.
                    <br>
                    <strong>Steam API Key:</strong> Get yours at <a href="https://steamcommunity.com/dev/apikey" target="_blank">steamcommunity.com/dev/apikey</a>
                    <br>
                    <strong>Steam ID:</strong> Find yours at <a href="https://steamid.io/" target="_blank">steamid.io</a> (use the 64-bit Steam ID)
                </p>
                
                <form id="steamForm">
                    <div class="form-group">
                        <label for="steamApiKey">Steam API Key</label>
                        <input type="text" id="steamApiKey" name="steam_api_key" placeholder="Enter your Steam API key" class="form-input">
                    </div>
                    
                    <div class="form-group">
                        <label for="steamUserId">Steam ID (64-bit)</label>
                        <input type="text" id="steamUserId" name="steam_user_id" placeholder="Enter your 64-bit Steam ID" class="form-input">
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" id="testSteamBtn" class="btn btn-secondary">Test Connection</button>
                        <button type="submit" class="btn btn-primary">Save Credentials</button>
                    </div>
                    
                    <div id="steamTestResult" style="margin-top: 15px; display: none;"></div>
                </form>
                
                <div style="margin-top: 30px; padding-top: 30px; border-top: 1px solid var(--border-color);">
                    <h3>Import Steam Library</h3>
                    <p class="settings-description">
                        Once your credentials are saved and tested, you can import all games from your Steam library.
                    </p>
                    <div style="margin-top: 15px;">
                        <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
                            <input type="checkbox" id="deleteExistingPCGames" style="width: auto;">
                            <span>Delete all existing PC games before importing (recommended to get vertical covers)</span>
                        </label>
                        <button type="button" id="importSteamBtn" class="btn btn-primary">📥 Import Steam Library</button>
                    </div>
                    <div id="steamImportResult" style="margin-top: 15px; display: none;"></div>
                </div>
            </div>
            
            <div class="settings-section">
                <h2>GameEye CSV Import</h2>
                <p class="settings-description">
                    Import your game collection from a GameEye CSV backup file. This will import games, consoles, and accessories.
                    <br>
                    <strong>Note:</strong> Games that already exist in your collection (matching title and platform) will be updated with new information.
                </p>
                
                <form id="gameeyeForm" enctype="multipart/form-data">
                    <div class="form-group">
                        <label for="gameeyeCsvFile">Select GameEye CSV File</label>
                        <input type="file" id="gameeyeCsvFile" name="csv_file" accept=".csv" required>
                        <small style="color: var(--text-light); font-size: 0.85em; display: block; margin-top: 5px;">
                            Maximum file size: 10MB
                        </small>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="importGameeyeBtn">📥 Import GameEye CSV</button>
                    </div>
                    
                    <div id="gameeyeImportResult" style="margin-top: 15px; display: none;"></div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    
    <script src="js/api.js"></script>
    <script src="js/settings.js"></script>
    <style>
        .settings-container {
            background: var(--card-background);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 30px;
        }
        
        .settings-section {
            margin-bottom: 30px;
        }
        
        .settings-section h2 {
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .settings-description {
            color: var(--text-light);
            margin-bottom: 20px;
        }
        
        .background-preview {
            min-height: 200px;
            background: var(--background-color);
            border-radius: var(--border-radius);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            padding: 20px;
        }
        
        .background-preview img {
            max-width: 100%;
            max-height: 400px;
        }
    </style>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

