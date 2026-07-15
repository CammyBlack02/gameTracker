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
        // Load all pages of games
        allGames = [];
        let page = 1;
        let hasMore = true;
        const perPage = 500; // Load 500 games per request
        
        while (hasMore) {
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
                // Add games from this page
                if (data.games && data.games.length > 0) {
                    allGames = allGames.concat(data.games);
                }
                
                // Check if there are more pages
                if (data.pagination) {
                    hasMore = data.pagination.has_more === true && page < data.pagination.total_pages;
                    page++;
                } else {
                    hasMore = false;
                }
            } else {
                throw new Error(data.message || 'Failed to load games');
            }
        }
        
        // Expose to window for spin wheel
        window.allGames = allGames;
        
        // Display all games first, then update filters (which may apply saved filters)
        displayGames(allGames);
        // Update filter dropdowns and restore saved filter state
        updateFilters();
        
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

/**
 * Determine case type (CD or DVD) based on platform
 * CD cases: Only Super Nintendo, PlayStation (original), Game Boys, DS, 3DS, and N64
 * DVD cases: All other platforms (PlayStation 2+, Xbox series, Wii, etc.)
 */
function getCaseType(platform) {
    if (!platform) return 'dvd-case'; // Default to DVD
    
    const platformLower = platform.toLowerCase();
    
    // CD-sized cases (taller/narrower) - only these specific platforms
    const cdPlatforms = [
        'snes',                    // Super Nintendo
        'super nintendo',          // Super Nintendo (full name)
        'playstation',             // Original PlayStation (but not PS2, PS3, etc.)
        'game boy',                // All Game Boy variants
        'nintendo ds',             // Nintendo DS
        'nintendo 3ds',            // Nintendo 3DS
        'nintendo 64',             // Nintendo 64
        'n64'                      // Nintendo 64 (abbreviation)
    ];
    
    // Check if platform is CD-sized
    for (const cdPlatform of cdPlatforms) {
        // Special check for PlayStation - only original, not PS2, PS3, etc.
        if (cdPlatform === 'playstation') {
            // Only match if it's exactly "playstation" (no number after it)
            if (platformLower === 'playstation' || 
                (platformLower.startsWith('playstation') && 
                 !platformLower.match(/playstation\s*[2-5]/))) {
                return 'cd-case';
            }
        } else if (platformLower.includes(cdPlatform)) {
            // For other platforms, check if the platform name contains the CD platform name
            return 'cd-case';
        }
    }
    
    // Default to DVD case for all others
    return 'dvd-case';
}

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
// (setupEditGameForm and related moved to js/forms/game-form.js in phase 4f/07.)
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
        splitCoverImage(imageUrl, {
            onSuccess: async ({ frontDataUrl, backDataUrl }) => {
                await uploadSplitImages(frontDataUrl, backDataUrl, context);
                showNotification('Cover images auto-split successfully!', 'success');
            },
            onError: (message) => {
                showNotification(message, 'error');
            },
        });
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
            } else if (context === 'edit-item-front' || context === 'edit-item-back') {
                // Handle item editing - when splitting, update both front and back images
                if (window.currentItem) {
                    window.currentItem.front_image = frontDataUrl;
                    window.currentItem.back_image = backDataUrl;
                }
                
                // Update front image preview and URL
                const frontPreview = document.getElementById('itemFrontImagePreview');
                if (frontPreview) {
                    frontPreview.innerHTML = `<img src="${frontDataUrl}" alt="Front Image" style="max-width: 200px;">`;
                }
                const frontUrlInput = document.getElementById('editItemFrontImageUrl');
                if (frontUrlInput) {
                    frontUrlInput.value = frontDataUrl;
                }
                document.getElementById('editItemFrontImageSplitBtn').style.display = 'none';
                document.getElementById('editItemFrontImageAutoSplitBtn').style.display = 'none';
                
                // Update back image preview and URL
                const backPreview = document.getElementById('itemBackImagePreview');
                if (backPreview) {
                    backPreview.innerHTML = `<img src="${backDataUrl}" alt="Back Image" style="max-width: 200px;">`;
                }
                const backUrlInput = document.getElementById('editItemBackImageUrl');
                if (backUrlInput) {
                    backUrlInput.value = backDataUrl;
                }
                document.getElementById('editItemBackImageSplitBtn').style.display = 'none';
                document.getElementById('editItemBackImageAutoSplitBtn').style.display = 'none';
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
                    const data = await apiGet(`api/games.php?action=delete&id=${window.currentGame.id}`);

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

/**
 * Setup scroll to top button
 */
function setupScrollToTop() {
    // Create button if it doesn't exist
    let scrollBtn = document.getElementById('scrollToTop');
    if (!scrollBtn) {
        scrollBtn = document.createElement('button');
        scrollBtn.id = 'scrollToTop';
        scrollBtn.className = 'scroll-to-top';
        scrollBtn.innerHTML = '↑';
        scrollBtn.setAttribute('aria-label', 'Scroll to top');
        document.body.appendChild(scrollBtn);
    }
    
    // Show/hide button based on scroll position
    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollBtn.classList.add('show');
        } else {
            scrollBtn.classList.remove('show');
        }
    });
    
    // Scroll to top on click
    scrollBtn.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}

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


/**
 * Animate counter from start to end
 */
function animateCounter(containerId, index, endValue, type = 'number') {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const valueElement = container.querySelectorAll('.hero-stat-value')[index];
    if (!valueElement) return;
    
    const duration = 2000; // 2 seconds
    const startTime = performance.now();
    const startValue = 0;
    
    function updateCounter(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function (ease-out)
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentValue = startValue + (endValue - startValue) * easeOut;
        
        if (type === 'currency') {
            valueElement.textContent = '£' + Math.round(currentValue).toLocaleString();
        } else {
            valueElement.textContent = Math.round(currentValue).toLocaleString();
        }
        
        if (progress < 1) {
            requestAnimationFrame(updateCounter);
        } else {
            // Ensure final value is exact
            if (type === 'currency') {
                valueElement.textContent = '£' + endValue.toLocaleString();
            } else {
                valueElement.textContent = endValue.toLocaleString();
            }
        }
    }
    
    requestAnimationFrame(updateCounter);
}

