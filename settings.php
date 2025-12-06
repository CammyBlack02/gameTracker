<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
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
    <script>
        // Load current settings
        async function loadSettings() {
            try {
                const response = await fetch('api/settings.php?action=get');
                const data = await response.json();
                
                if (data.success && data.settings.background_image) {
                    const preview = document.getElementById('backgroundPreview');
                    preview.innerHTML = `<img src="uploads/${data.settings.background_image}" alt="Background" style="max-width: 100%; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">`;
                    document.getElementById('removeBackgroundBtn').style.display = 'inline-block';
                }
            } catch (error) {
                console.error('Error loading settings:', error);
            }
        }
        
        // Handle background upload
        document.getElementById('backgroundForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const fileInput = document.getElementById('backgroundImage');
            if (!fileInput.files[0]) {
                showNotification('Please select an image', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('background_image', fileInput.files[0]);
            
            try {
                const response = await fetch('api/settings.php?action=set_background', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Background image updated successfully!', 'success');
                    loadSettings();
                    // Update body background
                    document.body.style.backgroundImage = `url(${data.url})`;
                    document.body.classList.add('custom-background');
                    fileInput.value = '';
                } else {
                    showNotification(data.message || 'Failed to upload background', 'error');
                }
            } catch (error) {
                console.error('Error uploading background:', error);
                showNotification('Error uploading background', 'error');
            }
        });
        
        // Handle remove background
        document.getElementById('removeBackgroundBtn').addEventListener('click', async function() {
            if (confirm('Are you sure you want to remove the background image?')) {
                try {
                    const response = await fetch('api/settings.php?action=remove_background');
                    const data = await response.json();
                    
                    if (data.success) {
                        showNotification('Background image removed', 'success');
                        document.getElementById('backgroundPreview').innerHTML = '<p>No background image set</p>';
                        document.getElementById('removeBackgroundBtn').style.display = 'none';
                        document.body.style.backgroundImage = '';
                        document.body.classList.remove('custom-background');
                    } else {
                        showNotification(data.message || 'Failed to remove background', 'error');
                    }
                } catch (error) {
                    console.error('Error removing background:', error);
                    showNotification('Error removing background', 'error');
                }
            }
        });
        
        // Load Steam settings
        async function loadSteamSettings() {
            try {
                const response = await fetch('api/settings.php?action=get');
                const data = await response.json();
                
                if (data.success && data.settings) {
                    if (data.settings.steam_api_key) {
                        document.getElementById('steamApiKey').value = data.settings.steam_api_key;
                    }
                    if (data.settings.steam_user_id) {
                        document.getElementById('steamUserId').value = data.settings.steam_user_id;
                    }
                }
            } catch (error) {
                console.error('Error loading Steam settings:', error);
            }
        }
        
        // Handle Steam form submission
        document.getElementById('steamForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const apiKey = document.getElementById('steamApiKey').value.trim();
            const steamId = document.getElementById('steamUserId').value.trim();
            
            if (!apiKey || !steamId) {
                showNotification('Please enter both Steam API key and Steam ID', 'error');
                return;
            }
            
            try {
                const response = await fetch('api/settings.php?action=set_steam', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        steam_api_key: apiKey,
                        steam_user_id: steamId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Steam credentials saved successfully!', 'success');
                    // Enable import button if credentials are now configured
                    checkSteamImportAvailability();
                } else {
                    showNotification(data.message || 'Failed to save credentials', 'error');
                }
            } catch (error) {
                console.error('Error saving Steam credentials:', error);
                showNotification('Error saving Steam credentials', 'error');
            }
        });
        
        // Handle test connection
        document.getElementById('testSteamBtn').addEventListener('click', async function() {
            const apiKey = document.getElementById('steamApiKey').value.trim();
            const steamId = document.getElementById('steamUserId').value.trim();
            
            if (!apiKey || !steamId) {
                showNotification('Please enter both Steam API key and Steam ID first', 'error');
                return;
            }
            
            // Save credentials first
            try {
                const saveResponse = await fetch('api/settings.php?action=set_steam', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        steam_api_key: apiKey,
                        steam_user_id: steamId
                    })
                });
                
                const saveData = await saveResponse.json();
                if (!saveData.success) {
                    showNotification('Failed to save credentials: ' + saveData.message, 'error');
                    return;
                }
            } catch (error) {
                showNotification('Error saving credentials', 'error');
                return;
            }
            
            // Test connection
            const resultDiv = document.getElementById('steamTestResult');
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<p>Testing connection...</p>';
            
            try {
                const response = await fetch('api/steam-import.php?action=test_connection');
                const data = await response.json();
                
                if (data.success) {
                    resultDiv.innerHTML = `<p style="color: var(--success-color);">✓ ${data.message}</p>`;
                } else {
                    resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ ${data.message}</p>`;
                }
            } catch (error) {
                resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ Connection error: ${error.message}</p>`;
            }
        });
        
        // Handle Steam import button
        document.getElementById('importSteamBtn').addEventListener('click', async function() {
            // Check if credentials are configured
            const apiKey = document.getElementById('steamApiKey').value.trim();
            const steamId = document.getElementById('steamUserId').value.trim();
            
            if (!apiKey || !steamId) {
                showNotification('Please configure and save your Steam credentials first', 'error');
                return;
            }
            
            const deleteExisting = document.getElementById('deleteExistingPCGames').checked;
            let confirmMessage = 'This will import all games from your Steam library.';
            if (deleteExisting) {
                confirmMessage = '⚠️ WARNING: This will DELETE all existing PC games from your collection, then import all games from your Steam library. This cannot be undone! Continue?';
            } else {
                confirmMessage += ' Games you already have will be skipped.';
            }
            confirmMessage += ' This may take a few minutes. Continue?';
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            const btn = this;
            const resultDiv = document.getElementById('steamImportResult');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Importing...';
            resultDiv.style.display = 'block';
            
            let deleteData = null;
            
            try {
                // First, delete existing PC games if requested
                if (deleteExisting) {
                    resultDiv.innerHTML = '<p>Deleting existing PC games...</p>';
                    const deleteResponse = await fetch('api/steam-import.php?action=delete_pc_games', {
                        method: 'POST'
                    });
                    deleteData = await deleteResponse.json();
                    
                    if (!deleteData.success) {
                        throw new Error(deleteData.message || 'Failed to delete existing PC games');
                    }
                    
                    resultDiv.innerHTML = `<p>Deleted ${deleteData.deleted_count || 0} existing PC games. Now importing from Steam...</p>`;
                } else {
                    resultDiv.innerHTML = '<p>Importing games from Steam... This may take a few minutes.</p>';
                }
                
                // Now import (with longer timeout for large libraries)
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutes timeout
                
                let response;
                try {
                    response = await fetch('api/steam-import.php?action=import', {
                        method: 'POST',
                        signal: controller.signal
                    });
                } catch (error) {
                    clearTimeout(timeoutId);
                    if (error.name === 'AbortError') {
                        throw new Error('Import timed out after 10 minutes. The import may still be processing in the background.');
                    }
                    throw error;
                }
                clearTimeout(timeoutId);
                
                // Check if response is OK
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Server returned ${response.status}: ${errorText.substring(0, 200)}`);
                }
                
                // Try to parse JSON
                let data;
                try {
                    const responseText = await response.text();
                    if (!responseText.trim()) {
                        throw new Error('Empty response from server');
                    }
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error(`Failed to parse response: ${parseError.message}. Response may be empty or invalid JSON.`);
                }
                
                if (data.success) {
                    // Build the success message
                    let successHtml = '<div style="color: var(--success-color);">';
                    successHtml += '<p><strong>✓ Import completed successfully!</strong></p>';
                    
                    if (deleteExisting && deleteData) {
                        successHtml += `<p>Deleted: ${deleteData.deleted_count || 0} old PC games</p>`;
                    }
                    
                    successHtml += `<p>Imported: ${data.imported} games</p>`;
                    
                    if (!deleteExisting) {
                        successHtml += `<p>Skipped: ${data.skipped} games (already in collection)</p>`;
                    }
                    
                    if (data.errors > 0) {
                        successHtml += `<p style="color: var(--error-color);">Errors: ${data.errors}</p>`;
                    }
                    
                    successHtml += '</div>';
                    
                    resultDiv.innerHTML = successHtml;
                    showNotification(data.message, 'success');
                } else {
                    resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ ${data.message || 'Import failed'}</p>`;
                    showNotification(data.message || 'Import failed', 'error');
                }
            } catch (error) {
                console.error('Error importing Steam library:', error);
                resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ Error importing Steam library: ${error.message}</p>`;
                showNotification('Error importing Steam library: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
        
        // Check if Steam credentials are configured and enable import button
        async function checkSteamImportAvailability() {
            try {
                const response = await fetch('api/settings.php?action=get');
                const data = await response.json();
                
                const importBtn = document.getElementById('importSteamBtn');
                if (importBtn) {
                    if (data.success && data.settings) {
                        if (data.settings.steam_api_key && data.settings.steam_user_id) {
                            importBtn.disabled = false;
                        } else {
                            importBtn.disabled = true;
                            importBtn.title = 'Please configure Steam credentials first';
                        }
                    } else {
                        importBtn.disabled = true;
                        importBtn.title = 'Please configure Steam credentials first';
                    }
                }
            } catch (error) {
                console.error('Error checking Steam credentials:', error);
            }
        }
        
        // Load settings on page load
        loadSettings();
        loadSteamSettings();
        checkSteamImportAvailability();
        
        // Load background image on body if set
        async function loadBackgroundImage() {
            try {
                const response = await fetch('api/settings.php?action=get');
                const data = await response.json();
                
                if (data.success && data.settings.background_image) {
                    document.body.style.backgroundImage = `url(uploads/${data.settings.background_image})`;
                    document.body.classList.add('custom-background');
                }
            } catch (error) {
                console.error('Error loading background:', error);
            }
        }
        
        loadBackgroundImage();
        
        // Handle GameEye CSV import
        document.getElementById('gameeyeForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const fileInput = document.getElementById('gameeyeCsvFile');
            if (!fileInput.files[0]) {
                showNotification('Please select a CSV file', 'error');
                return;
            }
            
            if (!confirm('This will import all games, consoles, and accessories from your GameEye CSV file. Games that already exist will be updated. This may take a few minutes. Continue?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('csv_file', fileInput.files[0]);
            
            const btn = document.getElementById('importGameeyeBtn');
            const resultDiv = document.getElementById('gameeyeImportResult');
            const originalText = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Importing...';
            resultDiv.style.display = 'block';
            resultDiv.innerHTML = '<p>Importing from CSV... This may take a few minutes.</p>';
            
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutes timeout
                
                let response;
                try {
                    response = await fetch('api/import-gameeye.php', {
                        method: 'POST',
                        body: formData,
                        signal: controller.signal
                    });
                } catch (error) {
                    clearTimeout(timeoutId);
                    if (error.name === 'AbortError') {
                        throw new Error('Import timed out after 10 minutes. The import may still be processing in the background.');
                    }
                    throw error;
                }
                clearTimeout(timeoutId);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(`Server returned ${response.status}: ${errorText.substring(0, 200)}`);
                }
                
                const responseText = await response.text();
                if (!responseText.trim()) {
                    throw new Error('Empty response from server');
                }
                
                const data = JSON.parse(responseText);
                
                if (data.success) {
                    let successHtml = '<div style="color: var(--success-color);">';
                    successHtml += '<p><strong>✓ Import completed successfully!</strong></p>';
                    successHtml += `<p>Imported: ${data.imported || 0} games, ${data.imported_items || 0} items</p>`;
                    
                    if (data.updated > 0) {
                        successHtml += `<p>Updated: ${data.updated} existing games</p>`;
                    }
                    
                    if (data.skipped > 0) {
                        successHtml += `<p>Skipped: ${data.skipped} items (wishlist/non-games)</p>`;
                    }
                    
                    if (data.errors > 0) {
                        successHtml += `<p style="color: var(--error-color);">Errors: ${data.errors}</p>`;
                        if (data.error_messages && data.error_messages.length > 0) {
                            successHtml += '<details style="margin-top: 10px;"><summary>Error details</summary><ul style="margin: 5px 0; padding-left: 20px;">';
                            data.error_messages.forEach(msg => {
                                successHtml += `<li style="font-size: 0.9em;">${escapeHtml(msg)}</li>`;
                            });
                            successHtml += '</ul></details>';
                        }
                    }
                    
                    successHtml += '</div>';
                    
                    resultDiv.innerHTML = successHtml;
                    showNotification(data.message || 'Import completed successfully!', 'success');
                    fileInput.value = '';
                    
                    // Refresh the page after a short delay to show new games
                    setTimeout(() => {
                        if (confirm('Import completed! Would you like to go to your dashboard to see the imported games?')) {
                            window.location.href = 'dashboard.php';
                        }
                    }, 2000);
                } else {
                    resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ ${data.message || 'Import failed'}</p>`;
                    showNotification(data.message || 'Import failed', 'error');
                }
            } catch (error) {
                console.error('Error importing GameEye CSV:', error);
                resultDiv.innerHTML = `<p style="color: var(--error-color);">✗ Error importing CSV: ${error.message}</p>`;
                showNotification('Error importing CSV: ' + error.message, 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = originalText;
            }
        });
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Setup dark mode toggle
        setupDarkMode();
    </script>
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

