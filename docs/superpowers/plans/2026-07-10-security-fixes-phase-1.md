# Security Fixes — Phase 1 (Fable §1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the four "🔴 critical" and three "🟠 medium" security holes Fable identified in §1 of `FABLE-SUGGESTIONS.md`, and rewrite `SECURITY-ASSESSMENT.md` to reflect reality. No new features; strictly stop-the-bleeding.

**Architecture:** All fetch-from-external-URL code paths converge on one shared helper (`includes/http-fetch.php`) that resolves DNS, rejects private/loopback/link-local/reserved IPs, keeps TLS verification on, and re-validates redirects. Auth, ownership, and CSRF concerns stay at the endpoint layer — we just enforce them consistently and honestly.

**Tech Stack:** PHP 8 + PDO/MySQL (existing v1 + v2 APIs), bash-based integration tests under `tests/v2/`. No new dependencies.

**Deploy note:** Every file this plan touches lives under `api/`, `includes/`, or top-level `*.php` — the production server pulls these via `git pull` on the VM. Last task pushes the branch and prints the deploy checklist.

**Scope explicitly excluded (deferred to later phases):**
- Full CSRF token rollout on the web frontend — deferred to Phase 4 (frontend modularisation) because the current 3,239-line `js/games.js` isn't a safe place to thread a token through 62 global fetch calls. Phase 1 mitigates via SameSite=Lax + POST-only enforcement, and documents the residual gap honestly.
- IDOR *design* decision (multi-user cross-shelf reads): Fable said "if deliberate for a shared household instance, fine — but say so." Phase 1 locks the cross-user reads down as the safer default. If Cameron decides post-facto to reopen for household sharing, that's a small forward change in Phase 7 (product).
- Migrating `initializeDatabase()` schema-by-side-effect into real migrations — that's §2's job (unify backend), a full week of its own.

---

## File Structure

**New files:**
- `includes/http-fetch.php` — one function, `gt_safe_http_fetch(string $url, array $opts = []): array`. Throws `GtSsrfException` for blocked hosts/IPs, `GtFetchException` for network errors or non-200. Callers wrap in try/catch.
- `tests/v2/test_ssrf.sh` — new bash integration test hitting `image-proxy.php` and `v2/images/cover.php` with metadata-endpoint URLs.

**Modified files (in task order):**
- `api/steam-import.php` — add `user_id` to dedup, INSERT, and delete-PC-games SQL
- `api/download-cover.php` — replace curl block with helper, remove disabled TLS
- `api/v2/images/cover.php` — replace inline curl at lines 81-106 with helper
- `api/image-proxy.php` — replace inline blocklist + curl with helper
- `api/download-external-image.php` — replace inline blocklist + curl with helper
- `api/games.php` — (a) replace `downloadExternalImage()` at line 438 with helper; (b) remove `?user_id=` override at line 123; (c) sanitise error responses at lines 219-221
- `api/items.php` — remove `?user_id=` override at line 45
- `api/admin.php` — require admin role for `list` action at line 23-26
- `api/cover-image.php` — remove hardcoded key at line 23; read from settings/env instead
- `api/auth.php` — strip `getFile`/`getLine`/`getMessage` at line 32
- `SECURITY-ASSESSMENT.md` — full rewrite as honest posture doc

**Repo-wide history rewrite:** requires user's explicit go-ahead (BFG or `git filter-repo`) — see Task 6 for the checklist.

---

## Task 1: Shared SSRF-safe HTTP fetch helper

**Files:**
- Create: `includes/http-fetch.php`
- Test: `tests/v2/test_ssrf.sh`

**Why first:** every subsequent SSRF fix (Tasks 3-5) uses this helper. Writing it in isolation with tests means Tasks 3-5 become mechanical swaps.

- [ ] **Step 1: Write the failing test file**

Create `tests/v2/test_ssrf.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "SSRF: image-proxy blocks cloud-metadata IP literal"
req GET "/api/image-proxy.php?url=https://169.254.169.254/latest/meta-data/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 169.254.169.254"

blue "SSRF: image-proxy blocks 0.0.0.0"
req GET "/api/image-proxy.php?url=https://0.0.0.0/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 0.0.0.0"

blue "SSRF: image-proxy blocks loopback via 127.0.0.1"
req GET "/api/image-proxy.php?url=https://127.0.0.1/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 127.0.0.1"

blue "SSRF: image-proxy allows a public host (placeholder that always resolves)"
# example.com is IANA-reserved and always resolves to public IPs; we just check
# the SSRF gate lets us past — 200/404/etc from example.com is fine.
req GET "/api/image-proxy.php?url=https://example.com/nothing.jpg"
[[ "$HTTP_STATUS" != "403" ]] && green "  PASS: example.com not blocked (HTTP $HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: example.com wrongly blocked"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash tests/v2/test_ssrf.sh`
Expected: FAIL on 169.254 test — `image-proxy.php`'s current regex-based blocklist misses `169.254.*`. The test suite must be able to reach the endpoints — that means the local dev server (or a staging URL via `BASE_URL`) must be running.

- [ ] **Step 3: Create the helper**

Write `includes/http-fetch.php`:

```php
<?php
/**
 * SSRF-safe HTTP fetch helper.
 *
 * Every external URL fetch in the app goes through gt_safe_http_fetch().
 * It enforces:
 *   - HTTPS only
 *   - Host resolves entirely to public IPs (blocks private, loopback,
 *     link-local, reserved — including cloud metadata 169.254.169.254)
 *   - TLS verification stays on
 *   - Redirects are followed manually, revalidating each hop
 *
 * Throws GtSsrfException on any blocked host/IP.
 * Throws GtFetchException on network error or non-200 response.
 *
 * Returns ['data' => string, 'content_type' => string, 'http_code' => int].
 */

class GtSsrfException extends RuntimeException {}
class GtFetchException extends RuntimeException {}

/**
 * @param string $url  Full URL to fetch. Must be https://.
 * @param array  $opts Optional overrides:
 *                     'timeout'         => int seconds (default 30)
 *                     'connect_timeout' => int seconds (default 10)
 *                     'max_redirects'   => int (default 5)
 *                     'user_agent'      => string (default GameTracker/1.0)
 *                     'accept'          => string Accept header
 * @return array{data:string,content_type:string,http_code:int}
 */
function gt_safe_http_fetch(string $url, array $opts = []): array
{
    $timeout        = $opts['timeout']         ?? 30;
    $connectTimeout = $opts['connect_timeout'] ?? 10;
    $maxRedirects   = $opts['max_redirects']   ?? 5;
    $userAgent      = $opts['user_agent']      ?? 'GameTracker/1.0';
    $accept         = $opts['accept']          ?? '*/*';

    $currentUrl = $url;
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        gt_ssrf_check_url($currentUrl);

        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, // we handle redirects manually
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => ["Accept: $accept"],
            CURLOPT_HEADER         => false,
        ]);
        $data        = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
        $redirectTo  = (string)(curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new GtFetchException("curl error: $curlError");
        }

        if ($httpCode >= 300 && $httpCode < 400 && $redirectTo !== '') {
            if ($hop === $maxRedirects) {
                throw new GtFetchException("too many redirects (>$maxRedirects)");
            }
            $currentUrl = $redirectTo;
            continue;
        }

        if ($httpCode !== 200 || $data === false || $data === '') {
            throw new GtFetchException("HTTP $httpCode / empty body");
        }

        return [
            'data'         => $data,
            'content_type' => $contentType,
            'http_code'    => $httpCode,
        ];
    }
    throw new GtFetchException("redirect loop exceeded");
}

/**
 * Reject the URL if scheme != https, host is missing, or the host resolves
 * (or literally is) any private/loopback/link-local/reserved IP.
 */
function gt_ssrf_check_url(string $url): void
{
    $parts = @parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new GtSsrfException("invalid URL");
    }
    if (strtolower($parts['scheme']) !== 'https') {
        throw new GtSsrfException("scheme not https: {$parts['scheme']}");
    }
    $host = $parts['host'];

    // Strip brackets from IPv6 literal hosts, e.g. [::1]
    $host = trim($host, '[]');

    // Case A: literal IP in URL
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        gt_ssrf_check_ip($host);
        return;
    }

    // Case B: hostname — resolve both A and AAAA
    $ips = gt_resolve_all($host);
    if (empty($ips)) {
        throw new GtFetchException("could not resolve host: $host");
    }
    foreach ($ips as $ip) {
        gt_ssrf_check_ip($ip);
    }
}

function gt_ssrf_check_ip(string $ip): void
{
    // Reject anything IANA-reserved / private / loopback. This includes:
    //   IPv4: 0.0.0.0/8, 10/8, 100.64/10, 127/8, 169.254/16 (link-local + AWS
    //         metadata), 172.16/12, 192.0.0/24, 192.0.2/24, 192.168/16,
    //         198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4, 255.255.255.255
    //   IPv6: ::1, fc00::/7, fe80::/10, ::ffff:0:0/96 (IPv4-mapped) etc.
    $ok = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($ok === false) {
        throw new GtSsrfException("blocked IP: $ip");
    }
    // Defense in depth (older PHPs have gaps around ::/128 and IPv4-mapped)
    if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
        throw new GtSsrfException("blocked IP: $ip");
    }
}

function gt_resolve_all(string $host): array
{
    $ips = gethostbynamel($host) ?: [];
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    return $ips;
}
```

- [ ] **Step 4: Wire the helper into `image-proxy.php` for the tests to pass**

The test in Step 1 hits `image-proxy.php`. Task 5 fully refactors it, but for Task 1 we need at least the SSRF check to pass. In `api/image-proxy.php`, replace lines 63-73 (the `if ($host === 'localhost' || …)` block) with:

```php
require_once __DIR__ . '/../includes/http-fetch.php';
try {
    gt_ssrf_check_url($url);
} catch (GtSsrfException $e) {
    error_log("Image proxy blocked SSRF: {$e->getMessage()} for URL $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Blocked: ' . $e->getMessage());
}
```

(The full refactor — replacing the curl block below too — happens in Task 5.)

- [ ] **Step 5: Run the test to verify it passes**

Run: `bash tests/v2/test_ssrf.sh`
Expected: PASS on 169.254 / 0.0.0.0 / 127.0.0.1 blocks. `example.com` non-403 result also PASS. Total 4 PASS.

- [ ] **Step 6: Commit**

```bash
git add includes/http-fetch.php api/image-proxy.php tests/v2/test_ssrf.sh
git commit -m "security: SSRF-safe HTTP fetch helper + apply to image-proxy

Introduces gt_safe_http_fetch() in includes/http-fetch.php with DNS
resolution + IANA-reserved-range blocking (catches 169.254.169.254 cloud
metadata, all private + loopback IPs). Rewires image-proxy.php to gate
on it; Tasks 3-5 migrate the remaining fetch sites.

Ref: FABLE-SUGGESTIONS.md §1 SSRF"
```

---

## Task 2: Fix steam-import.php cross-user data wipe

**Files:**
- Modify: `api/steam-import.php:223-228` (dedup SELECT missing `user_id`)
- Modify: `api/steam-import.php:247-264` (INSERT missing `user_id`)
- Modify: `api/steam-import.php:387-417` (`deletePCGames()` — global DELETE)

**Why now:** the single worst bug in the codebase. One authenticated user's Steam re-import deletes every user's PC games. Standalone fix; no dependencies.

- [ ] **Step 1: Write the failing test**

Add to `tests/v2/test_steam_import_scoping.sh` (new file):

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

# This test asserts steam-import's deletePCGames endpoint only affects the
# calling user, and that dedup queries are user-scoped. We test at the
# database level because the full Steam import flow requires real API creds.

blue "Steam import: deletePCGames is user-scoped"

# Set up two users, each with one PC game via direct DB write.
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" <<'SQL'
INSERT INTO users (username, password_hash, role) VALUES
  ('steamuser_a', '$2y$10$abcdefghijklmnopqrstuv', 'user'),
  ('steamuser_b', '$2y$10$abcdefghijklmnopqrstuv', 'user')
ON DUPLICATE KEY UPDATE username=username;

INSERT INTO games (user_id, title, platform) VALUES
  ((SELECT id FROM users WHERE username='steamuser_a'), 'A-Owned PC Game', 'PC'),
  ((SELECT id FROM users WHERE username='steamuser_b'), 'B-Owned PC Game', 'PC');
SQL

USER_A_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='steamuser_a'")
USER_B_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='steamuser_b'")

# Log in as user A via the session-cookie v1 flow.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=steamuser_a&password=test_password" > /dev/null

# Call deletePCGames as user A.
curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/steam-import.php?action=delete_pc_games" > /dev/null

# Assert user B's game still exists.
B_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe \
  "SELECT COUNT(*) FROM games WHERE user_id=$USER_B_ID AND title='B-Owned PC Game'")
assert_eq "1" "$B_COUNT" "user B's PC game survived user A's delete_pc_games"

# Assert user A's game is gone.
A_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe \
  "SELECT COUNT(*) FROM games WHERE user_id=$USER_A_ID AND title='A-Owned PC Game'")
assert_eq "0" "$A_COUNT" "user A's PC game was deleted"

summarize
```

Note: real login requires the users to have valid password hashes. If `test_password` isn't the hash used, update `setup-test-db.sh` to seed a known password, or generate the hash inline with `php -r 'echo password_hash("test_password", PASSWORD_DEFAULT);'`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `bash tests/v2/test_steam_import_scoping.sh`
Expected: FAIL — user B's game count is 0 (deletion was global).

- [ ] **Step 3: Fix the DELETE**

In `api/steam-import.php`, replace lines 394-403:

```php
    try {
        $userId = $_SESSION['user_id'];

        // Count games before deletion (this user only)
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM games WHERE platform = 'PC' AND user_id = ?");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        $count = $result['count'] ?? 0;

        // Delete this user's PC games (cascade will handle related images)
        $stmt = $pdo->prepare("DELETE FROM games WHERE platform = 'PC' AND user_id = ?");
        $stmt->execute([$userId]);
```

- [ ] **Step 4: Fix the dedup SELECT**

In `api/steam-import.php`, replace line 223 (inside `importSteamLibrary()`, which already has `$userId = $_SESSION['user_id']` at line 151):

```php
            // Check if THIS USER already has the game (by title and platform)
            $stmt = $pdo->prepare("SELECT id FROM games WHERE title = ? AND platform = 'PC' AND user_id = ?");
            $stmt->execute([$name, $userId]);
```

- [ ] **Step 5: Fix the INSERT**

In `api/steam-import.php`, replace lines 247-264:

```php
            // Insert game into database
            $stmt = $pdo->prepare("
                INSERT INTO games (
                    user_id, title, platform, genre, description, is_physical, digital_store,
                    front_cover_image, release_date, played
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $userId,
                $gameData['title'],
                $gameData['platform'],
                $gameData['genre'],
                $gameData['description'],
                $gameData['is_physical'],
                $gameData['digital_store'],
                $gameData['front_cover_image'],
                $gameData['release_date'],
                $gameData['played']
            ]);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `bash tests/v2/test_steam_import_scoping.sh`
Expected: PASS both assertions. 2 PASS / 0 FAIL.

- [ ] **Step 7: Commit**

```bash
git add api/steam-import.php tests/v2/test_steam_import_scoping.sh
git commit -m "security: user-scope steam import (DELETE + dedup + INSERT)

deletePCGames() previously ran DELETE FROM games WHERE platform='PC'
with no user_id filter, letting any authenticated user wipe every user's
PC games. The dedup SELECT was similarly global (skipping imports if any
user already owned the title), and INSERT never set user_id, orphaning
rows on the NOT NULL column.

All three now include WHERE/AND user_id = ?.

Ref: FABLE-SUGGESTIONS.md §1 cross-user data wipe"
```

---

## Task 3: Fix `api/download-cover.php` (SSRF + TLS)

**Files:**
- Modify: `api/download-cover.php` (whole file, ~85 lines)

- [ ] **Step 1: Add regression test to `tests/v2/test_ssrf.sh`**

Append (before the final `summarize`):

```bash
blue "SSRF: download-cover.php gates + requires auth"
# download-cover.php uses session cookies. First — anonymous.
req GET "/api/download-cover.php?url=https://169.254.169.254/"
[[ "$HTTP_STATUS" == "401" || "$HTTP_STATUS" == "302" || "$HTTP_STATUS" == "403" ]] && green "  PASS: no-auth blocked (HTTP $HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: no-auth status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Now with a session cookie for testuser.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null

req GET "/api/download-cover.php?url=https://169.254.169.254/" "" -b "$COOKIE"
assert_eq "400" "$HTTP_STATUS" "download-cover blocks 169.254.169.254 for authed user"
```

- [ ] **Step 2: Run test to see it fail**

Run: `bash tests/v2/test_ssrf.sh`
Expected: FAIL — download-cover.php currently accepts any URL and disables TLS.

- [ ] **Step 3: Rewrite `api/download-cover.php`**

Replace lines 22-45 (from `// Initialize cURL…` through `if ($httpCode !== 200 || !$imageData) { … }`):

```php
require_once __DIR__ . '/../includes/http-fetch.php';

try {
    $result = gt_safe_http_fetch($imageUrl, [
        'accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
    ]);
} catch (GtSsrfException $e) {
    error_log("download-cover blocked SSRF: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'URL not allowed'], 400);
} catch (GtFetchException $e) {
    error_log("download-cover fetch failed: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image'], 500);
}

$imageData   = $result['data'];
$contentType = $result['content_type'];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bash tests/v2/test_ssrf.sh`
Expected: PASS for both `download-cover` assertions.

- [ ] **Step 5: Commit**

```bash
git add api/download-cover.php tests/v2/test_ssrf.sh
git commit -m "security: route download-cover through SSRF-safe fetch

download-cover.php was fetching arbitrary URLs with CURLOPT_SSL_VERIFYPEER
and CURLOPT_SSL_VERIFYHOST disabled and no IP-range block at all — an
authenticated SSRF + free MITM. Now routed through gt_safe_http_fetch,
which enforces HTTPS, TLS verification, and DNS-resolved IP filtering.

Ref: FABLE-SUGGESTIONS.md §1 SSRF"
```

---

## Task 4: Fix `api/v2/images/cover.php` stored SSRF

**Files:**
- Modify: `api/v2/images/cover.php:81-107` (external HTTPS branch)

**Why:** `front_cover_image` is client-writable via v2 sync push. A malicious client can store `https://169.254.169.254/…` and have the server fetch it later when someone views the cover.

- [ ] **Step 1: Write the failing test**

Create `tests/v2/test_v2_cover_ssrf.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "v2 cover.php blocks stored SSRF"

# Seed a game owned by testuser with a metadata-endpoint URL in front_cover_image.
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" <<SQL
INSERT INTO games (user_id, title, platform, front_cover_image)
VALUES (
  (SELECT id FROM users WHERE username='testuser'),
  'SSRF Cover Test', 'PC', 'https://169.254.169.254/latest/meta-data/'
);
SQL

GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe \
  "SELECT id FROM games WHERE title='SSRF Cover Test' ORDER BY id DESC LIMIT 1")

req GET "/api/v2/images/cover.php?id=$GAME_ID" "" -H "Authorization: Bearer $TOKEN"
# Post-fix behaviour: 403 forbidden (SSRF blocked), or 404 (blocked-then-treated-as-not-found).
[[ "$HTTP_STATUS" == "403" || "$HTTP_STATUS" == "404" ]] && green "  PASS: stored SSRF blocked (HTTP $HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS — server may have fetched 169.254"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Cleanup
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e \
  "DELETE FROM games WHERE title='SSRF Cover Test'"

summarize
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/v2/test_v2_cover_ssrf.sh`
Expected: FAIL — current code fetches 169.254 with `CURLOPT_SSL_VERIFYPEER => true` but no host filtering, so it either 200s or 500s from the metadata endpoint. Neither is 403/404.

- [ ] **Step 3: Replace the external-HTTPS branch**

In `api/v2/images/cover.php`, replace lines 79-107 (the entire `if (strncmp($path, 'https://', 8) === 0) { … }` block):

```php
// Format 2: external HTTPS URL — fetch via SSRF-safe helper.
// (http:// is intentionally not supported, matching api/image-proxy.php.)
if (strncmp($path, 'https://', 8) === 0) {
    require_once __DIR__ . '/../../../includes/http-fetch.php';
    try {
        $result = gt_safe_http_fetch($path, [
            'accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
        ]);
    } catch (GtSsrfException $e) {
        error_log("v2/images/cover.php blocked SSRF for game {$id}: {$e->getMessage()}");
        v2_error('forbidden', 'Cover URL not allowed', 403);
    } catch (GtFetchException $e) {
        v2_error('not_found', "Failed to fetch external cover", 404);
    }

    header_remove('Content-Type');
    header("Content-Type: {$result['content_type']}");
    header("Content-Length: " . strlen($result['data']));
    header("Cache-Control: private, max-age=3600");
    echo $result['data'];
    exit;
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `bash tests/v2/test_v2_cover_ssrf.sh`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/v2/images/cover.php tests/v2/test_v2_cover_ssrf.sh
git commit -m "security: SSRF-gate v2/images/cover external HTTPS fetch

front_cover_image is client-writable via sync push, making the v2 cover
endpoint a stored-SSRF sink — a malicious client could store
https://169.254.169.254/... and have the server fetch it later. Now
routes through gt_safe_http_fetch which blocks the metadata endpoint
and all private/loopback ranges.

Ref: FABLE-SUGGESTIONS.md §1 SSRF"
```

---

## Task 5: Refactor remaining fetch sites to use the helper (remove duplication)

**Files:**
- Modify: `api/image-proxy.php` (replace curl block below line 73 with helper)
- Modify: `api/download-external-image.php:37-73` (replace inline blocklist + curl)
- Modify: `api/games.php:438-482` (replace `downloadExternalImage()` inline SSRF + curl)

**Why:** Task 1 gated `image-proxy.php`'s pre-fetch check. This task removes the ~40 lines of duplicated curl below it and also collapses the two other copies. Fable's "SSRF blocklist triplicated" quote resolves here.

- [ ] **Step 1: Add regression tests for each site**

Append to `tests/v2/test_ssrf.sh`:

```bash
blue "SSRF: download-external-image blocks 169.254"
# Requires session auth.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null
req GET "/api/download-external-image.php?url=https://169.254.169.254/" "" -b "$COOKIE"
assert_eq "400" "$HTTP_STATUS" "download-external-image blocks 169.254"

blue "SSRF: games.php createGame via external image blocks 169.254"
# createGame accepts JSON with cover_url; expect 400 or an error result.
req POST "/api/games.php?action=create" \
  '{"title":"SSRF Test","platform":"PC","front_cover_url":"https://169.254.169.254/"}' \
  -b "$COOKIE" -H "Content-Type: application/json"
[[ "$HTTP_STATUS" =~ ^[45] ]] && green "  PASS: games.php createGame blocks 169.254 (HTTP $HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/v2/test_ssrf.sh`
Expected: FAIL on `download-external-image` (its regex misses 169.254). Depending on `games.php` code path, that may already fail differently — check the current behaviour is not "accepted, 200 returned".

- [ ] **Step 3: Refactor `image-proxy.php` — replace curl block**

In `api/image-proxy.php`, replace lines 74-117 (from `// Fetch the image` through the second `exit;` in the httpCode error branch) with:

```php
// Fetch the image via SSRF-safe helper (redirects re-validated per hop).
try {
    $result = gt_safe_http_fetch($url, [
        'timeout' => 60,
        'connect_timeout' => 15,
        'user_agent' => 'Mozilla/5.0 (compatible; GameTracker/1.0)',
        'accept' => 'image/webp,image/apng,image/*,*/*;q=0.8',
    ]);
    $imageData   = $result['data'];
    $contentType = $result['content_type'];
} catch (GtSsrfException $e) {
    error_log("Image proxy blocked SSRF: {$e->getMessage()} for URL $url");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Blocked');
} catch (GtFetchException $e) {
    error_log("Image proxy fetch failed: {$e->getMessage()} for URL $url");
    // Return a 1x1 transparent PNG (existing behaviour).
    header('Content-Type: image/png');
    header('Cache-Control: no-cache');
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    exit;
}
```

The Task-1 pre-check block above (which just called `gt_ssrf_check_url`) becomes redundant — the helper does its own SSRF check. Remove the Task-1 shim to avoid duplicate DNS lookups; the require_once at the top of the file can stay.

- [ ] **Step 4: Refactor `download-external-image.php`**

In `api/download-external-image.php`, replace lines 26-73 (from `// Validate URL` through the httpCode-error branch):

```php
require_once __DIR__ . '/../includes/http-fetch.php';

try {
    $result = gt_safe_http_fetch($imageUrl, [
        'accept' => 'image/jpeg,image/png,image/gif,image/webp,*/*',
    ]);
} catch (GtSsrfException $e) {
    error_log("download-external-image blocked SSRF: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'URL not allowed'], 400);
} catch (GtFetchException $e) {
    error_log("download-external-image fetch failed: {$e->getMessage()} for URL $imageUrl");
    sendJsonResponse(['success' => false, 'message' => 'Failed to download image'], 500);
}

$imageData   = $result['data'];
$contentType = $result['content_type'];
```

- [ ] **Step 5: Refactor `games.php` `downloadExternalImage()` helper**

In `api/games.php`, replace lines 438-482 (the whole SSRF-check + curl portion of the function, up to but not including the "Validate it's actually an image (check magic bytes)" block):

```php
function downloadExternalImage($imageUrl, $gameId = null, $type = 'front') {
    require_once __DIR__ . '/../includes/http-fetch.php';
    try {
        $result = gt_safe_http_fetch($imageUrl, [
            'accept' => 'image/jpeg,image/png,image/gif,image/webp,*/*',
        ]);
    } catch (GtSsrfException $e) {
        error_log("games.php SSRF blocked for cover URL $imageUrl: {$e->getMessage()}");
        return false;
    } catch (GtFetchException $e) {
        error_log("games.php cover fetch failed for $imageUrl: {$e->getMessage()}");
        return false;
    }

    $imageData   = $result['data'];
    $contentType = $result['content_type'];
```

Everything below (the "Validate it's actually an image (check magic bytes)" block onwards) stays as-is.

- [ ] **Step 6: Run test to verify passes**

Run: `bash tests/v2/test_ssrf.sh`
Expected: all SSRF tests PASS.

- [ ] **Step 7: Commit**

```bash
git add api/image-proxy.php api/download-external-image.php api/games.php tests/v2/test_ssrf.sh
git commit -m "security: collapse SSRF blocklist duplication into helper

image-proxy.php, download-external-image.php, and games.php each carried
a hand-rolled regex blocklist that missed 169.254.0.0/16 (cloud metadata)
and pre-DNS hostnames that resolve to internal IPs. All three now share
gt_safe_http_fetch(), which resolves the host and blocks
private/loopback/link-local/reserved IPs.

Ref: FABLE-SUGGESTIONS.md §1 SSRF"
```

---

## Task 6: Rotate + scrub the committed TheGamesDB API key

**Files:**
- Modify: `api/cover-image.php:23`
- Modify: `includes/config.php.example` (add new env-var placeholder)
- Repo history: rewrite with `git filter-repo`

**Why:** The key at line 23 is committed. Even stale, it's a bad habit and grep'able signal to attackers.

- [ ] **Step 1: Ask user to rotate the key**

STOP. Output to the user:

> "Before we scrub the API key, please rotate it at TheGamesDB (log in → API keys → revoke `a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3`, create a new one). Paste the new key here or, better, store it in `/etc/gameTracker/env` (or wherever your prod app reads env vars) as `THEGAMESDB_API_KEY=<newkey>`. Reply 'rotated' with the new key or the env var path when done."

- [ ] **Step 2: Replace the hardcoded key with an env lookup**

In `api/cover-image.php`, replace line 23:

```php
$apiKey = getenv('THEGAMESDB_API_KEY');
if (empty($apiKey)) {
    // Fall back to per-user settings (mirrors how Steam key is stored)
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ? AND user_id = ?");
    $stmt->execute(['thegamesdb_api_key', $_SESSION['user_id'] ?? 0]);
    $apiKey = $stmt->fetchColumn() ?: '';
}
if (empty($apiKey)) {
    sendJsonResponse(['success' => false, 'message' => 'TheGamesDB API key not configured'], 500);
}
```

- [ ] **Step 3: Update `includes/config.php.example`**

Add near the top:

```php
// TheGamesDB API key — set at /etc/gameTracker/env or via per-user Settings
// (falls back to per-user setting `thegamesdb_api_key` if env is unset).
// putenv('THEGAMESDB_API_KEY=your_key_here');
```

- [ ] **Step 4: Test the endpoint still works with env var**

Run:
```bash
THEGAMESDB_API_KEY=<newkey> php -S localhost:8000
# in another terminal:
curl -sS "http://localhost:8000/api/cover-image.php?title=Halo&platform=Xbox" -b <session_cookie>
```
Expected: cover URL returned (or "no match" gracefully — not "API key not configured").

- [ ] **Step 5: Commit code change (before history scrub)**

```bash
git add api/cover-image.php includes/config.php.example
git commit -m "security: read TheGamesDB API key from env, remove hardcoded value

The key was committed at api/cover-image.php:23 and even if stale, an
in-repo secret is a bad habit and a grep target. Now sourced from the
THEGAMESDB_API_KEY env var (or a per-user setting fallback).

History scrub for the old value is done as a separate destructive
step — see docs/superpowers/plans/2026-07-10-security-fixes-phase-1.md
Task 6 Step 6.

Ref: FABLE-SUGGESTIONS.md §1 committed API key"
```

- [ ] **Step 6: History rewrite — ONLY with user approval**

STOP. Output to the user:

> "Ready to scrub the old key from git history. This is a **destructive, non-reversible** rewrite. It requires:
> 1. Every collaborator (including any deploy checkouts) re-cloning after the push.
> 2. Force-pushing every branch on `origin` (main + any active feature branches).
>
> Confirm 'go' and I'll run `git filter-repo --replace-text` with the old key mapped to `<REDACTED>`. Otherwise we can skip the history scrub, accept the stale-key-visible-in-history risk, and move on."

If user confirms, run:
```bash
# Requires: pipx install git-filter-repo (or brew install git-filter-repo)
echo 'a6665c94c14c40ce77c7546a1a1f12f4084650ef255637fef3e8e6c4c047d9f3==>REDACTED' > /tmp/keyscrub.txt
git filter-repo --replace-text /tmp/keyscrub.txt --force
rm /tmp/keyscrub.txt

# Force-push all branches
git push --force-with-lease origin --all
git push --force-with-lease origin --tags
```

Then reset any active worktrees or ask collaborators to re-clone.

---

## Task 7: Lock `admin.php` listUsers to admin-only

**Files:**
- Modify: `api/admin.php:22-26`

- [ ] **Step 1: Write failing test**

Create `tests/v2/test_admin_scoping.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "admin.php list is admin-only"

# Login as testuser (non-admin)
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null

req GET "/api/admin.php?action=list" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "non-admin cannot list users"

summarize
```

- [ ] **Step 2: Run to verify failure**

Run: `bash tests/v2/test_admin_scoping.sh`
Expected: FAIL — currently returns 200 with user list.

- [ ] **Step 3: Restrict listUsers**

In `api/admin.php`, replace lines 22-26:

```php
switch ($action) {
    case 'list':
        if (!$isAdmin) {
            sendJsonResponse(['success' => false, 'message' => 'Admin access required'], 403);
        }
        listUsers();
        break;
```

- [ ] **Step 4: Run to verify pass**

Run: `bash tests/v2/test_admin_scoping.sh`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add api/admin.php tests/v2/test_admin_scoping.sh
git commit -m "security: restrict admin.php list action to admin role

The list action carried a comment saying 'all authenticated users can
list users' — but there's no product reason a non-admin should see other
users' game/item/completion counts or email addresses. Now returns 403
for non-admin callers, matching reset_password and delete.

Ref: FABLE-SUGGESTIONS.md §1 IDOR"
```

---

## Task 8: Remove cross-user `?user_id=` override in list endpoints

**Files:**
- Modify: `api/games.php:121-123` (listGames)
- Modify: `api/items.php:44-45` (listItems)

**Why:** Fable flagged this as "IDOR by design"; per plan scope, we lock the safer default. If Cameron later wants shared-shelf browsing, it's easy to add back with an explicit acl.

- [ ] **Step 1: Write failing test**

Create `tests/v2/test_list_scoping.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

# Seed an admin user with a game.
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" <<'SQL'
INSERT IGNORE INTO users (username, password_hash, role)
VALUES ('otheruser_list', '$2y$10$abcdefghijklmnopqrstuv', 'user');
INSERT INTO games (user_id, title, platform)
VALUES ((SELECT id FROM users WHERE username='otheruser_list'), 'Other List Game', 'PC');
SQL
OTHER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe \
  "SELECT id FROM users WHERE username='otheruser_list'")

# testuser logs in
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null

# Attempt to peek at other user's games.
req GET "/api/games.php?action=list&user_id=$OTHER_ID" "" -b "$COOKIE"
# Post-fix expected: response contains testuser's games only, not "Other List Game".
if echo "$RESPONSE_BODY" | grep -q "Other List Game"; then
  red "  FAIL: cross-user peek returned other user's game"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: cross-user peek blocked"
  PASS_COUNT=$((PASS_COUNT+1))
fi

req GET "/api/items.php?action=list&user_id=$OTHER_ID" "" -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -q "Other List Item"; then
  red "  FAIL: cross-user items peek returned other user's item"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: cross-user items peek blocked"
  PASS_COUNT=$((PASS_COUNT+1))
fi

summarize
```

- [ ] **Step 2: Run to see it fail**

Run: `bash tests/v2/test_list_scoping.sh`
Expected: FAIL on games — response body currently contains "Other List Game".

- [ ] **Step 3: Fix `games.php`**

In `api/games.php`, replace lines 121-123:

```php
        // Always scope to the caller's own collection.
        $targetUserId = $_SESSION['user_id'];
```

- [ ] **Step 4: Fix `items.php`**

In `api/items.php`, replace lines 43-45:

```php
    $currentUserId = $_SESSION['user_id'];
    $targetUserId = $currentUserId;
```

- [ ] **Step 5: Run test to verify pass**

Run: `bash tests/v2/test_list_scoping.sh`
Expected: 2 PASS.

- [ ] **Step 6: Commit**

```bash
git add api/games.php api/items.php tests/v2/test_list_scoping.sh
git commit -m "security: scope games+items list to caller (drop cross-user peek)

listGames and listItems accepted ?user_id= to fetch another user's
collection. Fable flagged this as ambiguous (deliberate household
sharing? or bug?). We lock the safer default here; shared-shelf browsing
can come back later as an explicit ACL feature.

Ref: FABLE-SUGGESTIONS.md §1 IDOR"
```

---

## Task 9: Sanitize error responses (strip file/line/message)

**Files:**
- Modify: `api/games.php:219-221`
- Modify: `api/auth.php:32-36`
- Modify: `api/steam-import.php:34` (`Server error occurred: {message}`)
- Modify: `api/completions.php:118-119` and any similar `getMessage()` returns

**Why:** Fable §1 information disclosure — raw exception messages leak filenames, line numbers, and driver strings.

- [ ] **Step 1: Grep for the pattern**

Run: `grep -rn "getMessage()" api/*.php includes/*.php | grep -v '// removed\|error_log\|throw' | head -30`
Expected: a list of ~10-20 sites. For each, decide: keep in `error_log()` (server-side) vs. remove from client JSON.

- [ ] **Step 2: Write regression test**

Create `tests/v2/test_error_disclosure.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "Error responses do not leak file/line"

# Trigger a games.php error by passing something malformed
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null

# per_page over the sanitizer max, or invalid action — force an error path
req GET "/api/games.php?action=list&per_page=abc" "" -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -qE "\.php|Line: [0-9]|File:"; then
  red "  FAIL: response leaked file/line — $RESPONSE_BODY"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: no file/line in response"
  PASS_COUNT=$((PASS_COUNT+1))
fi

summarize
```

(This test may already pass depending on which branch triggers the error. If it passes as-written, add a more targeted test that forces the catch — e.g., a query hitting a missing column.)

- [ ] **Step 3: Sanitize `api/games.php:210-224`**

Replace lines 210-224:

```php
        // Log detail server-side; return generic message to client.
        error_log('listGames Error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to load games'], JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
```

- [ ] **Step 4: Sanitize `api/auth.php:21-38`**

Replace lines 21-38:

```php
} catch (Throwable $e) {
    error_log('Auth API initialization error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Server error'], JSON_UNESCAPED_SLASHES);
    exit;
}
```

- [ ] **Step 5: Sanitize `api/steam-import.php:34`**

Replace line 34:

```php
    sendJsonResponse(['success' => false, 'message' => 'Server error occurred'], 500);
```

- [ ] **Step 6: Sanitize `api/completions.php:118-119` (and equivalents)**

For every remaining `getMessage()` in a `sendJsonResponse(..., 500)` call across `api/*.php`, replace with a generic message and add `error_log()` server-side. Grep list from Step 1 is the checklist.

- [ ] **Step 7: Run test to verify pass**

Run: `bash tests/v2/test_error_disclosure.sh`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add api/games.php api/auth.php api/steam-import.php api/completions.php tests/v2/test_error_disclosure.sh
git commit -m "security: strip file/line/getMessage from client error responses

v1 endpoints returned raw exception messages plus basename/line numbers
in HTTP 500 bodies, leaking filenames and driver strings. Detail now
logged via error_log() server-side; client gets a generic message.

Ref: FABLE-SUGGESTIONS.md §1 information disclosure"
```

---

## Task 10: Enforce POST-only on mutating endpoints

**Files:**
- Audit all `api/*.php` v1 endpoints; add `if ($_SERVER['REQUEST_METHOD'] !== 'POST') { sendJsonResponse(...405); }` guards to any mutating action that currently accepts GET or has no check.

**Why:** Fable's §1 CSRF concern is partly that SameSite=Lax doesn't fully protect GET-triggered mutations. Full CSRF-token rollout waits for Phase 4 frontend modularisation; POST-only is the mitigating step we can safely take now.

- [ ] **Step 1: Enumerate mutating actions**

Run:
```bash
grep -n "action ===\|case '" api/*.php | grep -E "'(create|update|delete|reset|import|refresh|save|upload|set|change|remove|clear|reorder)'" > /tmp/mutating_actions.txt
cat /tmp/mutating_actions.txt
```

Expected: ~20-30 lines. For each, check the surrounding function for `if ($_SERVER['REQUEST_METHOD'] !== 'POST')`.

- [ ] **Step 2: Write a bash test that fetches each with GET and asserts 405**

Create `tests/v2/test_method_guards.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=testuser&password=test_password" > /dev/null

blue "Mutating actions reject GET"
for action in \
  "games.php?action=create" \
  "games.php?action=update" \
  "games.php?action=delete" \
  "items.php?action=create" \
  "items.php?action=update" \
  "items.php?action=delete" \
  "completions.php?action=create" \
  "completions.php?action=update" \
  "completions.php?action=delete" \
  "steam-import.php?action=import" \
  "steam-import.php?action=delete_pc_games" \
  ; do
  req GET "/api/$action" "" -b "$COOKIE"
  assert_eq "405" "$HTTP_STATUS" "GET /api/$action = 405"
done

summarize
```

- [ ] **Step 3: Run to see failures**

Run: `bash tests/v2/test_method_guards.sh`
Expected: several FAILs where the guard is missing.

- [ ] **Step 4: Add the guards**

For each failing endpoint, add at the top of its handler function:

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}
```

(Do this in the order the test failures appear. Some endpoints — e.g. `createGame`, `updateGame`, `deleteGame` inside `games.php` — will need the guard added inside each per-action function since the top-level switch runs on GET too.)

- [ ] **Step 5: Run to verify pass**

Run: `bash tests/v2/test_method_guards.sh`
Expected: all PASS.

- [ ] **Step 6: Commit**

```bash
git add api/*.php tests/v2/test_method_guards.sh
git commit -m "security: enforce POST-only on all mutating v1 endpoints

Full CSRF token enforcement is deferred to the frontend rewrite (Phase 4),
but SameSite=Lax only fully protects when destructive actions require POST.
Every mutating action now returns 405 for non-POST.

Ref: FABLE-SUGGESTIONS.md §1 CSRF"
```

---

## Task 11: Rewrite `SECURITY-ASSESSMENT.md` honestly

**Files:**
- Modify: `SECURITY-ASSESSMENT.md` (full replacement)

- [ ] **Step 1: Rewrite the doc**

Overwrite `SECURITY-ASSESSMENT.md`:

```markdown
# Security Posture — gameTracker

**Status:** honest posture doc, not a stamp of approval.
Last reviewed 2026-07-10 by Fable's audit + Phase 1 fixes.

This is a self-hosted, small-scale, single-household app. The goal is
"secure enough that a curious user can't wipe or exfiltrate someone
else's data," not "hardened against a nation-state." What's below is
what's actually mitigated, what's known-open, and what's accepted risk.

## What's mitigated

- **SQL injection**: prepared statements everywhere. No string
  concatenation with user input in query construction.
- **Password storage**: `password_hash()` with `PASSWORD_DEFAULT` (bcrypt).
- **Rate limiting**: application-level table `rate_limits` (login 5/15min,
  registration 3/hr) + nginx rate limits on `/api/`, `/login`, `/register`.
- **Session hijack basics**: HTTPS-only cookies, HttpOnly, SameSite=Lax,
  30-minute idle timeout, `session_regenerate_id()` every 5 minutes.
- **File upload**: MIME + magic-bytes + extension checks, 5 MB size cap,
  10000×10000 dimension cap, nginx blocks PHP execution in `/uploads/`.
- **SSRF** (fixed in Phase 1): every external-URL fetch — `image-proxy`,
  `download-cover`, `download-external-image`, `games.php`
  `downloadExternalImage()`, `v2/images/cover.php` HTTPS branch —
  routes through `includes/http-fetch.php`, which resolves the host and
  rejects any private/loopback/link-local/reserved IP (including
  `169.254.169.254`). TLS verification is always on. Redirects are
  followed manually and re-validated per hop.
- **Cross-user data isolation** (fixed in Phase 1):
  - `steam-import.php` `deletePCGames` and dedup are user-scoped.
  - `games.php` and `items.php` list endpoints ignore `?user_id=` override.
  - `admin.php?action=list` requires admin role.
- **Information disclosure** (fixed in Phase 1): v1 endpoints no longer
  return `$e->getFile()` / `getLine()` / `getMessage()` in JSON responses.
  Detail is logged via `error_log()`.
- **Method safety**: every mutating action requires `POST` (returns 405
  otherwise). Combined with `SameSite=Lax`, this closes the GET-CSRF gap.

## Known-open (accepted risk, tracked)

- **Full CSRF token enforcement** — the API layer has a `validateCsrfToken()`
  helper but it's only used on `change-admin-credentials.php`. Threading
  the token through the current 3,239-line `js/games.js` would be surgery
  on an unstable codebase; this waits for Phase 4 (frontend modularisation).
  Meanwhile, mitigations: SameSite=Lax + POST-only enforcement.
- **XSS in attribute contexts** — most text is escaped via `escapeHtml`,
  but a few image-attribute paths remain (Fable §3). Addressed in Phase 4.
- **Deploy-time schema changes** — `initializeDatabase()` runs ~20
  `CREATE TABLE`/`ALTER TABLE` statements on every request. Correctness
  hazard, not a security hazard directly. Addressed in Phase 2 (backend
  unification).
- **Committed secrets in history** — as of Phase 1 the live TheGamesDB
  key is removed from HEAD and moved to env var. History rewrite is
  optional and only done if we accept the force-push cost.

## Not attempted (out of scope for the app's threat model)

- 2FA on user accounts.
- Real-time intrusion detection beyond fail2ban.
- ClamAV / image re-encoding on uploads.
- WAF / CDN in front of nginx.

## Reviewing this doc

Whenever you touch the security surface — auth, session, external
fetches, file uploads, cross-user boundaries, or CSRF — update the
"Mitigated" list here or move the item to "Known-open." A doc that
lies about current posture is worse than no doc.

## References

- `FABLE-SUGGESTIONS.md` — the original Phase-1 audit that drove this work.
- `includes/http-fetch.php` — SSRF-safe fetch helper.
- `includes/csrf.php` — CSRF token infra (waiting on frontend).
- `tests/v2/test_ssrf.sh`, `tests/v2/test_steam_import_scoping.sh`,
  `tests/v2/test_list_scoping.sh`, `tests/v2/test_method_guards.sh`,
  `tests/v2/test_error_disclosure.sh`, `tests/v2/test_admin_scoping.sh`,
  `tests/v2/test_v2_cover_ssrf.sh` — regression tests for the fixes above.
```

- [ ] **Step 2: Commit**

```bash
git add SECURITY-ASSESSMENT.md
git commit -m "docs: rewrite SECURITY-ASSESSMENT as honest posture doc

The old doc opened with 'Overall Security Status: SECURE' and green
ticks including 'CSRF protection'. The code had an unauthenticated-shaped
cross-user delete, multiple SSRF holes, and CSRF enforced on 1 endpoint.
Now describes what's actually mitigated, what's known-open, and what's
accepted risk.

Ref: FABLE-SUGGESTIONS.md §5"
```

---

## Task 12: Push branch + deploy checklist

- [ ] **Step 1: Run the full v2 test suite locally**

Run: `bash tests/v2/run-all.sh`
Expected: all tests PASS. If any fail, fix before continuing.

- [ ] **Step 2: Push branch**

```bash
git push -u origin fix-recently-added-sort
```

(Or a fresh branch name if this branch is now overloaded — check with `git log --oneline main..HEAD` to see how many commits accumulated.)

- [ ] **Step 3: Open PR**

```bash
gh pr create --title "Phase 1 security fixes (Fable §1)" --body "$(cat <<'EOF'
## Summary
Closes every 🔴/🟠 item in `FABLE-SUGGESTIONS.md` §1:
- SSRF-safe HTTP fetch helper (`includes/http-fetch.php`) applied to `image-proxy`, `download-cover`, `download-external-image`, `games.php`, `v2/images/cover.php`. Blocks `169.254.169.254` and all private/loopback/reserved ranges; keeps TLS verification on; revalidates redirects per hop.
- `steam-import.php` cross-user data wipe closed: dedup, INSERT, and `deletePCGames` are all user-scoped.
- `admin.php?action=list` locked to admin role.
- `games.php` + `items.php` list drop `?user_id=` override.
- Client error responses no longer leak file/line/getMessage.
- POST-only enforced on all v1 mutating actions.
- Hardcoded TheGamesDB key removed from HEAD (env var + per-user setting fallback).
- `SECURITY-ASSESSMENT.md` rewritten as honest posture doc.

Full CSRF token enforcement is deferred to Phase 4 (frontend modularisation) — POST-only + SameSite=Lax is the interim mitigation, and the posture doc says so honestly.

## Test plan
- [ ] `bash tests/v2/run-all.sh` locally against a clean test DB
- [ ] Deploy to prod (see below), then hit `/api/image-proxy.php?url=https://169.254.169.254/` and verify 403
- [ ] Cover the primary golden paths on prod: add a game with an external cover URL, delete a game, sync from iOS

## Deploy
Once merged, on the prod VM:
```bash
cd /path/to/gameTracker
git pull
# If THEGAMESDB_API_KEY isn't yet exported, set it in /etc/gameTracker/env
sudo systemctl reload php8.3-fpm  # or whatever's actually running
```

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 4: Deploy reminder for user**

STOP. Output to the user:

> "Phase 1 branch is up, PR opened. To deploy to prod:
>
> 1. Review + merge the PR on GitHub.
> 2. SSH into the prod VM.
> 3. `cd /path/to/gameTracker && git pull`
> 4. If you rotated the TheGamesDB key (Task 6), export `THEGAMESDB_API_KEY` in whatever init file the app reads (e.g. `/etc/gameTracker/env`, or set it in nginx's `fastcgi_param` block).
> 5. Reload PHP-FPM (`sudo systemctl reload php8.3-fpm`).
> 6. Smoke-check: log in, add a game, delete a game, load the dashboard.
>
> Ready for Phase 2 (backend unification — kill v1 or wall it off) whenever you are."

---

## Self-review

**Spec coverage** (against Fable §1):
- 🔴 Cross-user data wipe → Task 2 ✓
- 🔴 CSRF unused → Task 10 (POST-only) + honest doc in Task 11 (full rollout deferred to Phase 4) ✓
- 🔴 SSRF holes → Tasks 1, 3, 4, 5 ✓
- 🟠 Committed API key → Task 6 ✓
- 🟠 IDOR by design → Tasks 7 + 8 ✓
- 🟠 Information disclosure → Task 9 ✓
- §5 docs that oversell → Task 11 ✓

**Placeholder scan:** No TBDs. Every step shows the code/command. The one deliberate pause is Task 6 Step 1/6 (waits for user to rotate the key and confirm the destructive history rewrite) — that's a required checkpoint, not a placeholder.

**Type consistency:** `gt_safe_http_fetch()`, `GtSsrfException`, `GtFetchException`, `gt_ssrf_check_url()`, `gt_ssrf_check_ip()`, `gt_resolve_all()` — used consistently across Tasks 1, 3, 4, 5. Return shape `['data'=>..., 'content_type'=>..., 'http_code'=>...]` matches every consumer.
