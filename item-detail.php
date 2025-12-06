<?php require_once __DIR__ . '/includes/auth-check.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
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
                        <input type="text" id="editItemCondition" name="condition" placeholder="e.g., New, Like New, Good">
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
    <script src="js/games.js"></script>
    <script src="js/items.js"></script>
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
        
        // Load item detail
        async function loadItemDetail() {
            const urlParams = new URLSearchParams(window.location.search);
            const itemId = urlParams.get('id');
            
            if (!itemId) {
                document.getElementById('itemDetailContainer').innerHTML = '<div class="error">Item ID not provided</div>';
                return;
            }
            
            try {
                const response = await fetch(`api/items.php?action=get&id=${itemId}`);
                const data = await response.json();
                
                if (data.success) {
                    displayItemDetail(data.item);
                } else {
                    document.getElementById('itemDetailContainer').innerHTML = '<div class="error">Item not found</div>';
                }
            } catch (error) {
                console.error('Error loading item:', error);
                document.getElementById('itemDetailContainer').innerHTML = '<div class="error">Error loading item</div>';
            }
        }
        
        /**
         * Get image URL - handles both external URLs and local paths
         */
        function getItemImageUrl(imagePath) {
            if (!imagePath) return null;
            // Check if it's already a full URL
            if (imagePath.startsWith('http://') || imagePath.startsWith('https://')) {
                return imagePath;
            }
            // Otherwise, it's a local file
            return `uploads/covers/${imagePath}`;
        }
        
        function displayItemDetail(item) {
            const container = document.getElementById('itemDetailContainer');
            
            const frontImage = item.front_image 
                ? `<img src="${getItemImageUrl(item.front_image)}" alt="Front Image" class="cover-image">`
                : '<div class="cover-placeholder">No Front Image</div>';
            
            const backImage = item.back_image 
                ? `<img src="${getItemImageUrl(item.back_image)}" alt="Back Image" class="cover-image">`
                : '<div class="cover-placeholder">No Back Image</div>';
            
            const extraImages = item.extra_images && item.extra_images.length > 0
                ? item.extra_images.map((img, index) => 
                    `<img src="uploads/extras/${img.image_path}" alt="Extra Photo ${index + 1}" class="extra-image-thumb" data-index="${index}" data-image-path="${img.image_path}">`
                ).join('')
                : '<p>No extra photos</p>';
            
            container.innerHTML = `
                <div class="game-detail">
                    <div class="game-detail-header">
                        <div class="game-covers">
                            <div class="cover-section">
                                <h3>Front Image</h3>
                                ${frontImage}
                            </div>
                            <div class="cover-section">
                                <h3>Back Image</h3>
                                ${backImage}
                            </div>
                        </div>
                        <div class="game-info-header">
                            <h1>${escapeHtml(item.title)}${item.category !== 'Systems' && item.category !== 'Console' && item.quantity > 1 ? ` <span style="color: var(--text-secondary); font-size: 0.8em; font-weight: normal;">(×${item.quantity})</span>` : ''}</h1>
                            <div class="game-meta">
                                ${item.platform ? `<span class="platform-badge">${escapeHtml(item.platform)}</span>` : ''}
                                <span class="badge badge-physical">${escapeHtml(item.category)}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="game-detail-content">
                        <div class="detail-section">
                            <h2>Details</h2>
                            <dl class="detail-list">
                                <dt>Category:</dt>
                                <dd>${escapeHtml(item.category)}</dd>
                                
                                ${item.platform ? `
                                <dt>Platform:</dt>
                                <dd>${escapeHtml(item.platform)}</dd>
                                ` : ''}
                                
                                ${item.condition ? `
                                <dt>Condition:</dt>
                                <dd>${escapeHtml(item.condition)}</dd>
                                ` : ''}
                                
                                ${item.category !== 'Systems' && item.category !== 'Console' && item.quantity ? `
                                <dt>Quantity:</dt>
                                <dd>${item.quantity}</dd>
                                ` : ''}
                                
                                <dt>Price I Paid:</dt>
                                <dd>${formatCurrency(item.price_paid)}</dd>
                                
                                <dt>Pricecharting Price:</dt>
                                <dd>${formatCurrency(item.pricecharting_price)}</dd>
                            </dl>
                        </div>
                        
                        ${item.description ? `
                        <div class="detail-section">
                            <h2>Description</h2>
                            <p>${escapeHtml(item.description)}</p>
                        </div>
                        ` : ''}
                        
                        ${item.notes ? `
                        <div class="detail-section">
                            <h2>Notes</h2>
                            <p>${escapeHtml(item.notes)}</p>
                        </div>
                        ` : ''}
                        
                        <div class="detail-section">
                            <h2>Extra Photos</h2>
                            <div class="extra-images-gallery">
                                ${extraImages}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            // Store item data for editing
            window.currentItem = item;
            
            // Setup lightbox for extra images
            setupImageLightbox();
        }
        
        // Setup edit and delete buttons
        document.getElementById('editItemBtn')?.addEventListener('click', () => {
            if (window.currentItem) {
                populateEditForm(window.currentItem);
                showModal('editItemModal');
            }
        });
        
        document.getElementById('deleteItemBtn')?.addEventListener('click', async () => {
            if (!window.currentItem) return;
            
            if (!confirm('Are you sure you want to delete this item?')) {
                return;
            }
            
            try {
                const response = await fetch('api/items.php?action=delete', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: window.currentItem.id })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Item deleted successfully', 'success');
                    setTimeout(() => {
                        window.location.href = 'dashboard.php';
                    }, 1000);
                } else {
                    showNotification(data.message || 'Failed to delete item', 'error');
                }
            } catch (error) {
                console.error('Error deleting item:', error);
                showNotification('Error deleting item', 'error');
            }
        });
        
        function populateEditForm(item) {
            document.getElementById('editItemId').value = item.id;
            document.getElementById('editItemTitle').value = item.title || '';
            document.getElementById('editItemPlatform').value = item.platform || '';
            document.getElementById('editItemCategory').value = item.category || '';
            document.getElementById('editItemCondition').value = item.condition || '';
            document.getElementById('editItemDescription').value = item.description || '';
            document.getElementById('editItemNotes').value = item.notes || '';
            document.getElementById('editItemPricePaid').value = item.price_paid || '';
            document.getElementById('editItemPricechartingPrice').value = item.pricecharting_price || '';
            
            // Handle quantity field visibility
            const quantityGroup = document.getElementById('editItemQuantityGroup');
            const quantityInput = document.getElementById('editItemQuantity');
            if (quantityGroup && quantityInput) {
                const category = item.category || '';
                if (category && category !== 'Systems' && category !== 'Console') {
                    quantityGroup.style.display = 'block';
                    quantityInput.value = item.quantity || 1;
                } else {
                    quantityGroup.style.display = 'none';
                    quantityInput.value = 1;
                }
            }
            
            if (item.front_image) {
                const frontImageUrl = getItemImageUrl(item.front_image);
                document.getElementById('itemFrontImagePreview').innerHTML = 
                    `<img src="${frontImageUrl}" alt="Front Image" style="max-width: 200px;">`;
                
                // Populate URL field if it's a URL
                const frontUrlInput = document.getElementById('editItemFrontImageUrl');
                if (frontUrlInput && (item.front_image.startsWith('http://') || item.front_image.startsWith('https://') || item.front_image.startsWith('data:'))) {
                    frontUrlInput.value = item.front_image;
                    // Show split buttons if it's an external URL
                    if (item.front_image.startsWith('http://') || item.front_image.startsWith('https://')) {
                        const splitBtn = document.getElementById('editItemFrontImageSplitBtn');
                        const autoSplitBtn = document.getElementById('editItemFrontImageAutoSplitBtn');
                        if (splitBtn) {
                            splitBtn.style.display = 'inline-block';
                            splitBtn.dataset.imageUrl = item.front_image;
                        }
                        if (autoSplitBtn) {
                            autoSplitBtn.style.display = 'inline-block';
                            autoSplitBtn.dataset.imageUrl = item.front_image;
                        }
                    }
                }
            }
            if (item.back_image) {
                const backImageUrl = getItemImageUrl(item.back_image);
                document.getElementById('itemBackImagePreview').innerHTML = 
                    `<img src="${backImageUrl}" alt="Back Image" style="max-width: 200px;">`;
                
                // Populate URL field if it's a URL
                const backUrlInput = document.getElementById('editItemBackImageUrl');
                if (backUrlInput && (item.back_image.startsWith('http://') || item.back_image.startsWith('https://') || item.back_image.startsWith('data:'))) {
                    backUrlInput.value = item.back_image;
                    // Show split buttons if it's an external URL
                    if (item.back_image.startsWith('http://') || item.back_image.startsWith('https://')) {
                        const splitBtn = document.getElementById('editItemBackImageSplitBtn');
                        const autoSplitBtn = document.getElementById('editItemBackImageAutoSplitBtn');
                        if (splitBtn) {
                            splitBtn.style.display = 'inline-block';
                            splitBtn.dataset.imageUrl = item.back_image;
                        }
                        if (autoSplitBtn) {
                            autoSplitBtn.style.display = 'inline-block';
                            autoSplitBtn.dataset.imageUrl = item.back_image;
                        }
                    }
                }
            }
        }
        
        // Setup image uploads for items
        document.querySelectorAll('.upload-image-btn[data-target^="editItem"]').forEach(btn => {
            btn.addEventListener('click', function() {
                const targetId = this.dataset.target;
                console.log('Upload button clicked, target:', targetId);
                const fileInput = document.getElementById(targetId);
                if (fileInput) {
                    fileInput.click();
                } else {
                    console.error('File input not found:', targetId);
                }
            });
        });
        
        // Handle front image upload
        const frontImageInput = document.getElementById('editItemFrontImage');
        if (frontImageInput) {
            frontImageInput.addEventListener('change', async function() {
                console.log('Front image file selected:', this.files);
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    console.log('Uploading file:', file.name, 'Size:', file.size, 'Type:', file.type);
                    
                    // Check file size (5MB limit - matches php.ini)
                    const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                    if (file.size > maxSize) {
                        showNotification('File is too large. Maximum size is 5MB. Please compress or resize the image.', 'error');
                        this.value = ''; // Clear the input
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('type', 'cover');
                    
                    try {
                        console.log('Sending upload request...');
                        const response = await fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        console.log('Upload response status:', response.status);
                        const data = await response.json();
                        console.log('Upload response data:', data);
                        
                        if (data.success) {
                            // Update the preview
                            document.getElementById('itemFrontImagePreview').innerHTML = 
                                `<img src="uploads/covers/${data.image_path}" alt="Front Image" style="max-width: 200px;">`;
                            
                            // Store the image path for form submission
                            if (!window.currentItem) window.currentItem = {};
                            window.currentItem.front_image = data.image_path;
                            console.log('Image path stored:', data.image_path);
                            
                            showNotification('Front image uploaded successfully', 'success');
                        } else {
                            console.error('Upload failed:', data.message);
                            showNotification(data.message || 'Failed to upload image', 'error');
                        }
                    } catch (error) {
                        console.error('Error uploading image:', error);
                        showNotification('Error uploading image: ' + error.message, 'error');
                    }
                } else {
                    console.error('No file selected');
                }
            });
        } else {
            console.error('Front image input not found!');
        }
        
        // Handle back image upload
        const backImageInput = document.getElementById('editItemBackImage');
        if (backImageInput) {
            backImageInput.addEventListener('change', async function() {
                console.log('Back image file selected:', this.files);
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    console.log('Uploading file:', file.name, 'Size:', file.size, 'Type:', file.type);
                    
                    // Check file size (5MB limit - matches php.ini)
                    const maxSize = 5 * 1024 * 1024; // 5MB in bytes
                    if (file.size > maxSize) {
                        showNotification('File is too large. Maximum size is 5MB. Please compress or resize the image.', 'error');
                        this.value = ''; // Clear the input
                        return;
                    }
                    
                    const formData = new FormData();
                    formData.append('image', file);
                    formData.append('type', 'cover');
                    
                    try {
                        console.log('Sending upload request...');
                        const response = await fetch('api/upload.php', {
                            method: 'POST',
                            body: formData
                        });
                        
                        console.log('Upload response status:', response.status);
                        const data = await response.json();
                        console.log('Upload response data:', data);
                        
                        if (data.success) {
                            // Update the preview
                            document.getElementById('itemBackImagePreview').innerHTML = 
                                `<img src="uploads/covers/${data.image_path}" alt="Back Image" style="max-width: 200px;">`;
                            
                            // Store the image path for form submission
                            if (!window.currentItem) window.currentItem = {};
                            window.currentItem.back_image = data.image_path;
                            console.log('Image path stored:', data.image_path);
                            
                            showNotification('Back image uploaded successfully', 'success');
                        } else {
                            console.error('Upload failed:', data.message);
                            showNotification(data.message || 'Failed to upload image', 'error');
                        }
                    } catch (error) {
                        console.error('Error uploading image:', error);
                        showNotification('Error uploading image: ' + error.message, 'error');
                    }
                } else {
                    console.error('No file selected');
                }
            });
        } else {
            console.error('Back image input not found!');
        }
        
        // Setup category change listener for quantity field
        const editCategorySelect = document.getElementById('editItemCategory');
        const editQuantityGroup = document.getElementById('editItemQuantityGroup');
        if (editCategorySelect && editQuantityGroup) {
            editCategorySelect.addEventListener('change', function() {
                const category = this.value;
                const quantityInput = document.getElementById('editItemQuantity');
                if (category && category !== 'Systems' && category !== 'Console') {
                    editQuantityGroup.style.display = 'block';
                } else {
                    editQuantityGroup.style.display = 'none';
                    if (quantityInput) {
                        quantityInput.value = 1;
                    }
                }
            });
        }
        
        // Setup form submission
        document.getElementById('editItemForm')?.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const category = document.getElementById('editItemCategory').value;
            const quantityInput = document.getElementById('editItemQuantity');
            
            // Prioritize URL inputs over uploaded images
            const frontUrlInput = document.getElementById('editItemFrontImageUrl');
            const backUrlInput = document.getElementById('editItemBackImageUrl');
            let frontImage = window.currentItem?.front_image || null;
            let backImage = window.currentItem?.back_image || null;
            
            // Check if URL inputs have values
            if (frontUrlInput && frontUrlInput.value.trim()) {
                frontImage = frontUrlInput.value.trim();
            }
            if (backUrlInput && backUrlInput.value.trim()) {
                backImage = backUrlInput.value.trim();
            }
            
            const formData = {
                id: window.currentItem.id,
                title: document.getElementById('editItemTitle').value,
                platform: document.getElementById('editItemPlatform').value || null,
                category: category,
                condition: document.getElementById('editItemCondition').value || null,
                description: document.getElementById('editItemDescription').value || null,
                notes: document.getElementById('editItemNotes').value || null,
                price_paid: document.getElementById('editItemPricePaid').value || null,
                pricecharting_price: document.getElementById('editItemPricechartingPrice').value || null,
                front_image: frontImage,
                back_image: backImage
            };
            
            // Only include quantity for accessories (not Systems/Console)
            if (category !== 'Systems' && category !== 'Console' && quantityInput) {
                formData.quantity = parseInt(quantityInput.value) || 1;
            }
            
            console.log('Submitting form with data:', formData);
            console.log('Current item front_image:', window.currentItem?.front_image);
            console.log('Current item back_image:', window.currentItem?.back_image);
            
            try {
                const response = await fetch('api/items.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                console.log('Update response status:', response.status);
                const data = await response.json();
                console.log('Update response data:', data);
                
                if (data.success) {
                    showNotification('Item updated successfully', 'success');
                    hideModal('editItemModal');
                    loadItemDetail();
                } else {
                    console.error('Update failed:', data.message);
                    showNotification(data.message || 'Failed to update item', 'error');
                }
            } catch (error) {
                console.error('Error updating item:', error);
                showNotification('Error updating item', 'error');
            }
        });
        
        /**
         * Setup URL inputs for edit item form
         */
        function setupEditItemUrlInputs() {
            // Front image URL
            const frontUrlBtn = document.getElementById('editItemFrontImageUrlBtn');
            const frontUrlInput = document.getElementById('editItemFrontImageUrl');
            
            if (frontUrlBtn && frontUrlInput) {
                frontUrlBtn.addEventListener('click', function() {
                    const url = frontUrlInput.value.trim();
                    if (url) {
                        try {
                            new URL(url);
                            const preview = document.getElementById('itemFrontImagePreview');
                            if (preview) {
                                preview.innerHTML = `<img src="${url}" alt="Front Image" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                            }
                            if (window.currentItem) {
                                window.currentItem.front_image = url;
                            }
                            
                            // Show split buttons
                            const splitBtn = document.getElementById('editItemFrontImageSplitBtn');
                            const autoSplitBtn = document.getElementById('editItemFrontImageAutoSplitBtn');
                            if (splitBtn) {
                                splitBtn.style.display = 'inline-block';
                                splitBtn.dataset.imageUrl = url;
                            }
                            if (autoSplitBtn) {
                                autoSplitBtn.style.display = 'inline-block';
                                autoSplitBtn.dataset.imageUrl = url;
                            }
                            
                            showNotification('Front image URL set!', 'success');
                        } catch (e) {
                            showNotification('Invalid URL format', 'error');
                        }
                    }
                });
                
                frontUrlInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        frontUrlBtn.click();
                    }
                });
                
                // Show split buttons when URL is entered
                frontUrlInput.addEventListener('input', function() {
                    const url = this.value.trim();
                    const splitBtn = document.getElementById('editItemFrontImageSplitBtn');
                    const autoSplitBtn = document.getElementById('editItemFrontImageAutoSplitBtn');
                    if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
                        if (splitBtn) {
                            splitBtn.style.display = 'inline-block';
                            splitBtn.dataset.imageUrl = url;
                        }
                        if (autoSplitBtn) {
                            autoSplitBtn.style.display = 'inline-block';
                            autoSplitBtn.dataset.imageUrl = url;
                        }
                    } else {
                        if (splitBtn) splitBtn.style.display = 'none';
                        if (autoSplitBtn) autoSplitBtn.style.display = 'none';
                    }
                });
            }
            
            // Back image URL
            const backUrlBtn = document.getElementById('editItemBackImageUrlBtn');
            const backUrlInput = document.getElementById('editItemBackImageUrl');
            
            if (backUrlBtn && backUrlInput) {
                backUrlBtn.addEventListener('click', function() {
                    const url = backUrlInput.value.trim();
                    if (url) {
                        try {
                            new URL(url);
                            const preview = document.getElementById('itemBackImagePreview');
                            if (preview) {
                                preview.innerHTML = `<img src="${url}" alt="Back Image" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                            }
                            if (window.currentItem) {
                                window.currentItem.back_image = url;
                            }
                            
                            // Show split buttons
                            const splitBtn = document.getElementById('editItemBackImageSplitBtn');
                            const autoSplitBtn = document.getElementById('editItemBackImageAutoSplitBtn');
                            if (splitBtn) {
                                splitBtn.style.display = 'inline-block';
                                splitBtn.dataset.imageUrl = url;
                            }
                            if (autoSplitBtn) {
                                autoSplitBtn.style.display = 'inline-block';
                                autoSplitBtn.dataset.imageUrl = url;
                            }
                            
                            showNotification('Back image URL set!', 'success');
                        } catch (e) {
                            showNotification('Invalid URL format', 'error');
                        }
                    }
                });
                
                backUrlInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        backUrlBtn.click();
                    }
                });
                
                // Show split buttons when URL is entered
                backUrlInput.addEventListener('input', function() {
                    const url = this.value.trim();
                    const splitBtn = document.getElementById('editItemBackImageSplitBtn');
                    const autoSplitBtn = document.getElementById('editItemBackImageAutoSplitBtn');
                    if (url && (url.startsWith('http://') || url.startsWith('https://'))) {
                        if (splitBtn) {
                            splitBtn.style.display = 'inline-block';
                            splitBtn.dataset.imageUrl = url;
                        }
                        if (autoSplitBtn) {
                            autoSplitBtn.style.display = 'inline-block';
                            autoSplitBtn.dataset.imageUrl = url;
                        }
                    } else {
                        if (splitBtn) splitBtn.style.display = 'none';
                        if (autoSplitBtn) autoSplitBtn.style.display = 'none';
                    }
                });
            }
            
            // Setup split buttons for edit form
            setupEditItemSplitButtons();
        }
        
        /**
         * Setup split buttons for edit item form
         */
        function setupEditItemSplitButtons() {
            // Front image split buttons
            const frontSplitBtn = document.getElementById('editItemFrontImageSplitBtn');
            const frontAutoSplitBtn = document.getElementById('editItemFrontImageAutoSplitBtn');
            
            if (frontSplitBtn) {
                frontSplitBtn.addEventListener('click', function() {
                    const imageUrl = this.dataset.imageUrl || document.getElementById('editItemFrontImageUrl').value.trim();
                    if (imageUrl && window.openSplitModal) {
                        window.currentSplitImageSide = 'front';
                        window.openSplitModal(imageUrl, 'edit-item-front');
                    }
                });
            }
            
            if (frontAutoSplitBtn) {
                frontAutoSplitBtn.addEventListener('click', function() {
                    const imageUrl = this.dataset.imageUrl || document.getElementById('editItemFrontImageUrl').value.trim();
                    if (imageUrl && window.performAutoSplit) {
                        window.performAutoSplit(imageUrl, 'edit-item-front');
                    }
                });
            }
            
            // Back image split buttons
            const backSplitBtn = document.getElementById('editItemBackImageSplitBtn');
            const backAutoSplitBtn = document.getElementById('editItemBackImageAutoSplitBtn');
            
            if (backSplitBtn) {
                backSplitBtn.addEventListener('click', function() {
                    const imageUrl = this.dataset.imageUrl || document.getElementById('editItemBackImageUrl').value.trim();
                    if (imageUrl && window.openSplitModal) {
                        window.currentSplitImageSide = 'back';
                        window.openSplitModal(imageUrl, 'edit-item-back');
                    }
                });
            }
            
            if (backAutoSplitBtn) {
                backAutoSplitBtn.addEventListener('click', function() {
                    const imageUrl = this.dataset.imageUrl || document.getElementById('editItemBackImageUrl').value.trim();
                    if (imageUrl && window.performAutoSplit) {
                        window.performAutoSplit(imageUrl, 'edit-item-back');
                    }
                });
            }
        }
        
        // Load item on page load
        loadItemDetail();
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>

