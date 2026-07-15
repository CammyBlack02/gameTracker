// Small shared utilities extracted from games.js in phase 4f/09.
//
//   getCaseType(platform)        — pick a CD/DVD case aspect ratio.
//                                   Used by render/{grid,list,coverflow}.js
//                                   and games.js's displayGameDetail.
//   animateCounter(...)          — animate a numeric counter up to endValue.
//                                   Used by setupHeroStats.
//   setupScrollToTop()           — inject a floating "↑ scroll to top"
//                                   button. Page-agnostic.
//
// All exposed as globals via top-level function declarations (classic
// script). Loaded on every page that includes games.js.

/**
 * Determine case type (CD or DVD) based on platform
 * CD cases: Only Super Nintendo, PlayStation (original), Game Boys, DS, 3DS, and N64
 * DVD cases: All other platforms (PlayStation 2+, Xbox series, Wii, etc.)
 */
function getCaseType(platform) {
    if (!platform) return 'dvd-case'; // Default to DVD
    
    const platformLower = platform.toLowerCase();
    
    // CD-sized cases (taller/narrower) - only these specific platforms
    const cdPlatforms = [
        'snes',                    // Super Nintendo
        'super nintendo',          // Super Nintendo (full name)
        'playstation',             // Original PlayStation (but not PS2, PS3, etc.)
        'game boy',                // All Game Boy variants
        'nintendo ds',             // Nintendo DS
        'nintendo 3ds',            // Nintendo 3DS
        'nintendo 64',             // Nintendo 64
        'n64'                      // Nintendo 64 (abbreviation)
    ];
    
    // Check if platform is CD-sized
    for (const cdPlatform of cdPlatforms) {
        // Special check for PlayStation - only original, not PS2, PS3, etc.
        if (cdPlatform === 'playstation') {
            // Only match if it's exactly "playstation" (no number after it)
            if (platformLower === 'playstation' || 
                (platformLower.startsWith('playstation') && 
                 !platformLower.match(/playstation\s*[2-5]/))) {
                return 'cd-case';
            }
        } else if (platformLower.includes(cdPlatform)) {
            // For other platforms, check if the platform name contains the CD platform name
            return 'cd-case';
        }
    }
    
    // Default to DVD case for all others
    return 'dvd-case';
}

/**
 * Animate counter from start to end
 */
function animateCounter(containerId, index, endValue, type = 'number') {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    const valueElement = container.querySelectorAll('.hero-stat-value')[index];
    if (!valueElement) return;
    
    const duration = 2000; // 2 seconds
    const startTime = performance.now();
    const startValue = 0;
    
    function updateCounter(currentTime) {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        
        // Easing function (ease-out)
        const easeOut = 1 - Math.pow(1 - progress, 3);
        const currentValue = startValue + (endValue - startValue) * easeOut;
        
        if (type === 'currency') {
            valueElement.textContent = '£' + Math.round(currentValue).toLocaleString();
        } else {
            valueElement.textContent = Math.round(currentValue).toLocaleString();
        }
        
        if (progress < 1) {
            requestAnimationFrame(updateCounter);
        } else {
            // Ensure final value is exact
            if (type === 'currency') {
                valueElement.textContent = '£' + endValue.toLocaleString();
            } else {
                valueElement.textContent = endValue.toLocaleString();
            }
        }
    }
    
    requestAnimationFrame(updateCounter);
}

/**
 * Setup scroll to top button
 */
function setupScrollToTop() {
    // Create button if it doesn't exist
    let scrollBtn = document.getElementById('scrollToTop');
    if (!scrollBtn) {
        scrollBtn = document.createElement('button');
        scrollBtn.id = 'scrollToTop';
        scrollBtn.className = 'scroll-to-top';
        scrollBtn.innerHTML = '↑';
        scrollBtn.setAttribute('aria-label', 'Scroll to top');
        document.body.appendChild(scrollBtn);
    }
    
    // Show/hide button based on scroll position
    window.addEventListener('scroll', () => {
        if (window.pageYOffset > 300) {
            scrollBtn.classList.add('show');
        } else {
            scrollBtn.classList.remove('show');
        }
    });
    
    // Scroll to top on click
    scrollBtn.addEventListener('click', () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    });
}
