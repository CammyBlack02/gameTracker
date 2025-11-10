/**
 * Statistics page JavaScript
 */

let allGames = [];
let allItems = [];
let currentStats = null;
let currentTopType = null;
let selectedTopItems = [];
let currentModalItems = []; // Store items for the current modal

document.addEventListener('DOMContentLoaded', function() {
    loadGames();
    loadItems();
    setupFilters();
    setupTopItemsModal();
    setupLogout();
});

/**
 * Load all games for filtering
 */
async function loadGames() {
    try {
        const response = await fetch('api/games.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            allGames = data.games;
            updatePlatformFilter();
            loadStats();
        } else {
            showError('Failed to load games');
        }
    } catch (error) {
        console.error('Error loading games:', error);
        showError('Error loading games');
    }
}

/**
 * Load all items for top items selection
 */
async function loadItems() {
    try {
        const response = await fetch('api/items.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            allItems = data.items;
        }
    } catch (error) {
        console.error('Error loading items:', error);
    }
}

/**
 * Update platform filter dropdown
 */
function updatePlatformFilter() {
    const platformFilter = document.getElementById('platformFilter');
    const platforms = [...new Set(allGames.map(g => g.platform).filter(Boolean))].sort();
    
    platformFilter.innerHTML = '<option value="">All Platforms</option>' +
        platforms.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
}

/**
 * Setup filter event listeners
 */
function setupFilters() {
    const platformFilter = document.getElementById('platformFilter');
    const toggleButtons = document.querySelectorAll('.toggle-btn');
    
    platformFilter.addEventListener('change', loadStats);
    
    toggleButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            toggleButtons.forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            loadStats();
        });
    });
}

/**
 * Load statistics
 */
async function loadStats() {
    const platformFilter = document.getElementById('platformFilter');
    const activeToggle = document.querySelector('.toggle-btn.active');
    
    const platform = platformFilter.value;
    const type = activeToggle.dataset.type;
    
    let isPhysical = null;
    if (type === 'physical') {
        isPhysical = '1';
    } else if (type === 'digital') {
        isPhysical = '0';
    }
    
    const params = new URLSearchParams();
    if (platform) params.append('platform', platform);
    if (isPhysical !== null) params.append('is_physical', isPhysical);
    
    try {
        const response = await fetch(`api/stats.php?action=get&${params.toString()}`);
        const data = await response.json();
        
        if (data.success) {
            currentStats = data.stats;
            displayStats();
        } else {
            showError(data.message || 'Failed to load statistics');
        }
    } catch (error) {
        console.error('Error loading stats:', error);
        showError('Error loading statistics');
    }
}

/**
 * Display statistics
 */
function displayStats() {
    if (!currentStats) return;
    
    const statsContent = document.getElementById('statsContent');
    
    const stats = currentStats;
    
    statsContent.innerHTML = `
        <div class="stats-grid">
            <div class="stat-card featured">
                <div class="stat-label">Total Games</div>
                <div class="stat-value">${stats.total_games}</div>
                <div class="stat-detail">In your collection</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Games Played</div>
                <div class="stat-value">${stats.games_played}</div>
                <div class="stat-detail">${stats.total_games > 0 ? Math.round((stats.games_played / stats.total_games) * 100) : 0}% of collection</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Games Unplayed</div>
                <div class="stat-value">${stats.games_unplayed}</div>
                <div class="stat-detail">${stats.total_games > 0 ? Math.round((stats.games_unplayed / stats.total_games) * 100) : 0}% of collection</div>
            </div>
            
            ${stats.most_expensive_game ? `
            <div class="stat-card">
                <div class="stat-label">Most Expensive Game</div>
                <div class="stat-value">$${parseFloat(stats.most_expensive_game.price).toFixed(2)}</div>
                <div class="stat-detail">${escapeHtml(stats.most_expensive_game.title)} (${escapeHtml(stats.most_expensive_game.platform)})</div>
            </div>
            ` : ''}
            
            ${stats.newest_game ? `
            <div class="stat-card">
                <div class="stat-label">Newest Game</div>
                <div class="stat-value">${escapeHtml(stats.newest_game.title)}</div>
                <div class="stat-detail">${escapeHtml(stats.newest_game.platform)} • ${formatDate(stats.newest_game.created_at)}</div>
            </div>
            ` : ''}
            
            <div class="stat-card">
                <div class="stat-label">Total Consoles</div>
                <div class="stat-value">${stats.total_consoles}</div>
                <div class="stat-detail">In your collection</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Items</div>
                <div class="stat-value">${stats.total_items}</div>
                <div class="stat-detail">Consoles & accessories</div>
            </div>
            
            <div class="stat-card featured">
                <div class="stat-label">Total Collection</div>
                <div class="stat-value">${stats.total_collection}</div>
                <div class="stat-detail">Games + Items</div>
            </div>
            
            ${stats.accessory_types && stats.accessory_types.length > 0 ? `
            <div class="stat-card" style="grid-column: 1 / -1;">
                <div class="stat-label">Accessory Types</div>
                <div class="accessory-types-list">
                    ${stats.accessory_types.map(type => `
                        <span class="accessory-type-badge">
                            ${escapeHtml(type.category)}: ${type.count}
                        </span>
                    `).join('')}
                </div>
            </div>
            ` : ''}
        </div>
        
        <div class="top-items-section">
            <div class="section-title">
                <span>🏆</span>
                <span>Top 5 Games</span>
                <button class="btn btn-secondary" style="margin-left: auto; font-size: 14px;" onclick="openTopItemsModal('games')">Edit</button>
            </div>
            <div class="top-items-grid" id="topGamesGrid">
                ${renderTopItems(stats.top_games || [], 'games')}
            </div>
        </div>
        
        <div class="top-items-section">
            <div class="section-title">
                <span>🎮</span>
                <span>Top 5 Consoles</span>
                <button class="btn btn-secondary" style="margin-left: auto; font-size: 14px;" onclick="openTopItemsModal('consoles')">Edit</button>
            </div>
            <div class="top-items-grid" id="topConsolesGrid">
                ${renderTopItems(stats.top_consoles || [], 'consoles')}
            </div>
        </div>
        
        <div class="top-items-section">
            <div class="section-title">
                <span>🎯</span>
                <span>Top 5 Accessories</span>
                <button class="btn btn-secondary" style="margin-left: auto; font-size: 14px;" onclick="openTopItemsModal('accessories')">Edit</button>
            </div>
            <div class="top-items-grid" id="topAccessoriesGrid">
                ${renderTopItems(stats.top_accessories || [], 'accessories')}
            </div>
        </div>
    `;
}

/**
 * Render top items
 */
function renderTopItems(items, type) {
    const html = [];
    
    for (let i = 0; i < 5; i++) {
        if (items[i]) {
            const item = items[i];
            const imageUrl = getImageUrl(item.image || item.front_cover_image || item.front_image);
            html.push(`
                <div class="top-item-card">
                    <img src="${imageUrl}" alt="${escapeHtml(item.title)}" class="top-item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                    <div class="top-item-image" style="display: none;">No Image</div>
                    <div class="top-item-info">
                        <div class="top-item-title">${escapeHtml(item.title)}</div>
                        ${item.platform ? `<div class="top-item-platform">${escapeHtml(item.platform)}</div>` : ''}
                    </div>
                </div>
            `);
        } else {
            html.push(`
                <div class="top-item-card">
                    <div class="top-item-placeholder">
                        <div class="top-item-placeholder-icon">+</div>
                        <div>Empty Slot</div>
                    </div>
                </div>
            `);
        }
    }
    
    return html.join('');
}

/**
 * Get image URL
 */
function getImageUrl(imagePath) {
    if (!imagePath) return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><rect fill="%23ddd" width="200" height="200"/></svg>';
    if (imagePath.startsWith('http://') || imagePath.startsWith('https://') || imagePath.startsWith('data:')) {
        return imagePath;
    }
    return `uploads/covers/${imagePath}`;
}

/**
 * Render items in modal
 */
function renderModalItems(items, searchTerm = '') {
    const content = document.getElementById('topItemsModalContent');
    
    // Filter items by search term
    let filteredItems = items;
    if (searchTerm) {
        const searchLower = searchTerm.toLowerCase();
        filteredItems = items.filter(item => {
            const title = (item.title || '').toLowerCase();
            const platform = (item.platform || '').toLowerCase();
            return title.includes(searchLower) || platform.includes(searchLower);
        });
    }
    
    content.innerHTML = `
        <div id="topItemsGrid" style="max-height: 500px; overflow-y: auto; display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
            ${filteredItems.length > 0 ? filteredItems.map(item => {
                const isSelected = selectedTopItems.some(ti => ti.id == item.id);
                const imageUrl = getImageUrl(item.front_cover_image || item.front_image);
                return `
                    <div class="top-item-card ${isSelected ? 'selected' : ''}" 
                         data-id="${item.id}"
                         onclick="toggleTopItem(${item.id})"
                         style="cursor: pointer; ${isSelected ? 'border: 2px solid var(--primary-color);' : ''}">
                        <img src="${imageUrl}" alt="${escapeHtml(item.title)}" class="top-item-image" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="top-item-image" style="display: none;">No Image</div>
                        <div class="top-item-info">
                            <div class="top-item-title">${escapeHtml(item.title)}</div>
                            ${item.platform ? `<div class="top-item-platform">${escapeHtml(item.platform)}</div>` : ''}
                        </div>
                        ${isSelected ? '<div style="position: absolute; top: 10px; right: 10px; background: var(--primary-color); color: white; border-radius: 50%; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-weight: bold;">✓</div>' : ''}
                    </div>
                `;
            }).join('') : `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-secondary);">
                    No items found matching "${escapeHtml(searchTerm)}"
                </div>
            `}
        </div>
        <div style="padding: 15px; background: var(--card-bg); border-radius: 8px; margin-bottom: 15px;">
            <strong>Selected (${selectedTopItems.length}/5):</strong>
            <div id="selectedItemsList" style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 10px;">
                ${selectedTopItems.map((item, index) => `
                    <div style="display: flex; align-items: center; gap: 8px; background: var(--bg-color); padding: 8px 12px; border-radius: 6px;">
                        <span style="font-weight: bold; color: var(--primary-color);">${index + 1}.</span>
                        <span>${escapeHtml(item.title)}</span>
                        <button onclick="removeTopItem(${item.id})" style="background: #f44; color: white; border: none; border-radius: 4px; padding: 4px 8px; cursor: pointer; font-size: 12px;">×</button>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

/**
 * Open top items modal
 */
async function openTopItemsModal(type) {
    currentTopType = type;
    selectedTopItems = currentStats[`top_${type}`] || [];
    
    const modal = document.getElementById('topItemsModal');
    const title = document.getElementById('topItemsModalTitle');
    const searchInput = document.getElementById('topItemsSearch');
    
    title.textContent = `Select Top 5 ${type.charAt(0).toUpperCase() + type.slice(1)}`;
    
    // Clear search
    if (searchInput) {
        searchInput.value = '';
    }
    
    // Get items based on type
    if (type === 'games') {
        currentModalItems = allGames;
    } else if (type === 'consoles') {
        // Consoles use "Systems" as category in the database
        currentModalItems = allItems.filter(i => i.category === 'Systems' || i.category === 'Console');
    } else if (type === 'accessories') {
        // Accessories are everything that's not Systems/Console
        currentModalItems = allItems.filter(i => i.category !== 'Systems' && i.category !== 'Console');
    }
    
    // Render item selection
    renderModalItems(currentModalItems);
    
    // Setup search handler
    if (searchInput) {
        searchInput.oninput = function() {
            renderModalItems(currentModalItems, this.value);
        };
    }
    
    showModal('topItemsModal');
}

/**
 * Toggle top item selection
 */
function toggleTopItem(itemId) {
    const index = selectedTopItems.findIndex(ti => ti.id == itemId);
    
    if (index >= 0) {
        selectedTopItems.splice(index, 1);
    } else {
        if (selectedTopItems.length >= 5) {
            alert('You can only select up to 5 items');
            return;
        }
        
        // Find the item and add it
        const item = currentModalItems.find(i => i.id == itemId);
        
        if (item) {
            selectedTopItems.push({
                id: item.id,
                title: item.title,
                platform: item.platform,
                image: item.front_cover_image || item.front_image
            });
        }
    }
    
    // Re-render modal content with current search term
    const searchInput = document.getElementById('topItemsSearch');
    const searchTerm = searchInput ? searchInput.value : '';
    renderModalItems(currentModalItems, searchTerm);
}

/**
 * Remove top item
 */
function removeTopItem(itemId) {
    const index = selectedTopItems.findIndex(ti => ti.id == itemId);
    if (index >= 0) {
        selectedTopItems.splice(index, 1);
        // Re-render modal content with current search term
        const searchInput = document.getElementById('topItemsSearch');
        const searchTerm = searchInput ? searchInput.value : '';
        renderModalItems(currentModalItems, searchTerm);
    }
}

/**
 * Setup top items modal
 */
function setupTopItemsModal() {
    const saveBtn = document.getElementById('saveTopItemsBtn');
    const cancelBtn = document.getElementById('cancelTopItemsBtn');
    const closeBtn = document.querySelector('#topItemsModal .modal-close');
    
    if (saveBtn) {
        saveBtn.addEventListener('click', saveTopItems);
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => hideModal('topItemsModal'));
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', () => hideModal('topItemsModal'));
    }
}

/**
 * Save top items
 */
async function saveTopItems() {
    if (!currentTopType) return;
    
    const itemIds = selectedTopItems.map(item => item.id);
    
    try {
        const response = await fetch('api/stats.php?action=update-top', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                type: currentTopType,
                item_ids: itemIds
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            hideModal('topItemsModal');
            loadStats(); // Reload stats to show updated top items
            showNotification('Top items updated successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to update top items', 'error');
        }
    } catch (error) {
        console.error('Error saving top items:', error);
        showNotification('Error saving top items', 'error');
    }
}

/**
 * Setup logout
 */
function setupLogout() {
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', async function() {
            try {
                const response = await fetch('api/auth.php?action=logout');
                const data = await response.json();
                if (data.success) {
                    window.location.href = 'index.php';
                }
            } catch (error) {
                console.error('Logout error:', error);
                window.location.href = 'index.php';
            }
        });
    }
}

/**
 * Show error
 */
function showError(message) {
    const statsContent = document.getElementById('statsContent');
    statsContent.innerHTML = `<div class="error">${escapeHtml(message)}</div>`;
}

/**
 * Format date
 */
function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Show modal
 */
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'flex';
    }
}

/**
 * Hide modal
 */
function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Show notification
 */
function showNotification(message, type = 'info') {
    // Simple alert for now, can be enhanced with a toast system
    alert(message);
}

