<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Game Tracker - Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <h1>My Collection</h1>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <a href="stats.php" class="btn btn-secondary">Stats</a>
                <a href="completions.php" class="btn btn-secondary">Completions</a>
                <a href="settings.php" class="btn btn-secondary">Settings</a>
                <button id="addGameBtn" class="btn btn-primary">+ Add Game</button>
                <button id="addItemBtn" class="btn btn-primary" style="display: none;">+ Add Item</button>
                <button id="logoutBtn" class="btn btn-secondary">Logout</button>
            </div>
        </header>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-button" data-tab="games">Games</button>
            <button class="tab-button" data-tab="consoles">Consoles</button>
            <button class="tab-button" data-tab="accessories">Accessories</button>
        </div>
        
        <div class="toolbar" id="gamesToolbar">
            <div class="search-container">
                <input type="text" id="searchInput" placeholder="Search games..." class="search-input">
            </div>
            
            <div class="filter-container">
                <select id="platformFilter" class="filter-select">
                    <option value="">All Platforms</option>
                </select>
                
                <select id="genreFilter" class="filter-select">
                    <option value="">All Genres</option>
                </select>
                
                <select id="typeFilter" class="filter-select">
                    <option value="">All Types</option>
                    <option value="physical">Physical</option>
                    <option value="digital">Digital</option>
                </select>
                
                <select id="playedFilter" class="filter-select">
                    <option value="">All Games</option>
                    <option value="1">Played</option>
                    <option value="0">Not Played</option>
                </select>
                
                <select id="sortSelect" class="filter-select">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="release-newest">Release Date (Newest)</option>
                    <option value="release-oldest">Release Date (Oldest)</option>
                    <option value="title-asc">Title (A-Z)</option>
                    <option value="title-desc">Title (Z-A)</option>
                    <option value="price-low">Price (Low to High)</option>
                    <option value="price-high">Price (High to Low)</option>
                    <option value="rating-high">Rating (High to Low)</option>
                    <option value="rating-low">Rating (Low to High)</option>
                </select>
            </div>
            
            <div class="view-toggle">
                <button id="listViewBtn" class="view-btn active" title="List View">
                    <span>☰</span>
                </button>
                <button id="gridViewBtn" class="view-btn" title="Grid View">
                    <span>⊞</span>
                </button>
            </div>
        </div>
        
        <!-- Items Toolbar (for consoles/accessories) -->
        <div class="toolbar" id="itemsToolbar" style="display: none;">
            <div class="search-container">
                <input type="text" id="itemsSearchInput" placeholder="Search items..." class="search-input">
            </div>
            
            <div class="view-toggle">
                <button id="itemsListViewBtn" class="view-btn active" title="List View">
                    <span>☰</span>
                </button>
                <button id="itemsGridViewBtn" class="view-btn" title="Grid View">
                    <span>⊞</span>
                </button>
            </div>
        </div>
        
        <!-- Games Tab Content -->
        <div id="gamesTab" class="tab-content">
            <div id="gamesContainer" class="games-container list-view">
                <div class="loading">Loading games...</div>
            </div>
        </div>
        
        <!-- Consoles Tab Content -->
        <div id="consolesTab" class="tab-content" style="display: none;">
            <div id="consolesContainer" class="games-container list-view">
                <div class="loading">Loading consoles...</div>
            </div>
        </div>
        
        <!-- Accessories Tab Content -->
        <div id="accessoriesTab" class="tab-content" style="display: none;">
            <div id="accessoriesContainer" class="games-container list-view">
                <div class="loading">Loading accessories...</div>
            </div>
        </div>
    </div>
    
    <!-- Add Game Modal -->
    <div id="addGameModal" class="modal" style="display: none;">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>Add New Game</h2>
                <button class="modal-close">&times;</button>
            </div>
            <form id="addGameForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="addTitle">Title *</label>
                        <input type="text" id="addTitle" name="title" required>
                        <button type="button" id="fetchCoverBtn" class="btn btn-small" style="margin-top: 5px;">Auto-fetch Cover</button>
                    </div>
                    <div class="form-group">
                        <label for="addPlatform">Platform *</label>
                        <input type="text" id="addPlatform" name="platform" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addGenre">Genre</label>
                        <div class="input-with-button">
                            <input type="text" id="addGenre" name="genre">
                            <button type="button" id="fetchMetadataBtn" class="btn btn-small">Auto-fetch</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="addReleaseDate">Release Date</label>
                        <input type="date" id="addReleaseDate" name="release_date">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addSeries">Series</label>
                        <input type="text" id="addSeries" name="series">
                    </div>
                    <div class="form-group">
                        <label for="addSpecialEdition">Special Edition</label>
                        <input type="text" id="addSpecialEdition" name="special_edition">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addCondition">Condition (Physical Only)</label>
                        <input type="text" id="addCondition" name="condition" placeholder="e.g., New, Like New, Good">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="addDescription">Description</label>
                    <textarea id="addDescription" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addStarRating">Star Rating (0-5)</label>
                        <input type="number" id="addStarRating" name="star_rating" min="0" max="5">
                    </div>
                    <div class="form-group">
                        <label for="addMetacriticRating">Metacritic Rating</label>
                        <div class="input-with-button">
                            <input type="number" id="addMetacriticRating" name="metacritic_rating" min="0" max="100">
                            <button type="button" id="fetchMetacriticAddBtn" class="btn btn-small">Fetch</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addPricePaid">Price I Paid</label>
                        <div class="price-input-group">
                            <input type="number" id="addPricePaid" name="price_paid" step="0.01" min="0" placeholder="0.00">
                            <label class="na-checkbox-label">
                                <input type="checkbox" id="addPricePaidNA" class="na-checkbox"> N/A
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="addPricechartingPrice">Pricecharting Price</label>
                        <div class="input-with-button">
                            <input type="number" id="addPricechartingPrice" name="pricecharting_price" step="0.01" min="0" placeholder="0.00" readonly>
                            <button type="button" id="fetchPriceAddBtn" class="btn btn-small">Fetch</button>
                        </div>
                        <label class="na-checkbox-label" style="margin-top: 5px;">
                            <input type="checkbox" id="addPricechartingNA" class="na-checkbox"> N/A
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="addReview">My Review</label>
                    <textarea id="addReview" name="review" rows="6"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="addIsPhysical" name="is_physical" checked>
                            Physical Copy
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="addPlayed" name="played">
                            I have played this game
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Front Cover Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="addFrontCover" name="front_cover" accept="image/*">
                        <div id="addFrontCoverPreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="addFrontCover">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="addFrontCoverUrl" placeholder="https://example.com/cover.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="addFrontCoverUrlBtn" style="margin-top: 5px;">Use URL</button>
                            <button type="button" class="btn btn-small" id="addFrontCoverSplitBtn" style="margin-top: 5px; display: none;">Split Combined Cover</button>
                            <button type="button" class="btn btn-small" id="addFrontCoverAutoSplitBtn" style="margin-top: 5px; display: none;">Auto Split (53%)</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Back Cover Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="addBackCover" name="back_cover" accept="image/*">
                        <div id="addBackCoverPreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="addBackCover">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="addBackCoverUrl" placeholder="https://example.com/cover.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="addBackCoverUrlBtn" style="margin-top: 5px;">Use URL</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Game</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Item Modal -->
    <div id="addItemModal" class="modal" style="display: none;">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>Add New Item</h2>
                <button class="modal-close">&times;</button>
            </div>
            <form id="addItemForm">
                <div class="form-row">
                    <div class="form-group">
                        <label for="addItemTitle">Title *</label>
                        <input type="text" id="addItemTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="addItemPlatform">Platform</label>
                        <input type="text" id="addItemPlatform" name="platform">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addItemCategory">Category *</label>
                        <select id="addItemCategory" name="category" required>
                            <option value="">Select Category</option>
                            <option value="Systems">Systems</option>
                            <option value="Controllers">Controllers</option>
                            <option value="Game Accessories">Game Accessories</option>
                            <option value="Toys To Life">Toys To Life</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="addItemCondition">Condition</label>
                        <input type="text" id="addItemCondition" name="condition" placeholder="e.g., New, Like New, Good">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="addItemPricePaid">Price I Paid</label>
                        <input type="number" id="addItemPricePaid" name="price_paid" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label for="addItemPricechartingPrice">Pricecharting Price</label>
                        <input type="number" id="addItemPricechartingPrice" name="pricecharting_price" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-group" id="addItemQuantityGroup" style="display: none;">
                    <label for="addItemQuantity">Quantity</label>
                    <input type="number" id="addItemQuantity" name="quantity" min="1" value="1">
                    <small style="color: var(--text-secondary);">How many of this accessory do you own?</small>
                </div>
                
                <div class="form-group">
                    <label for="addItemDescription">Description</label>
                    <textarea id="addItemDescription" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="addItemNotes">Notes</label>
                    <textarea id="addItemNotes" name="notes" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Front Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="addItemFrontImage" name="front_image" accept="image/*">
                        <div id="addItemFrontImagePreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="addItemFrontImage">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="addItemFrontImageUrl" placeholder="https://example.com/image.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="addItemFrontImageUrlBtn" style="margin-top: 5px;">Use URL</button>
                            <button type="button" class="btn btn-small" id="addItemFrontImageSplitBtn" style="margin-top: 5px; display: none;">Split Combined Cover</button>
                            <button type="button" class="btn btn-small" id="addItemFrontImageAutoSplitBtn" style="margin-top: 5px; display: none;">Auto Split (53%)</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Back Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="addItemBackImage" name="back_image" accept="image/*">
                        <div id="addItemBackImagePreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="addItemBackImage">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="addItemBackImageUrl" placeholder="https://example.com/image.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="addItemBackImageUrlBtn" style="margin-top: 5px;">Use URL</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Image Split Modal -->
    <div id="imageSplitModal" class="modal" style="display: none;">
        <div class="modal-content" style="max-width: 800px;">
            <div class="modal-header">
                <h2>Split Combined Cover Image</h2>
                <button type="button" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <p>Adjust the slider to set where to split the image into front and back covers.</p>
                <div style="text-align: center; margin: 20px 0;">
                    <div id="splitImageContainer" style="position: relative; display: inline-block; max-width: 100%;">
                        <img id="splitImagePreview" src="" alt="Combined Cover" style="max-width: 100%; max-height: 500px; display: block;">
                        <div id="splitLine" style="position: absolute; top: 0; bottom: 0; width: 2px; background: red; pointer-events: none; display: none;"></div>
                    </div>
                </div>
                <div style="margin: 20px 0;">
                    <label>Split Position: <span id="splitPositionValue">50%</span></label>
                    <input type="range" id="splitPositionSlider" min="0" max="100" value="50" style="width: 100%;">
                    <div style="display: flex; gap: 10px; margin-top: 10px;">
                        <label style="flex: 1;">
                            <input type="radio" name="splitDirection" value="horizontal" checked> Split Horizontally (Top/Bottom)
                        </label>
                        <label style="flex: 1;">
                            <input type="radio" name="splitDirection" value="vertical"> Split Vertically (Left/Right)
                        </label>
                    </div>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <div style="flex: 1;">
                        <label>Front Cover Preview:</label>
                        <canvas id="frontSplitPreview" style="max-width: 100%; border: 1px solid #ddd; display: block;"></canvas>
                    </div>
                    <div style="flex: 1;">
                        <label>Back Cover Preview:</label>
                        <canvas id="backSplitPreview" style="max-width: 100%; border: 1px solid #ddd; display: block;"></canvas>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancelSplitBtn">Cancel</button>
                <button type="button" class="btn btn-secondary" id="autoSplitBtn">Auto Split (53%)</button>
                <button type="button" class="btn btn-primary" id="applySplitBtn">Apply Split</button>
            </div>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    <script src="js/games.js"></script>
    <script src="js/filters.js"></script>
    <script src="js/items.js"></script>
    <script src="js/add-item.js"></script>
    <script>
        // Tab switching
        /**
         * Switch to a specific tab
         */
        function switchToTab(tabName) {
            // Update buttons
            document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
            const activeButton = document.querySelector(`.tab-button[data-tab="${tabName}"]`);
            if (activeButton) {
                activeButton.classList.add('active');
            }
            
            // Update content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            
            const targetTab = document.getElementById(tabName + 'Tab');
            if (targetTab) {
                targetTab.classList.add('active');
                targetTab.style.display = 'block';
            }
            
            // Show/hide toolbars
            const gamesToolbar = document.getElementById('gamesToolbar');
            const itemsToolbar = document.getElementById('itemsToolbar');
            
            if (gamesToolbar) {
                gamesToolbar.style.display = tabName === 'games' ? 'block' : 'none';
            }
            if (itemsToolbar) {
                itemsToolbar.style.display = (tabName === 'consoles' || tabName === 'accessories') ? 'block' : 'none';
            }
            
            // Show/hide add buttons
            const addGameBtn = document.getElementById('addGameBtn');
            const addItemBtn = document.getElementById('addItemBtn');
            
            if (addGameBtn) {
                addGameBtn.style.display = tabName === 'games' ? 'inline-block' : 'none';
            }
            if (addItemBtn) {
                addItemBtn.style.display = (tabName === 'consoles' || tabName === 'accessories') ? 'inline-block' : 'none';
            }
            
            // Save to localStorage
            localStorage.setItem('activeTab', tabName);
            
            // Load appropriate data (use setTimeout to ensure DOM is updated)
            setTimeout(() => {
                if (tabName === 'games') {
                    if (typeof loadGames === 'function') {
                        loadGames();
                    }
                } else if (tabName === 'consoles') {
                    // Use window.loadItems if available, or try direct call
                        const loadFn = window.loadItems || (typeof loadItems !== 'undefined' ? loadItems : null);
                        if (loadFn) {
                            loadFn('Systems');
                        } else {
                            console.error('loadItems function not found! Available functions:', Object.keys(window).filter(k => k.includes('load')));
                        }
                    } else if (tabName === 'accessories') {
                        const loadFn = window.loadItems || (typeof loadItems !== 'undefined' ? loadItems : null);
                        if (loadFn) {
                            loadFn('Controllers,Game Accessories,Toys To Life');
                        } else {
                            console.error('loadItems function not found!');
                        }
                    }
                }, 10);
        }
        
        // Set up tab button click handlers
        document.querySelectorAll('.tab-button').forEach(button => {
            button.addEventListener('click', function() {
                const tabName = this.dataset.tab;
                switchToTab(tabName);
            });
        });
        
        // Restore active tab from localStorage on page load
        const savedTab = localStorage.getItem('activeTab');
        if (savedTab && (savedTab === 'games' || savedTab === 'consoles' || savedTab === 'accessories')) {
            switchToTab(savedTab);
        } else {
            // Default to games tab if no saved tab
            switchToTab('games');
        }
        
        // Load background image on page load
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
</body>
</html>

