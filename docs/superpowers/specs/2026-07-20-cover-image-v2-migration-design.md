# Cover-Image v2 migration — dual-auth scaffolding

**Date:** 2026-07-20
**Phase:** 5 (v1 retirement) — first browser-facing migration
**Status:** design approved, plan pending

---

## Motivation

Phase 5/01 deleted the one v1 endpoint that had zero callers (`api/download-external-image.php`). The commit message flagged the harder truth: *"Fable's Phase 5 was 'once the frontend calls v2, delete v1'. That's a multi-week prerequisite (v2 has no admin / completions / items / settings / stats / uploads / imports / games CRUD equivalents)."*

Every remaining v1 endpoint has a browser caller. To delete any of them we first need a path for the browser to talk to v2. `api/cover-image.php` is the smallest, safest first target:

- Single caller (`js/forms/game-form.js` line 234)
- Read-only GET — no CSRF complexity for the endpoint's own request
- Self-contained (TheGamesDB lookup, no shared state)
- Failure is already user-visible ("upload manually") — a bad shipping day is annoying, not destructive

## Goal

Migrate `api/cover-image.php` off v1 while establishing the reusable pattern for future v2 web-caller migrations.

## Non-goals

- Migrating any other v1 endpoint. That's a future spec per endpoint.
- Deprecating v1 login (`api/auth.php?action=login`). Sessions stay as the browser's primary credential.
- Introducing browser-side bearer tokens. The design explicitly avoids this — HttpOnly session cookies are strictly better for XSS resistance.
- Changing iOS behavior. iOS's Bearer token flow is unchanged.

## Security posture

The security-critical decision: **browsers do not get bearer tokens.** Any credential JavaScript can attach to `Authorization` headers is by definition JavaScript-readable, which means XSS exfiltrates it and uses it from attacker infrastructure indefinitely. HttpOnly session cookies can't be read by JS; XSS can act as the user during their session but can't lift the credential.

Instead, v2 learns to accept the browser's existing HttpOnly session cookie, with CSRF enforcement on mutations. This is not the "session-faking" pattern Fable §2 called out — no v1 file inclusion, no forced `$_SESSION` state, no output-buffer reshaping. Just a second `_try_*` branch inside `v2_require_auth()` that recognises a real session PHP already set at login.

## Architecture

```
Browser (game-form.js)                iOS
   │                                   │
   │  fetch('/api/v2/cover-image?…',   │  fetch('/api/v2/cover-image?…',
   │      credentials: 'same-origin')  │      { Authorization: 'Bearer …' })
   │  ── HttpOnly session cookie        │
   │  ── no CSRF (GET request)          │
   ▼                                   ▼
        ┌────────────────────────────┐
        │  api/v2/cover-image.php    │
        │  1. include _helpers.php   │
        │  2. include config.php     │
        │  3. include _auth.php      │
        │  4. v2_require_method('GET')
        │  5. v2_require_auth($pdo)  ◄── dual-auth
        │  6. inline TheGamesDB flow  
        │  7. v2_ok / v2_error       
        └────────────────────────────┘
                    │
                    ▼
             api/v2/_auth.php
             ┌─────────────────────────────────┐
             │ v2_require_auth(PDO $pdo,       │
             │     bool $requireCsrfIfSession  │
             │       = true): int              │
             │                                 │
             │ Try _v2_try_bearer($pdo)        │
             │   └─ Authorization hdr present? │
             │      hash → api_tokens lookup   │
             │      hit → return user_id       │
             │                                 │
             │ Try _v2_try_session($requireCsrf)
             │   └─ session_start()            │
             │      $_SESSION[user_id] set?    │
             │      if mutating: validateCsrf  │
             │      hit → return user_id       │
             │                                 │
             │ Else v2_error('missing_token',  │
             │     401)                        │
             └─────────────────────────────────┘
```

## Components

### Backend

**`api/v2/_auth.php` — signature change**

`v2_require_auth(PDO $pdo, bool $requireCsrfIfSession = true): int` — default keeps CSRF on. Refactored internals:

- `_v2_try_bearer(PDO $pdo): ?int` — today's Bearer logic verbatim, returns `null` on absence/miss instead of exiting.
- `_v2_try_session(bool $requireCsrf): ?int` — starts the session only if not already active (`if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }` — avoids the "session already started" notice on setups where auto_start or a prior include already opened it). Checks `$_SESSION['user_id']` + `$_SESSION['username']` present, verifies CSRF via `validateCsrfToken()` from `includes/csrf.php` when `$requireCsrf` is true AND `$_SERVER['REQUEST_METHOD']` is not GET/HEAD. Returns `user_id` or `null`. Distinct `v2_error('invalid_csrf', …, 403)` when session is valid but CSRF fails — so callers can tell CSRF-failure apart from missing-credential.
- Top-level function tries Bearer, then Session, else `v2_error('missing_token', …, 401)` with a message updated to acknowledge both credential types.

iOS never enters the session branch (its Bearer wins first). Every existing v2 endpoint continues to work unchanged.

**`api/v2/cover-image.php` — new file, inline logic**

Structure:

```php
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$title    = trim($_GET['title']    ?? '');
$platform = trim($_GET['platform'] ?? '');

if ($title === '') {
    v2_error('bad_request', 'title is required', 400);
}

// API key resolution (env → per-user setting)
// TheGamesDB search across title variations
// Response
v2_ok(['image_url' => $imageUrl]);
```

Behavior differences from v1 (documented in the commit message when the endpoint lands):

- Response envelope: `{data: {image_url: …}}` instead of `{success: true, image_url: …}`
- Errors return proper HTTP status codes with error slugs, not 200 with `success:false`
- Distinct error codes: `bad_request` / `api_key_missing` / `not_found` / `no_boxart` / `upstream_auth_failed`

### Frontend

**`js/api.js` — v2 helpers**

```js
class V2ApiError extends Error {
    constructor(code, message) {
        super(message);
        this.name = 'V2ApiError';
        this.code = code;
    }
}

async function apiV2Get(path) {
    const response = await fetch('/api/v2/' + path, {
        credentials: 'same-origin',
    });
    const body = await response.json();
    if (!response.ok || body.error) {
        throw new V2ApiError(
            body.error || 'http_error',
            body.message || `HTTP ${response.status}`
        );
    }
    return body.data;
}
```

`apiV2PostJson` / `apiV2PostForm` are intentionally NOT added yet — YAGNI until a v2 mutation caller lands. When they arrive they'll piggyback on `apiRequest`'s existing `X-CSRF-Token` injection.

**`js/forms/game-form.js` — call-site swap**

Replace the "Auto-fetch Cover" `fetch(...)` block with:

```js
try {
    const result = await apiV2Get(
        `cover-image.php?title=${encodeURIComponent(title)}` +
        `&platform=${encodeURIComponent(platform || '')}`
    );
    setPreview(result.image_url);
    window.addGameFrontCover = result.image_url;
    showNotification('Cover image URL fetched!', 'success');
} catch (err) {
    if (err instanceof V2ApiError) {
        const msg = (err.code === 'not_found' || err.code === 'no_boxart')
            ? 'Could not find cover image automatically. Please upload manually.'
            : err.code === 'api_key_missing'
                ? 'TheGamesDB API key not configured. Add it in Settings.'
                : err.message;
        showNotification(msg, 'error');
    } else {
        showNotification('Error fetching cover image. Please check your connection.', 'error');
    }
}
```

## Error handling

### Auth failure taxonomy

| Cause | Response |
|---|---|
| No Bearer, no session | 401 `{error:"missing_token", message:"Authorization: Bearer <token> or valid session required"}` |
| Bearer present but invalid/revoked | 401 `{error:"invalid_token"}` — unchanged |
| Session cookie present but `$_SESSION[user_id]` empty (expired/never authed) | Falls through → 401 `missing_token`. No HTML redirect from `/api/v2/` ever. |
| Session valid, mutating request, no/bad CSRF | 403 `{error:"invalid_csrf", message:"Invalid or missing CSRF token"}` |
| Session valid, GET request | Allowed |

### Cover-image endpoint errors

| Cause | Response |
|---|---|
| Missing `title` | 400 `bad_request` |
| API key absent (env + per-user both empty) | 500 `api_key_missing` |
| TheGamesDB 401 (bad key) | 502 `upstream_auth_failed` |
| Search returned zero matches | 404 `not_found` |
| Match found but no boxart | 404 `no_boxart` |

Upstream curl errors are logged via `error_log()` with context; the browser sees a generic message. No raw upstream error detail leaks.

## Testing strategy

**Backend**

- `tests/v2/test_dual_auth.sh` (new) — six cases against `api/v2/_ping.php`. `_ping.php` already calls `v2_require_auth($pdo)` so no endpoint change is needed; it's the cleanest target and lets PR #1 ship the auth change without depending on PR #2:
  1. Valid Bearer → 200
  2. No credential → 401 `missing_token`
  3. Invalid Bearer → 401 `invalid_token`
  4. Valid session + GET → 200
  5. Valid session + POST with matching CSRF → 200
  6. Valid session + POST without CSRF header → 403 `invalid_csrf`

- `tests/v2/test_cover_image.sh` (new) — six cases:
  1. Auth-less request → 401 (dual-auth is covered above; this is a smoke test)
  2. Missing `title` → 400 `bad_request`
  3. Valid title with mocked API key set → 200 with `image_url` shape
  4. No API key configured → 500 `api_key_missing`
  5. TheGamesDB simulated 401 → 502 `upstream_auth_failed` — skipped in the shell test suite (no easy in-process mock for `curl_exec`). Covered by manual QA once (set a deliberately-wrong API key and hit the endpoint) at the PR #2 review checkpoint.
  6. Zero-match title → 404 `not_found`

**iOS regression**

- Manual: run the iOS app against a build with the dual-auth PR merged. iOS never calls cover-image, so this is a spot-check that other v2 endpoints still work with a Bearer. `test_ssrf.sh` and existing v2 suite provides most of this coverage.

**Browser manual QA (per feature-checkpoint convention)**

After PR #3 (caller switch) lands and deploys, in the browser:
1. Log in
2. Open Add Game form
3. Enter a known-matching title (e.g. "Halo 3"), select Xbox 360, click Auto-fetch Cover → cover appears
4. Enter a made-up title, click Auto-fetch Cover → "Could not find cover image" toast
5. Unset the API key, retry a valid title → "TheGamesDB API key not configured" toast

Per `feedback_per_feature_checkpoints.md`, pause after PR #3 for eye-on-glass QA before proceeding to PR #4.

## Migration sequence

Four PRs. Each ships independently, each is deployable.

**PR #1 — Auth: dual-auth in v2_require_auth**
- Refactor `api/v2/_auth.php` into `_v2_try_bearer` + `_v2_try_session` + new top-level.
- Extend `test_ssrf.sh` or add `test_dual_auth.sh` to exercise both credential types plus the CSRF branch.
- Deploy step: `git pull` on prod. Zero risk to iOS (Bearer wins first, existing tests catch regression).

**PR #2 — v2 endpoint: api/v2/cover-image.php**
- Add the new endpoint file.
- Add `test_cover_image.sh`.
- No frontend change; v1 caller still calls v1.
- Deploy step: `git pull` on prod.

**PR #3 — Caller switch: game-form.js → v2**
- Add `apiV2Get` + `V2ApiError` to `js/api.js`.
- Swap the fetch call in `js/forms/game-form.js`.
- Manual QA (see above) before merging.
- Deploy step: `git pull` on prod. **Per-feature checkpoint here** — pause implementer queue.

**PR #4 — Delete v1**
- `git rm api/cover-image.php`.
- Remove any lingering docs mentions (`SETUP-GUIDE.md`, tests).
- Verify with `grep -rn "api/cover-image\|cover-image.php" --exclude-dir=.git` → no code references remain.
- Deploy step: `git pull` on prod.

## Deploy considerations

Per `project_server_deploy_flow.md`: each PR includes a "Deploy" step in its PR description reminding to `git pull` on prod. PRs that touch server code (all four) need the branch pushed before Cameron can pull on the VM.

Per `project_stacked_pr_workflow.md`: PRs are sequential, not stacked. Merge PR #1 to main; branch PR #2 from main; merge; etc. Auto-merge is disabled on the repo, so each requires manual merge.

## Related notes

- Vault: create `gameTracker Phase 5b - Cover-image v2 migration.md` in the Obsidian vault (per `reference_vault_gametracker.md`) with an updated mermaid of the migration sequence and status per PR.
- Fable §2: this is the first real step toward "v2 is the one true API". Future v1 endpoint migrations reuse the dual-auth infrastructure landed here.
- `docs/superpowers/plans/2026-07-10-phase4-frontend-roadmap.md` §"Deferred to Phase 5" — this design realises the deferred item's prerequisite.

## Open decisions — resolved

- **Dual auth in v2_require_auth vs sibling function**: in-place, one call site.
- **Browser bearer tokens**: rejected on XSS grounds. Sessions + CSRF.
- **Extract cover-image logic to a service**: no. Inline in v2 file. v1 is going away in the same sequence; no second caller.
- **Bundle vs split spec**: bundled — auth change earns its keep alongside the first user of it.

## Follow-ups explicitly out of scope

- Adding `apiV2PostJson` / `apiV2PostForm` (add when a mutation caller lands, not before).
- Migrating additional v1 endpoints (each gets its own spec).
- Rate limiting on `api/v2/cover-image.php` (v1 doesn't rate-limit either; separate concern).
- Retiring `api/v2/auth/token.php` — iOS still uses it.
