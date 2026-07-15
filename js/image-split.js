// Shared cover-image splitter for the add-item and edit-game flows.
// Loads the image (via /api/image-proxy.php if external), splits it into
// front + back covers using an aspect-ratio-based percentage, and hands
// two data URLs back through onSuccess. Called by:
//   - performAutoSplit()         in js/games.js       (edit game flow)
//   - performAutoSplitForItem()  in js/add-item.js    (add item flow)
//
// Extraction of previously-duplicated logic — see Fable §3 (phase 4f).

/**
 * @param {string} imageUrl  Source image URL. If it contains "_thumb",
 *                           the thumb suffix is stripped to fetch full-size.
 * @param {object} handlers
 * @param {(result: {frontDataUrl: string, backDataUrl: string}) => void} handlers.onSuccess
 * @param {(message: string) => void} handlers.onError
 */
function splitCoverImage(imageUrl, handlers) {
    // Prefer full-size over thumbnail
    let fullSizeUrl = imageUrl;
    if (imageUrl.includes('_thumb')) {
        fullSizeUrl = imageUrl.replace('_thumb', '');
    }

    // Reddit combined covers split 50/50; Covers Project split ~53%
    const isRedditImage = fullSizeUrl.includes('i.redd.it') || fullSizeUrl.includes('preview.redd.it');
    const splitPercentage = isRedditImage ? 0.50 : 0.53;

    // External URLs need to be proxied so canvas isn't tainted by CORS
    const isExternalUrl = fullSizeUrl.startsWith('http://') || fullSizeUrl.startsWith('https://');
    const proxyUrl = isExternalUrl
        ? `api/image-proxy.php?url=${encodeURIComponent(fullSizeUrl)}`
        : fullSizeUrl;

    const img = new Image();
    img.crossOrigin = 'anonymous';

    img.onload = function () {
        try {
            const imgWidth = img.width;
            const imgHeight = img.height;
            const splitX = Math.floor(imgWidth * splitPercentage);

            // Front cover — right side of the source image
            const frontCanvas = document.createElement('canvas');
            frontCanvas.width = imgWidth - splitX;
            frontCanvas.height = imgHeight;
            frontCanvas.getContext('2d').drawImage(
                img,
                splitX, 0, imgWidth - splitX, imgHeight,
                0, 0, imgWidth - splitX, imgHeight
            );

            // Back cover — left side of the source image
            const backCanvas = document.createElement('canvas');
            backCanvas.width = splitX;
            backCanvas.height = imgHeight;
            backCanvas.getContext('2d').drawImage(
                img,
                0, 0, splitX, imgHeight,
                0, 0, splitX, imgHeight
            );

            handlers.onSuccess({
                frontDataUrl: frontCanvas.toDataURL('image/jpeg', 0.95),
                backDataUrl: backCanvas.toDataURL('image/jpeg', 0.95),
            });
        } catch (error) {
            console.error('splitCoverImage: canvas failure', error);
            handlers.onError('Error performing auto split');
        }
    };

    img.onerror = function () {
        // Full-size URL failed — retry once with the original (possibly
        // -thumb) URL in case the strip guessed wrong.
        if (fullSizeUrl !== imageUrl) {
            const fallbackProxyUrl = isExternalUrl
                ? `api/image-proxy.php?url=${encodeURIComponent(imageUrl)}`
                : imageUrl;
            img.src = fallbackProxyUrl;
        } else {
            handlers.onError('Failed to load image for auto split');
        }
    };

    img.src = proxyUrl;
}


// -----------------------------------------------------------------
// Split modal + upload plumbing — extracted from games.js in
// phase 4f/08. Called once from js/forms/game-form.js's
// setupAddGameForm() during DOMContentLoaded. Sets up the manual
// split modal (slider, direction radios, save button) that opens
// when the user clicks the Split button on a combined cover.
// The splitCoverImage() helper above handles the pure canvas math;
// this section wires it into the DOM modal.
// -----------------------------------------------------------------

function setupImageSplitTool() {
    let currentSplitImage = null;
    let currentSplitContext = null; // 'add' or 'edit'
    let currentSplitImageUrl = null; // Store the image URL for source detection
    
    // Setup split buttons
    const addSplitBtn = document.getElementById('addFrontCoverSplitBtn');
    const editSplitBtn = document.getElementById('editFrontCoverSplitBtn');
    const addAutoSplitBtn = document.getElementById('addFrontCoverAutoSplitBtn');
    const editAutoSplitBtn = document.getElementById('editFrontCoverAutoSplitBtn');
    
    if (addSplitBtn) {
        addSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                openSplitModal(imageUrl, 'add');
            }
        });
    }
    
    if (editSplitBtn) {
        editSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl || window.currentGame?.front_cover_image;
            if (imageUrl) {
                openSplitModal(imageUrl, 'edit');
            }
        });
    }
    
    // Setup auto split buttons
    if (addAutoSplitBtn) {
        addAutoSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                performAutoSplit(imageUrl, 'add');
            }
        });
    }
    
    if (editAutoSplitBtn) {
        editAutoSplitBtn.addEventListener('click', function() {
            const imageUrl = this.dataset.imageUrl || window.currentGame?.front_cover_image;
            if (imageUrl) {
                performAutoSplit(imageUrl, 'edit');
            }
        });
    }
    
    async function performAutoSplit(imageUrl, context) {
        splitCoverImage(imageUrl, {
            onSuccess: async ({ frontDataUrl, backDataUrl }) => {
                await uploadSplitImages(frontDataUrl, backDataUrl, context);
                showNotification('Cover images auto-split successfully!', 'success');
            },
            onError: (message) => {
                showNotification(message, 'error');
            },
        });
    }
    
    function openSplitModal(imageUrl, context) {
        currentSplitContext = context;
        currentSplitImageUrl = imageUrl; // Store for auto-split detection
        const modal = document.getElementById('imageSplitModal');
        const previewImg = document.getElementById('splitImagePreview');
        const splitLine = document.getElementById('splitLine');
        const slider = document.getElementById('splitPositionSlider');
        const positionValue = document.getElementById('splitPositionValue');
        const directionRadios = document.querySelectorAll('input[name="splitDirection"]');
        
        if (!modal || !previewImg) return;
        
        // Remove _thumb from URL to get full-size image (for The Covers Project)
        let fullSizeUrl = imageUrl;
        if (imageUrl.includes('_thumb')) {
            fullSizeUrl = imageUrl.replace('_thumb', '');
        }
        
        // Check if URL is external (needs proxy)
        const isExternalUrl = fullSizeUrl.startsWith('http://') || fullSizeUrl.startsWith('https://');
        const proxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(fullSizeUrl)}` : fullSizeUrl;
        
        // Detect image source and set appropriate defaults
        const isRedditImage = fullSizeUrl.includes('i.redd.it') || fullSizeUrl.includes('preview.redd.it');
        const isCoversProject = fullSizeUrl.includes('thecoverproject.net');
        
        // Load the image
        const img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            previewImg.src = proxyUrl;
            currentSplitImage = img;
            
            // Auto-configure based on image source
            if (isRedditImage) {
                // Reddit images are typically side-by-side (vertical split) at 50%
                const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
                if (verticalRadio) {
                    verticalRadio.checked = true;
                    verticalRadio.dispatchEvent(new Event('change'));
                }
                slider.value = 50;
                positionValue.textContent = '50%';
            } else if (isCoversProject) {
                // The Covers Project uses 53% vertical split
                const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
                if (verticalRadio) {
                    verticalRadio.checked = true;
                    verticalRadio.dispatchEvent(new Event('change'));
                }
                slider.value = 53;
                positionValue.textContent = '53%';
            }
            
            updateSplitPreview();
            showModal('imageSplitModal');
        };
        img.onerror = function() {
            // If full-size fails, try the original URL
            if (fullSizeUrl !== imageUrl) {
                const fallbackProxyUrl = isExternalUrl ? `api/image-proxy.php?url=${encodeURIComponent(imageUrl)}` : imageUrl;
                img.src = fallbackProxyUrl;
            } else {
                showNotification('Failed to load image. Please try again.', 'error');
            }
        };
        img.src = proxyUrl;
        
        // Update split line and preview on slider change
        slider.addEventListener('input', function() {
            const position = parseInt(this.value);
            positionValue.textContent = position + '%';
            updateSplitLine();
            updateSplitPreview();
        });
        
        // Update on direction change
        directionRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                updateSplitLine();
                updateSplitPreview();
            });
        });
        
        function updateSplitLine() {
            if (!previewImg.complete) return;
            
            const direction = document.querySelector('input[name="splitDirection"]:checked')?.value || 'horizontal';
            const position = parseInt(slider.value);
            const rect = previewImg.getBoundingClientRect();
            
            splitLine.style.display = 'block';
            
            if (direction === 'vertical') {
                // Vertical split (left/right)
                const leftPercent = position;
                splitLine.style.left = (leftPercent / 100 * rect.width) + 'px';
                splitLine.style.top = '0';
                splitLine.style.bottom = '0';
                splitLine.style.width = '2px';
                splitLine.style.height = '100%';
            } else {
                // Horizontal split (top/bottom)
                const topPercent = position;
                splitLine.style.top = (topPercent / 100 * rect.height) + 'px';
                splitLine.style.left = '0';
                splitLine.style.right = '0';
                splitLine.style.width = '100%';
                splitLine.style.height = '2px';
            }
        }
        
        function updateSplitPreview() {
            if (!currentSplitImage || !previewImg.complete) return;
            
            const direction = document.querySelector('input[name="splitDirection"]:checked')?.value || 'horizontal';
            const position = parseInt(slider.value) / 100;
            
            const frontCanvas = document.getElementById('frontSplitPreview');
            const backCanvas = document.getElementById('backSplitPreview');
            
            if (!frontCanvas || !backCanvas) return;
            
            const imgWidth = currentSplitImage.width;
            const imgHeight = currentSplitImage.height;
            
            // Store full resolution canvases separately for export
            if (!window.splitFrontCanvas) {
                window.splitFrontCanvas = document.createElement('canvas');
            }
            if (!window.splitBackCanvas) {
                window.splitBackCanvas = document.createElement('canvas');
            }
            
            if (direction === 'vertical') {
                // Split left/right
                const splitX = Math.floor(imgWidth * position);
                
                // Front cover (right side) - Full resolution
                window.splitFrontCanvas.width = imgWidth - splitX;
                window.splitFrontCanvas.height = imgHeight;
                const frontCtxFull = window.splitFrontCanvas.getContext('2d');
                frontCtxFull.drawImage(currentSplitImage, splitX, 0, imgWidth - splitX, imgHeight, 0, 0, imgWidth - splitX, imgHeight);
                
                // Back cover (left side) - Full resolution
                window.splitBackCanvas.width = splitX;
                window.splitBackCanvas.height = imgHeight;
                const backCtxFull = window.splitBackCanvas.getContext('2d');
                backCtxFull.drawImage(currentSplitImage, 0, 0, splitX, imgHeight, 0, 0, splitX, imgHeight);
                
                // Preview canvases (scaled for display)
                const maxPreviewSize = 300;
                const frontScale = Math.min(1, maxPreviewSize / (imgWidth - splitX));
                const backScale = Math.min(1, maxPreviewSize / splitX);
                
                frontCanvas.width = (imgWidth - splitX) * frontScale;
                frontCanvas.height = imgHeight * frontScale;
                const frontCtx = frontCanvas.getContext('2d');
                frontCtx.drawImage(window.splitFrontCanvas, 0, 0, frontCanvas.width, frontCanvas.height);
                
                backCanvas.width = splitX * backScale;
                backCanvas.height = imgHeight * backScale;
                const backCtx = backCanvas.getContext('2d');
                backCtx.drawImage(window.splitBackCanvas, 0, 0, backCanvas.width, backCanvas.height);
            } else {
                // Split top/bottom
                const splitY = Math.floor(imgHeight * position);
                
                // Front cover (bottom side) - Full resolution
                window.splitFrontCanvas.width = imgWidth;
                window.splitFrontCanvas.height = imgHeight - splitY;
                const frontCtxFull = window.splitFrontCanvas.getContext('2d');
                frontCtxFull.drawImage(currentSplitImage, 0, splitY, imgWidth, imgHeight - splitY, 0, 0, imgWidth, imgHeight - splitY);
                
                // Back cover (top side) - Full resolution
                window.splitBackCanvas.width = imgWidth;
                window.splitBackCanvas.height = splitY;
                const backCtxFull = window.splitBackCanvas.getContext('2d');
                backCtxFull.drawImage(currentSplitImage, 0, 0, imgWidth, splitY, 0, 0, imgWidth, splitY);
                
                // Preview canvases (scaled for display)
                const maxPreviewSize = 300;
                const frontScale = Math.min(1, maxPreviewSize / (imgHeight - splitY));
                const backScale = Math.min(1, maxPreviewSize / splitY);
                
                frontCanvas.width = imgWidth * frontScale;
                frontCanvas.height = (imgHeight - splitY) * frontScale;
                const frontCtx = frontCanvas.getContext('2d');
                frontCtx.drawImage(window.splitFrontCanvas, 0, 0, frontCanvas.width, frontCanvas.height);
                
                backCanvas.width = imgWidth * backScale;
                backCanvas.height = splitY * backScale;
                const backCtx = backCanvas.getContext('2d');
                backCtx.drawImage(window.splitBackCanvas, 0, 0, backCanvas.width, backCanvas.height);
            }
        }
        
        // Wait for image to load before updating
        previewImg.onload = function() {
            updateSplitLine();
            updateSplitPreview();
        };
    }
    
    // Apply split button
    const applyBtn = document.getElementById('applySplitBtn');
    if (applyBtn) {
        applyBtn.addEventListener('click', function() {
            // Use full resolution canvases if available, otherwise use preview canvases
            const frontCanvas = window.splitFrontCanvas || document.getElementById('frontSplitPreview');
            const backCanvas = window.splitBackCanvas || document.getElementById('backSplitPreview');
            
            if (!frontCanvas || !backCanvas) return;
            
            // Convert canvases to data URLs at high quality
            const frontDataUrl = frontCanvas.toDataURL('image/jpeg', 0.95);
            const backDataUrl = backCanvas.toDataURL('image/jpeg', 0.95);
            
            // Upload both images
            uploadSplitImages(frontDataUrl, backDataUrl, currentSplitContext);
            
            hideModal('imageSplitModal');
        });
    }
    
    // Auto Split button
    const autoSplitBtn = document.getElementById('autoSplitBtn');
    if (autoSplitBtn) {
        autoSplitBtn.addEventListener('click', function() {
            // Detect image source to determine split percentage
            let splitPercentage = 53; // Default for The Covers Project
            if (currentSplitImageUrl) {
                const isRedditImage = currentSplitImageUrl.includes('i.redd.it') || currentSplitImageUrl.includes('preview.redd.it');
                if (isRedditImage) {
                    splitPercentage = 50; // Reddit images use 50%
                }
            }
            
            // Set to vertical split
            const verticalRadio = document.querySelector('input[name="splitDirection"][value="vertical"]');
            if (verticalRadio) {
                verticalRadio.checked = true;
                verticalRadio.dispatchEvent(new Event('change'));
            }
            
            // Set position based on detected source
            const slider = document.getElementById('splitPositionSlider');
            const positionValue = document.getElementById('splitPositionValue');
            if (slider) {
                slider.value = splitPercentage;
                if (positionValue) {
                    positionValue.textContent = splitPercentage + '%';
                }
                slider.dispatchEvent(new Event('input'));
            }
            
            // Automatically apply the split after a brief moment
            setTimeout(function() {
                const applyBtn = document.getElementById('applySplitBtn');
                if (applyBtn) {
                    applyBtn.click();
                }
            }, 100);
        });
    }
    
    // Cancel button
    const cancelBtn = document.getElementById('cancelSplitBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            hideModal('imageSplitModal');
        });
    }
    
    // Close modal on X button
    const closeBtn = document.querySelector('#imageSplitModal .modal-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            hideModal('imageSplitModal');
        });
    }
    
    async function uploadSplitImages(frontDataUrl, backDataUrl, context) {
        try {
            // Store data URLs directly (no local file storage)
            // Data URLs are base64-encoded images that can be stored in the database
            if (context === 'add') {
                window.addGameFrontCover = frontDataUrl;
                window.addGameBackCover = backDataUrl;
                
                // Update previews
                document.getElementById('addFrontCoverPreview').innerHTML = 
                    `<img src="${frontDataUrl}" alt="Front Cover" style="max-width: 200px;">`;
                document.getElementById('addBackCoverPreview').innerHTML = 
                    `<img src="${backDataUrl}" alt="Back Cover" style="max-width: 200px;">`;
                
                // Clear URL inputs
                document.getElementById('addFrontCoverUrl').value = '';
                document.getElementById('addBackCoverUrl').value = '';
                document.getElementById('addFrontCoverSplitBtn').style.display = 'none';
                document.getElementById('addFrontCoverAutoSplitBtn').style.display = 'none';
            } else if (context === 'add-item') {
                window.addItemFrontImage = frontDataUrl;
                window.addItemBackImage = backDataUrl;
                
                // Update previews
                document.getElementById('addItemFrontImagePreview').innerHTML = 
                    `<img src="${frontDataUrl}" alt="Front Image" style="max-width: 200px;">`;
                document.getElementById('addItemBackImagePreview').innerHTML = 
                    `<img src="${backDataUrl}" alt="Back Image" style="max-width: 200px;">`;
                
                // Clear URL inputs
                document.getElementById('addItemFrontImageUrl').value = '';
                document.getElementById('addItemBackImageUrl').value = '';
                document.getElementById('addItemFrontImageSplitBtn').style.display = 'none';
                document.getElementById('addItemFrontImageAutoSplitBtn').style.display = 'none';
            } else if (context === 'edit-item-front' || context === 'edit-item-back') {
                // Handle item editing - when splitting, update both front and back images
                if (window.currentItem) {
                    window.currentItem.front_image = frontDataUrl;
                    window.currentItem.back_image = backDataUrl;
                }
                
                // Update front image preview and URL
                const frontPreview = document.getElementById('itemFrontImagePreview');
                if (frontPreview) {
                    frontPreview.innerHTML = `<img src="${frontDataUrl}" alt="Front Image" style="max-width: 200px;">`;
                }
                const frontUrlInput = document.getElementById('editItemFrontImageUrl');
                if (frontUrlInput) {
                    frontUrlInput.value = frontDataUrl;
                }
                document.getElementById('editItemFrontImageSplitBtn').style.display = 'none';
                document.getElementById('editItemFrontImageAutoSplitBtn').style.display = 'none';
                
                // Update back image preview and URL
                const backPreview = document.getElementById('itemBackImagePreview');
                if (backPreview) {
                    backPreview.innerHTML = `<img src="${backDataUrl}" alt="Back Image" style="max-width: 200px;">`;
                }
                const backUrlInput = document.getElementById('editItemBackImageUrl');
                if (backUrlInput) {
                    backUrlInput.value = backDataUrl;
                }
                document.getElementById('editItemBackImageSplitBtn').style.display = 'none';
                document.getElementById('editItemBackImageAutoSplitBtn').style.display = 'none';
            } else {
                window.currentGame.front_cover_image = frontDataUrl;
                window.currentGame.back_cover_image = backDataUrl;
                
                // Update previews
                document.getElementById('frontCoverPreview').innerHTML = 
                    `<img src="${frontDataUrl}" alt="Front Cover" style="max-width: 200px;">`;
                document.getElementById('backCoverPreview').innerHTML = 
                    `<img src="${backDataUrl}" alt="Back Cover" style="max-width: 200px;">`;
                
                // Clear URL inputs
                document.getElementById('editFrontCoverUrl').value = '';
                document.getElementById('editBackCoverUrl').value = '';
                document.getElementById('editFrontCoverSplitBtn').style.display = 'none';
            }
            
            showNotification('Cover images split successfully! (Stored as data URLs - no local files)', 'success');
        } catch (error) {
            console.error('Error processing split images:', error);
            showNotification('Error processing split images', 'error');
        }
    }
    
    function dataURLtoBlob(dataURL) {
        const arr = dataURL.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) {
            u8arr[n] = bstr.charCodeAt(n);
        }
        return new Blob([u8arr], { type: mime });
    }
}
