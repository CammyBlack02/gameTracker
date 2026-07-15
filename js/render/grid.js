// Grid view — extracted from games.js (phase 4f/06).
// Renders games as a grid of cover images. Uses getCaseType() from
// games.js to pick the platform-specific case aspect ratio, and reads
// escapeHtml / getImageUrl / formatDate from js/main.js's globals.
// Entry: displayGamesGridView(games, container) — called by games.js's
// displayGames dispatcher when currentView === 'grid'.

function displayGamesGridView(games, container) {
    container.className = 'games-container grid-view';
    const html = games.map(game => {
        const caseType = getCaseType(game.platform);
        const imageUrl = game.front_cover_image ? getImageUrl(game.front_cover_image, 'thumb') : null;
        const coverImage = imageUrl
            ? `<img src="${imageUrl}" alt="${escapeHtml(game.title)}" class="game-cover ${caseType}" loading="lazy" decoding="async">`
            : `<div class="game-cover-placeholder ${caseType}">${game.front_cover_image ? 'Image Error' : 'No Cover'}</div>`;
        
        return `
            <div class="game-card" data-id="${game.id}" data-type="game">
                ${coverImage}
                <div class="game-card-info">
                    <h3 class="game-title">${escapeHtml(game.title)}</h3>
                    <span class="platform-badge" data-platform="${escapeHtml(game.platform)}" style="display: inline-block; margin-bottom: 10px;">${escapeHtml(game.platform)}</span>
                    <div class="game-badges">
                        ${game.is_physical ? '<span class="badge badge-physical">Physical</span>' : '<span class="badge badge-digital">Digital</span>'}
                        ${game.played ? '<span class="badge badge-played">Played</span>' : ''}
                        ${game.star_rating ? `<span class="badge badge-rating">★ ${game.star_rating}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
    
    // Detect and handle combined covers (very wide or tall images)
    // Also handle image load errors (e.g., truncated base64 data)
    container.querySelectorAll('.game-cover').forEach(img => {
        img.addEventListener('load', function() {
            const aspectRatio = this.naturalWidth / this.naturalHeight;
            // Combined covers are usually wider (aspect ratio > 1.3) or taller (aspect ratio < 0.7)
            if (aspectRatio > 1.3 || aspectRatio < 0.7) {
                // Add a class to indicate it's a combined cover
                this.classList.add('combined-cover');
                // Optionally add a tooltip or visual indicator
                this.title = 'This appears to be a combined front/back cover. Use the split tool to separate them.';
            }
        });
        img.addEventListener('error', function() {
            // Thumbnail may not exist for legacy uploads that predate
            // auto-thumb generation. Try the full-size original once
            // before falling back to the placeholder.
            if (this.src.includes('/uploads/covers/thumbs/') && !this.dataset.thumbFellBack) {
                this.dataset.thumbFellBack = '1';
                this.src = this.src.replace('/uploads/covers/thumbs/', '/uploads/covers/');
                return;
            }
            // If image fails to load (e.g., truncated base64), show placeholder
            console.warn('Image failed to load, possibly truncated base64 data:', this.src.substring(0, 100));
            // Hide the broken image immediately
            this.style.display = 'none';
            // Check if placeholder already exists
            const card = this.closest('.game-card');
            if (card) {
                let placeholder = card.querySelector('.game-cover-placeholder');
                if (!placeholder) {
                    // Create placeholder if it doesn't exist
                    placeholder = document.createElement('div');
                    const caseType = this.classList.contains('cd-case') ? 'cd-case' : 'dvd-case';
                    placeholder.className = `game-cover-placeholder ${caseType}`;
                    placeholder.textContent = 'Image Error';
                    // Insert before the image or at the start of the card
                    this.parentNode.insertBefore(placeholder, this);
                } else {
                    // Show existing placeholder
                    placeholder.style.display = 'flex';
                    placeholder.textContent = 'Image Error';
                }
            }
        });
    });
    
    // Attach click handlers directly to each game card (only for games container)
    if (container && container.id === 'gamesContainer') {
        
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
            
            if (cards.length === 0) {
                console.error('No game cards found! Container HTML:', container.innerHTML.substring(0, 200));
            }
            
            cards.forEach((card) => {
                // Remove any existing handlers by cloning
                const newCard = card.cloneNode(true);
                card.parentNode.replaceChild(newCard, card);

                // Add click handler
                newCard.addEventListener('click', function(e) {
                    // Don't prevent if clicking on buttons or links inside
                    if (e.target.tagName === 'A' || e.target.tagName === 'BUTTON' || e.target.closest('a') || e.target.closest('button')) {
                        return;
                    }
                    
                    e.preventDefault();
                    e.stopPropagation();
                    const gameId = this.dataset.id;
                    if (gameId && this.dataset.type === 'game') {
                        window.location.href = `game-detail.php?id=${gameId}`;
                    } else {
                        console.error('Invalid card data:', {gameId, type: this.dataset.type});
                    }
                });
            });
            
        }, 10);
    } else {
        console.error('Container is NOT gamesContainer!', container?.id);
    }
}
