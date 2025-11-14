/**
 * Spin Wheel functionality for random game selection
 */

let wheelGames = [];
let isSpinning = false;
let wheelCanvas, wheelCtx;
let wheelStage = 'platform'; // 'platform' or 'game'
let selectedPlatform = null;
const MAX_GAMES_ON_WHEEL = 50; // Maximum games to show on a single wheel

/**
 * Initialize spin wheel
 */
function initSpinWheel() {
    const spinWheelBtn = document.getElementById('spinWheelBtn');
    const spinWheelModal = document.getElementById('spinWheelModal');
    const spinWheelBtnAction = document.getElementById('spinWheelBtnAction');
    const modalClose = spinWheelModal?.querySelector('.modal-close');
    const modalCancel = spinWheelModal?.querySelector('.modal-cancel');
    
    if (!spinWheelBtn || !spinWheelModal) return;
    
    // Open modal
    spinWheelBtn.addEventListener('click', () => {
        spinWheelModal.style.display = 'block';
        
        // Reset stage
        wheelStage = 'platform';
        selectedPlatform = null;
        
        // Wait for games to be available
        const checkGames = () => {
            if (!window.allGames || window.allGames.length === 0) {
                // Try to get games from the games.js module if available
                if (typeof allGames !== 'undefined' && allGames.length > 0) {
                    window.allGames = allGames;
                } else {
                    // Wait a bit more
                    setTimeout(checkGames, 200);
                    return;
                }
            }
            
            updateWheelFilters();
            // Initialize canvas
            wheelCanvas = document.getElementById('wheelCanvas');
            if (wheelCanvas) {
                wheelCtx = wheelCanvas.getContext('2d');
                // Set canvas size if not already set
                if (wheelCanvas.width === 0 || wheelCanvas.height === 0) {
                    wheelCanvas.width = 400;
                    wheelCanvas.height = 400;
                }
            }
            filterWheelGames();
            // Draw wheel after a short delay to ensure canvas is ready
            setTimeout(() => {
                if (wheelGames.length > 0) {
                    drawWheel();
                }
                // Reset wheel rotation
                const wheel = document.getElementById('spinWheel');
                if (wheel) {
                    wheel.style.transition = 'none';
                    wheel.style.transform = 'rotate(0deg)';
                }
                // Update button text
                const spinBtn = document.getElementById('spinWheelBtnAction');
                if (spinBtn) {
                    spinBtn.textContent = wheelStage === 'platform' ? 'Spin for Platform!' : 'Spin for Game!';
                }
            }, 100);
        };
        
        checkGames();
    });
    
    // Close modal
    if (modalClose) {
        modalClose.addEventListener('click', () => {
            spinWheelModal.style.display = 'none';
        });
    }
    
    if (modalCancel) {
        modalCancel.addEventListener('click', () => {
            spinWheelModal.style.display = 'none';
        });
    }
    
    // Spin button
    if (spinWheelBtnAction) {
        spinWheelBtnAction.addEventListener('click', () => {
            if (!isSpinning) {
                spinWheel();
            }
        });
    }
    
    // Filter changes
    const wheelPlatformFilter = document.getElementById('wheelPlatformFilter');
    const wheelGenreFilter = document.getElementById('wheelGenreFilter');
    const wheelPlayedFilter = document.getElementById('wheelPlayedFilter');
    const wheelTypeFilter = document.getElementById('wheelTypeFilter');
    
    [wheelPlatformFilter, wheelGenreFilter, wheelPlayedFilter, wheelTypeFilter].forEach(filter => {
        if (filter) {
            filter.addEventListener('change', () => {
                // Reset stage when filters change
                wheelStage = 'platform';
                selectedPlatform = null;
                updateWheelDisplay();
            });
        }
    });
    
    // Initialize canvas when modal opens
    const initCanvas = () => {
        wheelCanvas = document.getElementById('wheelCanvas');
        if (wheelCanvas) {
            wheelCtx = wheelCanvas.getContext('2d');
            // Set canvas size if not already set
            if (wheelCanvas.width === 0 || wheelCanvas.height === 0) {
                wheelCanvas.width = 400;
                wheelCanvas.height = 400;
            }
        }
    };
    
    // Initialize canvas immediately if modal is already in DOM
    initCanvas();
    
    // Also initialize when modal opens
    if (spinWheelBtn) {
        const originalClickHandler = spinWheelBtn.onclick;
        spinWheelBtn.addEventListener('click', () => {
            initCanvas();
        });
    }
}

/**
 * Update filter dropdowns with available options
 */
function updateWheelFilters() {
    if (!window.allGames || window.allGames.length === 0) {
        // Wait for games to load
        setTimeout(updateWheelFilters, 500);
        return;
    }
    
    const platforms = [...new Set(window.allGames.map(g => g.platform).filter(Boolean))].sort();
    const genres = [...new Set(window.allGames.map(g => g.genre).filter(Boolean))].sort();
    
    const platformSelect = document.getElementById('wheelPlatformFilter');
    const genreSelect = document.getElementById('wheelGenreFilter');
    
    if (platformSelect) {
        const currentValue = platformSelect.value;
        platformSelect.innerHTML = '<option value="">All Platforms</option>' +
            platforms.map(p => `<option value="${escapeHtml(p)}">${escapeHtml(p)}</option>`).join('');
        if (currentValue) {
            platformSelect.value = currentValue;
        }
    }
    
    if (genreSelect) {
        const currentValue = genreSelect.value;
        genreSelect.innerHTML = '<option value="">All Genres</option>' +
            genres.map(g => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
        if (currentValue) {
            genreSelect.value = currentValue;
        }
    }
}

/**
 * Filter games based on wheel filters
 */
function filterWheelGames() {
    // Try multiple ways to get games
    let games = window.allGames;
    if (!games || games.length === 0) {
        // Try to access from games.js scope if available
        if (typeof allGames !== 'undefined' && allGames.length > 0) {
            games = allGames;
            window.allGames = allGames;
        }
    }
    
    if (!games || games.length === 0) {
        console.log('No games available for spin wheel');
        wheelGames = [];
        return;
    }
    
    console.log('Filtering games, total available:', games.length);
    
    const platformFilter = document.getElementById('wheelPlatformFilter')?.value || '';
    const genreFilter = document.getElementById('wheelGenreFilter')?.value || '';
    const playedFilter = document.getElementById('wheelPlayedFilter')?.value || '';
    const typeFilter = document.getElementById('wheelTypeFilter')?.value || '';
    
    console.log('Filters:', { platformFilter, genreFilter, playedFilter, typeFilter });
    
    let filteredGames = games.filter(game => {
        // Platform filter - exact match (but skip if we're in platform selection stage)
        if (platformFilter && game.platform !== platformFilter) {
            return false;
        }
        
        // Apply selected platform if in game stage
        if (wheelStage === 'game' && selectedPlatform && game.platform !== selectedPlatform) {
            return false;
        }
        
        // Genre filter - exact match
        if (genreFilter && game.genre !== genreFilter) {
            return false;
        }
        
        // Played filter - handle both boolean and string/number
        if (playedFilter !== '') {
            const gamePlayed = game.played === true || game.played === 1 || game.played === '1';
            const filterPlayed = playedFilter === '1';
            if (gamePlayed !== filterPlayed) {
                return false;
            }
        }
        
        // Type filter - handle both boolean and string
        if (typeFilter === 'physical') {
            if (!game.is_physical || game.is_physical === 0 || game.is_physical === '0' || game.is_physical === false) {
                return false;
            }
        }
        if (typeFilter === 'digital') {
            if (game.is_physical === true || game.is_physical === 1 || game.is_physical === '1') {
                return false;
            }
        }
        
        return true;
    });
    
    // Determine if we should use two-stage spinning
    // Skip platform stage if platform filter is already set
    if (platformFilter) {
        // Platform already filtered, go straight to games
        wheelStage = 'game';
        wheelGames = filteredGames;
        console.log('Platform filter set, using game stage, games:', wheelGames.length);
    } else if (wheelStage === 'platform' && filteredGames.length > MAX_GAMES_ON_WHEEL) {
        // Get unique platforms from filtered games
        const platforms = [...new Set(filteredGames.map(g => g.platform).filter(Boolean))];
        wheelGames = platforms.map(platform => ({
            id: platform,
            title: platform,
            platform: platform,
            isPlatform: true
        }));
        console.log('Using platform stage, platforms:', wheelGames.length);
    } else if (wheelStage === 'game') {
        wheelGames = filteredGames;
        console.log('Using game stage, games:', wheelGames.length);
    } else {
        wheelGames = filteredGames;
        console.log('Single stage, games:', wheelGames.length);
    }
    
    // Hide result if no games match
    const wheelResult = document.getElementById('wheelResult');
    if (wheelResult) {
        wheelResult.style.display = 'none';
    }
}

/**
 * Spin the wheel
 */
function spinWheel() {
    if (isSpinning || wheelGames.length === 0) {
        if (wheelGames.length === 0) {
            alert('No games match your filters!');
        }
        return;
    }
    
    isSpinning = true;
    const spinBtn = document.getElementById('spinWheelBtnAction');
    if (spinBtn) {
        spinBtn.disabled = true;
        spinBtn.textContent = wheelStage === 'platform' ? 'Spinning for Platform...' : 'Spinning for Game...';
    }
    
    // Hide previous result
    const wheelResult = document.getElementById('wheelResult');
    if (wheelResult) {
        wheelResult.style.display = 'none';
    }
    
    // Random rotation (3-5 full spins + random angle)
    const spins = 3 + Math.random() * 2; // 3-5 spins
    const randomAngle = Math.random() * 360;
    const totalRotation = spins * 360 + randomAngle;
    
    const wheel = document.getElementById('spinWheel');
    if (wheel) {
        wheel.style.transition = 'transform 4s cubic-bezier(0.17, 0.67, 0.12, 0.99)';
        wheel.style.transform = `rotate(${totalRotation}deg)`;
    }
    
    // Calculate which item was selected
    setTimeout(() => {
        // Normalize angle to 0-360
        const normalizedAngle = ((totalRotation % 360) + 360) % 360;
        
        // The pointer is at the top (0° in standard, -90° in canvas coords)
        // Segments start at -90° (top) and go clockwise
        // When wheel rotates clockwise by X°, we need to find what's at the pointer
        // 
        // Simple approach: reverse the rotation to see what was originally at the pointer
        // The pointer is at -90° in canvas coordinates (top)
        // After rotating by X° clockwise, what's at the pointer was originally at -90° - X°
        const segmentSize = 360 / wheelGames.length;
        
        // Calculate the original angle (before rotation) that's now at the pointer
        // Pointer is at -90° in canvas coords, so we reverse the rotation
        const originalAngle = (-90 - normalizedAngle + 360) % 360;
        
        // Convert to 0-360 range (add 90 to convert from canvas to standard)
        const angleForIndex = (originalAngle + 90) % 360;
        
        // Calculate which segment (0 to wheelGames.length - 1)
        // Segments are: 0: -90° to -90°+segmentSize, 1: -90°+segmentSize to -90°+2*segmentSize, etc.
        // In 0-360 range: 0: 0° to segmentSize, 1: segmentSize to 2*segmentSize, etc.
        let itemIndex = Math.floor(angleForIndex / segmentSize);
        
        // Ensure index is within bounds
        itemIndex = itemIndex % wheelGames.length;
        if (itemIndex < 0) itemIndex += wheelGames.length;
        
        const selectedItem = wheelGames[itemIndex];
        
        if (wheelStage === 'platform' && selectedItem.isPlatform) {
            // Platform selected, now spin for games
            selectedPlatform = selectedItem.platform;
            wheelStage = 'game';
            filterWheelGames();
            drawWheel();
            
            // Reset wheel rotation
            if (wheel) {
                wheel.style.transition = 'none';
                wheel.style.transform = 'rotate(0deg)';
            }
            
            // Show platform selection
            displayPlatformSelection(selectedItem.platform);
            
            // Auto-spin for game after a short delay
            setTimeout(() => {
                isSpinning = false;
                if (spinBtn) {
                    spinBtn.disabled = false;
                    spinBtn.textContent = 'Spin for Game!';
                }
                // Auto-spin for game
                setTimeout(() => {
                    spinWheel();
                }, 1000);
            }, 100);
        } else {
            // Game selected
            displayWheelResult(selectedItem);
            
            isSpinning = false;
            if (spinBtn) {
                spinBtn.disabled = false;
                spinBtn.textContent = 'Spin Again!';
            }
            
            // Reset for next time
            wheelStage = 'platform';
            selectedPlatform = null;
        }
    }, 4000);
}

/**
 * Display platform selection
 */
function displayPlatformSelection(platform) {
    const wheelResult = document.getElementById('wheelResult');
    const wheelResultContent = document.getElementById('wheelResultContent');
    
    if (!wheelResult || !wheelResultContent) return;
    
    wheelResultContent.innerHTML = `
        <div class="wheel-result-platform-selection">
            <h4>🎯 Selected Platform:</h4>
            <p class="wheel-result-platform-name">${escapeHtml(platform)}</p>
            <p class="wheel-result-message">Now spinning for a game from this platform...</p>
        </div>
    `;
    
    wheelResult.style.display = 'block';
    wheelResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/**
 * Display the selected game result
 */
function displayWheelResult(game) {
    const wheelResult = document.getElementById('wheelResult');
    const wheelResultContent = document.getElementById('wheelResultContent');
    
    if (!wheelResult || !wheelResultContent) return;
    
    const coverUrl = game.front_cover_image 
        ? getImageUrl(game.front_cover_image)
        : '';
    
    wheelResultContent.innerHTML = `
        <div class="wheel-result-game">
            ${coverUrl ? `<img src="${coverUrl}" alt="${escapeHtml(game.title)}" class="wheel-result-cover">` : ''}
            <div class="wheel-result-info">
                <h4>${escapeHtml(game.title)}</h4>
                <p class="wheel-result-platform">${escapeHtml(game.platform)}</p>
                ${game.genre ? `<p class="wheel-result-genre">${escapeHtml(game.genre)}</p>` : ''}
                <div class="wheel-result-actions">
                    <button class="btn btn-primary" onclick="window.location.href='game-detail.php?id=${game.id}'">View Details</button>
                </div>
            </div>
        </div>
    `;
    
    wheelResult.style.display = 'block';
    wheelResult.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

/**
 * Draw the wheel
 */
function drawWheel() {
    // Re-initialize canvas if needed
    if (!wheelCanvas) {
        wheelCanvas = document.getElementById('wheelCanvas');
    }
    if (!wheelCtx && wheelCanvas) {
        wheelCtx = wheelCanvas.getContext('2d');
    }
    
    if (!wheelCanvas || !wheelCtx) {
        console.error('Canvas not initialized');
        return;
    }
    
    if (wheelGames.length === 0) {
        console.log('No games to draw on wheel');
        // Draw empty state
        wheelCtx.clearRect(0, 0, wheelCanvas.width, wheelCanvas.height);
        wheelCtx.fillStyle = '#f0f0f0';
        wheelCtx.beginPath();
        wheelCtx.arc(wheelCanvas.width / 2, wheelCanvas.height / 2, wheelCanvas.width / 2 - 20, 0, 2 * Math.PI);
        wheelCtx.fill();
        return;
    }
    
    const centerX = wheelCanvas.width / 2;
    const centerY = wheelCanvas.height / 2;
    const radius = Math.min(centerX, centerY) - 20;
    
    // Clear canvas
    wheelCtx.clearRect(0, 0, wheelCanvas.width, wheelCanvas.height);
    
    // Draw wheel segments
    const anglePerGame = (2 * Math.PI) / wheelGames.length;
    const colors = [
        '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
        '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B739', '#52BE80'
    ];
    
    wheelGames.forEach((game, index) => {
        const startAngle = index * anglePerGame - Math.PI / 2;
        const endAngle = (index + 1) * anglePerGame - Math.PI / 2;
        
        // Draw segment
        wheelCtx.beginPath();
        wheelCtx.moveTo(centerX, centerY);
        wheelCtx.arc(centerX, centerY, radius, startAngle, endAngle);
        wheelCtx.closePath();
        wheelCtx.fillStyle = colors[index % colors.length];
        wheelCtx.fill();
        wheelCtx.strokeStyle = '#fff';
        wheelCtx.lineWidth = 2;
        wheelCtx.stroke();
        
        // Draw text
        wheelCtx.save();
        wheelCtx.translate(centerX, centerY);
        wheelCtx.rotate(startAngle + anglePerGame / 2);
        wheelCtx.textAlign = 'left';
        wheelCtx.textBaseline = 'middle';
        wheelCtx.fillStyle = '#fff';
        wheelCtx.font = 'bold 12px Arial';
        
        // Truncate title if too long
        let title = game.title || game.platform || 'Unknown';
        if (title.length > 15) {
            title = title.substring(0, 12) + '...';
        }
        
        wheelCtx.fillText(title, radius * 0.4, 0);
        wheelCtx.restore();
    });
    
    console.log('Wheel drawn with', wheelGames.length, wheelStage === 'platform' ? 'platforms' : 'games');
}

/**
 * Update wheel when games are filtered
 */
function updateWheelDisplay() {
    filterWheelGames();
    
    // Ensure canvas is initialized
    if (!wheelCanvas) {
        wheelCanvas = document.getElementById('wheelCanvas');
    }
    if (!wheelCtx && wheelCanvas) {
        wheelCtx = wheelCanvas.getContext('2d');
        if (wheelCanvas.width === 0 || wheelCanvas.height === 0) {
            wheelCanvas.width = 400;
            wheelCanvas.height = 400;
        }
    }
    
    if (wheelGames.length > 0) {
        drawWheel();
        // Reset wheel rotation
        const wheel = document.getElementById('spinWheel');
        if (wheel) {
            wheel.style.transition = 'none';
            wheel.style.transform = 'rotate(0deg)';
        }
        // Hide result
        const wheelResult = document.getElementById('wheelResult');
        if (wheelResult) {
            wheelResult.style.display = 'none';
        }
        // Reset spin button
        const spinBtn = document.getElementById('spinWheelBtnAction');
        if (spinBtn) {
            spinBtn.disabled = false;
            spinBtn.textContent = wheelStage === 'platform' ? 'Spin for Platform!' : 'Spin for Game!';
        }
    } else {
        // Draw empty wheel
        if (wheelCanvas && wheelCtx) {
            drawWheel(); // This will draw the empty state
        }
        // Show message if no games
        const wheelResult = document.getElementById('wheelResult');
        const wheelResultContent = document.getElementById('wheelResultContent');
        if (wheelResult && wheelResultContent) {
            wheelResultContent.innerHTML = '<p>No games match your filters. Try adjusting your selection!</p>';
            wheelResult.style.display = 'block';
        }
        // Disable spin button
        const spinBtn = document.getElementById('spinWheelBtnAction');
        if (spinBtn) {
            spinBtn.disabled = true;
            spinBtn.textContent = 'No Games to Spin';
        }
    }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSpinWheel);
} else {
    initSpinWheel();
}

// Update wheel when filters change
document.addEventListener('change', (e) => {
    if (e.target.id === 'wheelPlatformFilter' || 
        e.target.id === 'wheelGenreFilter' || 
        e.target.id === 'wheelPlayedFilter' || 
        e.target.id === 'wheelTypeFilter') {
        updateWheelDisplay();
    }
});

