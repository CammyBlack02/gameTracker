// List view — extracted from games.js (phase 4f/06).
// Renders games as a table with cover thumbnails. Uses getCaseType()
// from games.js for the thumbnail aspect ratio, and reads escapeHtml /
// getImageUrl / formatDate from js/main.js's globals.
// Entry: displayGamesListView(games, container) — called by games.js's
// displayGames dispatcher when currentView !== 'grid'/'coverflow'.

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
                ${games.map(game => {
                    const caseType = getCaseType(game.platform);
                    return `
                    <tr data-id="${game.id}" data-type="game">
                        <td class="game-title-cell">
                            ${(() => {
                                const imageUrl = game.front_cover_image ? getImageUrl(game.front_cover_image, 'thumb') : null;
                                return imageUrl
                                    ? `<img src="${escapeHtml(imageUrl)}" alt="${escapeHtml(game.title)}" class="list-cover-thumb ${caseType}" loading="lazy" decoding="async">`
                                    : '';
                            })()}
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
                    `;
                }).join('')}
            </tbody>
        </table>
    `;
    
    // Add click handler for list view rows (only for games container)
    if (container && container.id === 'gamesContainer') {
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
