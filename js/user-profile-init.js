// Set flag BEFORE js/games.js loads to prevent its auto-load-on-DOMContentLoaded
// from firing on this page (we load a specific user's games via loadUserGames
// in js/user-profile.js instead).
window.IS_USER_PROFILE_PAGE = true;

// Neutralise loadGames so anything that races us can't kick off the wrong load.
// The real loader (loadUserGames) is defined in js/user-profile.js.
window.loadGames = function () {
    return Promise.resolve();
};
