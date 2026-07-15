/**
 * Main JavaScript file
 * Handles authentication, modals, and common functionality
 */

// Check authentication on page load
document.addEventListener('DOMContentLoaded', function() {
    // Check if user is authenticated (for protected pages)
    if (document.body.classList.contains('app-container')) {
        checkAuth();
    }
    
    // Setup modal handlers
    setupModals();
    
    // Setup logout button
    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', handleLogout);
    }
    
    // Setup dark mode (if not already set up by page-specific code)
    if (!document.getElementById('darkModeToggle')?.hasAttribute('data-setup')) {
        setupDarkMode();
    }
});

/**
 * Check if user is authenticated
 */
async function checkAuth() {
    try {
        const data = await apiGet('api/auth.php?action=check');

        if (!data.authenticated) {
            window.location.href = 'index.php';
        }
    } catch (error) {
        console.error('Auth check failed:', error);
        window.location.href = 'index.php';
    }
}

/**
 * Setup dark mode toggle
 */
function setupDarkMode() {
    // Load saved preference
    const isDarkMode = localStorage.getItem('darkMode') === 'true';
    if (isDarkMode) {
        document.body.classList.add('dark-mode');
        updateDarkModeIcon(true);
    }
    
    // Setup toggle button
    const toggleBtn = document.getElementById('darkModeToggle');
    if (toggleBtn && !toggleBtn.hasAttribute('data-setup')) {
        toggleBtn.setAttribute('data-setup', 'true');
        toggleBtn.addEventListener('click', function() {
            const isDark = document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', isDark);
            updateDarkModeIcon(isDark);
        });
    }
}

/**
 * Update dark mode icon
 */
function updateDarkModeIcon(isDark) {
    const toggleBtn = document.getElementById('darkModeToggle');
    if (toggleBtn) {
        toggleBtn.textContent = isDark ? '☀️' : '🌙';
        toggleBtn.title = isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode';
    }
}

/**
 * Handle logout
 */
async function handleLogout() {
    if (confirm('Are you sure you want to logout?')) {
        try {
            const data = await apiGet('api/auth.php?action=logout');

            if (data.success) {
                window.location.href = 'index.php';
            }
        } catch (error) {
            console.error('Logout failed:', error);
        }
    }
}

/**
 * Setup modal functionality
 */
function setupModals() {
    // Close modal buttons
    document.querySelectorAll('.modal-close, .modal-cancel').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.modal');
            if (modal) {
                modal.style.display = 'none';
            }
        });
    });
    
    // Close modal on outside click
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
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
 * Show notification message
 */
function showNotification(message, type = 'success') {
    // Remove existing notifications
    const existing = document.querySelector('.notification');
    if (existing) {
        existing.remove();
    }
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Show notification
    setTimeout(() => {
        notification.classList.add('show');
    }, 10);
    
    // Hide after 3 seconds
    setTimeout(() => {
        notification.classList.remove('show');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 3000);
}

/**
 * Format currency (in pounds)
 */
function formatCurrency(amount) {
    if (amount === null || amount === undefined || amount === '' || amount === 'N/A') {
        return 'N/A';
    }
    return '£' + parseFloat(amount).toFixed(2);
}

/**
 * Format date for display (DD/MM/YYYY)
 */
function formatDate(dateString) {
    if (!dateString) return 'N/A';

    try {
        const date = new Date(dateString);
        if (isNaN(date.getTime())) return 'N/A';

        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();

        return `${day}/${month}/${year}`;
    } catch (e) {
        return 'N/A';
    }
}

/**
 * Escape a string for safe insertion into HTML. Uses a detached DOM node
 * as the escaper so the browser handles every edge case correctly.
 *
 * Consolidated here in Phase 4a — was previously duplicated in games.js,
 * items.js, completions.js, stats.js AND inline in admin-dashboard.php,
 * settings.php, users.php, user-profile.php. main.js is loaded first
 * on every authed page (see PHP <script> tags), so callers get this
 * definition via hoisting.
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Get image URL — handles local paths, data: URIs, and external URLs
 * (proxied via api/image-proxy.php to dodge CORS + SSRF-gate them).
 * Priority: local files > external URLs (via proxy) > data URIs.
 *
 * Consolidated here in Phase 4b — was previously duplicated in games.js
 * (rich version with (imagePath, size) + base64 validation) and stats.js
 * (simpler one-arg version with an SVG grey-square fallback). Kept the
 * richer signature; callers in stats.js pass one argument and get
 * `null` on empty, which their inline onerror handlers already
 * convert to a "No Image" placeholder div. Base64 image support
 * matters because the pre-Phase-1 covers migration left some rows
 * with inline base64 data.
 *
 * `size` is 'thumb' (default 'full') and only affects local files —
 * base64 data URIs have no thumb variant, and the image proxy serves
 * whatever the upstream returns.
 */
function getImageUrl(imagePath, size) {
    if (!imagePath) return null;
    const useThumb = size === 'thumb';
    // Data URIs (base64) — validate and return.
    if (imagePath.startsWith('data:')) {
        const base64Part = imagePath.split(',')[1] || '';
        if (base64Part.length > 0) {
            // Remove any whitespace/newlines that might have been added.
            const cleanBase64 = base64Part.replace(/\s/g, '');
            // Exactly 65535 chars = truncated during migration; treat as missing.
            if (cleanBase64.length === 65535) {
                console.warn('Base64 image data appears truncated (exactly 65535 chars)');
                return null;
            }
            // Base64 shape check (letters + digits + / + optional == padding).
            const validBase64Pattern = /^[A-Za-z0-9+/]+={0,2}$/;
            if (!validBase64Pattern.test(cleanBase64)) {
                console.warn('Incomplete base64 image data detected - invalid characters', {
                    length: cleanBase64.length,
                    lastChars: cleanBase64.slice(-10),
                    firstChars: cleanBase64.slice(0, 10)
                });
                return null;
            }
            return imagePath.split(',')[0] + ',' + cleanBase64;
        }
        return imagePath;
    }
    // External URLs — use the SSRF-gated proxy.
    if (imagePath.startsWith('http://') || imagePath.startsWith('https://')) {
        return `api/image-proxy.php?url=${encodeURIComponent(imagePath)}`;
    }
    // Local file. Grid + list views ask for 'thumb' (uploads/covers/thumbs/,
    // auto-generated by upload.php at 512px on longest edge). CoverFlow and
    // game-detail keep using the full-res original.
    if (useThumb) {
        return `uploads/covers/thumbs/${imagePath}`;
    }
    return `uploads/covers/${imagePath}`;
}

