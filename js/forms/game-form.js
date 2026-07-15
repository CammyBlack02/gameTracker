// Add-game + Edit-game form logic — extracted from games.js (phase 4f/07).
// Both forms live in modals: Add on dashboard.php, Edit on game-detail.php.
//
// Entry points (called from games.js's DOMContentLoaded handler):
//   setupAddGameForm()
//   setupEditGameForm()
//
// populateEditForm was renamed to populateGameEditForm to avoid a global
// name collision with js/item-detail.js's own populateEditForm(item),
// which populates the item-edit modal — different data model.
//
// Reads escapeHtml / getImageUrl / formatDate / showNotification /
// showModal / hideModal from js/main.js. Uses apiGet / apiPostJson /
// apiPostForm from js/api.js. Uses splitCoverImage from js/image-split.js
// (indirectly via setupImageSplitTool which stays in games.js for now).

function setupAddGameForm() {
    const addBtn = document.getElementById('addGameBtn');
    const modal = document.getElementById('addGameModal');
    const form = document.getElementById('addGameForm');
    
    if (addBtn) {
        addBtn.addEventListener('click', () => {
            // Reset form when opening
            if (form) form.reset();
            document.getElementById('addFrontCoverPreview').innerHTML = '';
            document.getElementById('addBackCoverPreview').innerHTML = '';
            // Clear URL inputs
            const frontUrlInput = document.getElementById('addFrontCoverUrl');
            const backUrlInput = document.getElementById('addBackCoverUrl');
            if (frontUrlInput) frontUrlInput.value = '';
            if (backUrlInput) backUrlInput.value = '';
            window.addGameFrontCover = null;
            window.addGameBackCover = null;
            
            // Refresh platform dropdown when opening modal
            populatePlatformDropdowns();
            
            // Update digital store visibility when opening modal
            setTimeout(() => {
                updateAddFormDigitalStoreVisibility();
            }, 100);
            
            showModal('addGameModal');
        });
    }
    
    // Setup digital store field visibility for add form
    function setupAddFormDigitalStore() {
        const digitalStoreGroup = document.getElementById('addDigitalStoreGroup');
        const platformInput = document.getElementById('addPlatform');
        const isPhysicalCheckbox = document.getElementById('addIsPhysical');
        
        function updateVisibility() {
            const platform = platformInput.value.trim();
            const isPhysical = isPhysicalCheckbox.checked;
            
            if (digitalStoreGroup) {
                if (platform === 'PC' && !isPhysical) {
                    digitalStoreGroup.style.display = 'block';
                } else {
                    digitalStoreGroup.style.display = 'none';
                    const digitalStoreSelect = document.getElementById('addDigitalStore');
                    if (digitalStoreSelect) {
                        digitalStoreSelect.value = '';
                    }
                }
            }
        }
        
        // Store function globally so it can be called when modal opens
        window.updateAddFormDigitalStoreVisibility = updateVisibility;
        
        if (platformInput) {
            platformInput.addEventListener('change', updateVisibility);
            platformInput.addEventListener('input', updateVisibility);
        }
        
        if (isPhysicalCheckbox) {
            isPhysicalCheckbox.addEventListener('change', updateVisibility);
        }
    }
    
    // Setup N/A checkboxes
    setupNACheckboxes();
    
    // Setup cover image auto-fetch
    setupCoverImageFetch();
    
    // Setup price and metacritic fetching for add form
    setupAddFormFetching();
    
    // Setup genre and description fetching
    setupMetadataFetching();
    
    // Setup image uploads for add form
    setupAddFormImageUploads();
    
    // Setup URL inputs for add form
    setupAddFormUrlInputs();
    
    // Setup digital store field visibility
    setupAddFormDigitalStore();
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Handle N/A prices
            let pricePaid = document.getElementById('addPricePaid').value;
            if (document.getElementById('addPricePaidNA').checked) {
                pricePaid = 'N/A';
            }
            
            let pricechartingPrice = document.getElementById('addPricechartingPrice').value;
            if (document.getElementById('addPricechartingNA').checked) {
                pricechartingPrice = 'N/A';
            }
            
            const formData = {
                title: document.getElementById('addTitle').value,
                platform: document.getElementById('addPlatform').value,
                genre: document.getElementById('addGenre').value || null,
                release_date: document.getElementById('addReleaseDate').value || null,
                series: document.getElementById('addSeries').value || null,
                special_edition: document.getElementById('addSpecialEdition').value || null,
                condition: document.getElementById('addCondition').value || null,
                description: document.getElementById('addDescription').value || null,
                review: document.getElementById('addReview').value || null,
                star_rating: document.getElementById('addStarRating').value || null,
                metacritic_rating: document.getElementById('addMetacriticRating').value || null,
                played: document.getElementById('addPlayed').checked ? 1 : 0,
                price_paid: pricePaid === 'N/A' ? null : (pricePaid || null),
                pricecharting_price: pricechartingPrice === 'N/A' ? null : (pricechartingPrice || null),
                is_physical: document.getElementById('addIsPhysical').checked ? 1 : 0,
                digital_store: (() => {
                    const digitalStoreGroup = document.getElementById('addDigitalStoreGroup');
                    if (digitalStoreGroup && digitalStoreGroup.style.display !== 'none') {
                        return document.getElementById('addDigitalStore').value || null;
                    }
                    return null;
                })(),
                // Get cover images - prefer URL if provided, otherwise use uploaded file
                front_cover_image: (() => {
                    const urlInput = document.getElementById('addFrontCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    return window.addGameFrontCover || null;
                })(),
                back_cover_image: (() => {
                    const urlInput = document.getElementById('addBackCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    return window.addGameBackCover || null;
                })()
            };
            
            try {
                const data = await apiPostJson('api/games.php?action=create', formData);

                if (data.success) {
                    showNotification('Game added successfully!', 'success');
                    form.reset();
                    document.getElementById('addFrontCoverPreview').innerHTML = '';
                    document.getElementById('addBackCoverPreview').innerHTML = '';
                    window.addGameFrontCover = null;
                    window.addGameBackCover = null;
                    hideModal('addGameModal');
                    loadGames();
                } else {
                    showNotification(data.message || 'Failed to add game', 'error');
                }
            } catch (error) {
                console.error('Error adding game:', error);
                showNotification('Error adding game', 'error');
            }
        });
    }
}

/**
 * Setup N/A checkboxes for prices
 */
function setupNACheckboxes() {
    const pricePaidNA = document.getElementById('addPricePaidNA');
    const pricechartingNA = document.getElementById('addPricechartingNA');
    
    if (pricePaidNA) {
        pricePaidNA.addEventListener('change', function() {
            const input = document.getElementById('addPricePaid');
            if (this.checked) {
                input.value = '';
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
    }
    
    if (pricechartingNA) {
        pricechartingNA.addEventListener('change', function() {
            const input = document.getElementById('addPricechartingPrice');
            if (this.checked) {
                input.value = '';
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
    }
}

/**
 * Setup cover image auto-fetch
 */
function setupCoverImageFetch() {
    const fetchBtn = document.getElementById('fetchCoverBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;
            
            if (!title) {
                showNotification('Please enter a game title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const url = `api/cover-image.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform || '')}`;
                
                const response = await fetch(url);
                
                if (!response.ok) {
                    const text = await response.text();
                    console.error('HTTP error response:', text);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success && data.image_url) {
                    // Store the URL directly instead of downloading
                    const previewId = 'addFrontCoverPreview';
                    document.getElementById(previewId).innerHTML = 
                        `<img src="${data.image_url}" alt="Cover" style="max-width: 200px;">`;
                    
                    window.addGameFrontCover = data.image_url;
                    showNotification('Cover image URL fetched!', 'success');
                } else {
                    console.warn('No image URL in response:', data);
                    showNotification(data.message || 'Could not find cover image automatically. TheGamesDB API may be unavailable. Please upload manually.', 'error');
                }
            } catch (error) {
                console.error('Error fetching cover:', error);
                showNotification('Error fetching cover image. Please check your internet connection or upload manually.', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Auto-fetch Cover';
            }
        });
    }
}

/**
 * Download and upload cover image
 */
async function downloadAndUploadCover(imageUrl, type) {
    try {
        // First, download the image via our PHP proxy
        const url = `api/download-cover.php?url=${encodeURIComponent(imageUrl)}`;
        
        const response = await fetch(url);
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Download error response:', text);
            throw new Error(`Failed to download: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success && data.filename) {
            const previewId = type === 'front' ? 'addFrontCoverPreview' : 'addBackCoverPreview';
            document.getElementById(previewId).innerHTML = 
                `<img src="uploads/covers/${data.filename}" alt="Cover" style="max-width: 200px;">`;
            
            if (type === 'front') {
                window.addGameFrontCover = data.filename;
            } else {
                window.addGameBackCover = data.filename;
            }
        } else {
            console.error('Download failed:', data);
            throw new Error(data.message || 'Failed to download image');
        }
    } catch (error) {
        console.error('Error downloading cover:', error);
        throw error;
    }
}

/**
 * Setup price and metacritic fetching for add form
 */
function setupAddFormFetching() {
    // Price fetching
    const fetchPriceBtn = document.getElementById('fetchPriceAddBtn');
    if (fetchPriceBtn) {
        fetchPriceBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;
            
            if (!title) {
                showNotification('Please enter a title first', 'error');
                return;
            }
            
            fetchPriceBtn.disabled = true;
            fetchPriceBtn.textContent = 'Fetching...';
            
            try {
                const url = `api/pricecharting.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`;
                
                const response = await fetch(url);
                
                if (!response.ok) {
                    const text = await response.text();
                    console.error('HTTP error response:', text);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success && data.price) {
                    document.getElementById('addPricechartingPrice').value = data.price;
                    showNotification('Price fetched successfully', 'success');
                } else {
                    console.warn('No price in response:', data);
                    showNotification(data.message || 'Could not fetch price. Pricecharting API may require authentication.', 'error');
                }
            } catch (error) {
                console.error('Error fetching price:', error);
                showNotification('Error fetching price. Please check your internet connection.', 'error');
            } finally {
                fetchPriceBtn.disabled = false;
                fetchPriceBtn.textContent = 'Fetch';
            }
        });
    }

    // Metacritic auto-fetch was removed — no free source produced
    // reliable scores. The Metacritic input is now manual entry only.
}

/**
 * Setup genre and description fetching
 */
function setupMetadataFetching() {
    const fetchBtn = document.getElementById('fetchMetadataBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;
            
            if (!title) {
                showNotification('Please enter a game title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const url = `api/game-metadata.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform || '')}`;
                const response = await fetch(url);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.genre) {
                        document.getElementById('addGenre').value = data.genre;
                    }
                    if (data.description) {
                        document.getElementById('addDescription').value = data.description;
                    }
                    
                    if (data.genre || data.description) {
                        showNotification('Genre and description fetched successfully', 'success');
                    } else {
                        showNotification('Game found but no genre/description available', 'info');
                    }
                } else {
                    showNotification(data.message || 'Could not fetch game metadata', 'error');
                }
            } catch (error) {
                console.error('Error fetching metadata:', error);
                showNotification('Error fetching game metadata', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Auto-fetch';
            }
        });
    }
}

/**
 * Setup URL inputs for add form
 */
function setupAddFormUrlInputs() {
    // Front cover URL
    const frontUrlBtn = document.getElementById('addFrontCoverUrlBtn');
    const frontUrlInput = document.getElementById('addFrontCoverUrl');
    
    if (frontUrlBtn && frontUrlInput) {
        frontUrlBtn.addEventListener('click', function() {
            const url = frontUrlInput.value.trim();
            if (url) {
                // Validate URL
                try {
                    new URL(url);
                    // Show preview
                    const preview = document.getElementById('addFrontCoverPreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Front Cover" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    // Store URL
                    window.addGameFrontCover = url;
                    
                    // Show split buttons for external URLs (user can decide if they want to split)
                    const splitBtn = document.getElementById('addFrontCoverSplitBtn');
                    const autoSplitBtn = document.getElementById('addFrontCoverAutoSplitBtn');
                    if (splitBtn) {
                        splitBtn.style.display = 'inline-block';
                        splitBtn.dataset.imageUrl = url;
                    }
                    if (autoSplitBtn) {
                        autoSplitBtn.style.display = 'inline-block';
                        autoSplitBtn.dataset.imageUrl = url;
                    }
                    
                    // Optionally check aspect ratio to provide a hint
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = function() {
                        const aspectRatio = this.width / this.height;
                        // Combined covers are usually wider (aspect ratio > 1.3) or taller (aspect ratio < 0.7)
                        // This is just for reference, button is already shown
                        if (aspectRatio > 1.3 || aspectRatio < 0.7) {
                        }
                    };
                    img.onerror = function() {
                        // If direct load fails, try with proxy (for CORS)
                        const proxyUrl = `api/image-proxy.php?url=${encodeURIComponent(url)}`;
                        img.src = proxyUrl;
                    };
                    img.src = url;
                    
                    showNotification('Front cover URL set!', 'success');
                } catch (e) {
                    showNotification('Invalid URL format', 'error');
                }
            }
        });
        
        // Also allow Enter key
        frontUrlInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                frontUrlBtn.click();
            }
        });
    }
    
    // Back cover URL
    const backUrlBtn = document.getElementById('addBackCoverUrlBtn');
    const backUrlInput = document.getElementById('addBackCoverUrl');
    
    if (backUrlBtn && backUrlInput) {
        backUrlBtn.addEventListener('click', function() {
            const url = backUrlInput.value.trim();
            if (url) {
                // Validate URL
                try {
                    new URL(url);
                    // Show preview
                    const preview = document.getElementById('addBackCoverPreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Back Cover" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    // Store URL
                    window.addGameBackCover = url;
                    showNotification('Back cover URL set!', 'success');
                } catch (e) {
                    showNotification('Invalid URL format', 'error');
                }
            }
        });
        
        // Also allow Enter key
        backUrlInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                backUrlBtn.click();
            }
        });
    }
}

/**
 * Setup image uploads for add form
 */
function setupAddFormImageUploads() {
    document.getElementById('addFrontCover')?.addEventListener('change', function() {
        uploadAddFormCoverImage(this, 'front');
    });
    
    document.getElementById('addBackCover')?.addEventListener('change', function() {
        uploadAddFormCoverImage(this, 'back');
    });
    
    document.querySelectorAll('.upload-image-btn[data-target^="add"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const fileInput = document.getElementById(targetId);
            if (fileInput) {
                fileInput.click();
            }
        });
    });
}

/**
 * Upload cover image for add form
 */
async function uploadAddFormCoverImage(fileInput, type) {
    if (!fileInput.files[0]) return;
    
    const formData = new FormData();
    formData.append('image', fileInput.files[0]);
    formData.append('type', 'cover');
    
    try {
        const data = await apiPostForm('api/upload.php', formData);

        if (data.success) {
            const previewId = type === 'front' ? 'addFrontCoverPreview' : 'addBackCoverPreview';
            document.getElementById(previewId).innerHTML =
                `<img src="${data.url}" alt="${type} cover" style="max-width: 200px;">`;

            if (type === 'front') {
                window.addGameFrontCover = data.image_path;
            } else {
                window.addGameBackCover = data.image_path;
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
 * Setup view toggle
 */

function setupEditGameForm() {
    const editBtn = document.getElementById('editGameBtn');
    const modal = document.getElementById('editGameModal');
    const form = document.getElementById('editGameForm');
    
    if (editBtn) {
        editBtn.addEventListener('click', () => {
            if (window.currentGame) {
                // Refresh platform dropdown when opening modal
                populatePlatformDropdowns().then(() => {
                    populateGameEditForm(window.currentGame);
                    showModal('editGameModal');
                });
            }
        });
    }
    
    // Setup image uploads
    setupImageUploads();
    setupEditFormUrlInputs();
    
    // Setup image split tool
    setupImageSplitTool();
    
    // Setup price fetching
    setupPriceFetching();

    // (Metacritic auto-fetch removed — no working free source.)

    // Setup genre and description fetching for edit form
    setupEditMetadataFetching();
    
    if (form) {
        form.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            // Handle N/A prices
            let pricePaid = document.getElementById('editPricePaid').value;
            if (document.getElementById('editPricePaidNA').checked) {
                pricePaid = null;
            }
            
            let pricechartingPrice = document.getElementById('editPricechartingPrice').value;
            if (document.getElementById('editPricechartingNA').checked) {
                pricechartingPrice = null;
            }
            
            const formData = {
                id: window.currentGame.id,
                title: document.getElementById('editTitle').value,
                platform: document.getElementById('editPlatform').value,
                genre: document.getElementById('editGenre').value || null,
                release_date: document.getElementById('editReleaseDate').value || null,
                series: document.getElementById('editSeries').value || null,
                special_edition: document.getElementById('editSpecialEdition').value || null,
                condition: document.getElementById('editCondition').value || null,
                description: document.getElementById('editDescription').value || null,
                review: document.getElementById('editReview').value || null,
                star_rating: document.getElementById('editStarRating').value || null,
                metacritic_rating: document.getElementById('editMetacriticRating').value || null,
                played: document.getElementById('editPlayed').checked ? 1 : 0,
                price_paid: pricePaid || null,
                pricecharting_price: pricechartingPrice || null,
                is_physical: document.getElementById('editIsPhysical').checked ? 1 : 0,
                digital_store: (() => {
                    const digitalStoreGroup = document.getElementById('editDigitalStoreGroup');
                    if (digitalStoreGroup && digitalStoreGroup.style.display !== 'none') {
                        return document.getElementById('editDigitalStore').value || null;
                    }
                    return null;
                })(),
                // Get cover images - prefer updated currentGame values (from split/upload), then URL input, then existing
                front_cover_image: (() => {
                    // First check if currentGame was updated (e.g., from split tool or upload)
                    if (window.currentGame && window.currentGame.front_cover_image) {
                        const currentValue = window.currentGame.front_cover_image;
                        // If it's a data URL or external URL, use it
                        if (currentValue.startsWith('data:') || currentValue.startsWith('http://') || currentValue.startsWith('https://')) {
                            return currentValue;
                        }
                    }
                    // Then check URL input
                    const urlInput = document.getElementById('editFrontCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    // Finally fall back to original
                    return window.currentGame?.front_cover_image || null;
                })(),
                back_cover_image: (() => {
                    // First check if currentGame was updated (e.g., from split tool or upload)
                    if (window.currentGame && window.currentGame.back_cover_image) {
                        const currentValue = window.currentGame.back_cover_image;
                        // If it's a data URL or external URL, use it
                        if (currentValue.startsWith('data:') || currentValue.startsWith('http://') || currentValue.startsWith('https://')) {
                            return currentValue;
                        }
                    }
                    // Then check URL input
                    const urlInput = document.getElementById('editBackCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    // Finally fall back to original
                    return window.currentGame?.back_cover_image || null;
                })()
            };
            
            try {
                const data = await apiPostJson('api/games.php?action=update', formData);

                if (data.success) {
                    showNotification('Game updated successfully!', 'success');
                    hideModal('editGameModal');
                    loadGameDetail();
                } else {
                    showNotification(data.message || 'Failed to update game', 'error');
                }
            } catch (error) {
                console.error('Error updating game:', error);
                showNotification('Error updating game', 'error');
            }
        });
    }
}

/**
 * Populate edit form with game data
 */
function populateGameEditForm(game) {
    document.getElementById('editGameId').value = game.id;
    document.getElementById('editTitle').value = game.title || '';
    document.getElementById('editPlatform').value = game.platform || '';
    document.getElementById('editGenre').value = game.genre || '';
    document.getElementById('editReleaseDate').value = game.release_date || '';
    document.getElementById('editSeries').value = game.series || '';
    document.getElementById('editSpecialEdition').value = game.special_edition || '';
    document.getElementById('editCondition').value = game.condition || '';
    document.getElementById('editDescription').value = game.description || '';
    document.getElementById('editReview').value = game.review || '';
    document.getElementById('editStarRating').value = game.star_rating || '';
    document.getElementById('editMetacriticRating').value = game.metacritic_rating || '';
    document.getElementById('editPlayed').checked = game.played;
    document.getElementById('editIsPhysical').checked = game.is_physical;
    
    // Handle digital store field (show/hide based on platform and is_physical)
    const digitalStoreGroup = document.getElementById('editDigitalStoreGroup');
    const platform = (game.platform || '').trim().toUpperCase();
    const isPhysical = game.is_physical;
    
    if (digitalStoreGroup) {
        if (platform === 'PC' && !isPhysical) {
            digitalStoreGroup.style.display = 'block';
            const digitalStoreSelect = document.getElementById('editDigitalStore');
            if (digitalStoreSelect) {
                digitalStoreSelect.value = game.digital_store || '';
            }
        } else {
            digitalStoreGroup.style.display = 'none';
        }
    }
    
    // Setup listener to show/hide digital store when platform or is_physical changes
    const editPlatformInput = document.getElementById('editPlatform');
    const editIsPhysicalCheckbox = document.getElementById('editIsPhysical');
    
    function updateDigitalStoreVisibility() {
        const currentPlatform = editPlatformInput.value.trim().toUpperCase();
        const currentIsPhysical = editIsPhysicalCheckbox.checked;
        
        if (digitalStoreGroup) {
            if (currentPlatform === 'PC' && !currentIsPhysical) {
                digitalStoreGroup.style.display = 'block';
            } else {
                digitalStoreGroup.style.display = 'none';
                if (document.getElementById('editDigitalStore')) {
                    document.getElementById('editDigitalStore').value = '';
                }
            }
        }
    }
    
    if (editPlatformInput) {
        editPlatformInput.addEventListener('change', updateDigitalStoreVisibility);
        editPlatformInput.addEventListener('input', updateDigitalStoreVisibility);
    }
    
    if (editIsPhysicalCheckbox) {
        editIsPhysicalCheckbox.addEventListener('change', updateDigitalStoreVisibility);
    }
    
    // Handle prices with N/A option
    const pricePaid = game.price_paid;
    if (pricePaid === null || pricePaid === '' || pricePaid === undefined) {
        document.getElementById('editPricePaidNA').checked = true;
        document.getElementById('editPricePaid').value = '';
        document.getElementById('editPricePaid').disabled = true;
    } else {
        document.getElementById('editPricePaidNA').checked = false;
        document.getElementById('editPricePaid').value = pricePaid;
        document.getElementById('editPricePaid').disabled = false;
    }
    
    const pricechartingPrice = game.pricecharting_price;
    if (pricechartingPrice === null || pricechartingPrice === '' || pricechartingPrice === undefined) {
        document.getElementById('editPricechartingNA').checked = true;
        document.getElementById('editPricechartingPrice').value = '';
        document.getElementById('editPricechartingPrice').disabled = true;
    } else {
        document.getElementById('editPricechartingNA').checked = false;
        document.getElementById('editPricechartingPrice').value = pricechartingPrice;
        document.getElementById('editPricechartingPrice').disabled = false;
    }
    
    // Display cover images and populate URL fields if image is a URL
    if (game.front_cover_image) {
        const frontUrlInput = document.getElementById('editFrontCoverUrl');
        const isUrl = game.front_cover_image.startsWith('http://') || game.front_cover_image.startsWith('https://');
        
        if (isUrl && frontUrlInput) {
            frontUrlInput.value = game.front_cover_image;
        }
        
        const frontUrl = getImageUrl(game.front_cover_image);
        if (frontUrl) {
            document.getElementById('frontCoverPreview').innerHTML = 
                `<img src="${frontUrl}" alt="Front Cover" style="max-width: 200px;">`;
        } else {
            document.getElementById('frontCoverPreview').innerHTML = 
                '<div style="padding: 20px; text-align: center; color: #999;">Image Error</div>';
        }
    }
    if (game.back_cover_image) {
        const backUrlInput = document.getElementById('editBackCoverUrl');
        const isUrl = game.back_cover_image.startsWith('http://') || game.back_cover_image.startsWith('https://');
        
        if (isUrl && backUrlInput) {
            backUrlInput.value = game.back_cover_image;
        }
        
        const backUrl = getImageUrl(game.back_cover_image);
        if (backUrl) {
            document.getElementById('backCoverPreview').innerHTML = 
                `<img src="${backUrl}" alt="Back Cover" style="max-width: 200px;">`;
        } else {
            document.getElementById('backCoverPreview').innerHTML = 
                '<div style="padding: 20px; text-align: center; color: #999;">Image Error</div>';
        }
    }
    
    // Display extra images
    displayExtraImages(game.extra_images || []);
    
    // Setup N/A checkboxes for edit form
    setupEditFormNACheckboxes();
}

/**
 * Setup genre and description fetching for edit form
 */
function setupEditMetadataFetching() {
    const fetchBtn = document.getElementById('fetchMetadataEditBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('editTitle').value;
            const platform = document.getElementById('editPlatform').value;
            
            if (!title) {
                showNotification('Please enter a game title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const url = `api/game-metadata.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform || '')}`;
                const response = await fetch(url);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    if (data.genre) {
                        document.getElementById('editGenre').value = data.genre;
                    }
                    if (data.description) {
                        document.getElementById('editDescription').value = data.description;
                    }
                    
                    if (data.genre || data.description) {
                        showNotification('Genre and description fetched successfully', 'success');
                    } else {
                        showNotification('Game found but no genre/description available', 'info');
                    }
                } else {
                    showNotification(data.message || 'Could not fetch game metadata', 'error');
                }
            } catch (error) {
                console.error('Error fetching metadata:', error);
                showNotification('Error fetching game metadata', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Auto-fetch';
            }
        });
    }
}

/**
 * Setup N/A checkboxes for edit form
 */
function setupEditFormNACheckboxes() {
    const pricePaidNA = document.getElementById('editPricePaidNA');
    const pricechartingNA = document.getElementById('editPricechartingNA');
    
    if (pricePaidNA) {
        pricePaidNA.addEventListener('change', function() {
            const input = document.getElementById('editPricePaid');
            if (this.checked) {
                input.value = '';
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
    }
    
    if (pricechartingNA) {
        pricechartingNA.addEventListener('change', function() {
            const input = document.getElementById('editPricechartingPrice');
            if (this.checked) {
                input.value = '';
                input.disabled = true;
            } else {
                input.disabled = false;
            }
        });
    }
}

/**
 * Setup URL inputs for edit form
 */
function setupEditFormUrlInputs() {
    // Front cover URL
    const frontUrlBtn = document.getElementById('editFrontCoverUrlBtn');
    const frontUrlInput = document.getElementById('editFrontCoverUrl');
    
    if (frontUrlBtn && frontUrlInput) {
        frontUrlBtn.addEventListener('click', function() {
            const url = frontUrlInput.value.trim();
            if (url) {
                // Validate URL
                try {
                    new URL(url);
                    // Show preview
                    const preview = document.getElementById('frontCoverPreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Front Cover" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    // Store URL
                    window.currentGame.front_cover_image = url;
                    
                    // Show split buttons for external URLs (user can decide if they want to split)
                    const splitBtn = document.getElementById('editFrontCoverSplitBtn');
                    const autoSplitBtn = document.getElementById('editFrontCoverAutoSplitBtn');
                    if (splitBtn) {
                        splitBtn.style.display = 'inline-block';
                        splitBtn.dataset.imageUrl = url;
                    }
                    if (autoSplitBtn) {
                        autoSplitBtn.style.display = 'inline-block';
                        autoSplitBtn.dataset.imageUrl = url;
                    }
                    
                    // Optionally check aspect ratio to provide a hint
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    img.onload = function() {
                        const aspectRatio = this.width / this.height;
                        // Combined covers are usually wider (aspect ratio > 1.3) or taller (aspect ratio < 0.7)
                        // This is just for reference, button is already shown
                        if (aspectRatio > 1.3 || aspectRatio < 0.7) {
                        }
                    };
                    img.onerror = function() {
                        // If direct load fails, try with proxy (for CORS)
                        const proxyUrl = `api/image-proxy.php?url=${encodeURIComponent(url)}`;
                        img.src = proxyUrl;
                    };
                    img.src = url;
                    
                    showNotification('Front cover URL updated!', 'success');
                } catch (e) {
                    showNotification('Invalid URL format', 'error');
                }
            }
        });
        
        // Also allow Enter key
        frontUrlInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                frontUrlBtn.click();
            }
        });
    }
    
    // Back cover URL
    const backUrlBtn = document.getElementById('editBackCoverUrlBtn');
    const backUrlInput = document.getElementById('editBackCoverUrl');
    
    if (backUrlBtn && backUrlInput) {
        backUrlBtn.addEventListener('click', function() {
            const url = backUrlInput.value.trim();
            if (url) {
                // Validate URL
                try {
                    new URL(url);
                    // Show preview
                    const preview = document.getElementById('backCoverPreview');
                    if (preview) {
                        preview.innerHTML = `<img src="${url}" alt="Back Cover" style="max-width: 200px;" onerror="this.parentElement.innerHTML='<span style=\'color:red;\'>Invalid image URL</span>'">`;
                    }
                    // Store URL
                    window.currentGame.back_cover_image = url;
                    showNotification('Back cover URL updated!', 'success');
                } catch (e) {
                    showNotification('Invalid URL format', 'error');
                }
            }
        });
        
        // Also allow Enter key
        backUrlInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                backUrlBtn.click();
            }
        });
    }
}

/**
 * Setup image split tool for combined front/back covers
 */
