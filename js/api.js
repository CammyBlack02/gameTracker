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

/**
 * Error thrown by apiV2* helpers when a v2 endpoint returns
 * { error: <code>, message: <…> } or a non-2xx status.
 *
 * v2 uses a different envelope from v1: success is { data: … } and
 * failure is { error: <code_slug>, message: <human readable> }, whereas
 * v1 returns { success: bool, message: … }. Callers distinguish
 * user-visible failure modes by inspecting .code — e.g. 'not_found',
 * 'api_key_missing', 'bad_request'.
 */
class V2ApiError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'V2ApiError';
        this.code = code;
    }
}

/**
 * GET /api/v2/<path>, returning the unwrapped `data` payload.
 *
 * Throws V2ApiError on an { error, message } body or a non-2xx status.
 * The browser's HttpOnly session cookie is sent automatically
 * (same-origin), which is what v2's dual-auth session path consumes —
 * the frontend deliberately holds no bearer token, because anything JS
 * can read, injected JS can exfiltrate.
 *
 * No X-CSRF-Token is attached: this is a GET, and v2 only demands CSRF
 * from session callers on mutating methods. The one exception is
 * external-image.php, which mutates on GET and therefore requires a
 * token — a POST/CSRF-aware apiV2Post helper is the place for that when
 * a caller needs it.
 */
async function apiV2Get(path) {
    const response = await fetch('/api/v2/' + path, {
        credentials: 'same-origin',
    });
    let body;
    try {
        body = await response.json();
    } catch (e) {
        throw new V2ApiError('bad_json', `Non-JSON response (HTTP ${response.status})`);
    }
    if (!response.ok || body.error) {
        throw new V2ApiError(
            body.error || 'http_error',
            body.message || `HTTP ${response.status}`
        );
    }
    return body.data;
}
