/**
 * Games management JavaScript
 * Handles game listing, adding, editing, deleting
 */

let allGames = [];
let currentView = localStorage.getItem('gameView') || 'list';

document.addEventListener('DOMContentLoaded', function() {
    // Load games on dashboard
    if (document.getElementById('gamesContainer')) {
        loadGames();
        setupAddGameForm();
        setupViewToggle();
    }
    
    // Load game detail if on detail page
    if (document.getElementById('gameDetailContainer')) {
        loadGameDetail();
        setupEditGameForm();
        setupDeleteGame();
    }
});

/**
 * Load all games
 */
async function loadGames() {
    try {
        const response = await fetch('api/games.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            allGames = data.games;
            displayGames(allGames);
            updateFilters();
        } else {
            showNotification('Failed to load games', 'error');
        }
    } catch (error) {
        console.error('Error loading games:', error);
        showNotification('Error loading games', 'error');
    }
}

/**
 * Display games in container
 */
function displayGames(games) {
    const container = document.getElementById('gamesContainer');
    console.log('displayGames called with', games.length, 'games');
    console.log('Container:', container, 'ID:', container?.id);
    
    if (!container) {
        console.error('gamesContainer not found!');
        return;
    }
    
    if (games.length === 0) {
        container.innerHTML = '<div class="empty-state">No games found. Add your first game!</div>';
        return;
    }
    
    console.log('Current view:', currentView);
    if (currentView === 'grid') {
        console.log('Displaying grid view');
        displayGamesGridView(games, container);
    } else {
        console.log('Displaying list view');
        displayGamesListView(games, container);
    }
}

/**
 * Get image URL - handles both external URLs, data URLs, and local paths
 */
function getImageUrl(imagePath) {
    if (!imagePath) return null;
    // Check if it's already a full URL or data URL
    if (imagePath.startsWith('http://') || imagePath.startsWith('https://') || imagePath.startsWith('data:')) {
        return imagePath;
    }
    // Otherwise, it's a local file
    return `uploads/covers/${imagePath}`;
}

/**
 * Display games in grid view
 */
function displayGamesGridView(games, container) {
    console.log('displayGridView called with container:', container, 'ID:', container?.id);
    container.className = 'games-container grid-view';
    const html = games.map(game => {
        const coverImage = game.front_cover_image 
            ? `<img src="${getImageUrl(game.front_cover_image)}" alt="${escapeHtml(game.title)}" class="game-cover">`
            : '<div class="game-cover-placeholder">No Cover</div>';
        
        return `
            <div class="game-card" data-id="${game.id}" data-type="game">
                ${coverImage}
                <div class="game-card-info">
                    <h3 class="game-title">${escapeHtml(game.title)}</h3>
                    <p class="game-platform">${escapeHtml(game.platform)}</p>
                    <div class="game-badges">
                        ${game.is_physical ? '<span class="badge badge-physical">Physical</span>' : '<span class="badge badge-digital">Digital</span>'}
                        ${game.played ? '<span class="badge badge-played">Played</span>' : ''}
                        ${game.star_rating ? `<span class="badge badge-rating">★ ${game.star_rating}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    console.log('Setting innerHTML, HTML length:', html.length);
    container.innerHTML = html;
    console.log('innerHTML set, container now has', container.children.length, 'children');
    
    // Attach click handlers directly to each game card (only for games container)
    console.log('About to attach handlers, container:', container, 'container.id:', container?.id);
    if (container && container.id === 'gamesContainer') {
        console.log('Container is gamesContainer, proceeding...');
        
        // Remove any existing handlers from container
        const oldGridHandler = container._gameClickHandler;
        const oldListHandler = container._gameListClickHandler;
        if (oldGridHandler) {
            container.removeEventListener('click', oldGridHandler);
        }
        if (oldListHandler) {
            container.removeEventListener('click', oldListHandler);
        }
        
        // Wait a tiny bit for DOM to be ready
        setTimeout(() => {
            // Attach handlers directly to each card
            const cards = container.querySelectorAll('.game-card[data-type="game"]');
            console.log('Found', cards.length, 'game cards to attach handlers to');
            
            if (cards.length === 0) {
                console.error('No game cards found! Container HTML:', container.innerHTML.substring(0, 200));
            }
            
            cards.forEach((card, index) => {
                // Remove any existing handlers by cloning
                const newCard = card.cloneNode(true);
                card.parentNode.replaceChild(newCard, card);
                
                // Verify the card has the right attributes
                console.log(`Card ${index}: id=${newCard.dataset.id}, type=${newCard.dataset.type}`);
                
                // Add click handler
                newCard.addEventListener('click', function(e) {
                    console.log('Game card clicked!', 'ID:', this.dataset.id, 'Type:', this.dataset.type);
                    
                    // Don't prevent if clicking on buttons or links inside
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                        console.log('Click was on a link/button, ignoring');
                        return;
                    }
                    
                    e.preventDefault();
                    e.stopPropagation();
                    const gameId = this.dataset.id;
                    if (gameId && this.dataset.type === 'game') {
                        console.log('Navigating to game-detail.php?id=' + gameId);
                        window.location.href = `game-detail.php?id=${gameId}`;
                    } else {
                        console.error('Invalid card data:', {gameId, type: this.dataset.type});
                    }
                });
            });
            
            console.log('Game card handlers attached to', cards.length, 'cards');
        }, 10);
    } else {
        console.error('Container is NOT gamesContainer!', container?.id);
    }
}

/**
 * Display games in list view
 */
function displayGamesListView(games, container) {
    container.className = 'games-container list-view';
    container.innerHTML = `
        <table class="games-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Platform</th>
                    <th>Genre</th>
                    <th>Release Date</th>
                    <th>Type</th>
                    <th>Played</th>
                    <th>Rating</th>
                    <th>Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${games.map(game => `
                    <tr data-id="${game.id}" data-type="game">
                        <td class="game-title-cell">
                            ${game.front_cover_image 
                                ? `<img src="${getImageUrl(game.front_cover_image)}" alt="${escapeHtml(game.title)}" class="list-cover-thumb">`
                                : ''}
                            <span>${escapeHtml(game.title)}</span>
                        </td>
                        <td>${escapeHtml(game.platform)}</td>
                        <td>${escapeHtml(game.genre || 'N/A')}</td>
                        <td>${game.release_date ? formatDate(game.release_date) : 'N/A'}</td>
                        <td>${game.is_physical ? '<span class="badge badge-physical">Physical</span>' : '<span class="badge badge-digital">Digital</span>'}</td>
                        <td>${game.played ? '✓' : '✗'}</td>
                        <td>${game.star_rating ? '★ '.repeat(game.star_rating) : 'N/A'}</td>
                        <td>${formatCurrency(game.pricecharting_price || game.price_paid)}</td>
                        <td>
                            <a href="game-detail.php?id=${game.id}" class="btn btn-small" data-type="game">View</a>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    // Add click handler for list view rows (only for games container)
    console.log('Setting up list view handler, container.id:', container?.id);
    if (container && container.id === 'gamesContainer') {
        console.log('Container is gamesContainer for list view');
        // Remove any existing click handlers first
        const oldHandler = container._gameListClickHandler;
        if (oldHandler) {
            container.removeEventListener('click', oldHandler);
        }
        
        // Create new handler for list view
        container._gameListClickHandler = function(e) {
            // Check if clicking on a link - let it work normally
            if (e.target.tagName === 'A' || e.target.closest('a')) {
                return; // Let the link handle it
            }
            
            // Find the closest table row
            const row = e.target.closest('tr[data-type="game"]');
            if (row && row.dataset.type === 'game') {
                e.preventDefault();
                e.stopPropagation();
                const gameId = row.dataset.id;
                if (gameId) {
                    window.location.href = `game-detail.php?id=${gameId}`;
                }
            }
        };
        
        // Attach handler to container (bubble phase, not capture)
        container.addEventListener('click', container._gameListClickHandler);
    }
}

/**
 * Setup add game form
 */
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
            showModal('addGameModal');
        });
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
                const response = await fetch('api/games.php?action=create', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const data = await response.json();
                
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
                console.log('Fetching cover for:', title, platform);
                const url = `api/cover-image.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform || '')}`;
                console.log('Fetching from:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status, response.statusText);
                
                if (!response.ok) {
                    const text = await response.text();
                    console.error('HTTP error response:', text);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Cover API response:', data);
                
                if (data.success && data.image_url) {
                    console.log('Image URL found:', data.image_url);
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
        console.log('Downloading cover image from:', imageUrl);
        // First, download the image via our PHP proxy
        const url = `api/download-cover.php?url=${encodeURIComponent(imageUrl)}`;
        console.log('Download API URL:', url);
        
        const response = await fetch(url);
        console.log('Download response status:', response.status);
        
        if (!response.ok) {
            const text = await response.text();
            console.error('Download error response:', text);
            throw new Error(`Failed to download: ${response.status}`);
        }
        
        const data = await response.json();
        console.log('Download API response:', data);
        
        if (data.success && data.filename) {
            console.log('Image uploaded successfully:', data.filename);
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
                console.log('Fetching price for:', title, platform);
                const url = `api/pricecharting.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`;
                console.log('Fetching from:', url);
                
                const response = await fetch(url);
                console.log('Response status:', response.status, response.statusText);
                
                if (!response.ok) {
                    const text = await response.text();
                    console.error('HTTP error response:', text);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('Price API response:', data);
                
                if (data.success && data.price) {
                    console.log('Price found:', data.price);
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
    
    // Metacritic fetching
    const fetchMetacriticBtn = document.getElementById('fetchMetacriticAddBtn');
    if (fetchMetacriticBtn) {
        fetchMetacriticBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;
            
            if (!title) {
                showNotification('Please enter a title first', 'error');
                return;
            }
            
            fetchMetacriticBtn.disabled = true;
            fetchMetacriticBtn.textContent = 'Fetching...';
            
            try {
                const response = await fetch(`api/metacritic.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`);
                const data = await response.json();
                
                if (data.success && data.rating !== null) {
                    document.getElementById('addMetacriticRating').value = data.rating;
                    showNotification('Metacritic rating fetched successfully', 'success');
                } else {
                    showNotification(data.message || 'Could not fetch Metacritic rating', 'error');
                }
            } catch (error) {
                console.error('Error fetching Metacritic:', error);
                showNotification('Error fetching Metacritic rating', 'error');
            } finally {
                fetchMetacriticBtn.disabled = false;
                fetchMetacriticBtn.textContent = 'Fetch';
            }
        });
    }
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
                            console.log('Image appears to be a combined cover (aspect ratio:', aspectRatio.toFixed(2), ')');
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
        const response = await fetch('api/upload.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
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
function setupViewToggle() {
    const listBtn = document.getElementById('listViewBtn');
    const gridBtn = document.getElementById('gridViewBtn');
    
    if (listBtn && gridBtn) {
        // Set initial state
        if (currentView === 'grid') {
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
        } else {
            listBtn.classList.add('active');
            gridBtn.classList.remove('active');
        }
        
        listBtn.addEventListener('click', () => {
            currentView = 'list';
            localStorage.setItem('gameView', 'list');
            listBtn.classList.add('active');
            gridBtn.classList.remove('active');
            displayGames(allGames);
        });
        
        gridBtn.addEventListener('click', () => {
            currentView = 'grid';
            localStorage.setItem('gameView', 'grid');
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            displayGames(allGames);
        });
    }
}

/**
 * Load game detail
 */
async function loadGameDetail() {
    const urlParams = new URLSearchParams(window.location.search);
    const gameId = urlParams.get('id');
    
    if (!gameId) {
        document.getElementById('gameDetailContainer').innerHTML = '<div class="error">Game ID not provided</div>';
        return;
    }
    
    try {
        const response = await fetch(`api/games.php?action=get&id=${gameId}`);
        const data = await response.json();
        
        if (data.success) {
            displayGameDetail(data.game);
        } else {
            document.getElementById('gameDetailContainer').innerHTML = '<div class="error">Game not found</div>';
        }
    } catch (error) {
        console.error('Error loading game:', error);
        document.getElementById('gameDetailContainer').innerHTML = '<div class="error">Error loading game</div>';
    }
}

/**
 * Display game detail
 */
function displayGameDetail(game) {
    const container = document.getElementById('gameDetailContainer');
    
    const frontCover = game.front_cover_image 
        ? `<img src="${getImageUrl(game.front_cover_image)}" alt="Front Cover" class="cover-image">`
        : '<div class="cover-placeholder">No Front Cover</div>';
    
    const backCover = game.back_cover_image 
        ? `<img src="${getImageUrl(game.back_cover_image)}" alt="Back Cover" class="cover-image">`
        : '<div class="cover-placeholder">No Back Cover</div>';
    
    const extraImages = game.extra_images && game.extra_images.length > 0
        ? game.extra_images.map((img, index) => 
            `<img src="uploads/extras/${img.image_path}" alt="Extra Photo" class="extra-image" data-image-index="${index}" data-image-path="${img.image_path}">`
        ).join('')
        : '<p>No extra photos</p>';
    
    container.innerHTML = `
        <div class="game-detail">
            <div class="game-detail-header">
                <div class="game-covers">
                    <div class="cover-section">
                        <h3>Front Cover</h3>
                        ${frontCover}
                    </div>
                    <div class="cover-section">
                        <h3>Back Cover</h3>
                        ${backCover}
                    </div>
                </div>
                <div class="game-info-header">
                    <h1>${escapeHtml(game.title)}</h1>
                    <div class="game-meta">
                        <span class="platform-badge">${escapeHtml(game.platform)}</span>
                        ${game.is_physical ? '<span class="badge badge-physical">Physical</span>' : '<span class="badge badge-digital">Digital</span>'}
                        ${game.played ? '<span class="badge badge-played">Played</span>' : '<span class="badge badge-unplayed">Not Played</span>'}
                    </div>
                </div>
            </div>
            
            <div class="game-detail-content">
                <div class="detail-section">
                    <h2>Details</h2>
                    <dl class="detail-list">
                        <dt>Genre:</dt>
                        <dd>${escapeHtml(game.genre || 'N/A')}</dd>
                        
                        <dt>Release Date:</dt>
                        <dd>${game.release_date ? formatDate(game.release_date) : 'N/A'}</dd>
                        
                        ${game.series ? `
                        <dt>Series:</dt>
                        <dd>${escapeHtml(game.series)}</dd>
                        ` : ''}
                        
                        ${game.special_edition ? `
                        <dt>Special Edition:</dt>
                        <dd>${escapeHtml(game.special_edition)}</dd>
                        ` : ''}
                        
                        ${game.condition && game.is_physical ? `
                        <dt>Condition:</dt>
                        <dd>${escapeHtml(game.condition)}</dd>
                        ` : ''}
                        
                        <dt>Star Rating:</dt>
                        <dd>${game.star_rating ? '★ '.repeat(game.star_rating) + ` (${game.star_rating}/5)` : 'Not rated'}</dd>
                        
                        <dt>Metacritic Rating:</dt>
                        <dd>${game.metacritic_rating !== null ? game.metacritic_rating + '/100' : 'N/A'}</dd>
                        
                        <dt>Price I Paid:</dt>
                        <dd>${formatCurrency(game.price_paid)}</dd>
                        
                        <dt>Pricecharting Price:</dt>
                        <dd>${formatCurrency(game.pricecharting_price)}</dd>
                    </dl>
                </div>
                
                ${game.description ? `
                <div class="detail-section">
                    <h2>Description</h2>
                    <p>${escapeHtml(game.description)}</p>
                </div>
                ` : ''}
                
                ${game.review ? `
                <div class="detail-section">
                    <h2>My Review</h2>
                    <p class="review-text">${escapeHtml(game.review)}</p>
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
    
    // Store game data for editing
    window.currentGame = game;
    
    // Setup lightbox for extra images
    setupImageLightbox();
}

/**
 * Setup lightbox for viewing extra images in full size
 */
function setupImageLightbox() {
    // Remove existing lightbox if any
    const existingLightbox = document.getElementById('imageLightbox');
    if (existingLightbox) {
        existingLightbox.remove();
    }
    
    // Create lightbox modal
    const lightbox = document.createElement('div');
    lightbox.id = 'imageLightbox';
    lightbox.className = 'image-lightbox';
    lightbox.innerHTML = `
        <div class="lightbox-content">
            <button class="lightbox-close">&times;</button>
            <button class="lightbox-prev">‹</button>
            <button class="lightbox-next">›</button>
            <img id="lightboxImage" src="" alt="Full size image">
            <div class="lightbox-counter"></div>
        </div>
    `;
    document.body.appendChild(lightbox);
    
    // Add click handlers to extra images
    document.querySelectorAll('.extra-image').forEach(img => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', function() {
            const imagePath = this.dataset.imagePath;
            const imageIndex = parseInt(this.dataset.imageIndex);
            openLightbox(imagePath, imageIndex);
        });
    });
    
    // Close lightbox handlers
    lightbox.querySelector('.lightbox-close').addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) {
            closeLightbox();
        }
    });
    
    // Keyboard navigation (only when lightbox is open)
    // Use a single global handler to avoid duplicates
    if (!window.lightboxKeyHandler) {
        window.lightboxKeyHandler = function(e) {
            const lightbox = document.getElementById('imageLightbox');
            if (lightbox && lightbox.classList.contains('show')) {
                if (e.key === 'Escape') {
                    closeLightbox();
                } else if (e.key === 'ArrowLeft') {
                    navigateLightbox(-1);
                } else if (e.key === 'ArrowRight') {
                    navigateLightbox(1);
                }
            }
        };
        document.addEventListener('keydown', window.lightboxKeyHandler);
    }
    
    // Navigation buttons
    lightbox.querySelector('.lightbox-prev').addEventListener('click', () => navigateLightbox(-1));
    lightbox.querySelector('.lightbox-next').addEventListener('click', () => navigateLightbox(1));
}

/**
 * Open lightbox with image
 */
function openLightbox(imagePath, imageIndex) {
    const lightbox = document.getElementById('imageLightbox');
    const lightboxImage = document.getElementById('lightboxImage');
    const counter = lightbox.querySelector('.lightbox-counter');
    
    lightboxImage.src = `uploads/extras/${imagePath}`;
    lightbox.style.display = 'flex';
    lightbox.classList.add('show');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    
    // Store current index
    lightbox.dataset.currentIndex = imageIndex;
    
    // Update counter
    const allImages = Array.from(document.querySelectorAll('.extra-image'));
    if (allImages.length > 1) {
        counter.textContent = `${imageIndex + 1} / ${allImages.length}`;
        counter.style.display = 'block';
    } else {
        counter.style.display = 'none';
    }
    
    // Show/hide navigation buttons
    const prevBtn = lightbox.querySelector('.lightbox-prev');
    const nextBtn = lightbox.querySelector('.lightbox-next');
    prevBtn.style.display = allImages.length > 1 ? 'flex' : 'none';
    nextBtn.style.display = allImages.length > 1 ? 'flex' : 'none';
}

/**
 * Close lightbox
 */
function closeLightbox() {
    const lightbox = document.getElementById('imageLightbox');
    if (lightbox) {
        lightbox.style.display = 'none';
        lightbox.classList.remove('show');
        document.body.style.overflow = ''; // Restore scrolling
    }
}

/**
 * Navigate lightbox (prev/next)
 */
function navigateLightbox(direction) {
    const lightbox = document.getElementById('imageLightbox');
    if (!lightbox || !lightbox.classList.contains('show')) return;
    
    const allImages = Array.from(document.querySelectorAll('.extra-image'));
    if (allImages.length === 0) return;
    
    let currentIndex = parseInt(lightbox.dataset.currentIndex || 0);
    currentIndex += direction;
    
    // Wrap around
    if (currentIndex < 0) {
        currentIndex = allImages.length - 1;
    } else if (currentIndex >= allImages.length) {
        currentIndex = 0;
    }
    
    const nextImage = allImages[currentIndex];
    if (nextImage) {
        openLightbox(nextImage.dataset.imagePath, currentIndex);
    }
}

/**
 * Setup edit game form
 */
function setupEditGameForm() {
    const editBtn = document.getElementById('editGameBtn');
    const modal = document.getElementById('editGameModal');
    const form = document.getElementById('editGameForm');
    
    if (editBtn) {
        editBtn.addEventListener('click', () => {
            if (window.currentGame) {
                populateEditForm(window.currentGame);
                showModal('editGameModal');
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
    
    // Setup metacritic fetching
    setupMetacriticFetching();
    
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
                // Get cover images - prefer URL if provided, otherwise use uploaded file or existing
                front_cover_image: (() => {
                    const urlInput = document.getElementById('editFrontCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    return window.currentGame.front_cover_image || null;
                })(),
                back_cover_image: (() => {
                    const urlInput = document.getElementById('editBackCoverUrl');
                    if (urlInput && urlInput.value.trim()) {
                        return urlInput.value.trim();
                    }
                    return window.currentGame.back_cover_image || null;
                })()
            };
            
            try {
                const response = await fetch('api/games.php?action=update', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                });
                
                const data = await response.json();
                
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
function populateEditForm(game) {
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
        
        document.getElementById('frontCoverPreview').innerHTML = 
            `<img src="${getImageUrl(game.front_cover_image)}" alt="Front Cover" style="max-width: 200px;">`;
    }
    if (game.back_cover_image) {
        const backUrlInput = document.getElementById('editBackCoverUrl');
        const isUrl = game.back_cover_image.startsWith('http://') || game.back_cover_image.startsWith('https://');
        
        if (isUrl && backUrlInput) {
            backUrlInput.value = game.back_cover_image;
        }
        
        document.getElementById('backCoverPreview').innerHTML = 
            `<img src="${getImageUrl(game.back_cover_image)}" alt="Back Cover" style="max-width: 200px;">`;
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
                            console.log('Image appears to be a combined cover (aspect ratio:', aspectRatio.toFixed(2), ')');
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
function setupImageSplitTool() {
    let currentSplitImage = null;
    let currentSplitContext = null; // 'add' or 'edit'
    let currentSplitImageUrl = null; // Store the image URL for source detection
    
    // Setup split buttons
    const addSplitBtn = document.getElementById('addFrontCoverSplitBtn');
    const editSplitBtn = document.getElementById('editFrontCoverSplitBtn');
    const addAutoSplitBtn = document.getElementById('addFrontCoverAutoSplitBtn');
    const editAutoSplitBtn = document.getElementById('editFrontCoverAutoSplitBtn');
    
    if (addSplitBtn) {
        addSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                openSplitModal(imageUrl, 'add');
            }
        });
    }
    
    if (editSplitBtn) {
        editSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl || window.currentGame?.front_cover_image;
            if (imageUrl) {
                openSplitModal(imageUrl, 'edit');
            }
        });
    }
    
    // Setup auto split buttons
    if (addAutoSplitBtn) {
        addAutoSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                performAutoSplit(imageUrl, 'add');
            }
        });
    }
    
    if (editAutoSplitBtn) {
        editAutoSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl || window.currentGame?.front_cover_image;
            if (imageUrl) {
                performAutoSplit(imageUrl, 'edit');
            }
        });
    }
    
    async function performAutoSplit(imageUrl, context) {
        // Remove _thumb from URL to get full-size image
        let fullSizeUrl = imageUrl;
        if (imageUrl.includes('_thumb')) {
            fullSizeUrl = imageUrl.replace('_thumb', '');
        }
        
        // Detect image source to determine split percentage
        const isRedditImage = fullSizeUrl.includes('i.redd.it') || fullSizeUrl.includes('preview.redd.it');
        const splitPercentage = isRedditImage ? 0.50 : 0.53; // Reddit: 50%, Covers Project: 53%
        
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
                
                // Vertical split at detected percentage
                const splitX = Math.floor(imgWidth * splitPercentage);
                
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
                await uploadSplitImages(frontDataUrl, backDataUrl, context);
                
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
    
    function openSplitModal(imageUrl, context) {
        currentSplitContext = context;
        currentSplitImageUrl = imageUrl; // Store for auto-split detection
        const modal = document.getElementById('imageSplitModal');
        const previewImg = document.getElementById('splitImagePreview');
        const splitLine = document.getElementById('splitLine');
        const slider = document.getElementById('splitPositionSlider');
        const positionValue = document.getElementById('splitPositionValue');
        const directionRadios = document.querySelectorAll('input[name="splitDirection"]');
        
        if (!modal || !previewImg) return;
        
        // Remove _thumb from URL to get full-size image (for The Covers Project)
        let fullSizeUrl = imageUrl;
        if (imageUrl.includes('_thumb')) {
            fullSizeUrl = imageUrl.replace('_thumb', '');
        }
        
        // Check if URL is external (needs proxy)
        const isExternalUrl = fullSizeUrl.startsWith('http://') || fullSizeUrl.startsWith('https://');
        const proxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(fullSizeUrl)}` : fullSizeUrl;
        
        // Detect image source and set appropriate defaults
        const isRedditImage = fullSizeUrl.includes('i.redd.it') || fullSizeUrl.includes('preview.redd.it');
        const isCoversProject = fullSizeUrl.includes('thecoverproject.net');
        
        // Load the image
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            previewImg.src = proxyUrl;
            currentSplitImage = img;
            
            // Auto-configure based on image source
            if (isRedditImage) {
                // Reddit images are typically side-by-side (vertical split) at 50%
                const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
                if (verticalRadio) {
                    verticalRadio.checked = true;
                    verticalRadio.dispatchEvent(new Event('change'));
                }
                slider.value = 50;
                positionValue.textContent = '50%';
            } else if (isCoversProject) {
                // The Covers Project uses 53% vertical split
                const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
                if (verticalRadio) {
                    verticalRadio.checked = true;
                    verticalRadio.dispatchEvent(new Event('change'));
                }
                slider.value = 53;
                positionValue.textContent = '53%';
            }
            
            updateSplitPreview();
            showModal('imageSplitModal');
        };
        img.onerror = function() {
            // If full-size fails, try the original URL
            if (fullSizeUrl !== imageUrl) {
                const fallbackProxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(imageUrl)}` : imageUrl;
                img.src = fallbackProxyUrl;
            } else {
                showNotification('Failed to load image. Please try again.', 'error');
            }
        };
        img.src = proxyUrl;
        
        // Update split line and preview on slider change
        slider.addEventListener('input', function() {
            const position = parseInt(this.value);
            positionValue.textContent = position + '%';
            updateSplitLine();
            updateSplitPreview();
        });
        
        // Update on direction change
        directionRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updateSplitLine();
                updateSplitPreview();
            });
        });
        
        function updateSplitLine() {
            if (!previewImg.complete) return;
            
            const direction = document.querySelector('input[name="splitDirection"]:checked')?.value || 'horizontal';
            const position = parseInt(slider.value);
            const rect = previewImg.getBoundingClientRect();
            
            splitLine.style.display = 'block';
            
            if (direction === 'vertical') {
                // Vertical split (left/right)
                const leftPercent = position;
                splitLine.style.left = (leftPercent / 100 * rect.width) + 'px';
                splitLine.style.top = '0';
                splitLine.style.bottom = '0';
                splitLine.style.width = '2px';
                splitLine.style.height = '100%';
            } else {
                // Horizontal split (top/bottom)
                const topPercent = position;
                splitLine.style.top = (topPercent / 100 * rect.height) + 'px';
                splitLine.style.left = '0';
                splitLine.style.right = '0';
                splitLine.style.width = '100%';
                splitLine.style.height = '2px';
            }
        }
        
        function updateSplitPreview() {
            if (!currentSplitImage || !previewImg.complete) return;
            
            const direction = document.querySelector('input[name="splitDirection"]:checked')?.value || 'horizontal';
            const position = parseInt(slider.value) / 100;
            
            const frontCanvas = document.getElementById('frontSplitPreview');
            const backCanvas = document.getElementById('backSplitPreview');
            
            if (!frontCanvas || !backCanvas) return;
            
            const imgWidth = currentSplitImage.width;
            const imgHeight = currentSplitImage.height;
            
            // Store full resolution canvases separately for export
            if (!window.splitFrontCanvas) {
                window.splitFrontCanvas = document.createElement('canvas');
            }
            if (!window.splitBackCanvas) {
                window.splitBackCanvas = document.createElement('canvas');
            }
            
            if (direction === 'vertical') {
                // Split left/right
                const splitX = Math.floor(imgWidth * position);
                
                // Front cover (right side) - Full resolution
                window.splitFrontCanvas.width = imgWidth - splitX;
                window.splitFrontCanvas.height = imgHeight;
                const frontCtxFull = window.splitFrontCanvas.getContext('2d');
                frontCtxFull.drawImage(currentSplitImage, splitX, 0, imgWidth - splitX, imgHeight, 0, 0, imgWidth - splitX, imgHeight);
                
                // Back cover (left side) - Full resolution
                window.splitBackCanvas.width = splitX;
                window.splitBackCanvas.height = imgHeight;
                const backCtxFull = window.splitBackCanvas.getContext('2d');
                backCtxFull.drawImage(currentSplitImage, 0, 0, splitX, imgHeight, 0, 0, splitX, imgHeight);
                
                // Preview canvases (scaled for display)
                const maxPreviewSize = 300;
                const frontScale = Math.min(1, maxPreviewSize / (imgWidth - splitX));
                const backScale = Math.min(1, maxPreviewSize / splitX);
                
                frontCanvas.width = (imgWidth - splitX) * frontScale;
                frontCanvas.height = imgHeight * frontScale;
                const frontCtx = frontCanvas.getContext('2d');
                frontCtx.drawImage(window.splitFrontCanvas, 0, 0, frontCanvas.width, frontCanvas.height);
                
                backCanvas.width = splitX * backScale;
                backCanvas.height = imgHeight * backScale;
                const backCtx = backCanvas.getContext('2d');
                backCtx.drawImage(window.splitBackCanvas, 0, 0, backCanvas.width, backCanvas.height);
            } else {
                // Split top/bottom
                const splitY = Math.floor(imgHeight * position);
                
                // Front cover (bottom side) - Full resolution
                window.splitFrontCanvas.width = imgWidth;
                window.splitFrontCanvas.height = imgHeight - splitY;
                const frontCtxFull = window.splitFrontCanvas.getContext('2d');
                frontCtxFull.drawImage(currentSplitImage, 0, splitY, imgWidth, imgHeight - splitY, 0, 0, imgWidth, imgHeight - splitY);
                
                // Back cover (top side) - Full resolution
                window.splitBackCanvas.width = imgWidth;
                window.splitBackCanvas.height = splitY;
                const backCtxFull = window.splitBackCanvas.getContext('2d');
                backCtxFull.drawImage(currentSplitImage, 0, 0, imgWidth, splitY, 0, 0, imgWidth, splitY);
                
                // Preview canvases (scaled for display)
                const maxPreviewSize = 300;
                const frontScale = Math.min(1, maxPreviewSize / (imgHeight - splitY));
                const backScale = Math.min(1, maxPreviewSize / splitY);
                
                frontCanvas.width = imgWidth * frontScale;
                frontCanvas.height = (imgHeight - splitY) * frontScale;
                const frontCtx = frontCanvas.getContext('2d');
                frontCtx.drawImage(window.splitFrontCanvas, 0, 0, frontCanvas.width, frontCanvas.height);
                
                backCanvas.width = imgWidth * backScale;
                backCanvas.height = splitY * backScale;
                const backCtx = backCanvas.getContext('2d');
                backCtx.drawImage(window.splitBackCanvas, 0, 0, backCanvas.width, backCanvas.height);
            }
        }
        
        // Wait for image to load before updating
        previewImg.onload = function() {
            updateSplitLine();
            updateSplitPreview();
        };
    }
    
    // Apply split button
    const applyBtn = document.getElementById('applySplitBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            // Use full resolution canvases if available, otherwise use preview canvases
            const frontCanvas = window.splitFrontCanvas || document.getElementById('frontSplitPreview');
            const backCanvas = window.splitBackCanvas || document.getElementById('backSplitPreview');
            
            if (!frontCanvas || !backCanvas) return;
            
            // Convert canvases to data URLs at high quality
            const frontDataUrl = frontCanvas.toDataURL('image/jpeg', 0.95);
            const backDataUrl = backCanvas.toDataURL('image/jpeg', 0.95);
            
            // Upload both images
            uploadSplitImages(frontDataUrl, backDataUrl, currentSplitContext);
            
            hideModal('imageSplitModal');
        });
    }
    
    // Auto Split button
    const autoSplitBtn = document.getElementById('autoSplitBtn');
    if (autoSplitBtn) {
        autoSplitBtn.addEventListener('click', function() {
            // Detect image source to determine split percentage
            let splitPercentage = 53; // Default for The Covers Project
            if (currentSplitImageUrl) {
                const isRedditImage = currentSplitImageUrl.includes('i.redd.it') || currentSplitImageUrl.includes('preview.redd.it');
                if (isRedditImage) {
                    splitPercentage = 50; // Reddit images use 50%
                }
            }
            
            // Set to vertical split
            const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
            if (verticalRadio) {
                verticalRadio.checked = true;
                verticalRadio.dispatchEvent(new Event('change'));
            }
            
            // Set position based on detected source
            const slider = document.getElementById('splitPositionSlider');
            const positionValue = document.getElementById('splitPositionValue');
            if (slider) {
                slider.value = splitPercentage;
                if (positionValue) {
                    positionValue.textContent = splitPercentage + '%';
                }
                slider.dispatchEvent(new Event('input'));
            }
            
            // Automatically apply the split after a brief moment
            setTimeout(function() {
                const applyBtn = document.getElementById('applySplitBtn');
                if (applyBtn) {
                    applyBtn.click();
                }
            }, 100);
        });
    }
    
    // Cancel button
    const cancelBtn = document.getElementById('cancelSplitBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            hideModal('imageSplitModal');
        });
    }
    
    // Close modal on X button
    const closeBtn = document.querySelector('#imageSplitModal .modal-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            hideModal('imageSplitModal');
        });
    }
    
    async function uploadSplitImages(frontDataUrl, backDataUrl, context) {
        try {
            // Store data URLs directly (no local file storage)
            // Data URLs are base64-encoded images that can be stored in the database
            if (context === 'add') {
                window.addGameFrontCover = frontDataUrl;
                window.addGameBackCover = backDataUrl;
                
                // Update previews
                document.getElementById('addFrontCoverPreview').innerHTML = 
                    `<img src="${frontDataUrl}" alt="Front Cover" style="max-width: 200px;">`;
                document.getElementById('addBackCoverPreview').innerHTML = 
                    `<img src="${backDataUrl}" alt="Back Cover" style="max-width: 200px;">`;
                
                // Clear URL inputs
                document.getElementById('addFrontCoverUrl').value = '';
                document.getElementById('addBackCoverUrl').value = '';
                document.getElementById('addFrontCoverSplitBtn').style.display = 'none';
                document.getElementById('addFrontCoverAutoSplitBtn').style.display = 'none';
            } else if (context === 'add-item') {
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
            } else {
                window.currentGame.front_cover_image = frontDataUrl;
                window.currentGame.back_cover_image = backDataUrl;
                
                // Update previews
                document.getElementById('frontCoverPreview').innerHTML = 
                    `<img src="${frontDataUrl}" alt="Front Cover" style="max-width: 200px;">`;
                document.getElementById('backCoverPreview').innerHTML = 
                    `<img src="${backDataUrl}" alt="Back Cover" style="max-width: 200px;">`;
                
                // Clear URL inputs
                document.getElementById('editFrontCoverUrl').value = '';
                document.getElementById('editBackCoverUrl').value = '';
                document.getElementById('editFrontCoverSplitBtn').style.display = 'none';
            }
            
            showNotification('Cover images split successfully! (Stored as data URLs - no local files)', 'success');
        } catch (error) {
            console.error('Error processing split images:', error);
            showNotification('Error processing split images', 'error');
        }
    }
    
    function dataURLtoBlob(dataURL) {
        const arr = dataURL.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new Blob([u8arr], { type: mime });
    }
}

/**
 * Setup image uploads
 */
function setupImageUploads() {
    // Cover image uploads
    document.querySelectorAll('.upload-image-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const fileInput = document.getElementById(targetId);
            if (fileInput) {
                fileInput.click();
            }
        });
    });
    
    document.getElementById('editFrontCover')?.addEventListener('change', function() {
        uploadCoverImage(this, 'front');
    });
    
    document.getElementById('editBackCover')?.addEventListener('change', function() {
        uploadCoverImage(this, 'back');
    });
    
    // Extra image upload
    document.getElementById('uploadExtraImageBtn')?.addEventListener('click', function() {
        const fileInput = document.getElementById('addExtraImage');
        if (fileInput) {
            fileInput.click();
        }
    });
    
    document.getElementById('addExtraImage')?.addEventListener('change', function() {
        uploadExtraImage(this);
    });
}

/**
 * Upload cover image
 */
async function uploadCoverImage(fileInput, type) {
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
            const previewId = type === 'front' ? 'frontCoverPreview' : 'backCoverPreview';
            document.getElementById(previewId).innerHTML = 
                `<img src="${data.url}" alt="${type} cover" style="max-width: 200px;">`;
            
            // Update game data
            if (type === 'front') {
                window.currentGame.front_cover_image = data.image_path;
            } else {
                window.currentGame.back_cover_image = data.image_path;
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
 * Upload extra image
 */
async function uploadExtraImage(fileInput) {
    if (!fileInput.files[0]) return;
    
    const formData = new FormData();
    formData.append('image', fileInput.files[0]);
    formData.append('type', 'extra');
    formData.append('game_id', window.currentGame.id);
    
    try {
        const response = await fetch('api/upload.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Reload game detail to show new image
            loadGameDetail();
            showNotification('Photo added successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to upload photo', 'error');
        }
    } catch (error) {
        console.error('Error uploading photo:', error);
        showNotification('Error uploading photo', 'error');
    }
}

/**
 * Display extra images in edit form
 */
function displayExtraImages(images) {
    const container = document.getElementById('extraImagesContainer');
    if (!container) return;
    
    container.innerHTML = images.map(img => `
        <div class="extra-image-item">
            <img src="uploads/extras/${img.image_path}" alt="Extra photo" style="max-width: 150px;">
        </div>
    `).join('');
}

/**
 * Setup price fetching
 */
function setupPriceFetching() {
    const fetchBtn = document.getElementById('fetchPriceBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('editTitle').value;
            const platform = document.getElementById('editPlatform').value;
            
            if (!title) {
                showNotification('Please enter a title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const response = await fetch(`api/pricecharting.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`);
                const data = await response.json();
                
                if (data.success && data.price) {
                    document.getElementById('editPricechartingPrice').value = data.price;
                    showNotification('Price fetched successfully', 'success');
                } else {
                    showNotification(data.message || 'Could not fetch price', 'error');
                }
            } catch (error) {
                console.error('Error fetching price:', error);
                showNotification('Error fetching price', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Fetch';
            }
        });
    }
}

/**
 * Setup Metacritic fetching
 */
function setupMetacriticFetching() {
    const fetchBtn = document.getElementById('fetchMetacriticBtn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('editTitle').value;
            const platform = document.getElementById('editPlatform').value;
            
            if (!title) {
                showNotification('Please enter a title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const response = await fetch(`api/metacritic.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`);
                const data = await response.json();
                
                if (data.success && data.rating !== null) {
                    document.getElementById('editMetacriticRating').value = data.rating;
                    showNotification('Metacritic rating fetched successfully', 'success');
                } else {
                    showNotification(data.message || 'Could not fetch Metacritic rating', 'error');
                }
            } catch (error) {
                console.error('Error fetching Metacritic:', error);
                showNotification('Error fetching Metacritic rating', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Fetch';
            }
        });
    }
}

/**
 * Setup delete game
 */
function setupDeleteGame() {
    const deleteBtn = document.getElementById('deleteGameBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', async function() {
            if (!window.currentGame) return;
            
            if (confirm(`Are you sure you want to delete "${window.currentGame.title}"? This action cannot be undone.`)) {
                try {
                    const response = await fetch(`api/games.php?action=delete&id=${window.currentGame.id}`);
                    const data = await response.json();
                    
                    if (data.success) {
                        showNotification('Game deleted successfully', 'success');
                        setTimeout(() => {
                            window.location.href = 'dashboard.php';
                        }, 1000);
                    } else {
                        showNotification(data.message || 'Failed to delete game', 'error');
                    }
                } catch (error) {
                    console.error('Error deleting game:', error);
                    showNotification('Error deleting game', 'error');
                }
            }
        });
    }
}

/**
 * Update filter options based on available games
 */
function updateFilters() {
    // Get unique platforms and genres
    const platforms = [...new Set(allGames.map(g => g.platform).filter(Boolean))].sort();
    const genres = [...new Set(allGames.map(g => g.genre).filter(Boolean))].sort();
    
    // Get saved filter state before updating
    const saved = localStorage.getItem('gameFilters');
    let savedPlatform = '';
    let savedGenre = '';
    if (saved) {
        try {
            const filterState = JSON.parse(saved);
            savedPlatform = filterState.platform || '';
            savedGenre = filterState.genre || '';
        } catch (e) {
            // Ignore parse errors
        }
    }
    
    // Update platform filter
    const platformFilter = document.getElementById('platformFilter');
    if (platformFilter) {
        const currentValue = savedPlatform || platformFilter.value;
        platformFilter.innerHTML = '<option value="">All Platforms</option>' +
            platforms.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
        // Restore saved value if it exists in the options
        if (currentValue && platformFilter.querySelector(`option[value="${escapeHtml(currentValue)}"]`)) {
            platformFilter.value = currentValue;
        }
    }
    
    // Update genre filter
    const genreFilter = document.getElementById('genreFilter');
    if (genreFilter) {
        const currentValue = savedGenre || genreFilter.value;
        genreFilter.innerHTML = '<option value="">All Genres</option>' +
            genres.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
        // Restore saved value if it exists in the options
        if (currentValue && genreFilter.querySelector(`option[value="${escapeHtml(currentValue)}"]`)) {
            genreFilter.value = currentValue;
        }
    }
    
    // Restore all filter state after dropdowns are updated
    if (typeof restoreFilterState === 'function') {
        setTimeout(() => {
            restoreFilterState();
            // Apply filters after restoring state
            if (typeof applyFilters === 'function') {
                applyFilters();
            }
        }, 50);
    }
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

