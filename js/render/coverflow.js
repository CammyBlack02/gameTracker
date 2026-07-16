// Coverflow view mode — extracted from games.js (phase 4f/05).
// Displays games as a 3D-rotating stack of covers with keyboard/click
// navigation, an optional auto-rotate, and a flip-to-back-cover toggle.
//
// External entry point: displayGamesCoverFlow(games, container) — called
// by games.js's displayGames dispatcher when currentView === 'coverflow'.
// Reads escapeHtml / getImageUrl / formatDate from js/main.js's globals.

// State (module-scoped globals — coverflow is a stateful view mode)
let coverFlowCurrentIndex = 0;
let coverFlowGames = [];
let coverFlowFlipped = false;
let coverFlowAutoRotate = false;
let coverFlowAutoRotateInterval = null;

function displayGamesCoverFlow(games, container) {
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
    
    coverFlowGames = games;
    coverFlowCurrentIndex = 0;
    
    container.className = 'games-container coverflow-view';
    container.innerHTML = `
        <div class="coverflow-container">
            <button class="coverflow-nav-btn coverflow-prev" id="coverFlowPrev" aria-label="Previous game">‹</button>
            <div class="coverflow-wrapper" id="coverFlowWrapper">
                <!-- Items will be rendered dynamically -->
            </div>
            <button class="coverflow-nav-btn coverflow-next" id="coverFlowNext" aria-label="Next game">›</button>
            <div class="coverflow-info" id="coverFlowInfo">
                <h3 class="coverflow-title" id="coverFlowTitle"></h3>
                <p class="coverflow-platform" id="coverFlowPlatform"></p>
                <div class="coverflow-buttons">
                    <button class="coverflow-rotate-btn" id="coverFlowRotate" title="Auto Rotate Through Games"><span class="rotate-icon">🔄</span> Auto Rotate</button>
                    <button class="coverflow-flip-btn" id="coverFlowFlip" title="Flip to Back Cover" style="display: none;"><span class="flip-icon">🔄</span> Flip Cover</button>
                </div>
            </div>
        </div>
    `;
    
    setupCoverFlowNavigation();
    updateCoverFlowDisplay();
}

function createCoverFlowItem(game, index) {
    const frontCoverUrl = game.front_cover_image 
        ? getImageUrl(game.front_cover_image)
        : null;
    const backCoverUrl = game.back_cover_image 
        ? getImageUrl(game.back_cover_image)
        : null;
    const placeholder = 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMjAwIiBoZWlnaHQ9IjMwMCIgZmlsbD0iI2VlZSIvPjx0ZXh0IHg9IjUwJSIgeT0iNTAlIiBmb250LWZhbWlseT0iQXJpYWwiIGZvbnQtc2l6ZT0iMTQiIGZpbGw9IiM5OTkiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGR5PSIuM2VtIj5ObyBDb3ZlcjwvdGV4dD48L3N2Zz4=';
    const caseType = getCaseType(game.platform);
    
    return `
        <div class="coverflow-item ${caseType}" data-index="${index}" data-id="${game.id}">
            <div class="coverflow-cover-wrapper">
                <div class="coverflow-cover-inner">
                    <div class="coverflow-cover-face coverflow-cover-front">
                        <div class="coverflow-cover-reflection"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-top"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-right"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-bottom"></div>
                        <img src="${escapeHtml(frontCoverUrl || placeholder)}"
                             alt="${escapeHtml(game.title)}"
                             class="coverflow-cover"
                             onerror="this.src='${placeholder}';">
                    </div>
                    ${backCoverUrl ? `
                    <div class="coverflow-cover-face coverflow-cover-back">
                        <div class="coverflow-cover-reflection"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-top"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-right"></div>
                        <div class="coverflow-box-edge coverflow-box-edge-bottom"></div>
                        <img src="${escapeHtml(backCoverUrl)}"
                             alt="${escapeHtml(game.title)} Back"
                             class="coverflow-cover"
                             onerror="this.onerror=null; this.src='${placeholder}';">
                    </div>
                    ` : ''}
                </div>
            </div>
        </div>
    `;
}

function setupCoverFlowNavigation() {
    const prevBtn = document.getElementById('coverFlowPrev');
    const nextBtn = document.getElementById('coverFlowNext');
    const rotateBtn = document.getElementById('coverFlowRotate');
    const flipBtn = document.getElementById('coverFlowFlip');
    const wrapper = document.getElementById('coverFlowWrapper');
    
    if (prevBtn) {
        prevBtn.addEventListener('click', () => navigateCoverFlow(-1));
    }
    
    if (nextBtn) {
        nextBtn.addEventListener('click', () => navigateCoverFlow(1));
    }
    
    if (rotateBtn) {
        rotateBtn.addEventListener('click', () => toggleAutoRotate());
    }
    
    if (flipBtn) {
        flipBtn.addEventListener('click', flipCoverFlow);
    }
    
    // Keyboard navigation
    const keyboardHandler = (e) => {
        if (currentView !== 'coverflow') {
            document.removeEventListener('keydown', keyboardHandler);
            return;
        }
        
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            navigateCoverFlow(-1);
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            navigateCoverFlow(1);
        } else if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            const centerGame = coverFlowGames[coverFlowCurrentIndex];
            if (centerGame) {
                window.location.href = `game-detail.php?id=${centerGame.id}`;
            }
        }
    };
    
    document.addEventListener('keydown', keyboardHandler);
    
    // Touch/swipe support
    let touchStartX = 0;
    let touchEndX = 0;
    
    if (wrapper) {
        wrapper.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        });
        
        wrapper.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        });
    }
    
    function handleSwipe() {
        const swipeThreshold = 50;
        const diff = touchStartX - touchEndX;
        
        if (Math.abs(diff) > swipeThreshold) {
            if (diff > 0) {
                navigateCoverFlow(1); // Swipe left = next
            } else {
                navigateCoverFlow(-1); // Swipe right = previous
            }
        }
    }
    
    // Click on center game to view details
    if (wrapper) {
        wrapper.addEventListener('click', (e) => {
            const item = e.target.closest('.coverflow-item');
            if (item && item.classList.contains('center')) {
                const gameId = item.dataset.id;
                if (gameId) {
                    window.location.href = `game-detail.php?id=${gameId}`;
                }
            }
        });
    }
}

function navigateCoverFlow(direction) {
    coverFlowCurrentIndex += direction;
    
    if (coverFlowCurrentIndex < 0) {
        coverFlowCurrentIndex = coverFlowGames.length - 1;
    } else if (coverFlowCurrentIndex >= coverFlowGames.length) {
        coverFlowCurrentIndex = 0;
    }
    
    // Reset flip state when navigating
    coverFlowFlipped = false;
    
    updateCoverFlowDisplay();
}

function updateCoverFlowDisplay() {
    const wrapper = document.getElementById('coverFlowWrapper');
    const titleEl = document.getElementById('coverFlowTitle');
    const platformEl = document.getElementById('coverFlowPlatform');
    
    if (!wrapper || !titleEl || !platformEl) return;
    
    const centerIndex = coverFlowCurrentIndex;
    const totalGames = coverFlowGames.length;
    
    // Only render visible items (center ± 2 on each side, max 5 items)
    const visibleRange = 2; // Show 2 items on each side of center
    const visibleIndices = [];
    
    for (let i = -visibleRange; i <= visibleRange; i++) {
        let index = centerIndex + i;
        // Handle wrapping
        if (index < 0) {
            index = totalGames + index;
        } else if (index >= totalGames) {
            index = index - totalGames;
        }
        visibleIndices.push(index);
    }
    
    // Get existing items
    const existingItems = wrapper.querySelectorAll('.coverflow-item');
    const existingIndices = new Set();
    existingItems.forEach(item => {
        const index = parseInt(item.dataset.index);
        existingIndices.add(index);
    });
    
    // Remove items that are no longer visible
    existingItems.forEach(item => {
        const index = parseInt(item.dataset.index);
        if (!visibleIndices.includes(index)) {
            item.remove();
        }
    });
    
    // Add new items that aren't already rendered
    visibleIndices.forEach(index => {
        if (!existingIndices.has(index)) {
            const game = coverFlowGames[index];
            if (game) {
                const itemHtml = createCoverFlowItem(game, index);
                wrapper.insertAdjacentHTML('beforeend', itemHtml);
            }
        }
    });
    
    // Update positions and classes for all visible items
    const items = wrapper.querySelectorAll('.coverflow-item');
    items.forEach(item => {
        const itemIndex = parseInt(item.dataset.index);
        const isCenter = itemIndex === centerIndex;
        
        if (isCenter) {
            item.classList.remove('left', 'right', 'far-left', 'far-right');
            item.classList.add('center');
            item.style.display = 'block';
            return;
        }
        
        // Calculate relative position (accounting for wrapping)
        let relativePos = itemIndex - centerIndex;
        if (relativePos > totalGames / 2) {
            relativePos = relativePos - totalGames;
        } else if (relativePos < -totalGames / 2) {
            relativePos = relativePos + totalGames;
        }
        
        const distance = Math.abs(relativePos);
        const isLeft = relativePos < 0;
        
        // Remove all classes
        item.classList.remove('left', 'right', 'center', 'far-left', 'far-right');
        
        if (distance === 1) {
            item.classList.add(isLeft ? 'left' : 'right');
        } else if (distance === 2) {
            item.classList.add(isLeft ? 'far-left' : 'far-right');
        } else {
            item.style.display = 'none';
            return;
        }
        
        item.style.display = 'block';
    });
    
    // Update info
    const currentGame = coverFlowGames[centerIndex];
    if (currentGame) {
        const flipBtn = document.getElementById('coverFlowFlip');
        
        if (titleEl) titleEl.textContent = currentGame.title;
        if (platformEl) platformEl.textContent = currentGame.platform;
        
        // Show/hide flip button based on whether back cover exists
        if (flipBtn) {
            if (currentGame.back_cover_image) {
                flipBtn.style.display = 'inline-flex';
            } else {
                flipBtn.style.display = 'none';
                // Reset flip state if no back cover
                coverFlowFlipped = false;
                const centerItem = document.querySelector('.coverflow-item.center');
                if (centerItem) {
                    const coverInner = centerItem.querySelector('.coverflow-cover-inner');
                    if (coverInner) {
                        coverInner.classList.remove('flipped');
                    }
                }
            }
        }
    }
}

function toggleAutoRotate() {
    const rotateBtn = document.getElementById('coverFlowRotate');
    
    coverFlowAutoRotate = !coverFlowAutoRotate;
    
    if (coverFlowAutoRotate) {
        rotateBtn.classList.add('active');
        rotateBtn.title = 'Stop Auto Rotate';
        // Start rotating immediately, then continue every 3 seconds
        navigateCoverFlow(1);
        coverFlowAutoRotateInterval = setInterval(() => {
            navigateCoverFlow(1);
        }, 3000); // Rotate every 3 seconds
    } else {
        rotateBtn.title = 'Auto Rotate';
        stopCoverFlowAutoRotate();
    }
}

function stopCoverFlowAutoRotate() {
    const rotateBtn = document.getElementById('coverFlowRotate');
    if (rotateBtn) {
        rotateBtn.classList.remove('active');
    }
    if (coverFlowAutoRotateInterval) {
        clearInterval(coverFlowAutoRotateInterval);
        coverFlowAutoRotateInterval = null;
    }
    coverFlowAutoRotate = false;
}

function flipCoverFlow() {
    const centerItem = document.querySelector('.coverflow-item.center');
    if (!centerItem) return;
    
    const coverInner = centerItem.querySelector('.coverflow-cover-inner');
    if (!coverInner) return;
    
    // Check if back cover exists
    const backFace = centerItem.querySelector('.coverflow-cover-back');
    if (!backFace) {
        return;
    }

    coverFlowFlipped = !coverFlowFlipped;
    
    if (coverFlowFlipped) {
        coverInner.classList.add('flipped');
    } else {
        coverInner.classList.remove('flipped');
    }
}

/**
 * Display games in list view
 */
