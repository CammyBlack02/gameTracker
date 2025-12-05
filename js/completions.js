/**
 * Game Completions page JavaScript
 */

let allCompletions = [];
let allGames = [];
let currentEditingId = null;

document.addEventListener('DOMContentLoaded', function() {
    loadCompletions();
    loadGames();
    setupFilters();
    setupCompletionModal();
    setupLogout();
});

/**
 * Load all completions
 */
async function loadCompletions() {
    const yearFilter = document.getElementById('yearFilter');
    const statusFilter = document.getElementById('statusFilter');
    
    const year = yearFilter ? yearFilter.value : '';
    const status = statusFilter ? statusFilter.value : 'all';
    
    const params = new URLSearchParams();
    if (year) params.append('year', year);
    if (status !== 'all') params.append('status', status);
    
    try {
        const response = await fetch(`api/completions.php?action=list&${params.toString()}`);
        const data = await response.json();
        
        if (data.success) {
            allCompletions = data.completions;
            displayCompletions();
        } else {
            showError(data.message || 'Failed to load completions');
        }
    } catch (error) {
        console.error('Error loading completions:', error);
        showError('Error loading completions');
    }
}

/**
 * Load all games for linking
 */
async function loadGames() {
    try {
        const response = await fetch('api/games.php?action=list');
        const data = await response.json();
        
        if (data.success) {
            allGames = data.games;
            updatePlatformList();
        }
    } catch (error) {
        console.error('Error loading games:', error);
    }
}

/**
 * Update platform datalist
 */
function updatePlatformList() {
    const platformsList = document.getElementById('platformsList');
    if (!platformsList) return;
    
    const platforms = [...new Set(allGames.map(g => g.platform).filter(Boolean))].sort();
    platformsList.innerHTML = platforms.map(p => `<option value="${escapeHtml(p)}">`).join('');
}

/**
 * Setup filter event listeners
 */
function setupFilters() {
    const yearFilter = document.getElementById('yearFilter');
    const statusFilter = document.getElementById('statusFilter');
    
    if (yearFilter) {
        yearFilter.addEventListener('change', loadCompletions);
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', loadCompletions);
    }
}

/**
 * Display completions
 */
function displayCompletions() {
    const content = document.getElementById('completionsContent');
    
    if (allCompletions.length === 0) {
        content.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">🎮</div>
                <h3>No Completions Yet</h3>
                <p>Start tracking your gaming journey by adding your first completion!</p>
                <button class="btn btn-primary" onclick="openAddCompletionModal()" style="margin-top: 20px;">Add Completion</button>
            </div>
        `;
        return;
    }
    
    content.innerHTML = `
        <div class="completions-list">
            ${allCompletions.map(completion => {
                const isCompleted = completion.date_completed !== null;
                const statusClass = isCompleted ? 'completed' : 'in-progress';
                const statusText = isCompleted ? 'Completed' : 'In Progress';
                
                const imageUrl = getCompletionImageUrl(completion);
                const gameLink = completion.game_id ? `<a href="game-detail.php?id=${completion.game_id}">${escapeHtml(completion.title)}</a>` : escapeHtml(completion.title);
                
                return `
                    <div class="completion-card ${statusClass}">
                        ${imageUrl ? `<img src="${imageUrl}" alt="${escapeHtml(completion.title)}" class="completion-image">` : ''}
                        <div class="completion-info">
                            <div class="completion-title">
                                ${gameLink}
                                ${completion.game_id ? '<span class="link-indicator">✓ Linked</span>' : ''}
                            </div>
                            <div class="completion-meta">
                                <div class="completion-meta-item">
                                    <span class="completion-status ${statusClass}">${statusText}</span>
                                </div>
                                ${completion.platform ? `<div class="completion-meta-item">📱 ${escapeHtml(completion.platform)}</div>` : ''}
                                ${completion.time_taken ? `<div class="completion-meta-item">⏱️ ${escapeHtml(completion.time_taken)}</div>` : ''}
                                ${completion.date_started ? `<div class="completion-meta-item">📅 Started: ${formatDate(completion.date_started)}</div>` : ''}
                                ${completion.date_completed ? `<div class="completion-meta-item">✅ Completed: ${formatDate(completion.date_completed)}</div>` : ''}
                            </div>
                            ${completion.notes ? `<div style="margin-top: 8px; color: var(--text-secondary); font-size: 14px;">${escapeHtml(completion.notes)}</div>` : ''}
                            <div class="completion-actions">
                                <button class="btn btn-secondary" onclick="editCompletion(${completion.id})" style="font-size: 14px;">Edit</button>
                                ${!completion.game_id ? `<button class="btn btn-secondary" onclick="linkCompletion(${completion.id})" style="font-size: 14px;">Link to Game</button>` : ''}
                                <button class="btn btn-danger" onclick="deleteCompletion(${completion.id})" style="font-size: 14px;">Delete</button>
                            </div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
    `;
}

/**
 * Get completion image URL - uses proxy for external URLs to avoid CORS
 */
function getCompletionImageUrl(completion) {
    if (completion.front_cover_image) {
        // Data URLs - return as-is
        if (completion.front_cover_image.startsWith('data:')) {
            return completion.front_cover_image;
        }
        // External URLs - use proxy to avoid CORS
        if (completion.front_cover_image.startsWith('http://') || 
            completion.front_cover_image.startsWith('https://')) {
            return `api/image-proxy.php?url=${encodeURIComponent(completion.front_cover_image)}`;
        }
        return `uploads/covers/${completion.front_cover_image}`;
    }
    return null;
}

/**
 * Setup completion modal
 */
function setupCompletionModal() {
    const addBtn = document.getElementById('addCompletionBtn');
    const saveBtn = document.getElementById('saveCompletionBtn');
    const cancelBtn = document.getElementById('cancelCompletionBtn');
    const closeBtn = document.querySelector('#completionModal .modal-close');
    const form = document.getElementById('completionForm');
    const titleInput = document.getElementById('completionTitle');
    
    if (addBtn) {
        addBtn.addEventListener('click', openAddCompletionModal);
    }
    
    if (saveBtn) {
        saveBtn.addEventListener('click', saveCompletion);
    }
    
    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => hideModal('completionModal'));
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', () => hideModal('completionModal'));
    }
    
    // Game search as you type
    if (titleInput) {
        let searchTimeout;
        titleInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                document.getElementById('gameSearchResults').style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                searchGames(query);
            }, 300);
        });
    }
}

/**
 * Search games for linking
 */
function searchGames(query) {
    const resultsDiv = document.getElementById('gameSearchResults');
    if (!resultsDiv) return;
    
    const queryLower = query.toLowerCase();
    const matches = allGames.filter(game => {
        const title = (game.title || '').toLowerCase();
        return title.includes(queryLower);
    }).slice(0, 5);
    
    if (matches.length === 0) {
        resultsDiv.style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = matches.map(game => `
        <div style="padding: 10px; cursor: pointer; border-bottom: 1px solid var(--border-color);" 
             onclick="selectGameForCompletion(${game.id}, '${escapeHtml(game.title)}', '${escapeHtml(game.platform || '')}')"
             onmouseover="this.style.background='var(--bg-secondary)'"
             onmouseout="this.style.background=''">
            <strong>${escapeHtml(game.title)}</strong>
            ${game.platform ? `<div style="font-size: 12px; color: var(--text-secondary);">${escapeHtml(game.platform)}</div>` : ''}
        </div>
    `).join('');
    
    resultsDiv.style.display = 'block';
}

/**
 * Select game for completion
 */
function selectGameForCompletion(gameId, title, platform) {
    document.getElementById('completionTitle').value = title;
    document.getElementById('completionPlatform').value = platform;
    document.getElementById('completionForm').dataset.gameId = gameId;
    document.getElementById('gameSearchResults').style.display = 'none';
}

/**
 * Open add completion modal
 */
function openAddCompletionModal() {
    currentEditingId = null;
    const modal = document.getElementById('completionModal');
    const title = document.getElementById('completionModalTitle');
    const form = document.getElementById('completionForm');
    
    title.textContent = 'Add Completion';
    form.reset();
    form.dataset.gameId = '';
    
    showModal('completionModal');
}

/**
 * Edit completion
 */
async function editCompletion(id) {
    currentEditingId = id;
    
    try {
        const response = await fetch(`api/completions.php?action=get&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const completion = data.completion;
            const modal = document.getElementById('completionModal');
            const title = document.getElementById('completionModalTitle');
            const form = document.getElementById('completionForm');
            
            title.textContent = 'Edit Completion';
            
            document.getElementById('completionId').value = completion.id;
            document.getElementById('completionTitle').value = completion.title;
            document.getElementById('completionPlatform').value = completion.platform || '';
            document.getElementById('completionTimeTaken').value = completion.time_taken || '';
            document.getElementById('completionDateStarted').value = completion.date_started || '';
            document.getElementById('completionDateCompleted').value = completion.date_completed || '';
            document.getElementById('completionNotes').value = completion.notes || '';
            form.dataset.gameId = completion.game_id || '';
            
            showModal('completionModal');
        } else {
            showNotification(data.message || 'Failed to load completion', 'error');
        }
    } catch (error) {
        console.error('Error loading completion:', error);
        showNotification('Error loading completion', 'error');
    }
}

/**
 * Save completion
 */
async function saveCompletion() {
    const form = document.getElementById('completionForm');
    const title = document.getElementById('completionTitle').value.trim();
    
    if (!title) {
        showNotification('Title is required', 'error');
        return;
    }
    
    const gameId = form.dataset.gameId || null;
    const id = document.getElementById('completionId').value;
    
    const data = {
        title: title,
        platform: document.getElementById('completionPlatform').value.trim() || null,
        time_taken: document.getElementById('completionTimeTaken').value.trim() || null,
        date_started: document.getElementById('completionDateStarted').value || null,
        date_completed: document.getElementById('completionDateCompleted').value || null,
        notes: document.getElementById('completionNotes').value.trim() || null
    };
    
    if (gameId) {
        data.game_id = parseInt(gameId);
    }
    
    const action = id ? 'update' : 'create';
    const url = `api/completions.php?action=${action}`;
    
    if (id) {
        data.id = parseInt(id);
    }
    
    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            hideModal('completionModal');
            loadCompletions();
            showNotification(result.message || 'Completion saved successfully', 'success');
        } else {
            showNotification(result.message || 'Failed to save completion', 'error');
        }
    } catch (error) {
        console.error('Error saving completion:', error);
        showNotification('Error saving completion', 'error');
    }
}

/**
 * Link completion to game
 */
async function linkCompletion(completionId) {
    // Simple implementation: show a prompt to search and select
    const query = prompt('Enter game title to search:');
    if (!query) return;
    
    const matches = allGames.filter(game => {
        const title = (game.title || '').toLowerCase();
        return title.includes(query.toLowerCase());
    });
    
    if (matches.length === 0) {
        showNotification('No games found matching that title', 'error');
        return;
    }
    
    if (matches.length === 1) {
        // Auto-link if only one match
        await linkCompletionToGame(completionId, matches[0].id);
        return;
    }
    
    // Show selection dialog
    const gameList = matches.slice(0, 10).map((game, index) => 
        `${index + 1}. ${game.title} (${game.platform || 'Unknown'})`
    ).join('\n');
    
    const selection = prompt(`Multiple games found:\n\n${gameList}\n\nEnter number (1-${Math.min(matches.length, 10)}):`);
    const selectedIndex = parseInt(selection) - 1;
    
    if (selectedIndex >= 0 && selectedIndex < Math.min(matches.length, 10)) {
        await linkCompletionToGame(completionId, matches[selectedIndex].id);
    }
}

/**
 * Link completion to game
 */
async function linkCompletionToGame(completionId, gameId) {
    try {
        const response = await fetch('api/completions.php?action=link', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                completion_id: completionId,
                game_id: gameId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            loadCompletions();
            showNotification('Completion linked successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to link completion', 'error');
        }
    } catch (error) {
        console.error('Error linking completion:', error);
        showNotification('Error linking completion', 'error');
    }
}

/**
 * Delete completion
 */
async function deleteCompletion(id) {
    if (!confirm('Are you sure you want to delete this completion?')) {
        return;
    }
    
    try {
        const response = await fetch(`api/completions.php?action=delete&id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            loadCompletions();
            showNotification('Completion deleted successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to delete completion', 'error');
        }
    } catch (error) {
        console.error('Error deleting completion:', error);
        showNotification('Error deleting completion', 'error');
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
    const content = document.getElementById('completionsContent');
    content.innerHTML = `<div class="error">${escapeHtml(message)}</div>`;
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

