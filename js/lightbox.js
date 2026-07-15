// Image lightbox — extracted from games.js in phase 4f/10.
// Renders a full-screen modal for cycling through a game/item's extra
// images. Used by:
//   - games.js::displayGameDetail (called via setupImageLightbox after
//     the extra-images grid is rendered)
//   - item-detail.js (called via setupImageLightbox() at line 154)
//
// Reads no globals beyond the DOM. Self-contained modal — inserts its own
// #imageLightbox element into the body on first setup.

/**
 * Setup lightbox for viewing extra images in full size
 */
function setupImageLightbox() {
    // Remove existing lightbox if any
    const existingLightbox = document.getElementById('imageLightbox');
    if (existingLightbox) {
        existingLightbox.remove();
    }
    
    // Create lightbox modal
    const lightbox = document.createElement('div');
    lightbox.id = 'imageLightbox';
    lightbox.className = 'image-lightbox';
    lightbox.innerHTML = `
        <div class="lightbox-content">
            <button class="lightbox-close">&times;</button>
            <button class="lightbox-prev">‹</button>
            <button class="lightbox-next">›</button>
            <img id="lightboxImage" src="" alt="Full size image">
            <div class="lightbox-counter"></div>
        </div>
    `;
    document.body.appendChild(lightbox);
    
    // Add click handlers to extra images
    document.querySelectorAll('.extra-image').forEach(img => {
        img.style.cursor = 'pointer';
        img.addEventListener('click', function() {
            const imagePath = this.dataset.imagePath;
            const imageIndex = parseInt(this.dataset.imageIndex);
            openLightbox(imagePath, imageIndex);
        });
    });
    
    // Close lightbox handlers
    lightbox.querySelector('.lightbox-close').addEventListener('click', closeLightbox);
    lightbox.addEventListener('click', function(e) {
        if (e.target === lightbox) {
            closeLightbox();
        }
    });
    
    // Keyboard navigation (only when lightbox is open)
    // Use a single global handler to avoid duplicates
    if (!window.lightboxKeyHandler) {
        window.lightboxKeyHandler = function(e) {
            const lightbox = document.getElementById('imageLightbox');
            if (lightbox && lightbox.classList.contains('show')) {
                if (e.key === 'Escape') {
                    closeLightbox();
                } else if (e.key === 'ArrowLeft') {
                    navigateLightbox(-1);
                } else if (e.key === 'ArrowRight') {
                    navigateLightbox(1);
                }
            }
        };
        document.addEventListener('keydown', window.lightboxKeyHandler);
    }
    
    // Navigation buttons
    lightbox.querySelector('.lightbox-prev').addEventListener('click', () => navigateLightbox(-1));
    lightbox.querySelector('.lightbox-next').addEventListener('click', () => navigateLightbox(1));
}
/**
 * Open lightbox with image
 */
function openLightbox(imagePath, imageIndex) {
    const lightbox = document.getElementById('imageLightbox');
    const lightboxImage = document.getElementById('lightboxImage');
    const counter = lightbox.querySelector('.lightbox-counter');
    
    lightboxImage.src = `uploads/extras/${imagePath}`;
    lightbox.style.display = 'flex';
    lightbox.classList.add('show');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
    
    // Store current index
    lightbox.dataset.currentIndex = imageIndex;
    
    // Update counter
    const allImages = Array.from(document.querySelectorAll('.extra-image'));
    if (allImages.length > 1) {
        counter.textContent = `${imageIndex + 1} / ${allImages.length}`;
        counter.style.display = 'block';
    } else {
        counter.style.display = 'none';
    }
    
    // Show/hide navigation buttons
    const prevBtn = lightbox.querySelector('.lightbox-prev');
    const nextBtn = lightbox.querySelector('.lightbox-next');
    prevBtn.style.display = allImages.length > 1 ? 'flex' : 'none';
    nextBtn.style.display = allImages.length > 1 ? 'flex' : 'none';
}
/**
 * Close lightbox
 */
function closeLightbox() {
    const lightbox = document.getElementById('imageLightbox');
    if (lightbox) {
        lightbox.style.display = 'none';
        lightbox.classList.remove('show');
        document.body.style.overflow = ''; // Restore scrolling
    }
}
/**
 * Navigate lightbox (prev/next)
 */
function navigateLightbox(direction) {
    const lightbox = document.getElementById('imageLightbox');
    if (!lightbox || !lightbox.classList.contains('show')) return;
    
    const allImages = Array.from(document.querySelectorAll('.extra-image'));
    if (allImages.length === 0) return;
    
    let currentIndex = parseInt(lightbox.dataset.currentIndex || 0);
    currentIndex += direction;
    
    // Wrap around
    if (currentIndex < 0) {
        currentIndex = allImages.length - 1;
    } else if (currentIndex >= allImages.length) {
        currentIndex = 0;
    }
    
    const nextImage = allImages[currentIndex];
    if (nextImage) {
        openLightbox(nextImage.dataset.imagePath, currentIndex);
    }
}
