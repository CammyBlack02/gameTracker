// Shared fetch wrapper for all API calls. Migration target for the raw
// `fetch(...)` calls scattered across js/**. Sets up the seam for phase 4h
// (thread CSRF token through every mutating call) without needing to touch
// each caller when we get there — the wrapper adds the header, the caller
// keeps calling apiPostJson / apiPostForm.
//
// Return shape: whatever the endpoint returns as parsed JSON, regardless
// of HTTP status. The existing callers all check `data.success` from the
// parsed body — server error responses set that to false with a `.message`
// string, so preserving that shape avoids a regression. Network errors
// (fetch itself rejecting: DNS, offline, CORS) still throw and fall into
// the caller's catch block.

/**
 * Read the current session's CSRF token from the meta tag rendered on
 * every authenticated page. Returns undefined on pre-session pages
 * (index.php / register.php); the api helpers still function — just
 * without the header, which mutating endpoints will reject once
 * server-side enforcement lands.
 */
function getCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : undefined;
}

async function apiRequest(url, options = {}) {
    // Inject X-CSRF-Token on mutating requests. Server-side enforcement
    // rolls out endpoint-by-endpoint in phase 4h follow-ups — the header
    // is safe to always send; unknown headers are ignored.
    const method = (options.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD') {
        const token = getCsrfToken();
        if (token) {
            options.headers = { ...(options.headers || {}), 'X-CSRF-Token': token };
        }
    }
    const response = await fetch(url, options);
    // Some endpoints return non-2xx with a JSON error body; callers rely
    // on inspecting `data.success` / `data.message`, so pass the parsed
    // body through in both cases.
    return response.json();
}

async function apiGet(url) {
    return apiRequest(url);
}

async function apiPost(url) {
    return apiRequest(url, { method: 'POST' });
}

async function apiPostJson(url, body) {
    return apiRequest(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}

async function apiPostForm(url, formData) {
    return apiRequest(url, {
        method: 'POST',
        body: formData,
    });
}
