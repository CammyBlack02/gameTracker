/**
 * Filtering and search functionality
 */

document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('searchInput')) {
        setupFilters();
    }
});

/**
 * Setup all filter handlers
 */
function setupFilters() {
    const searchInput = document.getElementById('searchInput');
    const platformFilter = document.getElementById('platformFilter');
    const genreFilter = document.getElementById('genreFilter');
    const typeFilter = document.getElementById('typeFilter');
    const playedFilter = document.getElementById('playedFilter');
    const sortSelect = document.getElementById('sortSelect');
    
    // Add event listeners
    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }
    if (platformFilter) {
        platformFilter.addEventListener('change', applyFilters);
    }
    if (genreFilter) {
        genreFilter.addEventListener('change', applyFilters);
    }
    if (typeFilter) {
        typeFilter.addEventListener('change', applyFilters);
    }
    if (playedFilter) {
        playedFilter.addEventListener('change', applyFilters);
    }
    if (sortSelect) {
        sortSelect.addEventListener('change', applyFilters);
    }
}

/**
 * Apply all filters and update display
 */
function applyFilters() {
    if (typeof allGames === 'undefined' || !allGames) {
        return;
    }
    
    let filtered = [...allGames];
    
    // Search filter
    const searchTerm = document.getElementById('searchInput')?.value.toLowerCase() || '';
    if (searchTerm) {
        filtered = filtered.filter(game => {
            const title = (game.title || '').toLowerCase();
            const platform = (game.platform || '').toLowerCase();
            const genre = (game.genre || '').toLowerCase();
            return title.includes(searchTerm) || 
                   platform.includes(searchTerm) || 
                   genre.includes(searchTerm);
        });
    }
    
    // Platform filter
    const platform = document.getElementById('platformFilter')?.value || '';
    if (platform) {
        filtered = filtered.filter(game => game.platform === platform);
    }
    
    // Genre filter
    const genre = document.getElementById('genreFilter')?.value || '';
    if (genre) {
        filtered = filtered.filter(game => game.genre === genre);
    }
    
    // Type filter (physical/digital)
    const type = document.getElementById('typeFilter')?.value || '';
    if (type === 'physical') {
        filtered = filtered.filter(game => game.is_physical);
    } else if (type === 'digital') {
        filtered = filtered.filter(game => !game.is_physical);
    }
    
    // Played filter
    const played = document.getElementById('playedFilter')?.value || '';
    if (played !== '') {
        const playedBool = played === '1';
        filtered = filtered.filter(game => game.played === playedBool);
    }
    
    // Sort
    const sort = document.getElementById('sortSelect')?.value || 'newest';
    filtered = sortGames(filtered, sort);
    
    // Update display
    displayGames(filtered);
}

/**
 * Sort games based on selected option
 */
function sortGames(games, sortOption) {
    const sorted = [...games];
    
    switch (sortOption) {
        case 'newest':
            sorted.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            break;
        
        case 'oldest':
            sorted.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
            break;
        
        case 'title-asc':
            sorted.sort((a, b) => (a.title || '').localeCompare(b.title || ''));
            break;
        
        case 'title-desc':
            sorted.sort((a, b) => (b.title || '').localeCompare(a.title || ''));
            break;
        
        case 'price-low':
            sorted.sort((a, b) => {
                const priceA = parseFloat(a.pricecharting_price || a.price_paid || 0);
                const priceB = parseFloat(b.pricecharting_price || b.price_paid || 0);
                return priceA - priceB;
            });
            break;
        
        case 'price-high':
            sorted.sort((a, b) => {
                const priceA = parseFloat(a.pricecharting_price || a.price_paid || 0);
                const priceB = parseFloat(b.pricecharting_price || b.price_paid || 0);
                return priceB - priceA;
            });
            break;
        
        case 'rating-high':
            sorted.sort((a, b) => {
                const ratingA = a.star_rating || 0;
                const ratingB = b.star_rating || 0;
                return ratingB - ratingA;
            });
            break;
        
        case 'rating-low':
            sorted.sort((a, b) => {
                const ratingA = a.star_rating || 0;
                const ratingB = b.star_rating || 0;
                return ratingA - ratingB;
            });
            break;
        
        case 'release-newest':
            sorted.sort((a, b) => {
                // Games without release dates go to the end
                if (!a.release_date && !b.release_date) return 0;
                if (!a.release_date) return 1;
                if (!b.release_date) return -1;
                return new Date(b.release_date) - new Date(a.release_date);
            });
            break;
        
        case 'release-oldest':
            sorted.sort((a, b) => {
                // Games without release dates go to the end
                if (!a.release_date && !b.release_date) return 0;
                if (!a.release_date) return 1;
                if (!b.release_date) return -1;
                return new Date(a.release_date) - new Date(b.release_date);
            });
            break;
    }
    
    return sorted;
}

