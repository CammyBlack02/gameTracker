# Server-Side Foundation Implementation Plan (Plan 1 of 4)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/api/v2/` endpoint surface to the existing PHP web app so the upcoming iOS app can authenticate via Bearer tokens, perform delta sync, fetch images, and proxy external metadata lookups — without disturbing the existing web UI.

**Architecture:** New endpoints live under `api/v2/`, share the same MySQL database as the web UI, use a new `api_tokens` table for authentication, a new `deletions` table for sync tombstones, and additive `updated_at` columns on `game_images` and `item_images`. Image thumbnails are generated on upload and via a one-off backfill script. All new code follows the existing project's PDO + `sendJsonResponse` + `CREATE TABLE IF NOT EXISTS` patterns.

**Tech Stack:** PHP 8.3+, MySQL 8, PDO, GD (for thumbnails), Bash + curl + jq (for integration tests).

**Spec:** [docs/superpowers/specs/2026-05-15-ios-app-design.md](../specs/2026-05-15-ios-app-design.md)

---

## File Structure

### New files
- `api/v2/_auth.php` — Bearer-token verification helper (used by every v2 endpoint)
- `api/v2/_helpers.php` — shared response/error helpers for v2
- `api/v2/auth/token.php` — `POST` issue token (login)
- `api/v2/auth/revoke.php` — `POST` revoke token (logout)
- `api/v2/sync/changes.php` — `GET` delta-sync read
- `api/v2/sync/push.php` — `POST` delta-sync write + conflict detect
- `api/v2/images/cover.php` — `GET` cover image (thumb or full)
- `api/v2/images/extra.php` — `GET` extra image (thumb or full)
- `api/v2/games/cover-upload.php` — `POST` upload new cover for a game
- `api/v2/external-image.php` — `GET` download external URL, save locally
- `api/v2/pricecharting.php` — `GET` proxy for PriceCharting lookups
- `api/v2/metacritic.php` — `GET` proxy for Metacritic lookups
- `database/migrations/001_api_tokens.php` — creates `api_tokens` table
- `database/migrations/002_deletions.php` — creates `deletions` table + triggers
- `database/migrations/003_image_updated_at.php` — adds `updated_at` to image tables
- `database/migrate.php` — migration runner
- `scripts/generate-thumbnails.php` — one-off thumbnail backfill
- `includes/thumbnail.php` — thumbnail generation helper (reused by upload code)
- `tests/v2/lib.sh` — shared bash test helpers
- `tests/v2/run-all.sh` — runs every test file
- `tests/v2/test_auth.sh` — auth endpoint tests
- `tests/v2/test_sync.sh` — sync endpoint tests
- `tests/v2/test_images.sh` — image endpoint tests
- `tests/v2/test_proxies.sh` — pricecharting/metacritic proxy tests
- `tests/v2/setup-test-db.sh` — creates an isolated test database with seed data

### Modified files
- `api/upload.php` — call thumbnail generator after each upload
- `api/download-external-image.php` — call thumbnail generator after download
- `nginx-gameTracker.conf` — add `location /api/v2/` block
- `.gitignore` — ignore `tests/v2/.last_token` artifact

### Untouched
- The entire web UI (`dashboard.php`, `game-detail.php`, etc.)
- All existing `/api/` endpoints
- `includes/config.php` (we use its `$pdo` and helpers; we do **not** modify it)
- `includes/functions.php` (we reuse its helpers)

---

## Task 0: Local environment setup

**Files:** none

- [ ] **Step 0.1: Confirm required tools are installed**

Run:
```bash
which php mysql jq curl
php --version
mysql --version
```

Expected: paths printed for all four; PHP ≥ 8.3, MySQL ≥ 8.

If any are missing on macOS:
```bash
brew install php mysql jq
brew services start mysql
```

- [ ] **Step 0.2: Confirm `mysql` can connect locally**

Run:
```bash
mysql -uroot -e "SELECT VERSION();"
```

Expected: a version string. If it prompts for a password, set `MYSQL_PWD` env var or pass `-p`.

- [ ] **Step 0.3: Confirm GD extension is loaded**

Run:
```bash
php -r 'var_dump(extension_loaded("gd"));'
```

Expected: `bool(true)`. If false: `brew reinstall php` (Homebrew PHP includes GD).

- [ ] **Step 0.4: Confirm working directory is the project root**

Run:
```bash
pwd
ls includes/config.php.example
```

Expected: prints `…/gameTracker` and lists the file.

---

## Task 1: Test harness

**Files:**
- Create: `tests/v2/setup-test-db.sh`
- Create: `tests/v2/lib.sh`
- Create: `tests/v2/run-all.sh`
- Create: `tests/v2/test_smoke.sh`
- Modify: `.gitignore`

This task creates the test infrastructure all later tasks rely on. We use an isolated `gameTracker_test` MySQL database so tests never touch real data.

- [ ] **Step 1.1: Create test database setup script**

Write `tests/v2/setup-test-db.sh`:

```bash
#!/usr/bin/env bash
# Creates an isolated MySQL test database with two seed users.
# Idempotent: drops and recreates the DB on every run.

set -euo pipefail

DB_NAME="${TEST_DB_NAME:-gameTracker_test}"
DB_USER="${TEST_DB_USER:-root}"

echo "Recreating database $DB_NAME..."
mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Bootstrap schema by running the project's normal init code against the test DB.
# We do this by setting env vars that an overridable config can read.
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export GT_DB_NAME="$DB_NAME"
export GT_DB_USER="$DB_USER"
export GT_DB_PASS="${TEST_DB_PASS:-}"

# Trigger schema creation by hitting any PHP file that requires config.php
php -d display_errors=1 -r "
\$_SERVER['HTTP_HOST'] = 'localhost';
require '$PROJECT_ROOT/includes/config.php';
echo 'Schema initialized\n';
"

# Seed: create test user with known password (test_password)
PASSWORD_HASH=$(php -r 'echo password_hash("test_password", PASSWORD_DEFAULT);')
mysql -u"$DB_USER" "$DB_NAME" -e "
  INSERT INTO users (username, password_hash, role) VALUES ('testuser', '$PASSWORD_HASH', 'user');
"

echo "Test DB ready. User: testuser / test_password"
```

Make it executable:
```bash
chmod +x tests/v2/setup-test-db.sh
```

- [ ] **Step 1.2: Make config.php read env-var overrides**

This is the only modification to existing code in Task 1. The setup script needs to tell config.php to use the test database without editing the real config file.

In `includes/config.php`, find the existing block:

```php
// Database configuration
// TODO: Fill in your actual database credentials
define('DB_HOST', 'localhost');
define('DB_NAME', 'gameTracker');
define('DB_USER', 'your_database_username');
define('DB_PASS', 'your_database_password');
```

Replace with:

```php
// Database configuration
// Env-var overrides (used by tests; fall back to defaults for production)
define('DB_HOST', getenv('GT_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('GT_DB_NAME') ?: 'gameTracker');
define('DB_USER', getenv('GT_DB_USER') ?: 'your_database_username');
define('DB_PASS', getenv('GT_DB_PASS') !== false ? getenv('GT_DB_PASS') : 'your_database_password');
```

This is purely additive: behaviour is unchanged when env vars aren't set.

- [ ] **Step 1.3: Create shared bash test helpers**

Write `tests/v2/lib.sh`:

```bash
#!/usr/bin/env bash
# Shared helpers for v2 integration tests.
# Source this from individual test scripts.

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8000}"
TEST_USER="${TEST_USER:-testuser}"
TEST_PASS="${TEST_PASS:-test_password}"

# Coloured output
red()   { printf "\033[31m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
blue()  { printf "\033[34m%s\033[0m\n" "$*"; }

PASS_COUNT=0
FAIL_COUNT=0

# assert_eq <expected> <actual> <message>
assert_eq() {
  if [[ "$1" == "$2" ]]; then
    green "  PASS: $3"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red   "  FAIL: $3"
    red   "    expected: $1"
    red   "    actual:   $2"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
}

# assert_contains <needle> <haystack> <message>
assert_contains() {
  if echo "$2" | grep -q "$1"; then
    green "  PASS: $3"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red   "  FAIL: $3 — did not contain '$1'"
    red   "    haystack: $2"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
}

# req <METHOD> <path> [data] [extra-curl-args...]
# Sets globals: HTTP_STATUS, RESPONSE_BODY
req() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  shift 3 || true
  local response
  if [[ -n "$data" ]]; then
    response=$(curl -sS -o /tmp/v2_body -w "%{http_code}" \
      -X "$method" "$BASE_URL$path" \
      -H "Content-Type: application/x-www-form-urlencoded" \
      -d "$data" "$@")
  else
    response=$(curl -sS -o /tmp/v2_body -w "%{http_code}" \
      -X "$method" "$BASE_URL$path" "$@")
  fi
  HTTP_STATUS="$response"
  RESPONSE_BODY="$(cat /tmp/v2_body)"
}

summarize() {
  echo
  if [[ $FAIL_COUNT -eq 0 ]]; then
    green "==> $PASS_COUNT passed, 0 failed"
    exit 0
  else
    red "==> $PASS_COUNT passed, $FAIL_COUNT failed"
    exit 1
  fi
}
```

- [ ] **Step 1.4: Create the run-all script**

Write `tests/v2/run-all.sh`:

```bash
#!/usr/bin/env bash
# Top-level test runner. Starts a PHP dev server pointed at the test DB,
# runs every test_*.sh file, then shuts the server down cleanly.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

export GT_DB_NAME="${TEST_DB_NAME:-gameTracker_test}"
export GT_DB_USER="${TEST_DB_USER:-root}"
export GT_DB_PASS="${TEST_DB_PASS:-}"

# Reset DB
"$SCRIPT_DIR/setup-test-db.sh"

# Start dev server in background
cd "$PROJECT_ROOT"
php -S localhost:8000 router.php > /tmp/v2_server.log 2>&1 &
SERVER_PID=$!
trap "kill $SERVER_PID 2>/dev/null || true" EXIT

# Wait for server to be ready (poll, up to 5s)
for i in {1..50}; do
  if curl -sS http://localhost:8000/ -o /dev/null; then break; fi
  sleep 0.1
done

OVERALL_FAIL=0
for test in "$SCRIPT_DIR"/test_*.sh; do
  echo "=== $(basename "$test") ==="
  if ! bash "$test"; then
    OVERALL_FAIL=1
  fi
  echo
done

exit $OVERALL_FAIL
```

Make scripts executable:
```bash
chmod +x tests/v2/run-all.sh tests/v2/lib.sh
```

- [ ] **Step 1.5: Write a smoke test (the first failing test)**

Write `tests/v2/test_smoke.sh`:

```bash
#!/usr/bin/env bash
# Verifies the test harness itself works by hitting the existing /api/auth.php?action=check
source "$(dirname "$0")/lib.sh"

blue "Smoke: hitting existing /api/auth.php?action=check"
req GET "/api/auth.php?action=check"
assert_eq "200" "$HTTP_STATUS" "endpoint returns 200"
assert_contains '"success":true' "$RESPONSE_BODY" "response is success:true"
assert_contains '"authenticated":false' "$RESPONSE_BODY" "anonymous user is not authenticated"

summarize
```

Make it executable:
```bash
chmod +x tests/v2/test_smoke.sh
```

- [ ] **Step 1.6: Run the smoke test and verify it passes**

Run:
```bash
bash tests/v2/run-all.sh
```

Expected: ends with `==> 3 passed, 0 failed` and exits 0. If it fails, the failure is in the harness — debug before continuing.

- [ ] **Step 1.7: Add test artifact to .gitignore**

Append to `.gitignore`:

```
# v2 test artifacts
tests/v2/.last_token
/tmp/v2_body
/tmp/v2_server.log
```

- [ ] **Step 1.8: Commit**

```bash
git add tests/v2 includes/config.php .gitignore
git commit -m "Add v2 integration test harness with smoke test"
```

---

## Task 2: Database migrations

**Files:**
- Create: `database/migrate.php`
- Create: `database/migrations/001_api_tokens.php`
- Create: `database/migrations/002_deletions.php`
- Create: `database/migrations/003_image_updated_at.php`

The web app already uses idempotent schema in `initializeDatabase()`. We follow the same pattern for new tables, but isolate them into versioned migration files so they can be applied independently and re-run safely.

- [ ] **Step 2.1: Write a failing test for the migration runner**

Append to `tests/v2/test_smoke.sh`:

```bash
blue "Migrations: api_tokens table exists after running migrate.php"
php database/migrate.php > /dev/null
TABLES=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW TABLES LIKE 'api_tokens'" 2>&1)
assert_contains "api_tokens" "$TABLES" "api_tokens table exists"

blue "Migrations: deletions table exists"
TABLES=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW TABLES LIKE 'deletions'" 2>&1)
assert_contains "deletions" "$TABLES" "deletions table exists"

blue "Migrations: game_images has updated_at column"
COLS=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW COLUMNS FROM game_images LIKE 'updated_at'" 2>&1)
assert_contains "updated_at" "$COLS" "game_images.updated_at exists"
```

- [ ] **Step 2.2: Run test, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: fails because `database/migrate.php` doesn't exist yet (PHP errors), and the three new assertions fail.

- [ ] **Step 2.3: Write migration 001 (api_tokens)**

Write `database/migrations/001_api_tokens.php`:

```php
<?php
/**
 * Migration 001: api_tokens table
 *
 * Stores hashed Bearer tokens for the iOS app's authentication.
 * The token itself is never stored — only its SHA-256 hash. Verifying
 * a request hashes the incoming token and looks up the hash.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS api_tokens (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_name VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY uniq_token_hash (token_hash),
        INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
```

- [ ] **Step 2.4: Write migration 002 (deletions + triggers)**

Write `database/migrations/002_deletions.php`:

```php
<?php
/**
 * Migration 002: deletions table + triggers
 *
 * The deletions table is a tombstone log: when a row is deleted from a
 * synced table, a tombstone is inserted here so the iOS app's next delta
 * sync can learn about the deletion. Triggers do this automatically so
 * existing web-UI delete code paths need no changes.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS deletions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        table_name VARCHAR(64) NOT NULL,
        server_id INT NOT NULL,
        deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id_deleted (user_id, deleted_at),
        INDEX idx_table_server (table_name, server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Triggers: drop-then-create so re-runs are safe.
    // (MySQL does not support CREATE TRIGGER IF NOT EXISTS in all versions.)
    $tables = [
        'games'           => 'OLD.user_id',
        'items'           => 'OLD.user_id',
        'game_completions'=> 'OLD.user_id',
        'game_images'     => 'OLD.user_id',
        'item_images'     => 'OLD.user_id',
    ];
    foreach ($tables as $table => $userIdExpr) {
        $trigger = "trg_{$table}_after_delete";
        $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
        $pdo->exec("CREATE TRIGGER $trigger
            AFTER DELETE ON $table
            FOR EACH ROW
            INSERT INTO deletions (user_id, table_name, server_id)
            VALUES ($userIdExpr, '$table', OLD.id)");
    }
};
```

- [ ] **Step 2.5: Write migration 003 (updated_at on image tables)**

Write `database/migrations/003_image_updated_at.php`:

```php
<?php
/**
 * Migration 003: add updated_at to game_images and item_images.
 *
 * These tables don't currently have updated_at, so the iOS sync engine
 * can't tell whether an extra photo row has been modified server-side.
 * Adding the column with ON UPDATE CURRENT_TIMESTAMP makes it
 * self-maintaining.
 */
return function (PDO $pdo): void {
    foreach (['game_images', 'item_images'] as $table) {
        try {
            $pdo->exec("ALTER TABLE $table
                ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP");
        } catch (PDOException $e) {
            // Column already exists — ignore (matches project convention).
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
        try {
            $pdo->exec("ALTER TABLE $table ADD INDEX idx_updated_at (updated_at)");
        } catch (PDOException $e) {
            // Index already exists — ignore.
        }
    }
};
```

- [ ] **Step 2.6: Write the migration runner**

Write `database/migrate.php`:

```php
<?php
/**
 * Migration runner.
 *
 * Each file in database/migrations/ returns a closure that takes a PDO
 * and applies its changes idempotently. Migrations are applied in
 * filename order. There's no migration ledger because every migration
 * is required to be idempotent — re-running is a no-op.
 *
 * Usage:
 *   php database/migrate.php
 */

require_once __DIR__ . '/../includes/config.php';

if (!isset($pdo)) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.php');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    echo "Applying $name... ";
    $migration = require $file;
    if (!is_callable($migration)) {
        fwrite(STDERR, "ERROR: $name did not return a callable\n");
        exit(1);
    }
    try {
        $migration($pdo);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations applied.\n";
```

- [ ] **Step 2.7: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: 6 passed, 0 failed.

- [ ] **Step 2.8: Manually verify migrations are idempotent**

Run migrations a second time directly:
```bash
GT_DB_NAME=gameTracker_test GT_DB_USER=root php database/migrate.php
```

Expected: prints `Applying 001…ok`, `Applying 002…ok`, `Applying 003…ok`, `All migrations applied.` (no errors).

- [ ] **Step 2.9: Commit**

```bash
git add database/migrate.php database/migrations tests/v2/test_smoke.sh
git commit -m "Add migration runner + migrations for api_tokens, deletions, image updated_at"
```

---

## Task 3: Bearer-token authentication helper

**Files:**
- Create: `api/v2/_helpers.php`
- Create: `api/v2/_auth.php`
- Create: `tests/v2/test_auth_helper.sh`

Every v2 endpoint needs to check the `Authorization: Bearer …` header and look up the user. Centralise that here.

- [ ] **Step 3.1: Write the failing test**

Write `tests/v2/test_auth_helper.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "Auth helper: ping endpoint without token returns 401"
req GET "/api/v2/_ping.php"
assert_eq "401" "$HTTP_STATUS" "no token = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "error code is missing_token"

blue "Auth helper: ping endpoint with garbage token returns 401"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer not-a-real-token"
assert_eq "401" "$HTTP_STATUS" "bad token = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "error code is invalid_token"

# We can't test the success path until token issuance exists (Task 4).
# That test is added in Task 4.

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_auth_helper.sh
```

- [ ] **Step 3.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: the new tests fail (no `/api/v2/_ping.php` exists yet — likely 404).

- [ ] **Step 3.3: Write the shared helpers file**

Write `api/v2/_helpers.php`:

```php
<?php
/**
 * Shared helpers for /api/v2/ endpoints.
 *
 * v2 uses a slightly different response shape than v1:
 *   success: { "data": {...} }
 *   error:   { "error": "code_slug", "message": "human readable" }
 *
 * Bearer tokens replace cookie sessions. Most endpoints call
 * require_v2_auth() at the top to verify the token and populate
 * $GLOBALS['v2_user_id'].
 */

// Suppress browser-friendly error output; we want clean JSON.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
header('Content-Type: application/json');

function v2_ok(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function v2_error(string $code, string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function v2_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        v2_error('method_not_allowed', "Use $method", 405);
    }
}

/**
 * Read JSON body into an associative array. Returns [] for empty body.
 */
function v2_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        v2_error('bad_request', 'Body must be a JSON object', 400);
    }
    return $decoded;
}
```

- [ ] **Step 3.4: Write the auth helper file**

Write `api/v2/_auth.php`:

```php
<?php
/**
 * Bearer-token authentication for /api/v2/.
 *
 * Tokens are random 32 bytes, presented to clients as 64 hex chars.
 * The server stores SHA-256 of the token (not bcrypt — we look tokens
 * up on every request, so the hash must be cheap and deterministic).
 *
 * Usage at the top of an endpoint:
 *
 *   require_once __DIR__ . '/../_auth.php';
 *   require_once __DIR__ . '/../_helpers.php';
 *   $userId = v2_require_auth($pdo);
 */

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
 * Verify the request's Bearer token. On success, returns the user ID.
 * On failure, sends a JSON error response and exits.
 */
function v2_require_auth(PDO $pdo): int {
    $raw = v2_extract_bearer();
    if ($raw === null) {
        v2_error('missing_token', 'Authorization: Bearer <token> required', 401);
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
```

- [ ] **Step 3.5: Write a tiny ping endpoint to exercise the helper**

Write `api/v2/_ping.php`:

```php
<?php
/**
 * Internal endpoint used by the test harness to verify auth wiring.
 * Returns the authenticated user's ID on success.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);
v2_ok(['user_id' => $userId, 'pong' => true]);
```

- [ ] **Step 3.6: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: smoke + migration tests + 2 new auth-helper tests all pass.

- [ ] **Step 3.7: Commit**

```bash
git add api/v2/_helpers.php api/v2/_auth.php api/v2/_ping.php tests/v2/test_auth_helper.sh
git commit -m "Add v2 auth helper and shared response helpers"
```

---

## Task 4: Token issue & revoke endpoints

**Files:**
- Create: `api/v2/auth/token.php`
- Create: `api/v2/auth/revoke.php`
- Create: `tests/v2/test_auth.sh`

- [ ] **Step 4.1: Write failing tests**

Write `tests/v2/test_auth.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "POST /api/v2/auth/token with wrong password returns 401"
req POST "/api/v2/auth/token" "username=testuser&password=WRONG"
assert_eq "401" "$HTTP_STATUS" "wrong password = 401"
assert_contains '"error":"invalid_credentials"' "$RESPONSE_BODY" "error code"

blue "POST /api/v2/auth/token with valid credentials returns a token"
req POST "/api/v2/auth/token" "username=testuser&password=test_password&device_name=test-iphone"
assert_eq "200" "$HTTP_STATUS" "valid login = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
USER_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.user_id')
[[ ${#TOKEN} -eq 64 ]] && green "  PASS: token is 64 chars (hex)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: token length is ${#TOKEN}"; FAIL_COUNT=$((FAIL_COUNT+1)); }
[[ "$USER_ID" != "null" && -n "$USER_ID" ]] && green "  PASS: user_id present" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: user_id missing"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Save token for later tests
echo "$TOKEN" > tests/v2/.last_token

blue "Ping endpoint with valid token returns 200"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "valid token = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "response is pong"

blue "POST /api/v2/auth/revoke revokes the token"
req POST "/api/v2/auth/revoke" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "revoke succeeds"

blue "Ping endpoint with revoked token returns 401"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "401" "$HTTP_STATUS" "revoked token = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "error code"

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_auth.sh
```

- [ ] **Step 4.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: new auth tests all fail (no `/api/v2/auth/token` exists yet).

- [ ] **Step 4.3: Write the token endpoint**

Write `api/v2/auth/token.php`:

```php
<?php
/**
 * POST /api/v2/auth/token
 *
 * Body (form-encoded):
 *   username, password, device_name (optional)
 *
 * Response on success:
 *   { "data": { "token": "<64 hex>", "user_id": N, "username": "..." } }
 *
 * The raw token is returned to the client EXACTLY ONCE. Only its hash
 * is stored server-side.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$deviceName = $_POST['device_name'] ?? null;

if ($username === '' || $password === '') {
    v2_error('bad_request', 'username and password are required', 400);
}

$stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password_hash'])) {
    if (function_exists('logSecurityEvent')) {
        logSecurityEvent('v2_token_failed', ['username' => $username]);
    }
    v2_error('invalid_credentials', 'Username or password is incorrect', 401);
}

// Generate 32 random bytes → 64 hex chars
$rawToken = bin2hex(random_bytes(32));
$tokenHash = v2_hash_token($rawToken);

$insert = $pdo->prepare("INSERT INTO api_tokens (user_id, token_hash, device_name) VALUES (?, ?, ?)");
$insert->execute([$user['id'], $tokenHash, $deviceName]);

if (function_exists('logSecurityEvent')) {
    logSecurityEvent('v2_token_issued', ['user_id' => $user['id'], 'device' => $deviceName]);
}

v2_ok([
    'token'    => $rawToken,
    'user_id'  => (int)$user['id'],
    'username' => $user['username'],
]);
```

- [ ] **Step 4.4: Write the revoke endpoint**

Write `api/v2/auth/revoke.php`:

```php
<?php
/**
 * POST /api/v2/auth/revoke
 *
 * Marks the *currently used* Bearer token as revoked. The client is
 * expected to discard the token after this call.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');

$raw = v2_extract_bearer();
if ($raw === null) {
    v2_error('missing_token', 'Authorization: Bearer <token> required', 401);
}
$hash = v2_hash_token($raw);

$stmt = $pdo->prepare("UPDATE api_tokens SET revoked_at = NOW()
    WHERE token_hash = ? AND revoked_at IS NULL");
$stmt->execute([$hash]);

v2_ok(['revoked' => $stmt->rowCount() > 0]);
```

- [ ] **Step 4.5: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass, including 7 new auth-flow tests.

- [ ] **Step 4.6: Commit**

```bash
git add api/v2/auth tests/v2/test_auth.sh
git commit -m "Add v2 auth/token and auth/revoke endpoints"
```

---

## Task 5: Thumbnail generation

**Files:**
- Create: `includes/thumbnail.php`
- Create: `scripts/generate-thumbnails.php`
- Modify: `api/upload.php` (call thumbnail generator after save)
- Modify: `api/download-external-image.php` (same)
- Create: `tests/v2/test_thumbnails.sh`

We do this before image-serving endpoints because the serving code needs the thumbnails to exist.

- [ ] **Step 5.1: Write the failing test**

Write `tests/v2/test_thumbnails.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Thumbnail helper: generates a thumbnail from a source image"

# Create a test source image (1024x1024 red square)
TMP_SRC="/tmp/v2_thumb_src.jpg"
TMP_DEST="/tmp/v2_thumb_dest.jpg"
rm -f "$TMP_SRC" "$TMP_DEST"

php -r '
$img = imagecreatetruecolor(1024, 1024);
$red = imagecolorallocate($img, 255, 0, 0);
imagefill($img, 0, 0, $red);
imagejpeg($img, "/tmp/v2_thumb_src.jpg", 90);
imagedestroy($img);
'

# Call the helper
php -r "
require '$PROJECT_ROOT/includes/thumbnail.php';
\$ok = gt_generate_thumbnail('/tmp/v2_thumb_src.jpg', '/tmp/v2_thumb_dest.jpg', 512);
exit(\$ok ? 0 : 1);
"

[[ -f "$TMP_DEST" ]] && green "  PASS: thumbnail file exists" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: thumbnail not created"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Verify size
SIZE=$(php -r 'list($w, $h) = getimagesize("/tmp/v2_thumb_dest.jpg"); echo max($w, $h);')
assert_eq "512" "$SIZE" "thumbnail longest edge is 512px"

# Verify file size is meaningfully smaller
SRC_BYTES=$(stat -f%z "/tmp/v2_thumb_src.jpg" 2>/dev/null || stat -c%s "/tmp/v2_thumb_src.jpg")
DEST_BYTES=$(stat -f%z "/tmp/v2_thumb_dest.jpg" 2>/dev/null || stat -c%s "/tmp/v2_thumb_dest.jpg")
if (( DEST_BYTES < SRC_BYTES )); then
  green "  PASS: thumbnail is smaller ($DEST_BYTES < $SRC_BYTES bytes)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: thumbnail not smaller ($DEST_BYTES vs $SRC_BYTES)"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_thumbnails.sh
```

- [ ] **Step 5.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: the new test fails — `includes/thumbnail.php` doesn't exist.

- [ ] **Step 5.3: Write the thumbnail helper**

Write `includes/thumbnail.php`:

```php
<?php
/**
 * Thumbnail generation using the GD extension.
 *
 * Generates a JPEG thumbnail whose longest edge is $maxDimension pixels.
 * Aspect ratio is preserved. Quality fixed at 80 (good visual quality
 * with ~70% smaller file size than 95).
 *
 * Returns true on success, false on failure (logs the reason via error_log).
 */

function gt_generate_thumbnail(string $srcPath, string $destPath, int $maxDimension = 512): bool {
    if (!file_exists($srcPath)) {
        error_log("gt_generate_thumbnail: source missing $srcPath");
        return false;
    }
    $info = @getimagesize($srcPath);
    if ($info === false) {
        error_log("gt_generate_thumbnail: not an image $srcPath");
        return false;
    }
    [$srcW, $srcH, $type] = $info;

    // Load source.
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
        default:
            error_log("gt_generate_thumbnail: unsupported type $type");
            return false;
    }
    if (!$src) {
        error_log("gt_generate_thumbnail: failed to load $srcPath");
        return false;
    }

    // Calculate target dimensions, preserving aspect ratio.
    if ($srcW <= $maxDimension && $srcH <= $maxDimension) {
        $dstW = $srcW;
        $dstH = $srcH;
    } elseif ($srcW >= $srcH) {
        $dstW = $maxDimension;
        $dstH = (int)round($srcH * ($maxDimension / $srcW));
    } else {
        $dstH = $maxDimension;
        $dstW = (int)round($srcW * ($maxDimension / $srcH));
    }

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Preserve transparency on PNG/GIF sources by filling white.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    // Ensure destination directory exists.
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $ok = imagejpeg($dst, $destPath, 80);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        error_log("gt_generate_thumbnail: imagejpeg failed for $destPath");
    }
    return $ok;
}

/**
 * Given a path like 'uploads/covers/abc.jpg', returns the corresponding
 * thumbnail path: 'uploads/covers/thumbs/abc.jpg'.
 */
function gt_thumbnail_path(string $originalPath): string {
    $dir = dirname($originalPath);
    $name = basename($originalPath);
    return $dir . '/thumbs/' . $name;
}
```

- [ ] **Step 5.4: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass, including 3 new thumbnail tests.

- [ ] **Step 5.5: Hook thumbnail generation into the existing upload endpoint**

Read `api/upload.php` to find where new images are saved:
```bash
grep -n 'move_uploaded_file\|imagejpeg\|copy(' api/upload.php
```

For each location where a final image file is saved (the function's success path), add immediately after the save:

```php
// Generate thumbnail (best-effort; failure is non-fatal)
require_once __DIR__ . '/../includes/thumbnail.php';
gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);
```

Replace `$targetPath` with the actual variable used at that location. (When executing, read the file and adapt the exact insertion.)

- [ ] **Step 5.6: Hook thumbnail generation into the external-image download**

Same change in `api/download-external-image.php` — after the file is written (look for `file_put_contents` or similar), add:

```php
require_once __DIR__ . '/../includes/thumbnail.php';
gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);
```

- [ ] **Step 5.7: Write the backfill script**

Write `scripts/generate-thumbnails.php`:

```php
<?php
/**
 * One-off backfill: generate thumbnails for every existing cover and
 * extra image. Safe to re-run — skips images that already have a thumb.
 *
 * Usage:
 *   php scripts/generate-thumbnails.php
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/thumbnail.php';

$dirs = [
    __DIR__ . '/../uploads/covers',
    __DIR__ . '/../uploads/extras',
];

$total = 0;
$created = 0;
$skipped = 0;
$failed = 0;

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        echo "Skipping missing dir: $dir\n";
        continue;
    }
    // Recurse one level for items/<userid>/ subfolders that may exist.
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        // Skip files already inside a 'thumbs' folder.
        if (strpos($path, '/thumbs/') !== false) continue;
        // Skip non-images.
        if (!@getimagesize($path)) continue;

        $total++;
        $thumbPath = gt_thumbnail_path($path);
        if (file_exists($thumbPath)) {
            $skipped++;
            continue;
        }
        if (gt_generate_thumbnail($path, $thumbPath, 512)) {
            $created++;
            if ($created % 50 === 0) echo "  ...$created created\n";
        } else {
            $failed++;
            echo "  FAILED: $path\n";
        }
    }
}

echo "Done. Total scanned: $total, created: $created, skipped (already had thumb): $skipped, failed: $failed\n";
```

- [ ] **Step 5.8: Manually verify backfill script runs cleanly**

Set up a test image and run the script:
```bash
mkdir -p uploads/covers
cp /tmp/v2_thumb_src.jpg uploads/covers/backfill_test.jpg
php scripts/generate-thumbnails.php
ls uploads/covers/thumbs/
```

Expected: prints "Total scanned: 1, created: 1, skipped: 0, failed: 0"; `uploads/covers/thumbs/backfill_test.jpg` exists.

Then run it a second time:
```bash
php scripts/generate-thumbnails.php
```
Expected: "Total scanned: 1, created: 0, skipped: 1, failed: 0".

Clean up:
```bash
rm uploads/covers/backfill_test.jpg uploads/covers/thumbs/backfill_test.jpg
```

- [ ] **Step 5.9: Commit**

```bash
git add includes/thumbnail.php scripts/generate-thumbnails.php api/upload.php api/download-external-image.php tests/v2/test_thumbnails.sh
git commit -m "Add thumbnail generation helper, backfill script, and upload hooks"
```

---

## Task 6: Image-serving endpoints

**Files:**
- Create: `api/v2/images/cover.php`
- Create: `api/v2/images/extra.php`
- Create: `tests/v2/test_images.sh`

These endpoints look up the image's stored path for the authenticated user, then stream the file (or its thumbnail) back. They never accept arbitrary paths from the client — only the row's database ID — so there's no path-traversal surface.

- [ ] **Step 6.1: Write failing tests**

Write `tests/v2/test_images.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

TOKEN=$(cat tests/v2/.last_token 2>/dev/null || echo "")
if [[ -z "$TOKEN" ]]; then
  red "FATAL: no token saved from test_auth.sh — run test_auth.sh first"
  exit 1
fi

# Re-issue token (previous one was revoked at end of test_auth.sh)
req POST "/api/v2/auth/token" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
echo "$TOKEN" > tests/v2/.last_token

# Seed: insert a game + a cover image file for the test user
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$PROJECT_ROOT/uploads/covers/thumbs"

# Create a real JPEG so getimagesize works
php -r '
$img = imagecreatetruecolor(100, 100);
imagejpeg($img, "'"$PROJECT_ROOT"'/uploads/covers/test_image.jpg", 90);
$img2 = imagecreatetruecolor(50, 50);
imagejpeg($img2, "'"$PROJECT_ROOT"'/uploads/covers/thumbs/test_image.jpg", 80);
'

USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform, front_cover_image)
  VALUES ($USER_ID, 'Test Game', 'Test Platform', 'uploads/covers/test_image.jpg');
"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Test Game'")

blue "GET /api/v2/images/cover.php without token returns 401"
req GET "/api/v2/images/cover.php?id=$GAME_ID&size=thumb"
assert_eq "401" "$HTTP_STATUS" "no token = 401"

blue "GET cover thumb returns image bytes"
curl -sS -o /tmp/v2_cover_thumb "$BASE_URL/api/v2/images/cover.php?id=$GAME_ID&size=thumb" \
  -H "Authorization: Bearer $TOKEN"
SIZE=$(php -r 'list($w, $h) = @getimagesize("/tmp/v2_cover_thumb") ?: [0, 0]; echo "$w";')
assert_eq "50" "$SIZE" "thumb width is 50"

blue "GET cover full returns full-size image"
curl -sS -o /tmp/v2_cover_full "$BASE_URL/api/v2/images/cover.php?id=$GAME_ID&size=full" \
  -H "Authorization: Bearer $TOKEN"
SIZE=$(php -r 'list($w, $h) = @getimagesize("/tmp/v2_cover_full") ?: [0, 0]; echo "$w";')
assert_eq "100" "$SIZE" "full width is 100"

blue "GET cover for another user's game returns 404"
# Create a second user with their own game
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO users (username, password_hash, role) VALUES
    ('otheruser', 'x', 'user');
  INSERT INTO games (user_id, title, platform, front_cover_image)
  VALUES ((SELECT id FROM users WHERE username='otheruser'), 'Other Game', 'X', 'uploads/covers/other.jpg');
"
OTHER_GAME=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Other Game'")
req GET "/api/v2/images/cover.php?id=$OTHER_GAME&size=thumb" "" -H "Authorization: Bearer $TOKEN"
assert_eq "404" "$HTTP_STATUS" "other user's game = 404"

# Cleanup
rm -f "$PROJECT_ROOT/uploads/covers/test_image.jpg" "$PROJECT_ROOT/uploads/covers/thumbs/test_image.jpg"

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_images.sh
```

- [ ] **Step 6.2: Run tests, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: the new image tests fail (no endpoint yet).

- [ ] **Step 6.3: Write the cover endpoint**

Write `api/v2/images/cover.php`:

```php
<?php
/**
 * GET /api/v2/images/cover.php?id=<game_id>&size=thumb|full[&face=front|back]
 *
 * Streams the cover image for the given game, if it belongs to the
 * authenticated user. Defaults: face=front, size=full.
 *
 * On success, sends raw image bytes with appropriate Content-Type.
 * Errors are returned as JSON.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

$userId = v2_require_auth($pdo);

$gameId = (int)($_GET['id'] ?? 0);
$size   = $_GET['size'] ?? 'full';
$face   = $_GET['face'] ?? 'front';

if ($gameId <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}
if (!in_array($face, ['front', 'back'], true)) {
    v2_error('bad_request', 'face must be front or back', 400);
}

$col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
$stmt = $pdo->prepare("SELECT $col AS path FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['path'])) {
    v2_error('not_found', 'Image not found', 404);
}

$relative = ltrim($row['path'], '/');
$projectRoot = realpath(__DIR__ . '/../../..');
$fullPath = $projectRoot . '/' . $relative;

if ($size === 'thumb') {
    require_once __DIR__ . '/../../../includes/thumbnail.php';
    $thumbPath = gt_thumbnail_path($fullPath);
    if (file_exists($thumbPath)) {
        $fullPath = $thumbPath;
    }
    // If no thumb exists, fall through to the full image rather than 404.
}

if (!file_exists($fullPath)) {
    v2_error('not_found', 'Image file missing on disk', 404);
}

// Bounds-check: never serve anything outside uploads/.
$realFull = realpath($fullPath);
$uploadsRoot = realpath($projectRoot . '/uploads');
if ($realFull === false || strpos($realFull, $uploadsRoot) !== 0) {
    v2_error('forbidden', 'Path escapes uploads directory', 403);
}

// Stream the file.
$info = @getimagesize($realFull);
$mime = $info['mime'] ?? 'application/octet-stream';
header_remove('Content-Type'); // _helpers.php set JSON; we want the image MIME.
header("Content-Type: $mime");
header("Content-Length: " . filesize($realFull));
header("Cache-Control: private, max-age=3600");
readfile($realFull);
exit;
```

- [ ] **Step 6.4: Write the extra-image endpoint**

Write `api/v2/images/extra.php`:

```php
<?php
/**
 * GET /api/v2/images/extra.php?id=<image_id>&size=thumb|full
 *
 * Streams an extra photo (game_images or item_images) for the
 * authenticated user. The ?type=game|item param selects the table.
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

$userId = v2_require_auth($pdo);

$id   = (int)($_GET['id'] ?? 0);
$type = $_GET['type'] ?? 'game';
$size = $_GET['size'] ?? 'full';

if ($id <= 0) {
    v2_error('bad_request', 'id is required', 400);
}
if (!in_array($type, ['game', 'item'], true)) {
    v2_error('bad_request', 'type must be game or item', 400);
}
if (!in_array($size, ['thumb', 'full'], true)) {
    v2_error('bad_request', 'size must be thumb or full', 400);
}

$table = $type === 'item' ? 'item_images' : 'game_images';
$stmt = $pdo->prepare("SELECT image_path AS path FROM $table WHERE id = ? AND user_id = ?");
$stmt->execute([$id, $userId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || empty($row['path'])) {
    v2_error('not_found', 'Image not found', 404);
}

$projectRoot = realpath(__DIR__ . '/../../..');
$fullPath = $projectRoot . '/' . ltrim($row['path'], '/');

if ($size === 'thumb') {
    require_once __DIR__ . '/../../../includes/thumbnail.php';
    $thumbPath = gt_thumbnail_path($fullPath);
    if (file_exists($thumbPath)) {
        $fullPath = $thumbPath;
    }
}

if (!file_exists($fullPath)) {
    v2_error('not_found', 'Image file missing on disk', 404);
}

$realFull = realpath($fullPath);
$uploadsRoot = realpath($projectRoot . '/uploads');
if ($realFull === false || strpos($realFull, $uploadsRoot) !== 0) {
    v2_error('forbidden', 'Path escapes uploads directory', 403);
}

$info = @getimagesize($realFull);
$mime = $info['mime'] ?? 'application/octet-stream';
header_remove('Content-Type');
header("Content-Type: $mime");
header("Content-Length: " . filesize($realFull));
header("Cache-Control: private, max-age=3600");
readfile($realFull);
exit;
```

- [ ] **Step 6.5: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass, including 4 new image tests.

- [ ] **Step 6.6: Commit**

```bash
git add api/v2/images tests/v2/test_images.sh
git commit -m "Add v2 image-serving endpoints (cover and extra)"
```

---

## Task 7: Sync — read changes endpoint

**Files:**
- Create: `api/v2/sync/changes.php`
- Create: `tests/v2/test_sync_changes.sh`

This endpoint is the "pull" half of sync. The phone sends `?since=<iso8601>`; the server returns every row for the user whose `updated_at > since`, plus deletion tombstones since that time.

- [ ] **Step 7.1: Write failing tests**

Write `tests/v2/test_sync_changes.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

# Get fresh token
req POST "/api/v2/auth/token" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "GET /api/v2/sync/changes.php with since=0 returns all user rows"
req GET "/api/v2/sync/changes.php?since=1970-01-01T00:00:00Z" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "200 ok"

HAS_GAMES=$(echo "$RESPONSE_BODY" | jq '.data.games | type')
assert_eq '"array"' "$HAS_GAMES" "games is an array"

HAS_ITEMS=$(echo "$RESPONSE_BODY" | jq '.data.items | type')
assert_eq '"array"' "$HAS_ITEMS" "items is an array"

HAS_COMPLETIONS=$(echo "$RESPONSE_BODY" | jq '.data.game_completions | type')
assert_eq '"array"' "$HAS_COMPLETIONS" "game_completions is an array"

HAS_DELETIONS=$(echo "$RESPONSE_BODY" | jq '.data.deletions | type')
assert_eq '"array"' "$HAS_DELETIONS" "deletions is an array"

HAS_NOW=$(echo "$RESPONSE_BODY" | jq -r '.data.server_now')
[[ "$HAS_NOW" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T ]] && green "  PASS: server_now is ISO-8601" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: server_now=$HAS_NOW"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Seed a game that should show up
USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES ($USER_ID, 'Sync Test Game', 'TestPlatform');
"

req GET "/api/v2/sync/changes.php?since=1970-01-01T00:00:00Z" "" -H "Authorization: Bearer $TOKEN"
GAME_COUNT=$(echo "$RESPONSE_BODY" | jq '.data.games | length')
[[ "$GAME_COUNT" -ge 1 ]] && green "  PASS: at least one game returned" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: game_count=$GAME_COUNT"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "GET with since=NOW returns no new rows"
NOW_ISO=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
sleep 1
req GET "/api/v2/sync/changes.php?since=$NOW_ISO" "" -H "Authorization: Bearer $TOKEN"
GAME_COUNT=$(echo "$RESPONSE_BODY" | jq '.data.games | length')
assert_eq "0" "$GAME_COUNT" "no new games after NOW"

blue "Deletion shows up in tombstones"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Sync Test Game'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "DELETE FROM games WHERE id=$GAME_ID"
req GET "/api/v2/sync/changes.php?since=$NOW_ISO" "" -H "Authorization: Bearer $TOKEN"
DEL_COUNT=$(echo "$RESPONSE_BODY" | jq "[.data.deletions[] | select(.table_name==\"games\" and .server_id==$GAME_ID)] | length")
assert_eq "1" "$DEL_COUNT" "deletion tombstone present"

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_sync_changes.sh
```

- [ ] **Step 7.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: new sync tests fail (no endpoint).

- [ ] **Step 7.3: Write the changes endpoint**

Write `api/v2/sync/changes.php`:

```php
<?php
/**
 * GET /api/v2/sync/changes.php?since=<iso8601>
 *
 * Returns all rows (per synced table) belonging to the authenticated
 * user whose updated_at > since, plus all deletion tombstones since
 * that time.
 *
 * Response shape:
 *   {
 *     "data": {
 *       "games":            [ ...rows... ],
 *       "items":            [ ...rows... ],
 *       "game_completions": [ ...rows... ],
 *       "game_images":      [ ...rows... ],
 *       "item_images":      [ ...rows... ],
 *       "deletions":        [ { table_name, server_id, deleted_at } ],
 *       "server_now":       "2026-05-15T14:32:00Z"
 *     }
 *   }
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('GET');
$userId = v2_require_auth($pdo);

$since = $_GET['since'] ?? '1970-01-01T00:00:00Z';
// Validate ISO 8601 by attempting to parse it.
$sinceDt = DateTime::createFromFormat(DateTime::ATOM, $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $since)
        ?: DateTime::createFromFormat('Y-m-d\TH:i:s.u\Z', $since);
if ($sinceDt === false) {
    v2_error('bad_request', 'since must be ISO 8601', 400);
}
$sinceMysql = $sinceDt->format('Y-m-d H:i:s');

function fetchChanges(PDO $pdo, string $table, int $userId, string $sinceMysql): array {
    $stmt = $pdo->prepare("SELECT * FROM $table
        WHERE user_id = ? AND updated_at > ?
        ORDER BY updated_at ASC");
    $stmt->execute([$userId, $sinceMysql]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$data = [
    'games'            => fetchChanges($pdo, 'games',            $userId, $sinceMysql),
    'items'            => fetchChanges($pdo, 'items',            $userId, $sinceMysql),
    'game_completions' => fetchChanges($pdo, 'game_completions', $userId, $sinceMysql),
    'game_images'      => fetchChanges($pdo, 'game_images',      $userId, $sinceMysql),
    'item_images'      => fetchChanges($pdo, 'item_images',      $userId, $sinceMysql),
];

// Deletion tombstones.
$stmt = $pdo->prepare("SELECT table_name, server_id, deleted_at
    FROM deletions
    WHERE user_id = ? AND deleted_at > ?
    ORDER BY deleted_at ASC");
$stmt->execute([$userId, $sinceMysql]);
$data['deletions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$data['server_now'] = gmdate('Y-m-d\TH:i:s\Z');

v2_ok($data);
```

- [ ] **Step 7.4: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass.

- [ ] **Step 7.5: Commit**

```bash
git add api/v2/sync/changes.php tests/v2/test_sync_changes.sh
git commit -m "Add v2 sync/changes delta-read endpoint"
```

---

## Task 8: Sync — push endpoint with conflict detection

**Files:**
- Create: `api/v2/sync/push.php`
- Create: `tests/v2/test_sync_push.sh`

The "push" half of sync. The phone sends a JSON body containing local rows in three buckets per table: `new` (no server_id yet), `modified` (have server_id, edited locally), `deleted` (have server_id, deleted locally). Server returns a per-row result: `accepted` (with the assigned/updated server_id and timestamp) or `conflict` (with the server's current row, so the phone can show a conflict prompt).

- [ ] **Step 8.1: Write failing tests**

Write `tests/v2/test_sync_push.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Push: new game (no server_id) gets accepted and assigned an id"
JSON='{
  "games": {
    "new": [
      {"client_id": "phone-uuid-1", "title": "Phone-Created Game", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
assert_eq "200" "$HTTP_STATUS" "push succeeds"

RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "new row accepted"
NEW_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].server_id')
[[ "$NEW_ID" =~ ^[0-9]+$ ]] && green "  PASS: server_id assigned: $NEW_ID" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: server_id=$NEW_ID"; FAIL_COUNT=$((FAIL_COUNT+1)); }
CLIENT_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].client_id')
assert_eq "phone-uuid-1" "$CLIENT_ID" "client_id echoed back"

blue "Push: modified game with up-to-date last_synced_at is accepted"
LAST_SYNCED=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT DATE_FORMAT(updated_at, '%Y-%m-%dT%H:%i:%sZ') FROM games WHERE id=$NEW_ID")
JSON='{
  "games": {
    "modified": [
      {"server_id": '"$NEW_ID"', "last_synced_at": "'"$LAST_SYNCED"'", "title": "Phone Edited", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "modified row accepted when no conflict"

blue "Push: modified game with stale last_synced_at returns conflict"
# Bump the server row's updated_at by directly updating it (simulate web-side edit)
sleep 2
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "UPDATE games SET title='Web Edited' WHERE id=$NEW_ID"
# Use the OLD last_synced_at — the one captured before the web edit above
JSON='{
  "games": {
    "modified": [
      {"server_id": '"$NEW_ID"', "last_synced_at": "'"$LAST_SYNCED"'", "title": "Phone Edited Again", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "conflict" "$RESULT" "stale modified returns conflict"
SERVER_VER_TITLE=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].server_version.title')
assert_eq "Web Edited" "$SERVER_VER_TITLE" "conflict includes server version"

blue "Push: deletion is processed"
JSON='{
  "games": {
    "deleted": [
      {"server_id": '"$NEW_ID"'}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "deletion accepted"

DB_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT COUNT(*) FROM games WHERE id=$NEW_ID")
assert_eq "0" "$DB_COUNT" "row actually deleted"

DEL_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT COUNT(*) FROM deletions WHERE table_name='games' AND server_id=$NEW_ID")
assert_eq "1" "$DEL_COUNT" "tombstone written"

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_sync_push.sh
```

- [ ] **Step 8.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: new push tests fail.

- [ ] **Step 8.3: Write the push endpoint**

Write `api/v2/sync/push.php`:

```php
<?php
/**
 * POST /api/v2/sync/push.php
 * Content-Type: application/json
 *
 * Body shape:
 *   {
 *     "games": {
 *       "new":      [ {client_id, title, platform, ...} ],
 *       "modified": [ {server_id, last_synced_at, title, ...} ],
 *       "deleted":  [ {server_id} ]
 *     },
 *     "items":            { ... },
 *     "game_completions": { ... },
 *     "game_images":      { ... },
 *     "item_images":      { ... }
 *   }
 *
 * For modified rows, the phone includes its `last_synced_at` — the
 * server's updated_at value the last time the phone successfully read
 * this row. If the server's current updated_at is newer, that means
 * the row was edited elsewhere since the phone last saw it: a conflict.
 *
 * Response: same shape as input, but each row replaced with a result:
 *   accepted: {client_id?, server_id, updated_at, result:"accepted"}
 *   conflict: {server_id, server_version:{...full row...}, result:"conflict"}
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');
$userId = v2_require_auth($pdo);
$body = v2_json_body();

// Columns that are user-writable per table. Any other field in the
// request body is silently ignored — defence in depth against a
// malicious client trying to set, say, user_id or created_at.
$writable = [
    'games' => ['title', 'platform', 'genre', 'description', 'series', 'special_edition',
                'condition', 'review', 'star_rating', 'metacritic_rating', 'played',
                'price_paid', 'pricecharting_price', 'is_physical', 'digital_store',
                'front_cover_image', 'back_cover_image', 'release_date'],
    'items' => ['title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'front_image', 'back_image',
                'notes', 'quantity'],
    'game_completions' => ['game_id', 'title', 'platform', 'time_taken',
                           'date_started', 'date_completed', 'completion_year', 'notes'],
    'game_images' => ['game_id', 'image_path'],
    'item_images' => ['item_id', 'image_path'],
];

function process_new(PDO $pdo, int $userId, string $table, array $cols, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $clientId = $row['client_id'] ?? null;
        $values = ['user_id' => $userId];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) $values[$c] = $row[$c];
        }
        $colList = implode(',', array_map(fn($c) => "`$c`", array_keys($values)));
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $stmt = $pdo->prepare("INSERT INTO $table ($colList) VALUES ($placeholders)");
        $stmt->execute(array_values($values));
        $newId = (int)$pdo->lastInsertId();
        $upd = $pdo->prepare("SELECT updated_at FROM $table WHERE id = ?");
        $upd->execute([$newId]);
        $updatedAt = $upd->fetchColumn();
        $results[] = [
            'client_id'  => $clientId,
            'server_id'  => $newId,
            'updated_at' => $updatedAt ? (new DateTime($updatedAt))->format('Y-m-d\TH:i:s\Z') : null,
            'result'     => 'accepted',
        ];
    }
    return $results;
}

function process_modified(PDO $pdo, int $userId, string $table, array $cols, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $serverId = (int)($row['server_id'] ?? 0);
        $lastSynced = $row['last_synced_at'] ?? null;
        if ($serverId <= 0 || $lastSynced === null) {
            $results[] = ['server_id' => $serverId, 'result' => 'rejected',
                          'reason' => 'server_id and last_synced_at required'];
            continue;
        }
        // Conflict check: server's updated_at must be <= phone's last_synced_at.
        $check = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND user_id = ?");
        $check->execute([$serverId, $userId]);
        $current = $check->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            $results[] = ['server_id' => $serverId, 'result' => 'not_found'];
            continue;
        }
        $serverUpdated = (new DateTime($current['updated_at']))->format('Y-m-d\TH:i:s\Z');
        $phoneSeen = (new DateTime($lastSynced))->format('Y-m-d\TH:i:s\Z');
        if ($serverUpdated > $phoneSeen) {
            $results[] = [
                'server_id'      => $serverId,
                'server_version' => $current,
                'result'         => 'conflict',
            ];
            continue;
        }
        // No conflict — apply the update.
        $sets = [];
        $values = [];
        foreach ($cols as $c) {
            if (array_key_exists($c, $row)) {
                $sets[] = "`$c` = ?";
                $values[] = $row[$c];
            }
        }
        if (!$sets) {
            $results[] = ['server_id' => $serverId, 'result' => 'accepted',
                          'updated_at' => $serverUpdated];
            continue;
        }
        $values[] = $serverId;
        $values[] = $userId;
        $upd = $pdo->prepare("UPDATE $table SET " . implode(',', $sets)
            . " WHERE id = ? AND user_id = ?");
        $upd->execute($values);
        $reread = $pdo->prepare("SELECT updated_at FROM $table WHERE id = ?");
        $reread->execute([$serverId]);
        $newUpdated = $reread->fetchColumn();
        $results[] = [
            'server_id'  => $serverId,
            'updated_at' => $newUpdated ? (new DateTime($newUpdated))->format('Y-m-d\TH:i:s\Z') : null,
            'result'     => 'accepted',
        ];
    }
    return $results;
}

function process_deleted(PDO $pdo, int $userId, string $table, array $rows): array {
    $results = [];
    foreach ($rows as $row) {
        $serverId = (int)($row['server_id'] ?? 0);
        if ($serverId <= 0) {
            $results[] = ['server_id' => $serverId, 'result' => 'rejected'];
            continue;
        }
        $del = $pdo->prepare("DELETE FROM $table WHERE id = ? AND user_id = ?");
        $del->execute([$serverId, $userId]);
        $results[] = ['server_id' => $serverId, 'result' => 'accepted'];
    }
    return $results;
}

$response = [];
foreach ($writable as $table => $cols) {
    $bucket = $body[$table] ?? [];
    $tableResults = array_merge(
        process_new($pdo, $userId, $table, $cols, $bucket['new'] ?? []),
        process_modified($pdo, $userId, $table, $cols, $bucket['modified'] ?? []),
        process_deleted($pdo, $userId, $table, $bucket['deleted'] ?? [])
    );
    $response[$table] = $tableResults;
}

v2_ok($response);
```

- [ ] **Step 8.4: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all push tests pass (8 new assertions).

- [ ] **Step 8.5: Commit**

```bash
git add api/v2/sync/push.php tests/v2/test_sync_push.sh
git commit -m "Add v2 sync/push endpoint with conflict detection"
```

---

## Task 9: Cover-upload endpoint

**Files:**
- Create: `api/v2/games/cover-upload.php`
- Create: `tests/v2/test_cover_upload.sh`

The phone uploads a new cover for an existing game. Multipart, returns the new path which the phone will store locally and sync back to the games row on the next push.

- [ ] **Step 9.1: Write failing tests**

Write `tests/v2/test_cover_upload.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Seed: insert a game
USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES ($USER_ID, 'Upload Test Game', 'TestPlatform');
"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Upload Test Game'")

# Create a real JPEG to upload
php -r '$img = imagecreatetruecolor(200, 200); imagejpeg($img, "/tmp/v2_upload.jpg", 90);'

blue "POST cover-upload without token returns 401"
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$GAME_ID&face=front" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
assert_eq "401" "$HTTP_STATUS" "no token = 401"

blue "POST cover-upload with token saves the file and updates the games row"
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$GAME_ID&face=front" \
  -H "Authorization: Bearer $TOKEN" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
assert_eq "200" "$HTTP_STATUS" "200 ok"

PATH_RETURNED=$(echo "$RESPONSE_BODY" | jq -r '.data.path')
[[ "$PATH_RETURNED" == uploads/covers/* ]] && green "  PASS: returned path is under uploads/covers" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: path=$PATH_RETURNED"; FAIL_COUNT=$((FAIL_COUNT+1)); }

[[ -f "$PROJECT_ROOT/$PATH_RETURNED" ]] && green "  PASS: file exists on disk" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: file missing: $PATH_RETURNED"; FAIL_COUNT=$((FAIL_COUNT+1)); }

THUMB_PATH=$(dirname "$PATH_RETURNED")/thumbs/$(basename "$PATH_RETURNED")
[[ -f "$PROJECT_ROOT/$THUMB_PATH" ]] && green "  PASS: thumbnail generated" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: thumb missing: $THUMB_PATH"; FAIL_COUNT=$((FAIL_COUNT+1)); }

DB_PATH=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT front_cover_image FROM games WHERE id=$GAME_ID")
assert_eq "$PATH_RETURNED" "$DB_PATH" "games.front_cover_image updated"

blue "POST cover-upload for another user's game returns 404"
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES (
    (SELECT id FROM users WHERE username='otheruser'), 'Other Upload', 'X');
"
OTHER_GAME=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Other Upload'")
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$OTHER_GAME&face=front" \
  -H "Authorization: Bearer $TOKEN" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
assert_eq "404" "$HTTP_STATUS" "other user's game = 404"

# Cleanup
rm -f "$PROJECT_ROOT/$PATH_RETURNED" "$PROJECT_ROOT/$THUMB_PATH"

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_cover_upload.sh
```

- [ ] **Step 9.2: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: cover-upload tests fail (no endpoint).

- [ ] **Step 9.3: Write the cover-upload endpoint**

Write `api/v2/games/cover-upload.php`:

```php
<?php
/**
 * POST /api/v2/games/cover-upload.php?game_id=<id>&face=front|back
 * Body: multipart/form-data with field "image"
 *
 * Validates the upload (uses the existing isValidImage() helper),
 * stores it under uploads/covers/, generates a thumbnail, updates
 * the games row's front_cover_image or back_cover_image.
 *
 * Response: { "data": { "path": "uploads/covers/<file>", "thumb_path": "..." } }
 */
require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../includes/functions.php';  // isValidImage, generateUniqueFilename
require_once __DIR__ . '/../../../includes/thumbnail.php';
require_once __DIR__ . '/../_helpers.php';
require_once __DIR__ . '/../_auth.php';

v2_require_method('POST');
$userId = v2_require_auth($pdo);

$gameId = (int)($_GET['game_id'] ?? 0);
$face   = $_GET['face'] ?? 'front';

if ($gameId <= 0)                              v2_error('bad_request', 'game_id required', 400);
if (!in_array($face, ['front', 'back'], true)) v2_error('bad_request', 'face must be front or back', 400);
if (!isset($_FILES['image']))                  v2_error('bad_request', 'image file required', 400);

// Verify game belongs to user.
$stmt = $pdo->prepare("SELECT id FROM games WHERE id = ? AND user_id = ?");
$stmt->execute([$gameId, $userId]);
if (!$stmt->fetch()) v2_error('not_found', 'Game not found', 404);

if (!isValidImage($_FILES['image'])) {
    v2_error('bad_request', 'Invalid image (type, size, or upload failed)', 400);
}

$projectRoot = realpath(__DIR__ . '/../../..');
$coversDir = $projectRoot . '/uploads/covers/';
$filename = generateUniqueFilename($_FILES['image']['name'], $coversDir);
$targetPath = $coversDir . $filename;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
    v2_error('server_error', 'Failed to move uploaded file', 500);
}

// Generate thumbnail (best-effort).
$thumbPath = gt_thumbnail_path($targetPath);
gt_generate_thumbnail($targetPath, $thumbPath, 512);

// Update games row.
$relative = 'uploads/covers/' . $filename;
$relativeThumb = 'uploads/covers/thumbs/' . $filename;
$col = $face === 'back' ? 'back_cover_image' : 'front_cover_image';
$upd = $pdo->prepare("UPDATE games SET $col = ? WHERE id = ? AND user_id = ?");
$upd->execute([$relative, $gameId, $userId]);

v2_ok([
    'path'       => $relative,
    'thumb_path' => $relativeThumb,
]);
```

- [ ] **Step 9.4: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass.

- [ ] **Step 9.5: Commit**

```bash
git add api/v2/games tests/v2/test_cover_upload.sh
git commit -m "Add v2 games/cover-upload endpoint"
```

---

## Task 10: External-image, PriceCharting, and Metacritic proxies

**Files:**
- Create: `api/v2/external-image.php`
- Create: `api/v2/pricecharting.php`
- Create: `api/v2/metacritic.php`
- Create: `tests/v2/test_proxies.sh`

These are thin wrappers that swap cookie-session auth for Bearer-token auth, then delegate to the existing v1 endpoints' logic. We don't re-implement the scraping or the URL validation — we include the v1 file or call its functions.

- [ ] **Step 10.1: Inspect existing v1 endpoints**

Read `api/download-external-image.php`, `api/pricecharting.php`, `api/metacritic.php` to understand the request shape they expect. Most use `$_GET` / `$_POST` and write JSON to stdout via `sendJsonResponse`.

- [ ] **Step 10.2: Write failing tests**

Write `tests/v2/test_proxies.sh`:

```bash
#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "Proxies require auth"
req GET "/api/v2/external-image.php?url=https://example.com/x.jpg"
assert_eq "401" "$HTTP_STATUS" "external-image no-auth = 401"

req GET "/api/v2/pricecharting.php?title=halo&platform=xbox360"
assert_eq "401" "$HTTP_STATUS" "pricecharting no-auth = 401"

req GET "/api/v2/metacritic.php?title=halo&platform=xbox360"
assert_eq "401" "$HTTP_STATUS" "metacritic no-auth = 401"

blue "Proxies validate input with auth"
# Bad URL — no scheme — should be a 400 from the v1 logic.
req GET "/api/v2/external-image.php?url=not-a-url" "" -H "Authorization: Bearer $TOKEN"
[[ "$HTTP_STATUS" == "400" || "$HTTP_STATUS" == "500" ]] && green "  PASS: bad URL = 4xx/5xx ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Missing title param for pricecharting.
req GET "/api/v2/pricecharting.php" "" -H "Authorization: Bearer $TOKEN"
[[ "$HTTP_STATUS" =~ ^[45] ]] && green "  PASS: missing title returns error ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Note: we don't test the success path because it requires hitting real
# external services. That gets validated in manual smoke after deployment.

summarize
```

Make executable:
```bash
chmod +x tests/v2/test_proxies.sh
```

- [ ] **Step 10.3: Run, verify failure**

```bash
bash tests/v2/run-all.sh
```

Expected: proxy tests fail (endpoints don't exist yet).

- [ ] **Step 10.4: Write external-image proxy**

Write `api/v2/external-image.php`:

```php
<?php
/**
 * GET /api/v2/external-image.php?url=<https url>&game_id=<id>&type=front|back
 *
 * Thin wrapper around v1's download-external-image.php that uses Bearer-token
 * auth instead of session auth. The v1 file's logic (validating the URL,
 * downloading via curl, saving locally) is reused by injecting the
 * authenticated user into the session before requiring it.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);

// The v1 endpoint reads $_SESSION['user_id'] / $_SESSION['username'].
// Populate them so the v1 logic accepts the request.
$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;

// The v1 file expects POST or GET; both work. It writes its own JSON
// response and exits, so we just include it.
require __DIR__ . '/../download-external-image.php';
```

- [ ] **Step 10.5: Write pricecharting proxy**

Write `api/v2/pricecharting.php`:

```php
<?php
/**
 * GET /api/v2/pricecharting.php?title=<title>&platform=<platform>
 *
 * Thin Bearer-auth wrapper around the v1 pricecharting endpoint.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;

require __DIR__ . '/../pricecharting.php';
```

- [ ] **Step 10.6: Write metacritic proxy**

Write `api/v2/metacritic.php`:

```php
<?php
/**
 * GET /api/v2/metacritic.php?title=<title>&platform=<platform>
 *
 * Thin Bearer-auth wrapper around the v1 metacritic endpoint.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/_auth.php';

$userId = v2_require_auth($pdo);

$stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
$stmt->execute([$userId]);
$username = $stmt->fetchColumn();
$_SESSION['user_id']  = $userId;
$_SESSION['username'] = $username;

require __DIR__ . '/../metacritic.php';
```

- [ ] **Step 10.7: Run tests, verify they pass**

```bash
bash tests/v2/run-all.sh
```

Expected: all tests pass (5 new proxy tests).

- [ ] **Step 10.8: Commit**

```bash
git add api/v2/external-image.php api/v2/pricecharting.php api/v2/metacritic.php tests/v2/test_proxies.sh
git commit -m "Add v2 proxy endpoints for external-image, pricecharting, metacritic"
```

---

## Task 11: Nginx routing for /api/v2/

**Files:**
- Modify: `nginx-gameTracker.conf`

When deployed to the live server, Nginx needs to route `/api/v2/...` to PHP-FPM the same way it does `/api/...`. Most production nginx configs already have a generic `location ~ \.php$` block that handles this automatically. We verify and (if needed) add an explicit block for security headers.

- [ ] **Step 11.1: Read the existing nginx config**

```bash
cat nginx-gameTracker.conf
```

Confirm there's a `location ~ \.php$` block or similar that runs PHP files. If yes, the v2 endpoints route through it automatically — no changes needed.

- [ ] **Step 11.2: Add an explicit location block for v2 (optional but recommended)**

If the existing config doesn't already grant v2 endpoints the same treatment, append a `location` block before the generic PHP block:

```nginx
# v2 API endpoints (Bearer-token auth, used by iOS app)
location ~ ^/api/v2/.*\.php$ {
    try_files $uri =404;
    fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    include fastcgi_params;
    # Forward Authorization header to PHP (Nginx strips it by default in some setups)
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    # Generous body size for image uploads
    client_max_body_size 10M;
}
```

Adjust the `fastcgi_pass` socket path to match the existing config's value.

- [ ] **Step 11.3: Commit**

```bash
git add nginx-gameTracker.conf
git commit -m "Add nginx routing block for /api/v2/ endpoints"
```

---

## Task 12: Deployment dry-run on live server

**Files:** none (procedural task)

This task isn't code — it's the end-to-end verification that all the new work actually runs on the live Linux box without breaking anything.

- [ ] **Step 12.1: Pull the new branch on the Linux server**

On the Linux server:
```bash
cd /var/www/gameTracker  # or wherever the site lives
git fetch origin
git checkout <branch-name>
```

- [ ] **Step 12.2: Run migrations against the live MySQL**

```bash
php database/migrate.php
```

Expected: prints `Applying 001…ok`, `Applying 002…ok`, `Applying 003…ok`, `All migrations applied.` and exits 0.

If anything fails, do **not** proceed. Fix the migration and retry.

- [ ] **Step 12.3: Verify the web app still loads**

Open the live site in a browser. Log in. Browse games. Add a test game. Edit it. Delete it.

Expected: nothing changes from the user's perspective. (We added columns and tables but didn't change any existing endpoints.)

- [ ] **Step 12.4: Run thumbnail backfill**

```bash
php scripts/generate-thumbnails.php
```

Expected: prints progress and a final "Total scanned: N, created: N, skipped: 0, failed: 0" line.

- [ ] **Step 12.5: Reload nginx**

```bash
sudo nginx -t
sudo systemctl reload nginx
```

Expected: `syntax is ok`, `test is successful`, no errors on reload.

- [ ] **Step 12.6: Smoke-test v2 endpoints against the live server**

From the Mac:
```bash
# Replace YOUR_DUCKDNS_HOST with the actual hostname
HOST="https://YOUR_DUCKDNS_HOST"

# Issue a token (use a real account password)
curl -sS -X POST "$HOST/api/v2/auth/token" \
  -d "username=YOUR_USERNAME&password=YOUR_PASSWORD&device_name=smoke-test"
```

Expected: JSON with `data.token` (64 hex chars). Save it as `T`.

```bash
T="<paste the token>"

# Pull all changes
curl -sS "$HOST/api/v2/sync/changes.php?since=1970-01-01T00:00:00Z" \
  -H "Authorization: Bearer $T" | jq '.data | keys'
```

Expected: `["deletions","game_completions","game_images","games","item_images","items","server_now"]`.

```bash
# Revoke the smoke-test token
curl -sS -X POST "$HOST/api/v2/auth/revoke" -H "Authorization: Bearer $T"
```

Expected: `{"data":{"revoked":true}}`.

- [ ] **Step 12.7: Spot-check that nothing broke**

Tail the server's error log for ~5 minutes while using the web app normally:
```bash
sudo tail -f /var/log/nginx/error.log /var/log/php8.3-fpm.log
```

Expected: no new errors related to v2 endpoints or migrations.

- [ ] **Step 12.8: Tag the release**

```bash
git tag -a v2-server-foundation -m "Plan 1: server-side foundation for iOS app"
git push origin v2-server-foundation
```

- [ ] **Step 12.9: Update the spec with anything we learned**

Open `docs/superpowers/specs/2026-05-15-ios-app-design.md` and note anything that diverged from the design (e.g., the `items` table reality, any naming changes). Commit.

```bash
git add docs/superpowers/specs/2026-05-15-ios-app-design.md
git commit -m "Update spec with Plan 1 learnings"
```

---

## End of Plan 1

When all 12 tasks are checked off, the server is fully ready for the iOS app. The next plan (Plan 2: iOS skeleton + sync engine) is written **after** this plan is fully deployed and tested on the live server — that way Plan 2 can be informed by anything we discovered during deployment.

**What's been built:**
- Bearer-token authentication (`api_tokens` table + issue/revoke endpoints)
- Delta sync read/write (`sync/changes`, `sync/push`) with conflict detection
- Deletion tombstones via MySQL triggers
- Image thumbnail generation + backfill
- Image-serving endpoints (cover + extras, thumb + full)
- Cover-upload endpoint
- Proxy endpoints for external-image, PriceCharting, Metacritic
- Integration test harness with 30+ assertions

**What's NOT yet built (later plans):**
- Anything iOS-side (Plan 2, 3, 4)
