# Phase 2b — Move initializeDatabase() into migrations

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kill the per-request DDL tax. `includes/config.php`'s `initializeDatabase()` runs ~20 `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` statements on **every** request (Fable §6's #1 backend latency win). Move all of it into `database/migrations/000_baseline.php`, add a proper `schema_migrations` ledger to `database/migrate.php`, and delete the per-request path.

**Architecture:**
- `database/migrations/000_baseline.php` — new file capturing the entire schema currently created by `initializeDatabase()` (users, games, game_images, items, item_images, settings, game_completions), plus all performance indexes and the default `admin/admin` seed. Fully idempotent — safe to run against the existing prod DB, since everything is `CREATE TABLE IF NOT EXISTS` and `ALTER TABLE` in try/catch.
- `database/migrations/004_schema_migrations_ledger.php` — creates the `schema_migrations` table that records which migrations have been applied.
- `database/migrate.php` — updated runner. First creates the ledger table itself (bootstrap). Then for each migration file, checks the ledger and skips if already applied; otherwise runs and records.
- `includes/config.php` — `initializeDatabase()` function and its per-request call deleted. Config becomes strictly a bootstrap file (session, PDO, dirs) with zero DDL.
- `database/add-performance-indexes.php` — deleted. All four indexes it added are already in 000_baseline.
- `tests/v2/setup-test-db.sh` — dropped the "trigger config.php to build schema" step; test DB is now built purely by `php database/migrate.php`.

**Tech Stack:** PHP 8 + PDO/MySQL. No new dependencies.

**Deploy note:** Two-step deploy — see Task 8. `git pull` first (removes the per-request DDL), then `php database/migrate.php` to backfill the ledger against the existing schema. Order matters, but there's no window where the app is broken — the schema itself is unchanged.

---

## Task 1: Baseline migration

**Files:**
- Create: `database/migrations/000_baseline.php`

- [ ] Copy the entire body of `initializeDatabase($pdo)` from `includes/config.php:130-404` into a closure `return function (PDO $pdo): void { ... }`. Every DDL statement stays as-is — they're already idempotent via `CREATE TABLE IF NOT EXISTS` + `ALTER TABLE` inside `try/catch(PDOException)` swallow-error. The default admin seed at lines 386-403 also comes across.
- [ ] Naming: `000_` prefix guarantees it sorts before the existing `001_api_tokens` (which foreign-keys `users`). MySQL requires `users` to exist first.
- [ ] Commit.

---

## Task 2: Migration ledger

**Files:**
- Create: `database/migrations/004_schema_migrations_ledger.php`
- Modify: `database/migrate.php`

- [ ] Create `004_schema_migrations_ledger.php`:

```php
<?php
/**
 * Migration 004: schema_migrations ledger table.
 *
 * Records which migrations have been applied. The runner
 * (database/migrate.php) uses this to skip already-applied migrations
 * rather than relying on the "every migration must be idempotent"
 * convention — which works until it doesn't.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        name VARCHAR(255) PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
```

- [ ] Rewrite `database/migrate.php` to (a) bootstrap the ledger table itself before running any migrations (chicken-and-egg — the ledger has to exist before we can check the ledger) and (b) skip migrations already recorded in the ledger:

```php
<?php
/**
 * Migration runner.
 *
 * Each file in database/migrations/ returns a closure that takes a PDO
 * and applies its changes idempotently. Migrations run in filename order.
 *
 * The schema_migrations table records which migrations have been applied
 * so re-running the runner is a no-op — we don't rely on the convention
 * that every migration is idempotent, though most still are.
 *
 * Usage:
 *   php database/migrate.php
 */

require_once __DIR__ . '/../includes/config.php';

if (!isset($pdo)) {
    fwrite(STDERR, "Database connection unavailable\n");
    exit(1);
}

// Bootstrap the ledger table if it doesn't exist. This is the one place
// we do DDL outside a numbered migration — the ledger has to exist before
// we can check it.
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    name VARCHAR(255) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$appliedStmt = $pdo->query("SELECT name FROM schema_migrations");
$applied = array_flip($appliedStmt->fetchAll(PDO::FETCH_COLUMN));

$migrationDir = __DIR__ . '/migrations';
$files = glob($migrationDir . '/*.php');
sort($files);

$recordStmt = $pdo->prepare("INSERT INTO schema_migrations (name) VALUES (?)");

foreach ($files as $file) {
    $name = basename($file);
    if (isset($applied[$name])) {
        echo "Skipping $name (already applied)\n";
        continue;
    }
    echo "Applying $name... ";
    $migration = require $file;
    if (!is_callable($migration)) {
        fwrite(STDERR, "ERROR: $name did not return a callable\n");
        exit(1);
    }
    try {
        $migration($pdo);
        $recordStmt->execute([$name]);
        echo "ok\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "All migrations applied.\n";
```

- [ ] Commit.

---

## Task 3: Delete `initializeDatabase()`

**Files:**
- Modify: `includes/config.php` — remove function definition (lines 130-404) and its call site (line 115).

- [ ] Remove line 114-115 (comment + call) and lines 127-404 (function definition).
- [ ] `config.php` should end at the `catch (PDOException)` block. Anything below is gone.
- [ ] Commit.

---

## Task 4: Delete `add-performance-indexes.php`

**Files:**
- Delete: `database/add-performance-indexes.php`

- [ ] Confirm every index it adds is already in the 000_baseline migration (idx_game_id on game_images, idx_platform on games, idx_created_at on games, idx_platform_user_id on games — all present in `initializeDatabase()` at lines 251-269 → copied to 000_baseline in Task 1).
- [ ] `git rm database/add-performance-indexes.php`.
- [ ] Commit.

---

## Task 5: Update test DB setup

**Files:**
- Modify: `tests/v2/setup-test-db.sh`

- [ ] Remove the "trigger config.php to build schema" step (currently lines 20-25). Post-2b, that no longer builds anything.
- [ ] The existing migrate.php call at line 28 now creates ALL tables (baseline + api_tokens + deletions + image_updated_at + ledger). Good — leave it, just move it earlier and drop the config.php trigger.
- [ ] Commit.

---

## Task 6: Update `SETUP-GUIDE.md`

- [ ] Find the DB-setup section of `SETUP-GUIDE.md`.
- [ ] Ensure it tells the reader to run `php database/migrate.php` after configuring `includes/config.php` and creating the empty database. Previously that step was implicit (first request built the schema).
- [ ] Commit.

---

## Task 7: Push branch + open PR

- [ ] `git push -u origin phase-2b-migrations`.
- [ ] Open PR with test-plan + deploy checklist. Deploy is a **two-step** because the ledger is bootstrapped by `migrate.php` itself:
  1. `git pull` on prod.
  2. `php database/migrate.php` — creates the ledger, records 000/001/002/003/004 as applied, no-op re-applies against the existing schema.
  3. Reload PHP-FPM.

---

## Self-review

**Spec coverage** (against Fable §2 initializeDatabase concern + §6 perf-index concern):
- "~20 CREATE TABLE/ALTER TABLE statements on every single request" → deleted in Task 3 ✓
- "database/add-performance-indexes.php is a run-once script you have to remember to run — it isn't in the migration chain" → folded into 000_baseline; script deleted in Task 4 ✓
- "Add a migration ledger table while you're there" → Task 2 ✓
- "Every migration must be idempotent by convention works until the day it doesn't" → ledger removes reliance on this ✓

**Placeholder scan:** no TBDs; every step has concrete code or exact command.

**Type consistency:** `schema_migrations(name PK, applied_at DATETIME)` — used the same shape in migration 004 and in the runner's bootstrap. `$applied = array_flip(...)` gives O(1) lookups matched against `$name` string keys.

**Extra safeguard worth naming:** the migration runner's ledger bootstrap uses `CREATE TABLE IF NOT EXISTS` (not tracked in the ledger itself), so re-running the runner is safe even if the ledger table already exists.
