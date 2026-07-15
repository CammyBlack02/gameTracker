/**
 * Games management JavaScript
 * Handles game listing, adding, editing, deleting
 */

let allGames = [];
window.allGames = []; // Expose to window for spin wheel
let currentView = localStorage.getItem('gameView') || 'list';

document.addEventListener('DOMContentLoaded', function() {
    // Don't auto-load on user profile page (it loads games manually)
    const isUserProfilePage = window.IS_USER_PROFILE_PAGE || window.location.pathname.includes('user-profile.php');
    
    if (isUserProfilePage) {
        return; // Exit early, don't run any setup
    }
    
    // Load games on dashboard
    if (document.getElementById('gamesContainer')) {
        loadGames();
        setupAddGameForm();
        setupViewToggle();
        setupScrollToTop();
        setupHeroStats();
        // Populate platform dropdowns
        populatePlatformDropdowns();
    }
    
    // Load game detail if on detail page
    if (document.getElementById('gameDetailContainer')) {
        loadGameDetail();
        setupEditGameForm();
        setupDeleteGame();
        setupScrollToTop();
        // Populate platform dropdowns
        populatePlatformDropdowns();
    }
});

// Track if games are currently loading to prevent duplicate calls
let isLoadingGames = false;

/**
 * Load all games (with pagination)
 */
async function loadGames() {
    // Don't load games on user profile page
    if (window.IS_USER_PROFILE_PAGE || window.location.pathname.includes('user-profile.php')) {
        return;
    }
    
    // Prevent duplicate calls
    if (isLoadingGames) {
        return;
    }
    
    isLoadingGames = true;
    const container = document.getElementById('gamesContainer');
    if (container) {
        showSkeletonLoading(container);
    }
    
    try {
        // Progressive load: fetch a small first page for fast first paint,
        // then continue with larger pages in the background — rendering
        // after every page so the user sees content as it streams in.
        // Fable §6's real perf-win. Before: single render after all ~883
        // games arrived (~3-5s to first paint). After: first ~50 render
        // in ~300-500ms; the rest fill in as they land.
        allGames = [];
        let page = 1;
        let hasMore = true;
        const firstPageSize = 50;   // fast first paint
        const bulkPageSize = 500;   // load the rest efficiently

        while (hasMore) {
            const perPage = page === 1 ? firstPageSize : bulkPageSize;
            const response = await fetch(`api/games.php?action=list&page=${page}&per_page=${perPage}`);

            // Read the response as text first
            const text = await response.text();

            // Check if response is empty
            if (!text || text.trim().length === 0) {
                console.error('Empty response from server. Status:', response.status, response.statusText);
                throw new Error(`Empty response from server (HTTP ${response.status}). Please check server logs.`);
            }

            if (!response.ok) {
                console.error('HTTP error response:', response.status, response.statusText);
                console.error('Response body:', text);
                throw new Error(`HTTP error! status: ${response.status} - ${text.substring(0, 200)}`);
            }

            // Try to parse JSON
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseError) {
                console.error('JSON parse error:', parseError);
                console.error('Response text length:', text.length);
                console.error('Response preview:', text.substring(0, 500));
                console.error('Response status:', response.status);
                throw new Error('Invalid JSON response from server. The response may have been truncated or contain errors.');
            }

            if (data.success) {
                if (data.games && data.games.length > 0) {
                    allGames = allGames.concat(data.games);
                }

                // Determine if more pages come after this one BEFORE
                // deciding whether to render.
                if (data.pagination) {
                    hasMore = data.pagination.has_more === true && page < data.pagination.total_pages;
                } else {
                    hasMore = false;
                }

                // Render only on the first page (fast paint) and after the
                // final page (settled full grid). Skipping the middle pages
                // avoids the mid-load stutter that comes from repeated
                // innerHTML replacements as more games stream in.
                if (page === 1 || !hasMore) {
                    window.allGames = allGames;
                    displayGames(allGames);
                    updateFilters();
                }

                page++;
            } else {
                throw new Error(data.message || 'Failed to load games');
            }
        }

    } catch (error) {
        console.error('Error loading games:', error);
        // Only show error if we don't already have games loaded
        if (!allGames || allGames.length === 0) {
            showNotification('Error loading games: ' + error.message, 'error');
            if (container) {
                container.innerHTML = '<div class="empty-state"><div class="empty-state-icon">⚠️</div><h3>Error Loading Games</h3><p>Please check your connection and try again</p></div>';
            }
        } else {
            // If we already have games, just log the error but don't overwrite
            console.warn('Error during reload, but games are already loaded. Keeping existing games.');
        }
    } finally {
        isLoadingGames = false;
    }
}

/**
 * Show skeleton loading state
 */
function showSkeletonLoading(container) {
    const skeletonCount = 12;
    const skeletons = Array(skeletonCount).fill(0).map(() => `
        <div class="skeleton-card">
            <div class="skeleton skeleton-cover"></div>
            <div class="skeleton skeleton-text" style="margin-top: 15px;"></div>
            <div class="skeleton skeleton-text short"></div>
        </div>
    `).join('');
    
    container.className = 'games-container grid-view';
    container.innerHTML = skeletons;
}

/**
 * Display games in container
 */
function displayGames(games) {
    const container = document.getElementById('gamesContainer');
    
    if (!container) {
        console.error('gamesContainer not found!');
        return;
    }
    
    if (games.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🎮</div>
                <h3>No Games Found</h3>
                <p>Try adjusting your filters or add your first game!</p>
            </div>
        `;
        return;
    }
    
    if (currentView === 'coverflow') {
        displayGamesCoverFlow(games, container);
    } else if (currentView === 'grid') {
        displayGamesGridView(games, container);
    } else {
        displayGamesListView(games, container);
    }
}

// getImageUrl moved to main.js (Phase 4b) — canonical version handles
// (imagePath, size) + base64 validation + external-URL proxying + thumb
// paths for local files. Callers here still use `getImageUrl(path)` or
// `getImageUrl(path, 'thumb')` — same signature.

// (getCaseType moved to js/utils.js in phase 4f/09.)

/**
 * Display games in grid view
 */
// (displayGamesGridView moved to js/render/grid.js in phase 4f/06.)

/**
 * Display games in cover flow view
 */
// (Coverflow state + functions moved to js/render/coverflow.js in phase 4f/05.)
// (displayGamesListView moved to js/render/list.js in phase 4f/06.)

/**
 * Setup add game form
 */
// (setupAddGameForm and related moved to js/forms/game-form.js in phase 4f/07.)
function setupViewToggle() {
    const listBtn = document.getElementById('listViewBtn');
    const gridBtn = document.getElementById('gridViewBtn');
    const coverFlowBtn = document.getElementById('coverFlowViewBtn');
    
    if (listBtn && gridBtn && coverFlowBtn) {
        // Set initial state
        if (currentView === 'coverflow') {
            coverFlowBtn.classList.add('active');
            listBtn.classList.remove('active');
            gridBtn.classList.remove('active');
        } else if (currentView === 'grid') {
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            coverFlowBtn.classList.remove('active');
        } else {
            listBtn.classList.add('active');
            gridBtn.classList.remove('active');
            coverFlowBtn.classList.remove('active');
        }
        
        listBtn.addEventListener('click', () => {
            stopCoverFlowAutoRotate();
            currentView = 'list';
            localStorage.setItem('gameView', 'list');
            listBtn.classList.add('active');
            gridBtn.classList.remove('active');
            coverFlowBtn.classList.remove('active');
            displayGames(allGames);
        });
        
        gridBtn.addEventListener('click', () => {
            stopCoverFlowAutoRotate();
            currentView = 'grid';
            localStorage.setItem('gameView', 'grid');
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            coverFlowBtn.classList.remove('active');
            displayGames(allGames);
        });
        
        coverFlowBtn.addEventListener('click', () => {
            currentView = 'coverflow';
            localStorage.setItem('gameView', 'coverflow');
            coverFlowBtn.classList.add('active');
            listBtn.classList.remove('active');
            gridBtn.classList.remove('active');
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
        const data = await apiGet(`api/games.php?action=get&id=${gameId}`);

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
    
    const caseType = getCaseType(game.platform);
    const frontCoverUrl = game.front_cover_image ? getImageUrl(game.front_cover_image) : null;
    const frontCover = frontCoverUrl
        ? `<img src="${frontCoverUrl}" alt="Front Cover" class="cover-image ${caseType}">`
        : `<div class="cover-placeholder ${caseType}">${game.front_cover_image ? 'Image Error' : 'No Front Cover'}</div>`;
    
    const backCoverUrl = game.back_cover_image ? getImageUrl(game.back_cover_image) : null;
    const backCover = backCoverUrl
        ? `<img src="${backCoverUrl}" alt="Back Cover" class="cover-image ${caseType}">`
        : `<div class="cover-placeholder ${caseType}">${game.back_cover_image ? 'Image Error' : 'No Back Cover'}</div>`;
    
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
                        ${game.digital_store && game.platform && game.platform.trim().toUpperCase() === 'PC' && !game.is_physical ? `<span class="badge badge-digital-store">${escapeHtml(game.digital_store)}</span>` : ''}
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
                        
                        ${game.digital_store && game.platform && game.platform.trim().toUpperCase() === 'PC' && !game.is_physical ? `
                        <dt>Digital Store:</dt>
                        <dd>${escapeHtml(game.digital_store)}</dd>
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

// (setupImageLightbox moved to js/lightbox.js in phase 4f/10.)

// (openLightbox moved to js/lightbox.js in phase 4f/10.)

// (closeLightbox moved to js/lightbox.js in phase 4f/10.)

// (navigateLightbox moved to js/lightbox.js in phase 4f/10.)

/**
 * Setup edit game form
 */
// (setupEditGameForm and related moved to js/forms/game-form.js in phase 4f/07.)
// (setupImageSplitTool moved to js/image-split.js in phase 4f/08.)

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
        const data = await apiPostForm('api/upload.php', formData);

        if (data.success) {
            const previewId = type === 'front' ? 'frontCoverPreview' : 'backCoverPreview';
            document.getElementById(previewId).innerHTML =
                `<img src="${data.url}" alt="${type} cover" style="max-width: 200px;">`;

            // Update game data - store the full path, not just filename
            if (type === 'front') {
                window.currentGame.front_cover_image = data.image_path; // This is just the filename
                // Also update the URL input so it's saved on submit
                const urlInput = document.getElementById('editFrontCoverUrl');
                if (urlInput) {
                    urlInput.value = ''; // Clear URL input since we're using uploaded file
                }
            } else {
                window.currentGame.back_cover_image = data.image_path;
                const urlInput = document.getElementById('editBackCoverUrl');
                if (urlInput) {
                    urlInput.value = ''; // Clear URL input since we're using uploaded file
                }
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
        const data = await apiPostForm('api/upload.php', formData);

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
                const data = await apiGet(`api/pricecharting.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform)}`);

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

// Metacritic auto-fetch removed — no free source produced reliable
// scores. The Metacritic field on the edit form is now manual entry
// only.

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
                    const data = await apiPost(`api/games.php?action=delete&id=${window.currentGame.id}`);

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
/**
 * Populate platform dropdowns with admin's existing platforms
 */
async function populatePlatformDropdowns() {
    try {
        // Get platforms from all users (not just admin) to ensure all platforms are available
        // Pass no user_id to get all platforms across all users
        const response = await fetch('api/games.php?action=platforms');
        
        if (!response.ok) {
            console.error('Failed to fetch platforms:', response.status, response.statusText);
            return;
        }
        
        const data = await response.json();
        
        if (data.success && data.platforms) {
            const platforms = data.platforms;
            
            // Populate add platform datalist
            const addPlatformList = document.getElementById('addPlatformList');
            if (addPlatformList) {
                addPlatformList.innerHTML = platforms.map(p => `<option value="${escapeHtml(p)}">`).join('');
            }
            
            // Populate edit platform datalist
            const editPlatformList = document.getElementById('editPlatformList');
            if (editPlatformList) {
                editPlatformList.innerHTML = platforms.map(p => `<option value="${escapeHtml(p)}">`).join('');
            }
        }
    } catch (error) {
        console.error('Error populating platform dropdowns:', error);
    }
}

// updateFilters() moved to js/filters.js (phase 4f/02).

// escapeHtml moved to main.js (Phase 4a) — main.js loads before games.js
// on every page that includes this file, so the function is available
// via hoisting.

// (setupScrollToTop moved to js/utils.js in phase 4f/09.)

/**
 * Setup hero stats with animated counters
 */
async function setupHeroStats() {
    const heroStats = document.getElementById('heroStats');
    if (!heroStats) return;
    
    // Wait for games to load first
    if (allGames.length === 0) {
        setTimeout(setupHeroStats, 500);
        return;
    }
    
    try {
        // Fetch stats
        const data = await apiGet('api/stats.php?action=get');

        if (data.success && data.stats) {
            const stats = data.stats;
            
            // Calculate collection value (sum of all pricecharting prices or price_paid)
            let collectionValue = 0;
            if (allGames.length > 0) {
                collectionValue = allGames.reduce((sum, game) => {
                    const price = parseFloat(game.pricecharting_price || game.price_paid || 0);
                    return sum + price;
                }, 0);
            }
            
            // Show hero stats
            heroStats.style.display = 'block';
            
            // Animate counters
            animateCounter('heroStats', 0, stats.total_games, 'games');
            animateCounter('heroStats', 1, collectionValue, 'currency');
            animateCounter('heroStats', 2, stats.games_played, 'games');
            animateCounter('heroStats', 3, stats.total_collection, 'games');
            
        }
    } catch (error) {
        console.error('Error loading hero stats:', error);
    }
}


// (animateCounter moved to js/utils.js in phase 4f/09.)

