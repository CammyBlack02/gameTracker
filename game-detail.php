<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Game Details - Game Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <a href="dashboard.php" class="back-link">← Back to Collection</a>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <button id="editGameBtn" class="btn btn-primary">Edit Game</button>
                <button id="deleteGameBtn" class="btn btn-danger">Delete Game</button>
            </div>
        </header>
        
        <div id="gameDetailContainer" class="game-detail-container">
            <div class="loading">Loading game details...</div>
        </div>
    </div>
    
    <!-- Edit Game Modal -->
    <div id="editGameModal" class="modal" style="display: none;">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>Edit Game</h2>
                <button class="modal-close">&times;</button>
            </div>
            <form id="editGameForm">
                <input type="hidden" id="editGameId">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editTitle">Title *</label>
                        <input type="text" id="editTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="editPlatform">Platform *</label>
                        <input type="text" id="editPlatform" name="platform" required>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editGenre">Genre</label>
                        <div class="input-with-button">
                            <input type="text" id="editGenre" name="genre">
                            <button type="button" id="fetchMetadataEditBtn" class="btn btn-small">Auto-fetch</button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="editReleaseDate">Release Date</label>
                        <input type="date" id="editReleaseDate" name="release_date">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editSeries">Series</label>
                        <input type="text" id="editSeries" name="series">
                    </div>
                    <div class="form-group">
                        <label for="editSpecialEdition">Special Edition</label>
                        <input type="text" id="editSpecialEdition" name="special_edition">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editCondition">Condition (Physical Only)</label>
                        <input type="text" id="editCondition" name="condition" placeholder="e.g., New, Like New, Good">
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="editDescription">Description</label>
                    <textarea id="editDescription" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editStarRating">Star Rating (0-5)</label>
                        <input type="number" id="editStarRating" name="star_rating" min="0" max="5">
                    </div>
                    <div class="form-group">
                        <label for="editMetacriticRating">Metacritic Rating</label>
                        <div class="input-with-button">
                            <input type="number" id="editMetacriticRating" name="metacritic_rating" min="0" max="100">
                            <button type="button" id="fetchMetacriticBtn" class="btn btn-small">Fetch</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editPricePaid">Price I Paid</label>
                        <div class="price-input-group">
                            <input type="number" id="editPricePaid" name="price_paid" step="0.01" min="0">
                            <label class="na-checkbox-label">
                                <input type="checkbox" id="editPricePaidNA" class="na-checkbox"> N/A
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="editPricechartingPrice">Pricecharting Price</label>
                        <div class="input-with-button">
                            <input type="number" id="editPricechartingPrice" name="pricecharting_price" step="0.01" min="0" readonly>
                            <button type="button" id="fetchPriceBtn" class="btn btn-small">Fetch</button>
                        </div>
                        <label class="na-checkbox-label" style="margin-top: 5px;">
                            <input type="checkbox" id="editPricechartingNA" class="na-checkbox"> N/A
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="editReview">My Review</label>
                    <textarea id="editReview" name="review" rows="6"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="editIsPhysical" name="is_physical">
                            Physical Copy
                        </label>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" id="editPlayed" name="played">
                            I have played this game
                        </label>
                    </div>
                </div>
                
                <div class="form-group" id="editDigitalStoreGroup" style="display: none;">
                    <label for="editDigitalStore">Digital Store (PC Only)</label>
                    <select id="editDigitalStore" name="digital_store" class="form-input">
                        <option value="">None</option>
                        <option value="Steam">Steam</option>
                        <option value="EA App">EA App</option>
                        <option value="GOG">GOG</option>
                        <option value="Epic Games">Epic Games</option>
                        <option value="Battle.net">Battle.net</option>
                        <option value="Ubisoft Connect">Ubisoft Connect</option>
                        <option value="Microsoft Store">Microsoft Store</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Front Cover Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="editFrontCover" name="front_cover" accept="image/*">
                        <div id="frontCoverPreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="editFrontCover">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="editFrontCoverUrl" placeholder="https://example.com/cover.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="editFrontCoverUrlBtn" style="margin-top: 5px;">Use URL</button>
                            <button type="button" class="btn btn-small" id="editFrontCoverSplitBtn" style="margin-top: 5px; display: none;">Split Combined Cover</button>
                            <button type="button" class="btn btn-small" id="editFrontCoverAutoSplitBtn" style="margin-top: 5px; display: none;">Auto Split (53%)</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Back Cover Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="editBackCover" name="back_cover" accept="image/*">
                        <div id="backCoverPreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="editBackCover">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="editBackCoverUrl" placeholder="https://example.com/cover.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="editBackCoverUrlBtn" style="margin-top: 5px;">Use URL</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Extra Photos</label>
                    <div id="extraImagesContainer" class="extra-images-container"></div>
                    <input type="file" id="addExtraImage" name="extra_image" accept="image/*" multiple>
                    <button type="button" id="uploadExtraImageBtn" class="btn btn-small">Add Photo</button>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
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
    <script>
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

