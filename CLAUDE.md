# gameTracker

Self-hosted video-game collection tracker: PHP/nginx/MySQL web app + native SwiftUI
iPhone client. Single-household scale, multi-user.

## ⚠️ This checkout is production

`/var/www/gameTracker` is the live nginx document root (`root /var/www/gameTracker`
in `/etc/nginx/sites-enabled/gameTracker`). PHP is interpreted from these files on
every request — **an edit here is live the moment it is saved**. There is no staging
copy and no build step for the PHP layer.

Consequences:

- Work on a branch, but know that a dirty working tree is a dirty production site.
- Never leave a half-finished `.php` edit sitting on disk.
- To try something risky, use the test harness (`tests/v2/run-all.sh`, which boots
  `php -S` against a throwaway DB) rather than poking the live tree.
- `~/gameTracker` is a **stale pre-v2 leftover** (SQLite `database/games.db`, an old
  `game-detail.php`). It is not deployed, not a git repo, and not the app. Ignore it.

### Merging a PR from this checkout deploys everything else on main

`gh pr merge` run from here performs a local `git pull` as a side effect. That pull
brings down **every commit that has landed on `main` since this checkout last
fetched** — not just the PR being merged. Because PHP has no build step, all of it
is live the instant it touches disk.

This is not hypothetical. On 2026-07-28, merging a docs-only PR (#74) fast-forwarded
the live tree and silently deployed #71 and #72, which had been merged from another
machine days earlier and never pulled here. One of them changed `api/games.php`. It
happened to be a safe fix, but the same mechanism would just as happily deploy a
half-finished refactor.

The server is an intermittently-powered laptop, so this checkout is routinely days
behind `main` — which makes the gap wider than intuition suggests.

Before merging or pulling from here, look at what is about to ship:

```bash
git fetch && git log --oneline HEAD..origin/main
```

Then either merge from a non-production clone, or pull deliberately via
`scripts/deploy.sh`. After any pull, check whether the incoming diff needs more than
a file copy:

```bash
# Vite bundles are stale if any of these changed
git diff --name-only HEAD@{1}..HEAD | grep -E '^(js/|css/|package(-lock)?\.json|vite\.config\.js)'
#   -> ./scripts/deploy.sh

# Schema changes need the migration runner
git diff --name-only HEAD@{1}..HEAD | grep -E '^database/'
#   -> php database/migrate.php
```

## Stack

| Piece | Version / location |
|---|---|
| PHP | 8.3.6 via `php8.3-fpm` (`unix:/var/run/php/php8.3-fpm.sock`) |
| MySQL | 8.0.45, database `gameTracker`, localhost only |
| nginx | site config source of truth is `nginx-gameTracker.conf` in-repo |
| Node | v18.19.1 / npm 9.2.0 — only for the Vite/ESLint dev tooling |
| iOS | Swift/SwiftUI/SwiftData, min iOS 18, Xcode 16+, `ios/GameTracker/` |

## The two APIs — do not cross the wires

This is the single biggest source of confusion in the codebase. There are two
parallel API generations and they have different auth, different response shapes,
and different include graphs.

**v1 — `api/*.php`, session cookies, serves the web frontend**

- Auth: `includes/auth.php` → `requireUser()` / `requireAdmin()`, called at the top
  of the file immediately after `config.php`. Both return the `user_id` and `exit()`
  on failure (JSON 401 for `/api/` URIs, HTML redirect otherwise).
- Response shape: `{ "success": bool, "message": string, ... }`.
- Every mutating action requires POST (405 otherwise) — this plus `SameSite=Lax` is
  what currently stands in for full CSRF enforcement.

**v2 — `api/v2/**`, bearer tokens, serves the iOS app**

- Include order is fixed and matters:
  ```php
  require_once __DIR__ . '/_helpers.php';
  require_once __DIR__ . '/../../includes/config.php';
  require_once __DIR__ . '/_auth.php';
  $userId = v2_require_auth($pdo);
  ```
- Response shape: success `{ "data": {...} }`, error `{ "error": "code_slug",
  "message": "human readable" }`. Use `v2_ok()` / `v2_error()`, never hand-rolled
  `json_encode`.
- Tokens are 32 random bytes shown as 64 hex chars; the DB stores **SHA-256** of the
  token (cheap deterministic lookup, deliberately not bcrypt).
- **Never `require` a v1 file from v2 code.** Phase 2c removed the old proxy pattern
  that set `$_SESSION` and installed an output buffer to reshape v1 responses. Don't
  reintroduce it. Likewise never include `includes/auth.php` from a v2 path.

## Schema management

Two mechanisms coexist, and this is a known wart:

1. **`database/migrate.php`** — the intended path. Each `database/migrations/NNN_*.php`
   returns a closure taking `$pdo`; the `schema_migrations` ledger records what ran,
   so re-running is a no-op. Run with `php database/migrate.php`. Write new
   migrations idempotently anyway (`CREATE TABLE IF NOT EXISTS`, `ALTER` in
   try/catch) — the ledger is the source of truth, but belt and braces.
2. **`initializeDatabase()` in `includes/config.php`** — legacy. Fires ~20
   `CREATE TABLE`/`ALTER TABLE` statements **on every single request**. A correctness
   and performance hazard, tracked for removal in the Phase 2 backend work. Do not
   add new DDL here; put it in a numbered migration.

## Config and secrets

- `includes/config.php` is **not in git** (`.gitignore:62`), mode `0640`, owner
  `cammyblack02:www-data`. `includes/config.php.example` is the committed template.
- PDO reads `GT_DB_HOST` / `GT_DB_NAME` / `GT_DB_USER` / `GT_DB_PASS` from the
  environment — that is how the test harness and CI point at a different database.
- TheGamesDB key lives in `THEGAMESDB_API_KEY` (env) or a per-user setting. It used
  to be committed; the value is still reachable in git history until an optional
  `git filter-repo` scrub is run.

## Commands

```bash
# gt — the CLI (src/Cli, src/Query, src/Write, src/Journal, src/Services;
# PSR-4 via src/autoload.php). Talks to the database in-process. Output
# auto-detects: table on a terminal, JSON when piped. --json / --table force.
./bin/gt help
./bin/gt whoami --user=<username|id>        # or GT_USER env
./bin/gt db info                            # target DB, schema state, ledger
./bin/gt games list --platform="PlayStation 2" --unplayed
./bin/gt games list --missing=description   # any allowlisted column
./bin/gt games get <id>
./bin/gt games platforms                    # --platform matches EXACTLY; use
                                            # this to find the stored strings
./bin/gt items list --category=Controller
./bin/gt items get <id>

# Writes (sub-project #2). --yes is required when an operation affects more
# than one row; a single row applies immediately. Every applied write is
# journalled to ~/.gt/journal (GT_JOURNAL_DIR overrides) and revertable.
./bin/gt games set <id> --set-genre=RPG
./bin/gt games set --platform="PS2" --set-platform="PlayStation 2" --yes
./bin/gt games set <id> --clear-description   # sets NULL
./bin/gt undo --list
./bin/gt undo [<journal-id>] [--yes] [--force]

# Filters are AND-only, per-resource, and allowlisted: an unknown flag or
# column exits 2 rather than being ignored. Exit codes: 0 ok, 1 domain error,
# 2 usage, 3 bootstrap/database.
#
# A bulk write with no selector is refused; pass --all to mean every row.
# Write SQL lives only in src/Services/Write/ — enforced by
# tests/cli/test_readonly_guard.sh, which also asserts that directory is not
# empty so the guard cannot pass vacuously.
# See docs/superpowers/specs/2026-08-03-gt-cli-design.md and
#     docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md

# Deploy (git pull --ff-only + npm ci + vite build, with a Node >= 18 preflight)
./scripts/deploy.sh

# v2 API integration tests — boots php -S on :8000 against a throwaway DB,
# runs every tests/v2/test_*.sh, tears the server down
bash tests/v2/run-all.sh
bash tests/v2/setup-test-db.sh     # reset the test DB alone

# Frontend dev tooling (js/** only — not shipped, not served)
npm run lint      # ESLint 9 flat config, js/**/*.js
npm run build     # Vite -> js/dist/, content-hashed

# Migrations
php database/migrate.php
```

Reload nginx / php-fpm only when their config actually changes:

```bash
sudo cp nginx-gameTracker.conf /etc/nginx/sites-available/gameTracker
sudo sed -i 's|YOUR_DOMAIN_OR_IP|<real cert dir>|g' /etc/nginx/sites-available/gameTracker
sudo nginx -t && sudo systemctl reload nginx
```

`nginx-gameTracker.conf` ships with `YOUR_DOMAIN_OR_IP` as a **placeholder** in the
Let's Encrypt cert paths. Copying it into place without substituting breaks
`nginx -t` with a certificate-not-found error. Find the real name with
`sudo ls /etc/letsencrypt/live/`.

## Frontend layout

- `js/*.js` — classic `<script>`-loaded files, one per page, plus `js/render/`
  (grid/list/coverflow) and `js/forms/`.
- Vite is **scoped deliberately**: `vite.config.js` has exactly one entry
  (`spin-wheel`). Everything else is still classic scripts. When a sub-view is
  converted, add its entry there; `vite_asset()` in `includes/vite.php` resolves the
  content-hashed URL from `manifest.json` at render time.
- ESLint covers `js/**/*.js` only. ~1,600 lines of inline JS still live inside the
  PHP pages and are un-lintable until they are extracted (Phase 4g). No CI wiring
  for lint yet — run it locally.
- Escape on output. `escapeHtml` is the shared helper; attribute-context paths
  (`src="${url}"` and friends) have been a repeated XSS source, so be deliberate
  in those.

## CI

`.github/workflows/ci.yml`, two jobs:

- **iOS unit tests** on `macos-15`. Queries `simctl` for an available iPhone
  simulator UDID rather than hardcoding a device (runner sim sets fluctuate).
  `KeychainTokenStoreTests` is skipped — it needs the real Keychain, which an
  unsigned simulator build cannot provide (`errSecMissingEntitlement`, -34018).
- **v2 API shell tests** on `ubuntu-24.04` with a MySQL 8.0 service container,
  PHP 8.3, `php -S` + `router.php`, then `setup-test-db.sh` && `run-all.sh`.

## Conventions

- **Commits**: `type(scope): summary`, with a phase tag where applicable —
  e.g. `security(xss): escape all persistence-path src="${url}" hotspots (phase 4i migrations)`,
  `perf(js): render only on first + last page (Fable §6 follow-up)`.
- **Branches**: `phase-5-01-delete-download-external-image`, `fix-doubled-cover-filename` —
  descriptive kebab-case, phase-prefixed for roadmap work.
- **Everything lands via PR** onto `main`.
- **`Fable §N`** in a commit or comment refers to a numbered section of
  `FABLE-SUGGESTIONS.md`, the critical audit that drives much of the current
  roadmap. Worth reading the relevant section before touching an area it covers.
- When you touch the security surface — auth, sessions, external fetches, uploads,
  cross-user boundaries, CSRF — **update `SECURITY-ASSESSMENT.md`**, either adding to
  "Mitigated" or moving the item to "Known-open". That doc is meant to reflect
  reality, not aspiration.

## Security invariants worth not breaking

- **All external URL fetches go through `includes/http-fetch.php`.** It resolves the
  host and rejects private/loopback/link-local/reserved IPs (including
  `169.254.169.254`), keeps TLS verification on, and follows redirects manually so
  every hop is revalidated. Never call `file_get_contents`/`curl` on a user-supplied
  URL directly. Regression tests: `test_ssrf.sh`, `test_v2_cover_ssrf.sh`.
- **Every query is user-scoped.** List endpoints must ignore a `?user_id=` override;
  Steam import's delete/dedup/insert are all scoped. Regression tests:
  `test_list_scoping.sh`, `test_admin_scoping.sh`, `test_steam_import_scoping.sh`.
- **No exception detail in response bodies** — no `getFile()`/`getLine()`/
  `getMessage()` in JSON. Log server-side with `error_log()`.
  Test: `test_error_disclosure.sh`.
- Prepared statements everywhere; no string concatenation of user input into SQL.

## Docs map

- `README.md` — feature overview, v2.0 highlights, architecture diagram.
- `SETUP-GUIDE.md` — full fresh-install walkthrough, including the three landmines
  (Node from NodeSource, the `YOUR_DOMAIN_OR_IP` substitution, DuckDNS updater cron).
- `SECURITY-ASSESSMENT.md` — honest posture: mitigated / known-open / out of scope.
- `FABLE-SUGGESTIONS.md` — the critical audit driving the roadmap.
- `docs/superpowers/specs/` and `docs/superpowers/plans/` — per-feature design docs
  and phased implementation plans, named `YYYY-MM-DD-topic.md`.
- `UNIFI-SETUP.md` — network zones, firewall rules, DuckDNS.

## Server-side operational notes

Cron on the VM (`crontab -l` as `cammyblack02`):

- `0 2 * * *` — `~/backup-gameTracker.sh` (mysqldump + uploads + config tarball into
  `/var/backups/gameTracker`, 7-day retention).
- `0 * * * *` — `~/check-security-logs.sh`.
- `*/5 * * * *` — `~/duckdns/duck.sh`, keeps the DNS record tracking the rotating
  WAN IP. If the site is reachable on the LAN but not by domain, suspect a stale
  DuckDNS record first.
