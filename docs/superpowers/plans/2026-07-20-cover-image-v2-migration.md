# Cover-Image v2 Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate `api/cover-image.php` off v1 by adding dual-auth (Bearer OR session+CSRF) to `v2_require_auth()`, standing up `api/v2/cover-image.php`, switching the browser caller, then deleting v1. Sequenced across 4 PRs.

**Architecture:** Extend `v2_require_auth()` with a second credential path that accepts the browser's HttpOnly session cookie plus CSRF token, using `validateCsrfToken()` from `includes/csrf.php`. iOS's Bearer path is unchanged (Bearer wins first). Browser never gains bearer tokens — HttpOnly session cookies are strictly better against XSS.

**Tech Stack:** PHP 8+ (v1 sessions, v2 endpoints, `includes/*.php`), vanilla JS (`js/api.js`, `js/forms/game-form.js`), bash test harness (`tests/v2/*.sh` using `lib.sh` helpers, curl, jq).

**Design reference:** `docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md` — read before starting.

---

## Merge sequence (four PRs, all sequential)

1. **PR #1** — dual-auth in `v2_require_auth()` (this plan's Tasks 1–5)
2. **PR #2** — new `api/v2/cover-image.php` endpoint (Tasks 6–10)
3. **PR #3** — browser caller switch + `js/api.js` v2 helper (Tasks 11–14) — **per-feature checkpoint here**
4. **PR #4** — delete `api/cover-image.php` (Tasks 15–17)

**Between PRs:** merge to main, deploy (`git pull` on prod VM per `project_server_deploy_flow.md`), verify, then branch the next PR from main. Auto-merge is disabled on this repo per `project_stacked_pr_workflow.md`.

**Branching:** each PR starts from a fresh `main`. Branch names: `phase-5-02-v2-dual-auth`, `phase-5-03-v2-cover-image`, `phase-5-04-cover-image-caller-switch`, `phase-5-05-delete-v1-cover-image`.

---

## File Structure

**Created:**
- `api/v2/cover-image.php` — new v2 endpoint (PR #2)
- `tests/v2/test_dual_auth.sh` — dual-auth tests (PR #1)
- `tests/v2/test_cover_image.sh` — endpoint tests (PR #2)

**Modified:**
- `api/v2/_auth.php` — refactor `v2_require_auth()` internals + add signature parameter (PR #1)
- `api/v2/_ping.php` — accept POST too, so CSRF branch can be tested (PR #1)
- `js/api.js` — add `apiV2Get` + `V2ApiError` (PR #3)
- `js/forms/game-form.js` — swap Auto-fetch Cover fetch call (PR #3)

**Deleted:**
- `api/cover-image.php` — retired v1 endpoint (PR #4)

---

## PR #1 — Dual-auth in v2_require_auth

**Branch:** `phase-5-02-v2-dual-auth`

### Task 1: Extend `_ping.php` to accept POST

**Files:**
- Modify: `api/v2/_ping.php`

`_ping.php` is currently GET-only by convention (there's no explicit method guard). To exercise the CSRF branch in `v2_require_auth()`, we need a v2 endpoint we can POST to. `_ping.php` is the natural target — it exists specifically to verify auth wiring.

- [ ] **Step 1: Modify `_ping.php`**

Change the file to explicitly allow both GET and POST. Full file after change:

```php
<?php
/**
 * Internal endpoint used by the test harness to verify auth wiring.
 * Accepts GET and POST — POST is used by dual-auth tests to exercise
 * the CSRF verification branch in v2_require_auth().
 * Returns the authenticated user's ID on success.
 */
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_auth.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    v2_error('method_not_allowed', 'Use GET or POST', 405);
}

$userId = v2_require_auth($pdo);
v2_ok(['user_id' => $userId, 'pong' => true]);
```

- [ ] **Step 2: Sanity check — existing ping test still passes**

Run: `bash tests/v2/test_auth.sh`

Expected: same PASS output as before (the existing Bearer + GET path is unchanged).

- [ ] **Step 3: Commit**

```bash
git add api/v2/_ping.php
git commit -m "chore(v2): _ping.php accepts POST too (dual-auth test prep)

Existing GET path unchanged. POST needed so tests/v2/test_dual_auth.sh
can exercise the CSRF-verification branch of v2_require_auth() being
added in the next commit.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Write failing dual-auth test

**Files:**
- Create: `tests/v2/test_dual_auth.sh`

TDD: this test exercises six cases. Cases 1–3 pass immediately (Bearer path unchanged). Cases 4–6 fail until Task 3 lands. That failure delta is our target.

- [ ] **Step 1: Create the test file**

```bash
#!/usr/bin/env bash
# Dual-auth tests — verify v2_require_auth accepts both Bearer tokens
# (iOS) and browser session cookies with CSRF. See design doc:
# docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md
source "$(dirname "$0")/lib.sh"

# --- Setup: obtain a Bearer token and a session cookie for the test user.

blue "Setup: mint a Bearer token"
req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS&device_name=dual-auth-test"
assert_eq "200" "$HTTP_STATUS" "token mint = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
[[ ${#TOKEN} -eq 64 ]] || { red "  FAIL: bad token length"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Setup: log in via v1 to establish a session cookie"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

# CSRF token lives in $_SESSION and is rendered on authenticated HTML pages
# as <meta name="csrf-token">. Fetch a page with the cookie and grep out the token.
blue "Setup: extract CSRF token from an authed page"
CSRF=$(curl -sS -b "$COOKIE" "$BASE_URL/dashboard.php" \
  | grep -oE '<meta name="csrf-token" content="[^"]+"' \
  | sed -E 's/.*content="([^"]+)"/\1/')
[[ -n "$CSRF" && ${#CSRF} -ge 32 ]] && green "  PASS: CSRF token captured (${#CSRF} chars)" && PASS_COUNT=$((PASS_COUNT+1)) \
  || { red "  FAIL: CSRF token capture failed"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Case 1: Bearer valid → 200 (Bearer path, GET)
blue "Case 1: valid Bearer + GET → 200"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "Bearer GET = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "pong body"

# --- Case 2: No credentials → 401 missing_token
blue "Case 2: no Bearer, no cookie → 401 missing_token"
req GET "/api/v2/_ping.php"
assert_eq "401" "$HTTP_STATUS" "no creds = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "missing_token code"

# --- Case 3: Invalid Bearer → 401 invalid_token
blue "Case 3: invalid Bearer → 401 invalid_token"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer 0000000000000000000000000000000000000000000000000000000000000000"
assert_eq "401" "$HTTP_STATUS" "bad Bearer = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "invalid_token code"

# --- Case 4: Session cookie + GET → 200 (session path, no CSRF needed)
blue "Case 4: session cookie + GET → 200"
req GET "/api/v2/_ping.php" "" -b "$COOKIE"
assert_eq "200" "$HTTP_STATUS" "session GET = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "pong body"

# --- Case 5: Session cookie + POST + valid CSRF → 200
blue "Case 5: session + POST + valid CSRF → 200"
req POST "/api/v2/_ping.php" "" -b "$COOKIE" -H "X-CSRF-Token: $CSRF"
assert_eq "200" "$HTTP_STATUS" "session POST + CSRF = 200"

# --- Case 6: Session cookie + POST + no CSRF → 403 invalid_csrf
blue "Case 6: session + POST + missing CSRF → 403 invalid_csrf"
req POST "/api/v2/_ping.php" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "session POST no CSRF = 403"
assert_contains '"error":"invalid_csrf"' "$RESPONSE_BODY" "invalid_csrf code"

# --- Cleanup
blue "Cleanup: revoke Bearer token"
curl -sS -X POST "$BASE_URL/api/v2/auth/revoke.php" \
  -H "Authorization: Bearer $TOKEN" > /dev/null
rm -f "$COOKIE"

summarize
```

- [ ] **Step 2: Make it executable**

Run: `chmod +x tests/v2/test_dual_auth.sh`

- [ ] **Step 3: Run against the current code — expect failures on cases 4–6**

Run: `bash tests/v2/test_dual_auth.sh`

Expected: cases 1–3 PASS (Bearer path unchanged), cases 4–6 FAIL because `v2_require_auth()` currently rejects any request without a Bearer header. Exact fail message will be "expected: 200 / actual: 401" for Case 4 with an `error:"missing_token"` body.

If cases 1–3 fail, stop and investigate — the Bearer path shouldn't be affected.

- [ ] **Step 4: Commit failing test**

```bash
git add tests/v2/test_dual_auth.sh
git commit -m "test(v2): failing dual-auth test — session path not yet supported

Six cases covering Bearer + session + CSRF paths against _ping.php.
Cases 1-3 (Bearer path) pass against current code; cases 4-6
(session path) fail because v2_require_auth is Bearer-only today.
Next commit refactors _auth.php to make cases 4-6 pass.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Refactor `v2_require_auth()` to accept dual credentials

**Files:**
- Modify: `api/v2/_auth.php`

- [ ] **Step 1: Rewrite `api/v2/_auth.php`**

Full new file content:

```php
<?php
/**
 * Authentication for /api/v2/ endpoints.
 *
 * Accepts EITHER:
 *   1. Authorization: Bearer <64-hex-token>   — iOS / API clients
 *   2. An active PHP session (from api/auth.php?action=login)
 *      PLUS a valid CSRF token on mutating requests (POST/PUT/DELETE/PATCH)
 *
 * The browser uses path (2): its HttpOnly session cookie is strictly
 * safer against XSS than any JS-readable Bearer token would be, and the
 * CSRF token the frontend already sends via X-CSRF-Token (see js/api.js)
 * covers the request-forgery risk that Bearer tokens would sidestep.
 *
 * This is NOT the session-faking pattern Fable §2 called out — no v1
 * file inclusion, no forced $_SESSION state, no output-buffer reshaping.
 * The session referred to here is the same one PHP set during v1 login;
 * we just recognise it as a valid credential.
 *
 * Usage at the top of an endpoint (unchanged):
 *
 *   require_once __DIR__ . '/_helpers.php';
 *   require_once __DIR__ . '/../../includes/config.php';
 *   require_once __DIR__ . '/_auth.php';
 *   $userId = v2_require_auth($pdo);
 *
 * For endpoints that MUST reject session auth (e.g. auth/revoke where
 * requiring a Bearer forces the client to prove token possession):
 *   $userId = v2_require_auth($pdo, requireCsrfIfSession: false, bearerOnly: true);
 * (bearerOnly currently unused; if we ever need it, add it here.)
 */

require_once __DIR__ . '/../../includes/csrf.php';

function v2_extract_bearer(): ?string {
    // Try standard Apache/Nginx header first.
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Some setups expose it under a different key.
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if ($header === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        return null;
    }
    return $m[1];
}

/**
 * Hash a raw token for storage/lookup. SHA-256 is enough — the token is
 * already a high-entropy random value; we just need a deterministic
 * lookup key that doesn't leak the original if the DB is dumped.
 */
function v2_hash_token(string $rawToken): string {
    return hash('sha256', $rawToken);
}

/**
 * @internal — Bearer credential path. Returns user_id on hit, null on
 * absence (no Authorization header). Exits with 401 invalid_token if a
 * Bearer is present but doesn't match a live token.
 */
function _v2_try_bearer(PDO $pdo): ?int {
    $raw = v2_extract_bearer();
    if ($raw === null) {
        return null;
    }
    $hash = v2_hash_token($raw);
    $stmt = $pdo->prepare("SELECT id, user_id FROM api_tokens
        WHERE token_hash = ? AND revoked_at IS NULL");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        v2_error('invalid_token', 'Token is invalid or revoked', 401);
    }
    // Update last_used_at lazily (don't block the request on this).
    $upd = $pdo->prepare("UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?");
    $upd->execute([$row['id']]);
    return (int)$row['user_id'];
}

/**
 * @internal — Session credential path. Returns user_id on hit, null on
 * absence (no active session or no user_id in session). Exits with 403
 * invalid_csrf if the session is valid but the request is mutating and
 * the CSRF token is missing or wrong.
 */
function _v2_try_session(bool $requireCsrf): ?int {
    // Only start a session if one isn't already active. Avoids the
    // "session already started" notice on setups where auto_start or a
    // prior include has already opened it.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        return null;
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $isMutating = ($method !== 'GET' && $method !== 'HEAD');
    if ($requireCsrf && $isMutating) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? $_POST['csrf_token']
              ?? '';
        if (!validateCsrfToken($token)) {
            v2_error('invalid_csrf', 'Invalid or missing CSRF token', 403);
        }
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Verify the request has a valid credential. Returns the user ID on
 * success. On failure, sends a JSON error response and exits.
 *
 * @param bool $requireCsrfIfSession   If true (default), the session
 *   credential path enforces CSRF on mutating requests. Set to false
 *   only if the endpoint has its own CSRF handling.
 */
function v2_require_auth(PDO $pdo, bool $requireCsrfIfSession = true): int {
    $userId = _v2_try_bearer($pdo);
    if ($userId !== null) {
        return $userId;
    }
    $userId = _v2_try_session($requireCsrfIfSession);
    if ($userId !== null) {
        return $userId;
    }
    v2_error(
        'missing_token',
        'Authorization: Bearer <token> or valid session required',
        401
    );
}
```

- [ ] **Step 2: Run the dual-auth test — expect all six cases to pass now**

Run: `bash tests/v2/test_dual_auth.sh`

Expected: `==> 6 passed, 0 failed` (plus the two setup PASS lines, so 8 total).

If Case 5 fails ("session POST + CSRF = 200 — actual 403"), suspect the CSRF meta-tag scrape returned an outdated token; re-run — `dashboard.php` should render a fresh one bound to the current session.

If Case 4 fails ("session GET = 200 — actual 401"), the session is not being recognized. Debug: `curl -sS -b $COOKIE -c $COOKIE "$BASE_URL/api/auth.php?action=check"` should return `authenticated:true`. If it does but Case 4 still fails, `_v2_try_session()`'s `session_start()` may not be seeing the cookie — check `session_status()` and `$_SESSION` contents via a temporary error_log.

- [ ] **Step 3: Run the existing v2 auth test to check for regressions**

Run: `bash tests/v2/test_auth.sh`

Expected: all existing cases pass (Bearer path unchanged).

- [ ] **Step 4: Run the full v2 suite**

Run: `bash tests/v2/run-all.sh`

Expected: no new failures. The iOS-related sync/upload tests should all continue to pass since they use Bearer.

- [ ] **Step 5: Commit refactor**

```bash
git add api/v2/_auth.php
git commit -m "feat(v2): v2_require_auth accepts session cookies with CSRF

Refactors v2_require_auth into _v2_try_bearer + _v2_try_session
helpers. Bearer path unchanged (iOS unaffected). Session path
recognizes an active PHP session and enforces CSRF via
validateCsrfToken() on mutating requests. Distinct 'invalid_csrf'
error code (403) so callers can distinguish auth-failure from
CSRF-failure.

Rationale: browsers should never be given bearer tokens (JS-readable
credentials leak to XSS). HttpOnly session cookies + CSRF is strictly
safer for the browser side; iOS keeps Bearer. This is not the
session-faking pattern Fable §2 flagged — no v1 file inclusion.

Test: tests/v2/test_dual_auth.sh (6 cases). Existing test_auth.sh
still green (Bearer regression check).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Wire test_dual_auth.sh into run-all.sh

**Files:**
- Modify: `tests/v2/run-all.sh`

- [ ] **Step 1: Check current contents**

Run: `cat tests/v2/run-all.sh`

- [ ] **Step 2: Add the new test file to the runner**

If `run-all.sh` iterates over `tests/v2/test_*.sh` via a glob, no change is needed — the new file is picked up automatically.

If it lists tests explicitly, add `test_dual_auth.sh` in alphabetical order (between `test_cover_upload.sh` and `test_error_disclosure.sh`).

- [ ] **Step 3: Verify by running run-all.sh**

Run: `bash tests/v2/run-all.sh 2>&1 | grep -E "test_dual_auth|PASS|FAIL"`

Expected: `test_dual_auth.sh` appears in the run and reports its passes.

- [ ] **Step 4: Commit if run-all.sh was edited**

If Step 2 required changes:
```bash
git add tests/v2/run-all.sh
git commit -m "test(v2): wire test_dual_auth.sh into run-all.sh

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

Otherwise skip — the glob picked it up automatically.

---

### Task 5: Push branch and open PR #1

- [ ] **Step 1: Push branch**

```bash
git push -u origin phase-5-02-v2-dual-auth
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(v2): dual-auth (Bearer OR session+CSRF) in v2_require_auth" --body "$(cat <<'EOF'
## Summary
- Refactors \`v2_require_auth()\` to accept either a Bearer token (iOS, unchanged) or an active PHP session with CSRF verification on mutations (browser).
- Adds \`tests/v2/test_dual_auth.sh\` with six cases covering both credential types.
- Extends \`api/v2/_ping.php\` to accept POST so the CSRF branch can be tested.

## Rationale
Browsers should never be given bearer tokens — any JS-readable credential leaks to XSS. HttpOnly session cookies + CSRF is strictly safer. iOS keeps Bearer. This isn't Fable §2's session-faking pattern (no v1 file inclusion, no forced \$_SESSION state).

First step in Phase 5 v1-retirement: prepares the ground for migrating individual v1 endpoints. \`api/cover-image.php\` is the first target in the follow-up PR.

## Test plan
- [x] \`bash tests/v2/test_dual_auth.sh\` → 6 passed
- [x] \`bash tests/v2/test_auth.sh\` → existing Bearer path green (no regression)
- [x] \`bash tests/v2/run-all.sh\` → full v2 suite green
- [ ] Deploy: \`git pull\` on prod VM
- [ ] Manual sanity: iOS app still logs in and syncs against updated backend

## Design
docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Deploy reminder**

Note in the PR (or the merge commit message) that this requires `git pull` on the prod VM per `project_server_deploy_flow.md`. Server-side change; no browser or iOS action required post-deploy.

---

## PR #2 — New api/v2/cover-image.php endpoint

**Branch:** `phase-5-03-v2-cover-image` (branched from `main` AFTER PR #1 merges)

### Task 6: Write failing test for v2 cover-image endpoint

**Files:**
- Create: `tests/v2/test_cover_image.sh`

- [ ] **Step 1: Create the test file**

```bash
#!/usr/bin/env bash
# Integration tests for api/v2/cover-image.php.
# Covers auth wiring, param validation, and known error codes.
# Skipped: real TheGamesDB hit (requires network + api key) and
# upstream_auth_failed (no easy curl_exec mock). Those are exercised
# manually per docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md
source "$(dirname "$0")/lib.sh"

# --- Setup: get a Bearer token
blue "Setup: mint a Bearer token"
req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS&device_name=cover-image-test"
assert_eq "200" "$HTTP_STATUS" "token mint = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

AUTH="-H Authorization:\ Bearer\ $TOKEN"

# --- Case 1: no credentials → 401 missing_token
blue "Case 1: no auth → 401 missing_token"
req GET "/api/v2/cover-image.php?title=Halo"
assert_eq "401" "$HTTP_STATUS" "no auth = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "missing_token code"

# --- Case 2: authed + missing title → 400 bad_request
blue "Case 2: authed, no title → 400 bad_request"
req GET "/api/v2/cover-image.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "400" "$HTTP_STATUS" "missing title = 400"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 3: authed + empty title → 400 bad_request
blue "Case 3: authed, empty title → 400 bad_request"
req GET "/api/v2/cover-image.php?title=" "" -H "Authorization: Bearer $TOKEN"
assert_eq "400" "$HTTP_STATUS" "empty title = 400"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 4: wrong HTTP method → 405 method_not_allowed
blue "Case 4: POST not allowed → 405"
req POST "/api/v2/cover-image.php?title=Halo" "" -H "Authorization: Bearer $TOKEN"
assert_eq "405" "$HTTP_STATUS" "POST = 405"
assert_contains '"error":"method_not_allowed"' "$RESPONSE_BODY" "method_not_allowed code"

# --- Case 5: authed + no API key configured → 500 api_key_missing
# We can't reliably set/unset env or per-user config in a test, so this case
# is EXECUTED CONDITIONALLY: skip it if THEGAMESDB_API_KEY is set OR the
# test user has a per-user setting. Test doc-covers the code path via the
# case-2/3 assertions of the error envelope shape.
blue "Case 5: no API key → 500 api_key_missing (conditional)"
# Best-effort: hit the endpoint with a title that WOULD trigger the API key
# lookup. If the response is 500 api_key_missing, we've confirmed the path.
# If it's 200 or 404, the environment has a key configured — skip the assertion.
req GET "/api/v2/cover-image.php?title=___definitely_not_a_real_game___" "" -H "Authorization: Bearer $TOKEN"
if [[ "$HTTP_STATUS" == "500" ]] && echo "$RESPONSE_BODY" | grep -q '"error":"api_key_missing"'; then
    green "  PASS: api_key_missing path exercised"
    PASS_COUNT=$((PASS_COUNT+1))
elif [[ "$HTTP_STATUS" == "404" ]] && echo "$RESPONSE_BODY" | grep -q '"error":"not_found"'; then
    green "  PASS: api_key_missing skipped (key configured — not_found path exercised instead)"
    PASS_COUNT=$((PASS_COUNT+1))
else
    red "  FAIL: unexpected status=$HTTP_STATUS body=$RESPONSE_BODY"
    FAIL_COUNT=$((FAIL_COUNT+1))
fi

# --- Cleanup
blue "Cleanup: revoke Bearer token"
curl -sS -X POST "$BASE_URL/api/v2/auth/revoke.php" \
  -H "Authorization: Bearer $TOKEN" > /dev/null

summarize
```

- [ ] **Step 2: Make executable and run — expect all cases to fail (endpoint doesn't exist)**

```bash
chmod +x tests/v2/test_cover_image.sh
bash tests/v2/test_cover_image.sh
```

Expected: all four "authed" cases return 404 (file doesn't exist) instead of the expected status codes. Setup + Case 1 might pass (Case 1 depends on how the missing file is handled — if nginx serves a plain 404, the body won't have `missing_token`).

Actual expected outcome is a red summary — probably `==> 1 passed, 5 failed` or similar. That's fine; we're TDD'ing.

- [ ] **Step 3: Commit failing test**

```bash
git add tests/v2/test_cover_image.sh
git commit -m "test(v2): failing tests for api/v2/cover-image.php

Endpoint doesn't exist yet — all cases 404. Next commit adds the
endpoint and turns these green.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Add api/v2/cover-image.php

**Files:**
- Create: `api/v2/cover-image.php`

- [ ] **Step 1: Create the endpoint file**

```php
<?php
/**
 * GET /api/v2/cover-image.php?title=<title>&platform=<platform>
 *
 * Searches TheGamesDB for a cover image matching the given title and
 * (optionally) platform. Returns the CDN URL on success. Does NOT
 * download or persist the image — that's the caller's job (via
 * api/v2/external-image.php).
 *
 * Auth: Bearer OR session (this is a GET, so no CSRF needed).
 *
 * Response shapes:
 *   200 { "data": { "image_url": "https://cdn.thegamesdb.net/..." } }
 *   400 { "error": "bad_request",         "message": "title is required" }
 *   401 { "error": "missing_token",       "message": "..." }
 *   404 { "error": "not_found",           "message": "Could not find cover image automatically" }
 *   404 { "error": "no_boxart",           "message": "Match found but no cover art available" }
 *   405 { "error": "method_not_allowed",  "message": "Use GET" }
 *   500 { "error": "api_key_missing",     "message": "TheGamesDB API key not configured" }
 *   502 { "error": "upstream_auth_failed","message": "TheGamesDB rejected the API key" }
 */
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

// --- API key resolution: env var first, then per-user setting.
$apiKey = getenv('THEGAMESDB_API_KEY') ?: '';
if ($apiKey === '') {
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? AND user_id = ?");
    $stmt->execute(['thegamesdb_api_key', $userId]);
    $apiKey = (string)($stmt->fetchColumn() ?: '');
}
if ($apiKey === '') {
    v2_error('api_key_missing', 'TheGamesDB API key not configured', 500);
}

// --- Title cleanup + variations. Same rules as the retired v1 endpoint.
$cleanTitle = preg_replace('/\s*\[[^\]]+\]\s*/', ' ', $title);
$cleanTitle = preg_replace('/\s*\([^\)]+\)\s*/', ' ', $cleanTitle);
$cleanTitle = trim($cleanTitle);

$titleVariations = [
    $cleanTitle,
    str_replace(':', '', $cleanTitle),
    preg_replace('/^0+(\d+):/', '$1', $cleanTitle),
    preg_replace('/^0+(\d+)\s/', '$1 ', $cleanTitle),
];

$platformMap = [
    'PlayStation' => 1, 'PlayStation 2' => 2, 'PlayStation 3' => 3,
    'PlayStation 4' => 4, 'PlayStation 5' => 5,
    'Xbox' => 6, 'Xbox 360' => 7, 'Xbox One' => 8, 'Xbox Series X' => 9,
    'Nintendo Switch' => 10, 'Wii' => 11, 'Wii U' => 12,
    'Nintendo 3DS' => 13, 'Nintendo DS' => 14,
    'PC' => 15, 'Steam' => 15, 'Windows' => 15,
    'GameCube' => 16, 'Nintendo 64' => 19, 'SNES' => 20,
    'Mega Drive' => 29, 'Sega Genesis' => 29,
    'Dreamcast' => 23, 'PS Vita' => 38,
];
$platformId = $platform !== '' ? ($platformMap[$platform] ?? null) : null;

// --- Search each variation until we find a match.
$gameId = null;
$game = null;
foreach ($titleVariations as $searchTitle) {
    $searchUrl = 'https://api.thegamesdb.net/v1/Games/ByGameName?apikey=' . urlencode($apiKey) . '&name=' . urlencode($searchTitle);
    if ($platformId) {
        $searchUrl .= '&platform=' . $platformId;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $searchUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        error_log("cover-image: search HTTP $httpCode for '$searchTitle'");
        continue;
    }
    $data = json_decode($response, true);
    if (isset($data['code']) && $data['code'] == 401) {
        error_log("cover-image: TheGamesDB rejected API key");
        v2_error('upstream_auth_failed', 'TheGamesDB rejected the API key', 502);
    }
    $games = $data['data']['Games']
          ?? $data['data']['games']
          ?? null;
    if (!empty($games)) {
        $game   = $games[0];
        $gameId = $game['id'];
        break;
    }
}

if (!$gameId) {
    v2_error('not_found', 'Could not find cover image automatically', 404);
}

// --- Fetch images for the matched game.
$imagesUrl = 'https://api.thegamesdb.net/v1/Games/Images?apikey=' . urlencode($apiKey) . '&games_id=' . $gameId;
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $imagesUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_USERAGENT, 'GameTracker/1.0');
$imagesResponse = curl_exec($ch);
$imagesHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($imagesHttp !== 200 || !$imagesResponse) {
    error_log("cover-image: images HTTP $imagesHttp for game $gameId");
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

$imagesData = json_decode($imagesResponse, true);
$images = $imagesData['data']['images'][$gameId]
       ?? $imagesData['data']['Images'][$gameId]
       ?? null;

$boxart = $images['boxart']
       ?? $images['Boxart']
       ?? null;

if (!$boxart) {
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

// --- Extract image path from any of the boxart shapes TheGamesDB returns.
if (is_string($boxart)) {
    $imagePath = $boxart;
} elseif (is_array($boxart)) {
    $imagePath = $boxart['original']
              ?? $boxart[0]
              ?? reset($boxart)
              ?? null;
} else {
    $imagePath = null;
}

if (!$imagePath) {
    v2_error('no_boxart', 'Match found but no cover art available', 404);
}

if (substr($imagePath, 0, 1) !== '/') {
    $imagePath = '/' . $imagePath;
}
$imageUrl = 'https://cdn.thegamesdb.net/images/original' . $imagePath;

v2_ok(['image_url' => $imageUrl]);
```

- [ ] **Step 2: Run the test — expect all cases to pass**

Run: `bash tests/v2/test_cover_image.sh`

Expected: `==> 5 passed, 0 failed` (plus setup).

If Case 5 fails with unexpected status, check whether an API key is set in the environment via `env | grep THEGAMESDB` or in the test user's settings row via a quick DB query — the test skips gracefully in either configuration.

- [ ] **Step 3: Run the full v2 suite**

Run: `bash tests/v2/run-all.sh`

Expected: no regressions.

- [ ] **Step 4: Commit**

```bash
git add api/v2/cover-image.php
git commit -m "feat(v2): add api/v2/cover-image.php

Ports TheGamesDB search logic from api/cover-image.php into v2's
response envelope. Distinct error codes: bad_request /
api_key_missing / not_found / no_boxart / upstream_auth_failed —
each with a proper HTTP status. No more 200-with-success:false.

Uses dual-auth from v2_require_auth() (PR #1); browser will migrate
to this endpoint in PR #3.

Test: tests/v2/test_cover_image.sh (5 cases, plus one conditional
depending on whether an API key is configured).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Manual smoke test against a real TheGamesDB hit

- [ ] **Step 1: Ensure the local dev server has an API key set**

Either via env var (`export THEGAMESDB_API_KEY=…` before starting PHP) or via the test user's settings row.

- [ ] **Step 2: Log in via curl and hit the endpoint with a known-matching title**

```bash
# Mint a token
TOKEN=$(curl -sS -X POST "http://localhost:8000/api/v2/auth/token.php" \
  -d "username=$TEST_USER&password=$TEST_PASS" \
  | jq -r '.data.token')

# Successful search
curl -sS "http://localhost:8000/api/v2/cover-image.php?title=Halo%203&platform=Xbox%20360" \
  -H "Authorization: Bearer $TOKEN" | jq .
```

Expected: `{"data":{"image_url":"https://cdn.thegamesdb.net/images/original/boxart/..."}}`.

- [ ] **Step 3: Test upstream_auth_failed by using a deliberately-wrong API key**

```bash
# Temporarily set a bogus key in the test user's settings
mysql -u"$DB_USER" -p"$DB_PASS" -D"$DB_NAME" -e \
  "INSERT INTO settings (user_id, setting_key, setting_value) \
   VALUES ((SELECT id FROM users WHERE username='$TEST_USER'), 'thegamesdb_api_key', 'INVALID_KEY_FOR_TEST') \
   ON DUPLICATE KEY UPDATE setting_value='INVALID_KEY_FOR_TEST'"

# With env var unset, retry
unset THEGAMESDB_API_KEY  # if it was set
curl -sS "http://localhost:8000/api/v2/cover-image.php?title=Halo%203" \
  -H "Authorization: Bearer $TOKEN" | jq .
```

Expected: `{"error":"upstream_auth_failed","message":"TheGamesDB rejected the API key"}` with HTTP 502.

Restore the good key or delete the settings row after.

- [ ] **Step 4: No commit — this is verification only.**

---

### Task 9: Wire test_cover_image.sh into run-all.sh if needed

- [ ] **Step 1: Same as Task 4, Step 2 — add to explicit list only if run-all.sh doesn't use a glob.**

- [ ] **Step 2: Commit if edited.**

---

### Task 10: Push branch and open PR #2

- [ ] **Step 1: Push**

```bash
git push -u origin phase-5-03-v2-cover-image
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(v2): add api/v2/cover-image.php" --body "$(cat <<'EOF'
## Summary
- Adds \`api/v2/cover-image.php\`. Ports TheGamesDB search from v1's \`api/cover-image.php\` into v2's response envelope.
- Distinct error codes (\`bad_request\` / \`api_key_missing\` / \`not_found\` / \`no_boxart\` / \`upstream_auth_failed\`) with proper HTTP statuses.
- Uses dual-auth from PR #1 (Bearer or session+CSRF, GET so no CSRF needed in practice).

Depends on: PR #1 (dual-auth in \`v2_require_auth()\`) already merged.

## Test plan
- [x] \`bash tests/v2/test_cover_image.sh\` → 5 passed (Case 5 exercises api_key_missing OR not_found depending on env)
- [x] Manual: \`curl\` a known-good title → 200 with image_url
- [x] Manual: bogus API key → 502 upstream_auth_failed
- [x] \`bash tests/v2/run-all.sh\` → no regressions
- [ ] Deploy: \`git pull\` on prod VM

## Design
docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Deploy: `git pull` on prod after merge.**

---

## PR #3 — Browser caller switch (`game-form.js` → v2) + `apiV2Get` helper

**Branch:** `phase-5-04-cover-image-caller-switch` (from `main` after PR #2 merges)

**Per-feature checkpoint here** (per `feedback_per_feature_checkpoints.md`): after merging PR #3 and deploying, pause for browser eye-on-glass QA before starting PR #4.

### Task 11: Add `apiV2Get` + `V2ApiError` to `js/api.js`

**Files:**
- Modify: `js/api.js`

- [ ] **Step 1: Add the new class and helper at the end of `js/api.js`**

Append (do not replace existing content):

```javascript
/**
 * Error thrown by apiV2* helpers when the v2 endpoint returns
 * { error: <code>, message: <...> } or a non-2xx status.
 *
 * Callers can distinguish user-visible failure modes by inspecting
 * .code (e.g. 'not_found', 'api_key_missing', 'bad_request').
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
 * Throws V2ApiError on { error, message } bodies or non-2xx status.
 * Sends the browser's HttpOnly session cookie (same-origin default).
 * No CSRF header needed on GETs.
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
```

- [ ] **Step 2: Sanity check — no syntax errors**

Run: `node -c js/api.js` (if node is available) OR open a browser dev-tools console after loading a page and check no errors.

Alternative: `php -r 'echo file_get_contents("js/api.js");' | node --check`

- [ ] **Step 3: Commit**

```bash
git add js/api.js
git commit -m "feat(web): add apiV2Get + V2ApiError to js/api.js

Enables browser callers to hit /api/v2/* endpoints and unwrap the
{data} envelope, with V2ApiError carrying the .code slug on failure.
First consumer: js/forms/game-form.js in the next commit.

Same-origin cookie is sent automatically (HttpOnly session cookie —
the browser handles storage). No X-CSRF-Token added on GET (matches
existing apiRequest behavior).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Switch `game-form.js` cover-fetch to v2

**Files:**
- Modify: `js/forms/game-form.js` (around lines 220–266)

- [ ] **Step 1: Read the current handler**

Run: `sed -n '210,270p' js/forms/game-form.js`

Confirm the fetch call at line ~234 is the "Auto-fetch Cover" click handler.

- [ ] **Step 2: Replace the handler body**

Find this block (approximately lines 220–266):

```javascript
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;
            
            if (!title) {
                showNotification('Please enter a game title first', 'error');
                return;
            }
            
            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';
            
            try {
                const url = `api/cover-image.php?title=${encodeURIComponent(title)}&platform=${encodeURIComponent(platform || '')}`;
                
                const response = await fetch(url);
                
                if (!response.ok) {
                    const text = await response.text();
                    console.error('HTTP error response:', text);
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (data.success && data.image_url) {
                    // Store the URL directly instead of downloading
                    const previewId = 'addFrontCoverPreview';
                    document.getElementById(previewId).innerHTML = 
                        `<img src="${data.image_url}" alt="Cover" style="max-width: 200px;">`;
                    
                    window.addGameFrontCover = data.image_url;
                    showNotification('Cover image URL fetched!', 'success');
                } else {
                    console.warn('No image URL in response:', data);
                    showNotification(data.message || 'Could not find cover image automatically. TheGamesDB API may be unavailable. Please upload manually.', 'error');
                }
            } catch (error) {
                console.error('Error fetching cover:', error);
                showNotification('Error fetching cover image. Please check your internet connection or upload manually.', 'error');
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Auto-fetch Cover';
            }
        });
    }
```

Replace with:

```javascript
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async function() {
            const title = document.getElementById('addTitle').value;
            const platform = document.getElementById('addPlatform').value;

            if (!title) {
                showNotification('Please enter a game title first', 'error');
                return;
            }

            fetchBtn.disabled = true;
            fetchBtn.textContent = 'Fetching...';

            try {
                const path = `cover-image.php?title=${encodeURIComponent(title)}` +
                             `&platform=${encodeURIComponent(platform || '')}`;
                const result = await apiV2Get(path);

                document.getElementById('addFrontCoverPreview').innerHTML =
                    `<img src="${result.image_url}" alt="Cover" style="max-width: 200px;">`;
                window.addGameFrontCover = result.image_url;
                showNotification('Cover image URL fetched!', 'success');
            } catch (err) {
                if (err instanceof V2ApiError) {
                    const msg = (err.code === 'not_found' || err.code === 'no_boxart')
                        ? 'Could not find cover image automatically. Please upload manually.'
                        : err.code === 'api_key_missing'
                            ? 'TheGamesDB API key not configured. Add it in Settings.'
                            : err.code === 'upstream_auth_failed'
                                ? 'TheGamesDB rejected the API key. Please check it in Settings.'
                                : err.message;
                    showNotification(msg, 'error');
                } else {
                    showNotification('Error fetching cover image. Please check your connection or upload manually.', 'error');
                }
            } finally {
                fetchBtn.disabled = false;
                fetchBtn.textContent = 'Auto-fetch Cover';
            }
        });
    }
```

Key differences:
- Path is passed to `apiV2Get` (which prepends `/api/v2/`); no leading `/api/v2/` in the call.
- Success path reads `result.image_url` directly (unwrapped by `apiV2Get`).
- Failure path maps `V2ApiError.code` → user-facing message.
- `window.addGameFrontCover` still gets the URL (behavior preserved for downstream `downloadAndUploadCover()`).

- [ ] **Step 3: Load order — verify `api.js` loads before `game-form.js`**

Run:

```bash
grep -n "api.js\|game-form.js" *.php includes/footer.php 2>/dev/null | head -20
```

Expected: `api.js` script tag appears before `game-form.js` (or is in a lower position number). If not, the load order is wrong and `V2ApiError`/`apiV2Get` would be `undefined` at click time. Fix by moving the script tags in the relevant `.php` files.

If they're both loaded on the add-game page (probably `dashboard.php` or wherever the add form lives), confirm ordering. If both go through Vite (Phase 4e), the module system handles it — but this project doesn't use imports for these files yet.

- [ ] **Step 4: Manual smoke test in the browser**

1. Start the dev server: `php -S localhost:8000 -t .`
2. Open `http://localhost:8000/index.php`, log in as `testuser` / `test_password`
3. Navigate to Add Game (dashboard "Add" button or wherever the form lives)
4. Enter title "Halo 3", platform "Xbox 360", click **Auto-fetch Cover**

Expected: cover image preview appears; success toast.

5. Enter title "definitely_not_a_real_game", click **Auto-fetch Cover**

Expected: "Could not find cover image automatically. Please upload manually." toast.

6. Open browser DevTools → Network. Verify the request URL is `/api/v2/cover-image.php?title=...` (not `/api/cover-image.php`).

7. Verify no `V2ApiError is not defined` in the DevTools console.

- [ ] **Step 5: Commit**

```bash
git add js/forms/game-form.js
git commit -m "feat(web): game-form 'Auto-fetch Cover' now calls v2

Swaps the raw fetch('api/cover-image.php...') for
apiV2Get('cover-image.php...'). Uses V2ApiError.code to map
distinct failure modes (not_found / api_key_missing /
upstream_auth_failed) to user-visible messages.

Behavior preserved:
- Success sets window.addGameFrontCover for downstream
  downloadAndUploadCover() to consume.
- Not-found + generic-network-error toasts match the previous
  wording.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Manual QA checklist before merging PR #3

Per the design's testing section and `feedback_per_feature_checkpoints.md`, PR #3 gets an eye-on-glass QA pass before merging.

- [ ] **Step 1: Golden path** — Auto-fetch Cover on a known-matching game → cover appears, success toast, Network tab shows `/api/v2/cover-image.php`.

- [ ] **Step 2: Not-found path** — Made-up title → "Could not find" toast, no console errors.

- [ ] **Step 3: API-key-missing path** (optional if easily reproducible) — Temporarily clear the per-user API key in the settings row, retry a valid title → "TheGamesDB API key not configured" toast.

- [ ] **Step 4: Bad-API-key path** — Set a bogus API key, retry → "TheGamesDB rejected the API key" toast.

- [ ] **Step 5: Downstream continuity** — After a successful Auto-fetch Cover, submit the add-game form. Confirm the game saves with the cover image URL (i.e. `window.addGameFrontCover` propagated through the existing submit path).

- [ ] **Step 6: iOS regression check** — Launch the iOS app, sync, verify no errors.

Any failure here → fix + rerun before pushing.

---

### Task 14: Push branch and open PR #3

- [ ] **Step 1: Push**

```bash
git push -u origin phase-5-04-cover-image-caller-switch
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "feat(web): switch Auto-fetch Cover to /api/v2/" --body "$(cat <<'EOF'
## Summary
- Adds \`apiV2Get\` + \`V2ApiError\` to \`js/api.js\`.
- Switches the "Auto-fetch Cover" handler in \`js/forms/game-form.js\` from \`api/cover-image.php\` (v1) to \`api/v2/cover-image.php\`.
- Maps \`V2ApiError.code\` → user-facing toast messages (not_found / api_key_missing / upstream_auth_failed).

Depends on: PR #2 (\`api/v2/cover-image.php\`) merged and deployed.

## Test plan
- [x] Manual: golden path (known title) → cover appears
- [x] Manual: not_found path → "Could not find" toast
- [x] Manual: api_key_missing path (via settings row clear)
- [x] Manual: upstream_auth_failed (bogus key)
- [x] Manual: submit form after Auto-fetch → game saves with cover
- [x] Manual: iOS app still syncs
- [x] DevTools Network confirms /api/v2/cover-image.php
- [ ] Deploy: \`git pull\` on prod

## Per-feature checkpoint
Per feedback_per_feature_checkpoints.md, pause here for eye-on-glass QA on prod before starting PR #4 (v1 deletion).

## Design
docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Deploy: `git pull` on prod. Then repeat Task 13's manual QA against the prod site before starting PR #4.**

---

## PR #4 — Delete api/cover-image.php

**Branch:** `phase-5-05-delete-v1-cover-image` (from `main` after PR #3 merges + prod QA passes)

### Task 15: Verify no callers remain

- [ ] **Step 1: Grep for any remaining references**

```bash
grep -rn "api/cover-image\|cover-image\.php" \
  --include="*.php" \
  --include="*.js" \
  --include="*.html" \
  --include="*.md" \
  --include="*.sh" \
  --exclude-dir=.git \
  --exclude-dir=node_modules \
  .
```

Expected results (each acceptable):
- `api/cover-image.php` — the file itself, about to be deleted
- `SETUP-GUIDE.md` — legacy documentation, will be updated in this PR
- `docs/superpowers/**/*.md` — this plan, the design doc, and Phase 4 roadmap. Fine to leave.

Unacceptable (must be fixed before deletion):
- Any `*.js` or `*.php` (outside `api/cover-image.php` itself) that still references the path

- [ ] **Step 2: If any surprises appear, fix them first and STOP** — do not delete until the grep is clean of active-code references.

---

### Task 16: Delete v1 endpoint and update docs

**Files:**
- Delete: `api/cover-image.php`
- Modify: `SETUP-GUIDE.md` (if it mentions `cover-image.php`)

- [ ] **Step 1: Delete the file**

```bash
git rm api/cover-image.php
```

- [ ] **Step 2: Update `SETUP-GUIDE.md` if it references the endpoint**

```bash
grep -n "cover-image" SETUP-GUIDE.md
```

If the grep hits, open the file and delete the referring line(s). If no hits, skip.

- [ ] **Step 3: Add a regression test that the v1 URL is gone**

Append to `tests/v2/test_cover_image.sh` (before `summarize`):

```bash
# --- Regression: v1 URL should now 404 (server 404, file removed in Phase 5)
blue "Regression: v1 /api/cover-image.php returns 404"
req GET "/api/cover-image.php?title=Halo"
assert_eq "404" "$HTTP_STATUS" "v1 URL is gone"
```

- [ ] **Step 4: Run the test**

Run: `bash tests/v2/test_cover_image.sh`

Expected: all existing cases still pass, plus the new regression case.

- [ ] **Step 5: Run the full v2 suite**

Run: `bash tests/v2/run-all.sh`

Expected: no regressions.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore(api): delete api/cover-image.php (phase 5/02)

Second v1 endpoint retired in Phase 5. Web caller migrated to
api/v2/cover-image.php in the prior PR; grep confirms zero
remaining code references.

Also adds a regression test to tests/v2/test_cover_image.sh
that /api/cover-image.php returns 404.

Related: Fable §2 ('v2 as the one true API'), Phase 5/01
(#70, download-external-image.php).

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

### Task 17: Push branch and open PR #4

- [ ] **Step 1: Push**

```bash
git push -u origin phase-5-05-delete-v1-cover-image
```

- [ ] **Step 2: Open PR**

```bash
gh pr create --title "chore(api): delete api/cover-image.php (phase 5/02)" --body "$(cat <<'EOF'
## Summary
- \`git rm api/cover-image.php\`. The browser caller was switched to \`/api/v2/cover-image.php\` in the prior PR; grep confirms zero remaining code references.
- Regression test added to \`tests/v2/test_cover_image.sh\` confirming the v1 URL now 404s.
- Updates \`SETUP-GUIDE.md\` if it referenced the endpoint.

Second v1 endpoint retired in Phase 5. Related: #70 (Phase 5/01, download-external-image.php).

## Test plan
- [x] \`grep -rn 'api/cover-image\|cover-image.php'\` → only docs/spec hits remain
- [x] \`bash tests/v2/test_cover_image.sh\` → passes including new v1-404 regression case
- [x] \`bash tests/v2/run-all.sh\` → no regressions
- [x] Manual: Auto-fetch Cover still works (v2 endpoint from PR #3)
- [ ] Deploy: \`git pull\` on prod

## Design
docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 3: Deploy: `git pull` on prod. Verify Auto-fetch Cover still works one last time.**

---

## Post-merge follow-ups (out of this plan's scope, worth noting)

- Update Obsidian vault: create `gameTracker Phase 5b - Cover-image v2 migration.md` per `reference_vault_gametracker.md` convention. Include a mermaid diagram of the 4-PR sequence and per-PR status. Only after all four PRs merge.
- Next v1 endpoint candidate: pick from the remaining set. `api/download-cover.php` is a natural pair (used by `game-form.js` right after cover-image, has SSRF-safe fetch already in v2 via `api/v2/external-image.php`).
- If additional web callers of v2 mutations land, add `apiV2PostJson` / `apiV2PostForm` to `js/api.js` — YAGNI right now.
