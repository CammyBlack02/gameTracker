/**
 * Add Item Form Handler
 */

// Store image data
window.addItemFrontImage = null;
window.addItemBackImage = null;

document.addEventListener('DOMContentLoaded', function() {
    setupAddItemForm();
    setupAddItemImageHandlers();
});

/**
 * Setup add item form
 */
function setupAddItemForm() {
    const addBtn = document.getElementById('addItemBtn');
    const form = document.getElementById('addItemForm');
    
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            showModal('addItemModal');
        });
    }
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = {
                title: document.getElementById('addItemTitle').value,
                platform: document.getElementById('addItemPlatform').value || null,
                category: document.getElementById('addItemCategory').value,
                condition: document.getElementById('addItemCondition').value || null,
                description: document.getElementById('addItemDescription').value || null,
                notes: document.getElementById('addItemNotes').value || null,
                price_paid: document.getElementById('addItemPricePaid').value || null,
                pricecharting_price: document.getElementById('addItemPricechartingPrice').value || null,
                front_image: window.addItemFrontImage || null,
                back_image: window.addItemBackImage || null
            };
            
            try {
                const response = await fetch('api/items.php?action=create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const data = await response.json();
                
                if (data.success) {
                    showNotification('Item added successfully!', 'success');
                    hideModal('addItemModal');
                    
                    // Reset form
                    form.reset();
                    window.addItemFrontImage = null;
                    window.addItemBackImage = null;
                    document.getElementById('addItemFrontImagePreview').innerHTML = '';
                    document.getElementById('addItemBackImagePreview').innerHTML = '';
                    document.getElementById('addItemFrontImageUrl').value = '';
                    document.getElementById('addItemBackImageUrl').value = '';
                    document.getElementById('addItemFrontImageSplitBtn').style.display = 'none';
                    document.getElementById('addItemFrontImageAutoSplitBtn').style.display = 'none';
                    
                    // Reload items
                    const activeTab = document.querySelector('.tab-button.active');
                    if (activeTab) {
                        const tabName = activeTab.dataset.tab;
                        if (tabName === 'consoles') {
                            if (window.loadItems) {
                                window.loadItems('Systems');
                            }
                        } else if (tabName === 'accessories') {
                            if (window.loadItems) {
                                window.loadItems('Controllers,Game Accessories,Toys To Life');
                            }
                        }
                    }
                } else {
                    showNotification(data.message || 'Failed to add item', 'error');
                }
            } catch (error) {
                console.error('Error adding item:', error);
                showNotification('Error adding item', 'error');
            }
        });
    }
}

/**
 * Setup image handlers for add item form
 */
function setupAddItemImageHandlers() {
    // Front image upload
    const frontImageInput = document.getElementById('addItemFrontImage');
    if (frontImageInput) {
        frontImageInput.addEventListener('change', function() {
            uploadItemImage(this, 'front');
        });
    }
    
    // Back image upload
    const backImageInput = document.getElementById('addItemBackImage');
    if (backImageInput) {
        backImageInput.addEventListener('change', function() {
            uploadItemImage(this, 'back');
        });
    }
    
    // Front image URL
    const frontUrlBtn = document.getElementById('addItemFrontImageUrlBtn');
    const frontUrlInput = document.getElementById('addItemFrontImageUrl');
    
    if (frontUrlBtn && frontUrlInput) {
        frontUrlBtn.addEventListener('click', function() {
            const url = frontUrlInput.value.trim();
            if (url) {
                try {
                    new URL(url);
                    const preview = document.getElementById('addItemFrontImagePreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Front Image" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    window.addItemFrontImage = url;
                    
                    // Show split buttons
                    const splitBtn = document.getElementById('addItemFrontImageSplitBtn');
                    const autoSplitBtn = document.getElementById('addItemFrontImageAutoSplitBtn');
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
    }
    
    // Back image URL
    const backUrlBtn = document.getElementById('addItemBackImageUrlBtn');
    const backUrlInput = document.getElementById('addItemBackImageUrl');
    
    if (backUrlBtn && backUrlInput) {
        backUrlBtn.addEventListener('click', function() {
            const url = backUrlInput.value.trim();
            if (url) {
                try {
                    new URL(url);
                    const preview = document.getElementById('addItemBackImagePreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Back Image" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    window.addItemBackImage = url;
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
    }
    
    // Setup split tool for items
    setupItemImageSplitTool();
}

/**
 * Upload item image
 */
async function uploadItemImage(fileInput, type) {
    if (!fileInput.files[0]) return;
    
    const formData = new FormData();
    formData.append('image', fileInput.files[0]);
    formData.append('type', 'cover');
    
    try {
        const response = await fetch('api/upload.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            const previewId = type === 'front' ? 'addItemFrontImagePreview' : 'addItemBackImagePreview';
            document.getElementById(previewId).innerHTML = 
                `<img src="${data.url}" alt="${type} image" style="max-width: 200px;">`;
            
            if (type === 'front') {
                window.addItemFrontImage = data.image_path;
            } else {
                window.addItemBackImage = data.image_path;
            }
            
            showNotification('Image uploaded successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to upload image', 'error');
        }
    } catch (error) {
        console.error('Error uploading image:', error);
        showNotification('Error uploading image', 'error');
    }
}

/**
 * Setup image split tool for items
 */
function setupItemImageSplitTool() {
    // Setup split buttons
    const addSplitBtn = document.getElementById('addItemFrontImageSplitBtn');
    const addAutoSplitBtn = document.getElementById('addItemFrontImageAutoSplitBtn');
    
    if (addSplitBtn) {
        addSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                openSplitModal(imageUrl, 'add-item');
            }
        });
    }
    
    if (addAutoSplitBtn) {
        addAutoSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                performAutoSplitForItem(imageUrl);
            }
        });
    }
}

/**
 * Perform auto split for item
 */
async function performAutoSplitForItem(imageUrl) {
    // Remove _thumb from URL to get full-size image
    let fullSizeUrl = imageUrl;
    if (imageUrl.includes('_thumb')) {
        fullSizeUrl = imageUrl.replace('_thumb', '');
    }
    
    // Check if URL is external (needs proxy)
    const isExternalUrl = fullSizeUrl.startsWith('http://') || fullSizeUrl.startsWith('https://');
    const proxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(fullSizeUrl)}` : fullSizeUrl;
    
    // Load the image
    const img = new Image();
    img.crossOrigin = 'anonymous';
    
    img.onload = async function() {
        try {
            // Create full resolution canvases
            const frontCanvas = document.createElement('canvas');
            const backCanvas = document.createElement('canvas');
            
            const imgWidth = img.width;
            const imgHeight = img.height;
            
            // Vertical split at 53%
            const splitX = Math.floor(imgWidth * 0.53);
            
            // Front cover (right side) - Full resolution
            frontCanvas.width = imgWidth - splitX;
            frontCanvas.height = imgHeight;
            const frontCtx = frontCanvas.getContext('2d');
            frontCtx.drawImage(img, splitX, 0, imgWidth - splitX, imgHeight, 0, 0, imgWidth - splitX, imgHeight);
            
            // Back cover (left side) - Full resolution
            backCanvas.width = splitX;
            backCanvas.height = imgHeight;
            const backCtx = backCanvas.getContext('2d');
            backCtx.drawImage(img, 0, 0, splitX, imgHeight, 0, 0, splitX, imgHeight);
            
            // Convert to data URLs
            const frontDataUrl = frontCanvas.toDataURL('image/jpeg', 0.95);
            const backDataUrl = backCanvas.toDataURL('image/jpeg', 0.95);
            
            // Store the split images
            window.addItemFrontImage = frontDataUrl;
            window.addItemBackImage = backDataUrl;
            
            // Update previews
            document.getElementById('addItemFrontImagePreview').innerHTML = 
                `<img src="${frontDataUrl}" alt="Front Image" style="max-width: 200px;">`;
            document.getElementById('addItemBackImagePreview').innerHTML = 
                `<img src="${backDataUrl}" alt="Back Image" style="max-width: 200px;">`;
            
            // Clear URL inputs
            document.getElementById('addItemFrontImageUrl').value = '';
            document.getElementById('addItemBackImageUrl').value = '';
            document.getElementById('addItemFrontImageSplitBtn').style.display = 'none';
            document.getElementById('addItemFrontImageAutoSplitBtn').style.display = 'none';
            
            showNotification('Cover images auto-split successfully!', 'success');
        } catch (error) {
            console.error('Error performing auto split:', error);
            showNotification('Error performing auto split', 'error');
        }
    };
    
    img.onerror = function() {
        // If full-size fails, try the original URL
        if (fullSizeUrl !== imageUrl) {
            const fallbackProxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(imageUrl)}` : imageUrl;
            img.src = fallbackProxyUrl;
        } else {
            showNotification('Failed to load image for auto split', 'error');
        }
    };
    
    img.src = proxyUrl;
}

/**
 * Open split modal for items (reuse games split modal)
 */
function openSplitModal(imageUrl, context) {
    // Use the existing split modal from games.js
    // The split tool in games.js should handle 'add-item' context
    if (typeof window.openSplitModalForItem === 'function') {
        window.openSplitModalForItem(imageUrl, 'add-item');
    } else {
        // Fallback: try to use the games.js split modal
        // We need to make the split tool accessible
        const img = new Image();
        img.crossOrigin = 'anonymous';
        
        // Remove _thumb from URL
        let fullSizeUrl = imageUrl;
        if (imageUrl.includes('_thumb')) {
            fullSizeUrl = imageUrl.replace('_thumb', '');
        }
        
        const isExternalUrl = fullSizeUrl.startsWith('http://') || fullSizeUrl.startsWith('https://');
        const proxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(fullSizeUrl)}` : fullSizeUrl;
        
        img.onload = function() {
            // Store context for split tool
            window.currentSplitContext = 'add-item';
            
            // Trigger the split modal (it's already set up in games.js)
            const modal = document.getElementById('imageSplitModal');
            const previewImg = document.getElementById('splitImagePreview');
            if (modal && previewImg) {
                previewImg.src = proxyUrl;
                window.currentSplitImage = img;
                showModal('imageSplitModal');
            }
        };
        img.onerror = function() {
            if (fullSizeUrl !== imageUrl) {
                const fallbackProxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(imageUrl)}` : imageUrl;
                img.src = fallbackProxyUrl;
            } else {
                showNotification('Failed to load image', 'error');
            }
        };
        img.src = proxyUrl;
    }
}

