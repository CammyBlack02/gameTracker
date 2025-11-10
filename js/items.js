/**
 * Items (Consoles/Accessories) JavaScript
 */

let currentItems = [];
let currentItemView = 'list';

// Setup view toggle for items
document.addEventListener('DOMContentLoaded', function() {
    const listBtn = document.getElementById('itemsListViewBtn');
    const gridBtn = document.getElementById('itemsGridViewBtn');
    
    if (listBtn) {
        listBtn.addEventListener('click', function() {
            currentItemView = 'list';
            listBtn.classList.add('active');
            gridBtn.classList.remove('active');
            const container = document.getElementById('consolesContainer') || document.getElementById('accessoriesContainer');
            if (container && currentItems.length > 0) {
                displayItems(currentItems, container);
            }
        });
    }
    
    if (gridBtn) {
        gridBtn.addEventListener('click', function() {
            currentItemView = 'grid';
            gridBtn.classList.add('active');
            listBtn.classList.remove('active');
            const container = document.getElementById('consolesContainer') || document.getElementById('accessoriesContainer');
            if (container && currentItems.length > 0) {
                displayItems(currentItems, container);
            }
        });
    }
});

/**
 * Load items from API
 */
window.loadItems = async function loadItems(category = '') {
    // Determine which container to use based on category or active tab
    let container = null;
    
    // Try to determine from category first
    if (category === 'Systems') {
        container = document.getElementById('consolesContainer');
    } else if (category && category.includes('Controllers')) {
        container = document.getElementById('accessoriesContainer');
    } else {
        // Fallback to checking active tab
        const consolesTab = document.getElementById('consolesTab');
        const accessoriesTab = document.getElementById('accessoriesTab');
        
        if (consolesTab && (consolesTab.classList.contains('active') || window.getComputedStyle(consolesTab).display !== 'none')) {
            container = document.getElementById('consolesContainer');
        } else if (accessoriesTab && (accessoriesTab.classList.contains('active') || window.getComputedStyle(accessoriesTab).display !== 'none')) {
            container = document.getElementById('accessoriesContainer');
        } else {
            // Last resort - try both
            container = document.getElementById('consolesContainer') || document.getElementById('accessoriesContainer');
        }
    }
    
    if (!container) {
        console.error('No container found!');
        return;
    }
    
    container.innerHTML = '<div class="loading">Loading items...</div>';
    
    try {
        let url = 'api/items.php?action=list';
        if (category) {
            // Handle multiple categories
            const categories = category.split(',');
            if (categories.length === 1) {
                url += '&category=' + encodeURIComponent(category);
            } else {
                // Load all and filter client-side
                url = 'api/items.php?action=list';
            }
        }
        
        const response = await fetch(url);
        const data = await response.json();
        
        if (data.success) {
            let items = data.items || [];
            
            // Filter by category if multiple categories specified
            if (category && category.includes(',')) {
                const categories = category.split(',');
                items = items.filter(item => categories.includes(item.category));
            } else if (category && !category.includes(',')) {
                // Single category filter
                items = items.filter(item => item.category === category);
            }
            
            currentItems = items;
            
            if (items.length === 0) {
                container.innerHTML = '<div class="error">No items found in this category</div>';
            } else {
                displayItems(items, container);
            }
        } else {
            container.innerHTML = '<div class="error">Error loading items: ' + (data.message || 'Unknown error') + '</div>';
        }
    } catch (error) {
        console.error('Error loading items:', error);
        container.innerHTML = '<div class="error">Error loading items: ' + error.message + '</div>';
    }
}

/**
 * Display items in list or grid view
 */
function displayItems(items, container) {
    if (currentItemView === 'grid') {
        displayGridView(items, container);
    } else {
        displayListView(items, container);
    }
}

/**
 * Display items in grid view
 */
function displayGridView(items, container) {
    container.className = 'games-container grid-view';
    container.innerHTML = items.map((item, index) => {
        const itemId = item.id || item.ID;
        if (!itemId) {
            console.error('Item missing ID:', item);
        }
        const image = item.front_image 
            ? `<img src="uploads/covers/${item.front_image}" alt="${escapeHtml(item.title)}" class="game-cover">`
            : '<div class="game-cover-placeholder">No Image</div>';
        
        return `
            <div class="game-card" data-id="${itemId}" data-type="item" data-item-index="${index}">
                ${image}
                <div class="game-card-info">
                    <h3 class="game-title">${escapeHtml(item.title)}</h3>
                    <p class="game-platform">${escapeHtml(item.platform || 'N/A')}</p>
                    <div class="game-badges">
                        <span class="badge badge-physical">${escapeHtml(item.category)}</span>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    // Use event delegation on the container to avoid conflicts
    // ONLY attach to items containers, NEVER to gamesContainer
    if (container && (container.id === 'consolesContainer' || container.id === 'accessoriesContainer')) {
        // Make absolutely sure we're not on gamesContainer
        if (container.id === 'gamesContainer') {
            console.error('ERROR: items.js tried to attach handler to gamesContainer! This should never happen.');
            return;
        }
        
        // Remove any existing click handlers first
        const oldHandler = container._itemClickHandler;
        if (oldHandler) {
            container.removeEventListener('click', oldHandler);
        }
        
        // Create new handler
        container._itemClickHandler = function(e) {
            // Double-check we're in the right container
            if (e.currentTarget.id === 'gamesContainer') {
                return; // Don't handle clicks in games container
            }
            
            // Find the closest game-card element
            const card = e.target.closest('.game-card[data-type="item"]');
            if (card && card.dataset.type === 'item' && (card.closest('#consolesContainer') || card.closest('#accessoriesContainer'))) {
                e.preventDefault();
                e.stopPropagation();
                const itemId = card.dataset.id || card.getAttribute('data-id');
                if (itemId) {
                    window.location.href = `item-detail.php?id=${itemId}`;
                }
            }
        };
        
        // Attach handler to container
        container.addEventListener('click', container._itemClickHandler);
    }
}

/**
 * Display items in list view
 */
function displayListView(items, container) {
    if (!items || items.length === 0) {
        container.innerHTML = '<div class="error">No items to display</div>';
        return;
    }
    
    container.className = 'games-container list-view';
    
    // Use formatCurrency if available, otherwise use a simple fallback
    const formatPrice = typeof formatCurrency === 'function' ? formatCurrency : function(amount) {
        if (!amount && amount !== 0) return 'N/A';
        return '£' + parseFloat(amount).toFixed(2);
    };
    
    container.innerHTML = `
        <table class="games-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Platform</th>
                    <th>Category</th>
                    <th>Condition</th>
                    <th>Price</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((item, index) => {
                    const itemId = item.id || item.ID;
                    if (!itemId) {
                        console.error('Item missing ID:', item);
                    }
                    return `
                    <tr data-id="${itemId}">
                        <td class="game-title-cell">
                            ${item.front_image 
                                ? `<img src="uploads/covers/${item.front_image}" alt="${escapeHtml(item.title)}" class="list-cover-thumb">`
                                : ''}
                            <span>${escapeHtml(item.title)}</span>
                        </td>
                        <td>${escapeHtml(item.platform || 'N/A')}</td>
                        <td>${escapeHtml(item.category)}</td>
                        <td>${escapeHtml(item.condition || 'N/A')}</td>
                        <td>${formatPrice(item.pricecharting_price || item.price_paid)}</td>
                        <td>
                            <a href="item-detail.php?id=${itemId}" class="btn btn-small" data-item-id="${itemId}">View</a>
                        </td>
                    </tr>
                `;
                }).join('')}
            </tbody>
        </table>
    `;
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

