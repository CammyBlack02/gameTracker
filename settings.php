<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
        
        // Load settings on page load
        loadSettings();
        
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
</body>
</html>

