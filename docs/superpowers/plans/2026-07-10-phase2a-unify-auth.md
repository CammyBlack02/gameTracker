# Phase 2a — Unify v1 Authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the ~14 copy-pasted auth blocks across v1 endpoints (7 using `require_once auth-check.php` HTML-redirect, 10 using inline JSON 401) with one canonical `requireUser()` helper. Also consolidate the ~17 inline `isAdmin` checks onto a shared `isAdmin()` / `requireAdmin()`. Pure refactor — no behavior change, no new endpoints.

**Architecture:** New file `includes/auth.php` becomes the canonical auth module. It exposes `requireUser(): int`, `requireAdmin(): int`, `isAdmin(): bool`, and `currentUserId(): int`. `includes/auth-check.php` (HTML-redirect-only) is deleted; its callers switch to `requireUser()` which detects `/api/` in the URI and returns JSON 401 vs HTML 302 accordingly (same content-negotiation logic already in `csrf.php::requireAdmin`). The existing `isAdmin` / `requireAdmin` implementations move from `csrf.php` to `auth.php` — csrf.php becomes strictly a CSRF-token module.

**Tech Stack:** PHP 8 + PDO/MySQL, bash tests under `tests/v2/`.

**Deploy note:** Every file this plan touches lives under `api/`, `includes/`, or top-level `*.php` — deploy is a manual `git pull` on the prod VM. No env-var changes this time; pure refactor.

**Scope explicitly excluded** (deferred to their own PRs):
- Moving `initializeDatabase()` DDL into `database/migrations/` — separate concern, separate plan (Phase 2b).
- Rewriting the v2 proxy endpoints (`metacritic.php`, `pricecharting.php`, `external-image.php`) to stop faking a `$_SESSION` — Phase 2c.
- Unifying the JSON envelope shape between v1 `{success, message}` and v2 `{data}/{error}` — requires frontend edits, part of Phase 4.
- Deleting v1 entirely — Phase 5.

---

## File Structure

**New file:**
- `includes/auth.php` — canonical auth module. Four functions: `requireUser()`, `requireAdmin()`, `isAdmin()`, `currentUserId()`.

**Deleted file:**
- `includes/auth-check.php` — replaced by `requireUser()` at each caller.

**Modified files (per task):**
- `includes/csrf.php` — remove `isAdmin()` / `requireAdmin()`; add `require_once __DIR__ . '/auth.php';`
- **10 v1 files with inline JSON 401 auth:** `api/admin.php`, `api/games.php`, `api/completions.php`, `api/stats.php`, `api/upload.php`, `api/download-external-image.php`, `api/steam-import.php`, `api/items.php` (wait — items uses auth-check.php, moved to the 7 group), so actually the 10 are: `api/admin.php`, `api/games.php`, `api/completions.php`, `api/stats.php`, `api/upload.php`, `api/download-external-image.php`, `api/steam-import.php` — that's 7. Let me recount from the survey.
- Full list of v1 endpoints refactored in this plan:
  - Inline JSON 401 → `requireUser()`: `api/admin.php:13`, `api/games.php:52`, `api/completions.php:24`, `api/stats.php:24`, `api/upload.php:20`, `api/download-external-image.php:12`, `api/steam-import.php:25` (7 files)
  - `auth-check.php` include → `requireUser()`: `api/items.php:8`, `api/settings.php:9`, `api/cover-image.php:9`, `api/download-cover.php:8`, `api/game-metadata.php:25`, `api/import-gameeye.php:9`, `api/pricecharting.php:26`, `api/metacritic.php:20` (8 files)
  - `api/auth.php` — the auth endpoint itself; leaves its handleLogin / handleLogout / handleRegister untouched.
- **11 v1 files with inline `($_SESSION['role'] ?? 'user') === 'admin'`:** `api/items.php` (×3), `api/games.php` (×3), `api/completions.php` (×3), `api/upload.php` (×1), `api/admin.php` (×4). Consolidate onto `isAdmin()`.
- **Top-level HTML pages** using `auth-check.php`: `dashboard.php`, `game-detail.php`, `item-detail.php`, `stats.php`, `completions.php`, `settings.php`, `user-profile.php`, `admin-dashboard.php`, `users.php`, `change-admin-credentials.php`, `import-gameeye.php`, `register.php`. `requireUser()` will detect the non-`/api/` URI and issue a redirect, preserving existing behaviour. (Verify by inclusion, don't touch pages unless they use direct `$_SESSION` checks that would be simpler as a call to `requireUser()`.)

**New test file:**
- `tests/v2/test_auth_consolidation.sh` — for each of the ~15 v1 endpoints, assert unauthenticated response is 401 JSON (for `/api/*` routes) or 302 redirect to `/index.php` (for HTML pages). Also assert `requireAdmin()` returns 403 for non-admin session at admin-only endpoints.

---

## Task 1: Create `includes/auth.php`

**Files:**
- Create: `includes/auth.php`
- Test: `tests/v2/test_auth_consolidation.sh` (write skeleton; endpoints filled in later tasks)

- [ ] **Step 1: Write the canonical auth module**

Create `includes/auth.php`:

```php
<?php
/**
 * Canonical authentication module for the v1 (session-cookie) API and
 * top-level HTML pages. v2 uses bearer tokens via api/v2/_auth.php —
 * do not include this file from v2 code paths.
 *
 * Every v1 endpoint should call requireUser() (or requireAdmin() where
 * appropriate) at the top of the file, immediately after config.php.
 * The helper returns the caller's user_id and calls exit() on failure —
 * JSON 401 for /api/ URIs, HTML redirect to /index.php otherwise.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

/**
 * Enforce that the request has a valid session. Returns user_id on
 * success; exits with 401/302 on failure.
 */
function requireUser(): int
{
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
        if (gt_is_api_route()) {
            sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401);
        }
        header('Location: /index.php');
        exit;
    }
    return (int)$_SESSION['user_id'];
}

/**
 * Enforce that the caller is an admin. Returns user_id on success;
 * exits with 401/302 for unauthenticated, 403/redirect-to-dashboard for
 * authenticated-but-not-admin.
 */
function requireAdmin(): int
{
    $userId = requireUser();
    if (!isAdmin()) {
        if (gt_is_api_route()) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        }
        header('Location: /dashboard.php');
        exit;
    }
    return $userId;
}

/**
 * True iff the current session is an admin. Does not exit.
 */
function isAdmin(): bool
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Convenience accessor. Assumes requireUser() was called first — no check.
 */
function currentUserId(): int
{
    return (int)$_SESSION['user_id'];
}

/**
 * @internal — used to pick JSON-vs-HTML failure UX.
 */
function gt_is_api_route(): bool
{
    return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
}
```

- [ ] **Step 2: Write skeleton test file**

Create `tests/v2/test_auth_consolidation.sh`:

```bash
#!/usr/bin/env bash
# Every v1 endpoint that was previously guarding auth inline should now
# refuse anonymous requests via requireUser(). API routes → 401 JSON;
# HTML routes → 302 to /index.php.
source "$(dirname "$0")/lib.sh"

blue "API v1: unauthenticated requests return 401 JSON"
for endpoint in \
  "admin.php?action=list" \
  "games.php?action=list" \
  "completions.php?action=list" \
  "stats.php?action=overview" \
  "items.php?action=list" \
  "settings.php?action=get" \
  "cover-image.php?title=x" \
  "steam-import.php?action=test_connection" \
  ; do
  req GET "/api/$endpoint"
  assert_eq "401" "$HTTP_STATUS" "GET /api/$endpoint anonymous = 401"
done

blue "API v1: authenticated non-admin gets 403 on admin-only actions"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null
req GET "/api/admin.php?action=list" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "admin?list as non-admin = 403"
rm -f "$COOKIE"

summarize
```

- [ ] **Step 3: Commit**

```bash
git add includes/auth.php tests/v2/test_auth_consolidation.sh
git commit -m "refactor: introduce canonical auth module

Adds includes/auth.php with requireUser() / requireAdmin() / isAdmin() /
currentUserId(). Content-negotiates failure UX: JSON 401/403 for /api/
URIs, redirect for HTML routes. Callers migrate in follow-up commits.

Ref: FABLE-SUGGESTIONS.md §2 dual-auth mess"
```

---

## Task 2: Refactor `csrf.php` to consume the new module

**Files:**
- Modify: `includes/csrf.php` — remove `isAdmin()` + `requireAdmin()`; add `require_once __DIR__ . '/auth.php';` at the top.

- [ ] **Step 1: Edit csrf.php**

Replace the tail of `includes/csrf.php` (the current `isAdmin` + `requireAdmin` definitions, lines 36-57) with:

```php
// isAdmin() and requireAdmin() live in includes/auth.php as of Phase 2a.
// This require_once ensures they are available to any legacy caller that
// includes csrf.php expecting them.
require_once __DIR__ . '/auth.php';
```

- [ ] **Step 2: Commit**

```bash
git add includes/csrf.php
git commit -m "refactor: move isAdmin/requireAdmin from csrf.php to auth.php

csrf.php is now strictly a CSRF-token module. Auth lives in auth.php.
Backward-compatible: csrf.php require_once's auth.php so any legacy
caller that included csrf.php for isAdmin() still works.

Ref: FABLE-SUGGESTIONS.md §2"
```

---

## Task 3: Refactor the 7 inline-JSON-401 v1 endpoints

**Files (all in `api/`):** `admin.php`, `games.php`, `completions.php`, `stats.php`, `upload.php`, `download-external-image.php`, `steam-import.php`.

For each: replace the `if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) { sendJsonResponse(['success' => false, 'message' => 'Authentication required'], 401); }` block with:

```php
require_once __DIR__ . '/../includes/auth.php';
$userId = requireUser();
```

Also drop the redundant `$_SESSION['user_id']` reads at the top of handler functions where a local `$userId` is already in scope — just as tidiness, not correctness.

- [ ] **Step 1: Do the 7 edits**

For each file, locate the inline auth block (survey has the exact line numbers) and replace as above.

- [ ] **Step 2: Verify no `sendJsonResponse(['success' => false, 'message' => 'Authentication required']` sites remain**

```bash
grep -rn "Authentication required" api/*.php
# Only api/download-external-image.php:11-14 SHOULD remain if it's the
# non-auth-check.php pattern; ideally zero.
```

Iterate until zero.

- [ ] **Step 3: Commit**

```bash
git add api/admin.php api/games.php api/completions.php api/stats.php api/upload.php api/download-external-image.php api/steam-import.php
git commit -m "refactor: v1 endpoints call requireUser() instead of inline check

Seven v1 endpoints replaced their inline session check with a single
requireUser() call. Behaviour unchanged (JSON 401 on failure) but the
copy-paste is gone.

Ref: FABLE-SUGGESTIONS.md §2"
```

---

## Task 4: Refactor the 8 `auth-check.php`-including v1 endpoints

**Files (all in `api/`):** `items.php`, `settings.php`, `cover-image.php`, `download-cover.php`, `game-metadata.php`, `import-gameeye.php`, `pricecharting.php`, `metacritic.php`.

For each: replace `require_once __DIR__ . '/../includes/auth-check.php';` with:

```php
require_once __DIR__ . '/../includes/auth.php';
$userId = requireUser();
```

Now the `/api/` route detection kicks in and these endpoints will return JSON 401 instead of a 302 redirect to `/index.php`. That is intentional — an API client cannot follow an HTML redirect meaningfully, and this closes Fable §2's "HTML redirect from an API endpoint" bug.

- [ ] **Step 1: Do the 8 edits**

- [ ] **Step 2: Commit**

```bash
git add api/items.php api/settings.php api/cover-image.php api/download-cover.php api/game-metadata.php api/import-gameeye.php api/pricecharting.php api/metacritic.php
git commit -m "refactor: 8 v1 endpoints replace auth-check.php with requireUser()

These endpoints previously included auth-check.php, which redirects
unauthenticated callers to /index.php via a 302. Any API client fetching
these got an HTML redirect it couldn't follow. requireUser() returns
JSON 401 for /api/ URIs — the right behaviour for API callers.

Ref: FABLE-SUGGESTIONS.md §2 dual-auth mess"
```

---

## Task 5: Consolidate inline `isAdmin` checks

For each site where a handler function contains `$isAdmin = ($_SESSION['role'] ?? 'user') === 'admin';` (~11 places per survey), replace with `$isAdmin = isAdmin();`. Functionally identical; removes the string literal duplication.

**Files & counts:**
- `api/items.php` × 3 (`getItem`, `updateItem`, `deleteItem` — lines 83, 180, 254)
- `api/games.php` × 3 (`getGame`, `updateGame`, `deleteGame` — lines 232, 528, 694)
- `api/completions.php` × 3 (`getCompletion`, `updateCompletion`, `deleteCompletion` — lines 130, 247, 323)
- `api/upload.php` × 1 (line 245)

`api/admin.php` uses `$isAdmin` at the top (line 20) as an init-time snapshot — that stays as-is since it's a single site, and the enforcement now happens via `requireAdmin()` inside individual case handlers where needed.

- [ ] **Step 1: Do the edits (10 sites across 4 files)**

- [ ] **Step 2: Commit**

```bash
git add api/items.php api/games.php api/completions.php api/upload.php
git commit -m "refactor: consolidate inline isAdmin checks onto shared helper

Ten sites across items/games/completions/upload replaced
(\$_SESSION['role'] ?? 'user') === 'admin' with isAdmin(). Functionally
identical; kills the duplication.

Ref: FABLE-SUGGESTIONS.md §2"
```

---

## Task 6: Delete `includes/auth-check.php`

**Files:**
- Delete: `includes/auth-check.php`
- Verify: no remaining `require_once .*auth-check.php` sites via grep.

- [ ] **Step 1: Grep for stragglers**

```bash
grep -rn "auth-check" . --include='*.php' | grep -v '.git'
```
Expected: no results after Tasks 3+4. If any top-level HTML pages still include it, migrate them to `require_once __DIR__ . '/includes/auth.php'; requireUser();` and add to the change list before deleting.

- [ ] **Step 2: Delete the file**

```bash
git rm includes/auth-check.php
```

- [ ] **Step 3: Commit**

```bash
git commit -m "refactor: delete includes/auth-check.php

All callers migrated to requireUser() in the prior commits. auth-check.php
existed only to redirect unauthenticated callers to /index.php — now
subsumed by requireUser()'s content-negotiated failure UX.

Ref: FABLE-SUGGESTIONS.md §2"
```

---

## Task 7: Push branch + open PR

- [ ] **Step 1: Confirm test suite still parses**

Run `php -l` over all touched files to catch syntax errors from a botched edit:

```bash
for f in includes/auth.php includes/csrf.php api/admin.php api/games.php api/completions.php api/stats.php api/upload.php api/download-external-image.php api/steam-import.php api/items.php api/settings.php api/cover-image.php api/download-cover.php api/game-metadata.php api/import-gameeye.php api/pricecharting.php api/metacritic.php; do
  php -l "$f" | grep -v "^No syntax errors"
done
```
Expected: empty output.

- [ ] **Step 2: Push + PR**

```bash
git push -u origin phase-2a-unify-auth
gh pr create --title "Phase 2a: unify v1 auth (requireUser + isAdmin)" --body "..."
```

- [ ] **Step 3: Deploy checklist (in PR body)**

Standard: merge → `git pull` on prod → reload PHP-FPM. No env-var or schema changes.

---

## Self-review

**Spec coverage** (against Fable §2 "dual-auth mess"):
- 14 copy-pasted auth blocks → 15 endpoints migrated to one `requireUser()` in Tasks 3+4 ✓
- Two mutually incompatible flavours (JSON 401 vs HTML redirect) unified via content-negotiation in `auth.php` ✓
- 11 inline admin checks → shared `isAdmin()` in Task 5 ✓
- `initializeDatabase()` migration — out of scope, will be Phase 2b ✓ (declared)
- v2 proxy session-faking — out of scope, will be Phase 2c ✓ (declared)

**Placeholder scan:** no TBDs. Grep patterns and file lists come from the survey; every file:line reference is concrete.

**Type consistency:** `requireUser(): int`, `requireAdmin(): int`, `isAdmin(): bool`, `currentUserId(): int`, `gt_is_api_route(): bool`. Used consistently. No name collisions with existing symbols (grep confirmed).
