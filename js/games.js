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
 * Display games in grid view
 */
function displayGamesGridView(games, container) {
    console.log('displayGridView called with container:', container, 'ID:', container?.id);
    container.className = 'games-container grid-view';
    const html = games.map(game => {
        const coverImage = game.front_cover_image 
            ? `<img src="uploads/covers/${game.front_cover_image}" alt="${escapeHtml(game.title)}" class="game-cover">`
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
                                ? `<img src="uploads/covers/${game.front_cover_image}" alt="${escapeHtml(game.title)}" class="list-cover-thumb">`
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
                front_cover_image: window.addGameFrontCover || null,
                back_cover_image: window.addGameBackCover || null
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
                    // Download and upload the image
                    await downloadAndUploadCover(data.image_url, 'front');
                    showNotification('Cover image fetched and uploaded!', 'success');
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
            console.log('Image downloaded successfully:', data.filename);
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
        ? `<img src="uploads/covers/${game.front_cover_image}" alt="Front Cover" class="cover-image">`
        : '<div class="cover-placeholder">No Front Cover</div>';
    
    const backCover = game.back_cover_image 
        ? `<img src="uploads/covers/${game.back_cover_image}" alt="Back Cover" class="cover-image">`
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
                front_cover_image: window.currentGame.front_cover_image || null,
                back_cover_image: window.currentGame.back_cover_image || null
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
    
    // Display cover images
    if (game.front_cover_image) {
        document.getElementById('frontCoverPreview').innerHTML = 
            `<img src="uploads/covers/${game.front_cover_image}" alt="Front Cover" style="max-width: 200px;">`;
    }
    if (game.back_cover_image) {
        document.getElementById('backCoverPreview').innerHTML = 
            `<img src="uploads/covers/${game.back_cover_image}" alt="Back Cover" style="max-width: 200px;">`;
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
    
    // Update platform filter
    const platformFilter = document.getElementById('platformFilter');
    if (platformFilter) {
        const currentValue = platformFilter.value;
        platformFilter.innerHTML = '<option value="">All Platforms</option>' +
            platforms.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
        platformFilter.value = currentValue;
    }
    
    // Update genre filter
    const genreFilter = document.getElementById('genreFilter');
    if (genreFilter) {
        const currentValue = genreFilter.value;
        genreFilter.innerHTML = '<option value="">All Genres</option>' +
            genres.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
        genreFilter.value = currentValue;
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

