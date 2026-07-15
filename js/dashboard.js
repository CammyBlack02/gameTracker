// Tab switching
/**
 * Switch to a specific tab
 */
function switchToTab(tabName) {
    // Update buttons
    document.querySelectorAll('.tab-button').forEach(btn => btn.classList.remove('active'));
    const activeButton = document.querySelector(`.tab-button[data-tab="${tabName}"]`);
    if (activeButton) {
        activeButton.classList.add('active');
    }

    // Update content
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
        content.style.display = 'none';
    });

    const targetTab = document.getElementById(tabName + 'Tab');
    if (targetTab) {
        targetTab.classList.add('active');
        targetTab.style.display = 'block';
    }

    // Show/hide toolbars
    const gamesToolbar = document.getElementById('gamesToolbar');
    const itemsToolbar = document.getElementById('itemsToolbar');

    if (gamesToolbar) {
        gamesToolbar.style.display = tabName === 'games' ? 'block' : 'none';
    }
    if (itemsToolbar) {
        itemsToolbar.style.display = (tabName === 'consoles' || tabName === 'accessories') ? 'block' : 'none';
    }

    // Show/hide add buttons
    const addGameBtn = document.getElementById('addGameBtn');
    const addItemBtn = document.getElementById('addItemBtn');
    const importSteamBtn = document.getElementById('importSteamBtn');

    if (addGameBtn) {
        addGameBtn.style.display = tabName === 'games' ? 'inline-block' : 'none';
    }
    if (addItemBtn) {
        addItemBtn.style.display = (tabName === 'consoles' || tabName === 'accessories') ? 'inline-block' : 'none';
    }

    // Save to localStorage
    localStorage.setItem('activeTab', tabName);

    // Load appropriate data (use setTimeout to ensure DOM is updated)
    setTimeout(() => {
        if (tabName === 'games') {
            if (typeof loadGames === 'function') {
                loadGames();
            }
        } else if (tabName === 'consoles') {
            // Use window.loadItems if available, or try direct call
                const loadFn = window.loadItems || (typeof loadItems !== 'undefined' ? loadItems : null);
                if (loadFn) {
                    loadFn('Systems');
                } else {
                    console.error('loadItems function not found! Available functions:', Object.keys(window).filter(k => k.includes('load')));
                }
            } else if (tabName === 'accessories') {
                const loadFn = window.loadItems || (typeof loadItems !== 'undefined' ? loadItems : null);
                if (loadFn) {
                    loadFn('Controllers,Game Accessories,Toys To Life');
                } else {
                    console.error('loadItems function not found!');
                }
            }
        }, 10);
}

// Set up tab button click handlers
document.querySelectorAll('.tab-button').forEach(button => {
    button.addEventListener('click', function() {
        const tabName = this.dataset.tab;
        switchToTab(tabName);
    });
});

// Restore active tab from localStorage on page load
const savedTab = localStorage.getItem('activeTab');
if (savedTab && (savedTab === 'games' || savedTab === 'consoles' || savedTab === 'accessories')) {
    switchToTab(savedTab);
} else {
    // Default to games tab if no saved tab
    switchToTab('games');
}

// Load background image on page load
async function loadBackgroundImage() {
    try {
        const data = await apiGet('api/settings.php?action=get');

        if (data.success && data.settings.background_image) {
            document.body.style.backgroundImage = `url(uploads/${data.settings.background_image})`;
            document.body.classList.add('custom-background');
        }
    } catch (error) {
        console.error('Error loading background:', error);
    }
}

loadBackgroundImage();

// Setup dark mode toggle
setupDarkMode();
