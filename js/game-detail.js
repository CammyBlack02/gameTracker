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
