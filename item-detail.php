<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
requireUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars(getCsrfToken()); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Item Details - Game Tracker</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="app-container">
        <header class="app-header">
            <a href="dashboard.php" class="back-link">← Back to Collection</a>
            <div class="header-actions">
                <button id="darkModeToggle" class="btn btn-secondary" title="Toggle Dark Mode">🌙</button>
                <button id="editItemBtn" class="btn btn-primary">Edit Item</button>
                <button id="deleteItemBtn" class="btn btn-danger">Delete Item</button>
            </div>
        </header>
        
        <div id="itemDetailContainer" class="game-detail-container">
            <div class="loading">Loading item details...</div>
        </div>
    </div>
    
    <!-- Edit Item Modal -->
    <div id="editItemModal" class="modal" style="display: none;">
        <div class="modal-content modal-large">
            <div class="modal-header">
                <h2>Edit Item</h2>
                <button class="modal-close">&times;</button>
            </div>
            <form id="editItemForm">
                <input type="hidden" id="editItemId">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editItemTitle">Title *</label>
                        <input type="text" id="editItemTitle" name="title" required>
                    </div>
                    <div class="form-group">
                        <label for="editItemPlatform">Platform</label>
                        <input type="text" id="editItemPlatform" name="platform">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editItemCategory">Category *</label>
                        <select id="editItemCategory" name="category" required>
                            <option value="Systems">Systems</option>
                            <option value="Controllers">Controllers</option>
                            <option value="Game Accessories">Game Accessories</option>
                            <option value="Toys To Life">Toys To Life</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editItemCondition">Condition</label>
                        <select id="editItemCondition" name="condition">
                            <option value="">Select Condition</option>
                            <option value="New">New</option>
                            <option value="Like New">Like New</option>
                            <option value="Good">Good</option>
                            <option value="Acceptable">Acceptable</option>
                            <option value="Poor">Poor</option>
                            <option value="Disc/Cart Only">Disc/Cart Only</option>
                            <option value="Broken">Broken</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="editItemPricePaid">Price I Paid</label>
                        <input type="number" id="editItemPricePaid" name="price_paid" step="0.01" min="0">
                    </div>
                    <div class="form-group">
                        <label for="editItemPricechartingPrice">Pricecharting Price</label>
                        <input type="number" id="editItemPricechartingPrice" name="pricecharting_price" step="0.01" min="0">
                    </div>
                </div>
                
                <div class="form-group" id="editItemQuantityGroup" style="display: none;">
                    <label for="editItemQuantity">Quantity</label>
                    <input type="number" id="editItemQuantity" name="quantity" min="1" value="1">
                    <small style="color: var(--text-secondary);">How many of this accessory do you own?</small>
                </div>
                
                <div class="form-group">
                    <label for="editItemDescription">Description</label>
                    <textarea id="editItemDescription" name="description" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="editItemNotes">Notes</label>
                    <textarea id="editItemNotes" name="notes" rows="4"></textarea>
                </div>
                
                <div class="form-group">
                    <label>Front Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="editItemFrontImage" name="front_image" accept="image/*">
                        <div id="itemFrontImagePreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="editItemFrontImage">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="editItemFrontImageUrl" placeholder="https://example.com/image.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="editItemFrontImageUrlBtn" style="margin-top: 5px;">Use URL</button>
                            <button type="button" class="btn btn-small" id="editItemFrontImageSplitBtn" style="margin-top: 5px; display: none;">Split Combined Cover</button>
                            <button type="button" class="btn btn-small" id="editItemFrontImageAutoSplitBtn" style="margin-top: 5px; display: none;">Auto Split (53%)</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Back Image</label>
                    <div class="image-upload-container">
                        <input type="file" id="editItemBackImage" name="back_image" accept="image/*">
                        <div id="itemBackImagePreview" class="image-preview"></div>
                        <button type="button" class="btn btn-small upload-image-btn" data-target="editItemBackImage">Upload</button>
                        <div style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #666;">Or enter URL:</label>
                            <input type="url" id="editItemBackImageUrl" placeholder="https://example.com/image.jpg" style="width: 100%; margin-top: 5px; padding: 5px;">
                            <button type="button" class="btn btn-small" id="editItemBackImageUrlBtn" style="margin-top: 5px;">Use URL</button>
                            <button type="button" class="btn btn-small" id="editItemBackImageSplitBtn" style="margin-top: 5px; display: none;">Split Combined Cover</button>
                            <button type="button" class="btn btn-small" id="editItemBackImageAutoSplitBtn" style="margin-top: 5px; display: none;">Auto Split (53%)</button>
                        </div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Extra Photos</label>
                    <div id="itemExtraImagesContainer" class="extra-images-container"></div>
                    <input type="file" id="addItemExtraImage" name="extra_image" accept="image/*" multiple>
                    <button type="button" id="uploadItemExtraImageBtn" class="btn btn-small">Add Photo</button>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-cancel">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="js/main.js"></script>
    
    <script src="js/api.js"></script>
    
    <script src="js/utils.js"></script>
    <script src="js/image-split.js"></script>
    <script src="js/forms/game-form.js"></script>
    <script src="js/games.js"></script>
    <script src="js/lightbox.js"></script>
    <script src="js/render/coverflow.js"></script>
    <script src="js/render/grid.js"></script>
    <script src="js/render/list.js"></script>
    <script src="js/items.js"></script>
    <script src="js/item-detail.js"></script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

