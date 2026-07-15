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
