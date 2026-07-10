# Fable's Suggestions — an honest, critical review of gameTracker

Right then. You asked for brutal honesty and said you're open to anything, up to
and including stripping it back and redoing it. So this isn't a pat on the head.
I read the whole thing — the PHP backend (v1 + v2), the web frontend, and the
iOS/SwiftData app — and I'm going to tell you where it's genuinely good, where
it's quietly dangerous, and what I'd actually do about it.

**The one-line verdict:** this is a *far* better hobby project than most. The v2
API and the iOS sync layer are real engineering — optimistic concurrency,
tombstone deletion, streaming pulls, a proper test suite. But it's carrying a
**legacy v1 layer that is a liability**, a **web frontend that has collapsed
into a 3,200-line god-file**, and a handful of **real security holes** that your
own `SECURITY-ASSESSMENT.md` confidently says don't exist. That gap between "the
doc says SECURE ✅" and what the code actually does is the single most important
thing to fix, because right now it's lying to you.

---

## TL;DR — if you only do five things

1. **Fix the data-loss and SSRF bugs this week** (§1). One authenticated user can
   delete *every* user's PC games with a single Steam-import call. That's not a
   nitpick, that's a live footgun.
2. **Kill v1 or wall it off.** The whole backend is two APIs bolted together, and
   v2 literally fakes a login session to `require` v1 files. Pick one. (§2)
3. **Break up `games.js`.** 3,239 lines, ~62 global functions, 88 `console.log`s
   shipped to prod, an image-splitter implemented *twice*. (§3)
4. **Fix the three iOS sync bugs** — deleted games resurrect, and both conflict
   buttons do the wrong thing. Your tests currently assert the buggy behaviour is
   correct. (§4)
5. **Rewrite `SECURITY-ASSESSMENT.md` to be honest**, and delete the hardcoded API
   key from git history. (§5, §9)

And two things beyond bug-fixing, because you asked how to make it *better, faster,
and a better product*: **§6 is performance** (what's actually slow and how to fix
it) and **§7 is product** (features, UX, and deployment that would make people
want to use it). The sections above make it *safe and clean*; §6 and §7 make it
*fast and good*.

---

## 1. Security — the stuff that can actually bite you

Your README and `SECURITY-ASSESSMENT.md` claim "SQL injection protection
(prepared statements throughout)", "CSRF protection (tokens + SameSite cookies)",
and a big green **✅ SECURE**. The prepared-statements bit is genuinely true and
credit for it. The rest is optimistic. Here's what's real, verified against the
code:

### 🔴 Cross-user data wipe (critical)
`api/steam-import.php:402`:
```php
$stmt = $pdo->prepare("DELETE FROM games WHERE platform = 'PC'");
```
No `WHERE user_id = ?`. Any authenticated user who triggers a Steam re-import
**deletes every user's PC games**, not just their own. The dedup query on the way
in (`:223`) is global too. And the INSERT path doesn't set `user_id` at all — on
a `NOT NULL` column that means imported rows are either failing or orphaned. This
one endpoint is both a security hole *and* a correctness bug. Fix first.

### 🔴 CSRF is implemented but essentially not used
`validateCsrfToken()` exists in `includes/csrf.php` and is called in **exactly one
file**: `change-admin-credentials.php:104`. Login, register, every game/item/
completion create-update-delete, admin password-reset and delete-user, settings,
uploads — none of them validate CSRF. `SameSite=Lax` saves you from the worst of
it, but several destructive actions accept `GET`, which Lax does *not* fully
protect. The README shouldn't claim CSRF protection when 1 of ~20 mutating
endpoints checks a token.

### 🔴 SSRF holes in the image endpoints
You have an internal-IP blocklist copy-pasted into three places
(`api/image-proxy.php:63`, `download-external-image.php`, `games.php`). All three
share the same blind spots: they block `127.0.0.1`/`192.168.`/`10.` etc. but
**not `169.254.169.254`** (the cloud metadata endpoint), and they match the
*hostname string* before DNS resolution — so a domain that resolves to an
internal IP walks straight through, and `CURLOPT_FOLLOWLOCATION` means an allowed
host can 302-redirect you to an internal target anyway.

Worse, `api/download-cover.php:31-32`:
```php
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
```
No internal-IP check at all *and* TLS verification disabled — authenticated SSRF
plus a free man-in-the-middle. And `api/v2/images/cover.php` is a *stored* SSRF
sink: the `front_cover_image` column is client-writable via sync push, so a
client can store `https://169.254.169.254/…` and have the server fetch it later.

**Fix:** one shared fetch helper. Resolve the host, reject if the resolved IP is
private/link-local/loopback (including `169.254.0.0/16` and `0.0.0.0`), disable
redirects or re-validate each hop, and never turn off TLS verification.

### 🟠 A committed API key
`api/cover-image.php:23` has a live TheGamesDB key hardcoded and committed to git.
Even if it's stale, rotate it and scrub it from history (`git filter-repo` or BFG),
because "it's in the public repo but expired" is still a bad habit to keep.

### 🟠 IDOR by design
`api/games.php:122` and `items.php:44` intentionally let any user read any other
user's collection via `?user_id=`, and `admin.php` lets any authenticated user
list all users. If that's deliberate for a shared household instance, fine — but
say so, because it reads like a bug.

### 🟠 Information disclosure
Raw exception messages, filenames and line numbers get returned to clients in
several v1 endpoints (`games.php`, `auth.php`, `upload.php`) and even the DB
connection error in `config.php`. Log the detail server-side; return a generic
message.

**None of the iOS-side security is in this list, and that's a compliment** —
Keychain with device-only accessibility, no committed secrets, tokens stored
SHA-256 server-side and shown once. That's the standard the rest of the codebase
should be held to.

---

## 2. The backend's real problem: it's two apps pretending to be one

There are two APIs:

- **v1** (`api/*.php`): session-cookie auth, `{success, message}` JSON, page-file
  logic, the whole original web app.
- **v2** (`api/v2/**`): Bearer tokens, clean `{data}`/`{error}` envelope, proper
  sync — the API the iOS app talks to.

The problem isn't that both exist. It's how they're joined. v2's proxy endpoints
(`metacritic.php`, `pricecharting.php`, `external-image.php`) **fake a session** —
they look up the username, set `$_SESSION['user_id']`, install an output-buffer
filter to reshape the response, then `require` the v1 file. A stateless
token-authed request is mutated into a fake cookie session so the old code will
run. `_helpers.php` even carries a comment about a load-order landmine where a PHP
8.5 deprecation warning corrupts the JSON body. That's a house of cards.

And the duplication is measurable:

- The inline "is the user logged in?" auth block is **copy-pasted across ~14
  files**, in **two mutually incompatible flavours** — some return JSON 401, some
  (`includes/auth-check.php`) `header('Location: …')` **redirect to HTML**, which
  an API client cannot handle.
- The admin check `($_SESSION['role'] ?? 'user') === 'admin'` is inlined **~11
  times** even though `isAdmin()` already exists in `includes/csrf.php`.
- `downloadExternalImage()` exists as a standalone endpoint *and* as a private
  re-implementation inside `games.php`.
- The SSRF blocklist is triplicated (see §1).
- The error-handling boilerplate (`ob_start`, shutdown handler, display_errors
  off) is pasted at the top of every v1 endpoint.

**What I'd do:** commit to v2 as the one true API. Extract a thin service layer
(auth, ownership checks, JSON envelope, a `fetchExternalImage` helper) that *both*
the web pages and the API call. Then either (a) port the web frontend to call v2
and delete v1, or (b) if that's too much, freeze v1, route all new work through
v2, and at minimum replace the 14 copy-pasted auth blocks with **one**
`requireUser()` helper. The dual-auth mess is the highest-leverage cleanup in the
whole repo.

### Schema management is fighting itself
`initializeDatabase()` in `config.php` runs **~20 `CREATE TABLE`/`ALTER TABLE`
statements on every single request**, wrapped in swallow-the-error try/catch. It's
a per-request tax and a schema-by-side-effect anti-pattern. Meanwhile
`database/migrations/` is a proper, idempotent, ordered migration runner for the
newer tables. You have two schema authorities that don't know about each other.
Move the `initializeDatabase()` blob into migrations, run migrations at deploy
time, and delete the per-request DDL. (Add a migration ledger table while you're
there — "every migration must be idempotent by convention" works until the day it
doesn't.)

---

## 3. The web frontend needs a real intervention

This is where the codebase has most obviously outgrown its structure.

- **`js/games.js` is 3,239 lines**, ~62 top-level global functions, no modules, no
  classes. It mixes at least 8 unrelated concerns (loading, three renderers, the
  add form, detail view, a 500-line canvas image-splitter, uploads, filters). It's
  a flat bag of globals held together by load order that's load-bearing and
  undocumented.
- **No build tooling at all** — no `package.json`, no linter, no bundler, no
  modules. Everything is classic `<script src>` sharing one global scope, which is
  *why* you have 14 `window.*` globals used as an ad-hoc message bus and functions
  probing `if (window.uploadSplitImages)` to find each other.
- **88 `console.*` statements in `games.js` alone** (131 across all JS) shipping to
  production, some logging on every card render.
- **Copy-paste everywhere:** `escapeHtml` defined **4 times** identically
  (`games.js`, `completions.js`, `items.js`, `stats.js`); the grid/list renderers
  duplicated between games and items; the **canvas image-splitter implemented twice**
  (`games.js` and `add-item.js`) and cross-wired through globals.
- **~1,600 lines of JavaScript live *inline inside* PHP pages** (`item-detail.php`
  ~687, `settings.php` ~475, etc.) — un-lintable, un-cacheable, and duplicating the
  helpers from the `js/` files.
- **It loads your entire collection into memory** (paged at 500, looped until done)
  and filters/sorts client-side, despite the recent "perf" work. Fine at your size;
  a wall if the library grows.
- A couple of **attribute-context XSS gaps** remain — image paths dropped raw into
  `src="${…}"` and brittle nested inline `onerror="…"` handlers — even though text
  fields are correctly escaped elsewhere.

**What I'd do (and it's not a framework):** this is server-rendered PHP with
islands of interactivity. Don't reach for React. Reach for:
1. **ES modules** (`type="module"` + `import`/`export`) — zero tooling, and it
   instantly kills the global-scope coupling and the `window.*` bus.
2. A **minimal toolchain**: `package.json` + Vite + ESLint + Prettier. Vite gives
   you one-command minification and cache-busting hashes, and lets you delete the
   inline `<script>` blocks from PHP.
3. **Split `games.js`** into `api.js` (one fetch wrapper with shared error
   handling), `render/{grid,list,coverflow}.js`, `forms/game-form.js`, and **one**
   `image-split.js` imported by both flows.
4. **One `utils.js`** for `escapeHtml`/`getImageUrl`/`formatDate` — delete the
   copies. (`formatDate` already lives correctly once in `main.js`; follow that.)
5. A tiny auto-escaping tagged-template helper (or `<template>` + `textContent`) to
   close the attribute-injection gaps for good.
6. ESLint `no-console` to strip the debug noise.

Genuinely good bits worth keeping: the CSS custom-property theming + dark mode is
clean; `escapeHtml` *is* applied to most text (the author was clearly XSS-aware);
the fetch error handling in `loadGames` is thoughtful (preserves loaded games on a
refresh failure); the image thumb-with-fallback and skeleton loaders are nice.

---

## 4. iOS — the best-engineered part, with three real bugs

Credit where it's due: this is a proper offline-first delta-sync — `since`-cursor
pulls, server-enforced optimistic concurrency (`last_synced_at` vs `updated_at`),
tombstone deletion via DB triggers, and a *streaming* server pull that encodes one
row at a time to bound memory. Most hobby apps just POST and pray. The test suite
(23 files, ~1,600 LOC, `URLProtocol` stubbing, in-memory SwiftData) is a real
asset. But:

- **🔴 Deleted games resurrect.** (`SyncEngine.swift:177-192`) The push-result
  handler keys off the `result` string only, with no operation discriminator. A
  deletion that the server accepts comes back as `"accepted"` and the client
  re-marks the row `.synced` — resurrecting it — until the *next* launch's pull
  brings the tombstone back and re-deletes it. With "one sync per launch," a
  deleted game visibly reappears and lingers. Fix: give push results a
  `deleted`/operation flag and actually `context.delete` accepted tombstones.
- **🔴 "Keep server version" keeps the phone version.** (`ConflictDetailView.swift:53`)
  It marks the row `.synced` and sets a *per-row* `lastSyncedAt = nil`, expecting a
  re-pull — but pulls use the *global* cursor, so the server row (older than the
  cursor) is never re-sent. The button does the opposite of its label.
- **🟠 The conflict UI is blind.** `server_version` is decoded from the response
  but never stored, so `ConflictDetailView` can only show the phone's side while
  telling the user "you edited both." Persist `server_version` and drive
  resolution off it.
- **Your tests bless the bugs.** There's no delete-round-trip test (would catch #1),
  and the conflict test only asserts the state becomes `.conflict`, not that
  resolution works (#2/#3). Add those.
- **Lifecycle smell:** `SyncEngine`/`SyncTrigger` are built as locals inside
  `RootViewContainer.body` (`GameTrackerApp.swift:75`), so a `@Bindable` change
  rebuilds them every render — defeating "sync once per launch" and dropping
  debounced syncs. Make them `@State`.
- **Second-granularity cursor** can drop an update written in the same wall-clock
  second as `serverNow`. Move to sub-second precision or an `(updated_at, id)`
  tiebreak with `>=` de-dup.
- Boilerplate: the five near-identical `applyX`/`bucketX`/`applyXResult` triplets
  in `ChangeApplier`/`PushBuilder`/`SyncEngine` should collapse via the
  `SyncableModel` protocol you already have. Date parsing is rolled three times
  (one version rebuilds three `DateFormatter`s per row).

**No CI and no committed scheme** is the biggest iOS gap: you have a genuinely good
test suite that *never runs on push*, and a fresh clone can't `xcodebuild test`
without regenerating a scheme. Add both.

### The Invaders mini-game
It's charming — a full SpriteKit Space-Invaders (829 LOC, 11 files) that textures
invaders with your own covers, plus bundled sounds, a font, and a Metal shader.
But be honest: it's a second app's worth of subsystem inside a collection tracker.
Keep it as an easter egg if it sparks joy, but feature-flag it and don't let it
carry the same maintenance and review weight as sync. If you ever need to cut
scope, this is the first thing overboard.

---

## 5. Docs that oversell

`SECURITY-ASSESSMENT.md` opens with **"Overall Security Status: ✅ SECURE"** and a
wall of green ticks including "CSRF protection" and comprehensive coverage — while
the code has an unauthenticated-shaped cross-user delete, multiple SSRF holes, a
committed API key, and CSRF enforced on 1 endpoint. A security doc that's wrong is
worse than no security doc, because it tells *you* to stop looking. Rewrite it as
an honest posture doc: what's actually mitigated, what's known-open, what's
accepted risk for a self-hosted single-household app. Same for the README's
security bullets.

Conversely, `docs/superpowers/` (specs + plans per feature) and the iOS code
comments are genuinely excellent — the *why* is documented all over the sync
layer. Keep that habit; just point it at the security story too.

---

## 6. Making it faster — where the time actually goes

You asked about speed, and the honest news is: the biggest wins are cheap. The
codebase already does some good things (indexes exist, images are lazy-loaded and
thumbnailed, the v2 pull streams row-by-row, static assets get `expires 30d`).
Here's what's still leaving performance on the table, roughly in order of
bang-for-buck.

### Backend
- **🔴 `initializeDatabase()` runs on every request.** ~20 `CREATE TABLE
  IF NOT EXISTS` + `ALTER TABLE` statements fire on *every single page load and API
  call* (`config.php`), each a round-trip to MySQL wrapped in try/catch. On a cold
  connection that's the dominant cost of a request that should be near-instant.
  Move it into migrations (§2) and this disappears entirely — probably your single
  largest latency win.
- **🟠 Indexes are a manual afterthought.** `database/add-performance-indexes.php`
  is a run-once script you have to remember to run — it isn't in the migration
  chain, so a fresh deploy has *no* performance indexes until you notice. And it
  adds a composite `(platform, user_id)` but **no plain index on `games.user_id`**,
  which is the column nearly every query filters on first. Fold these into
  migrations and add a `user_id` index (plus `(user_id, created_at)` for the
  default sort).
- **🟠 Every v2 proxy call boots v1.** Faking a session and `require`-ing a v1 file
  (§2) means each metacritic/pricecharting/image call re-runs v1's whole bootstrap
  (session setup, `initializeDatabase`, the lot). A shared service layer removes the
  double-boot.
- **No gzip/brotli in nginx.** `nginx-gameTracker.conf` has HTTP/2 and TLS but no
  `gzip on;` — your 2,468-line CSS and 3,239-line JS ship uncompressed. One line of
  config, ~70-80% smaller transfers.
- **Enable OPcache.** Nothing in `php.ini` turns it on. For a PHP app this is free
  10-30% across the board — it stops PHP recompiling every script on every request.
- Kill the `error_log` calls in hot paths (`listGames` logs query text + counts on
  every call) — logging isn't free at volume.

### Frontend
- **🔴 It loads the entire collection into memory.** `loadGames` loops
  `per_page=500` until every game is fetched, concatenates into one array, and
  renders the lot; filter/sort run client-side over the full set. Fine at a few
  hundred games, a visible stall at a few thousand. Fix: server-side filter/sort/
  paginate, and render on scroll (windowing) instead of all at once.
- **🟠 The grid re-render is doing real damage.** The clone-every-card-to-strip-
  listeners trick inside a `setTimeout(…,10)` (`games.js:399-436`) forces layout
  thrash on every re-render. Event delegation (one listener on the container) makes
  the whole dance unnecessary and faster.
- **No minification, no cache-busting.** Without a bundler, JS/CSS ship full-size
  and unhashed — and because assets are served `expires 30d immutable` *without*
  content hashes, a deploy either serves stale files to returning users or you
  can't safely cache at all. Vite (§3) fixes both: minified, hashed filenames,
  long-cache-safe.
- **`defer` your scripts.** Six classic `<script src>` tags load render-blocking in
  `dashboard.php`. Add `defer` (or `type="module"`, which defers by default) so the
  page paints before the JS parses.
- **131 `console.*` calls** aren't free when they run per-card in a render loop.
  Strip them.
- **Split the CSS.** One 2,468-line stylesheet blocks first paint. At minimum
  inline the critical above-the-fold rules; ideally let the bundler code-split.

### iOS
- **`countPending()`/`countConflicts()` fetch entire tables into memory** and filter
  in Swift on every sync (`SyncEngine.swift:66-67`, 205-219) — O(all rows) twice
  per cycle. Use a `#Predicate` + `fetchCount` so SwiftData counts in SQLite.
- **`ChangeApplier.parseDate` rebuilds three `DateFormatter`s per row per pull** —
  formatter allocation is genuinely expensive; cache them once at file scope like
  `DTOs` already does.
- **Pull-before-push echo** re-fetches every row you just pushed on the next sync
  (§4, RISK E) — wasted bandwidth and CPU each cycle.
- The recreated-every-render `SyncEngine`/`SyncTrigger` (§4) also churns object
  allocation on every state change.

Most of this is config and small refactors, not rewrites. The OPcache + gzip +
`initializeDatabase`-removal trio alone will make the web app feel dramatically
snappier for a day's work.

---

## 7. Making it a better *product* — features, UX, and shipping

The engineering review above is about the code. This is about whether people
(including future-you) actually enjoy using it. The doc didn't cover this before,
and it's where a hobby project either grows legs or quietly dies. Ranked by
value-per-effort:

### High value, low effort
- **Make the web app a PWA.** Add a manifest + service worker and it becomes
  installable on a phone home screen and works offline for browsing. For a
  self-hosted collection tracker this is the highest-leverage product change you
  can make — it closes most of the gap that justifies a separate native app.
- **Barcode / cover scanning to add games.** The most tedious part of any
  collection tracker is data entry. Let the iOS camera scan a barcode (or match a
  cover photo) and prefill title/platform/cover from a metadata lookup. This is the
  feature people actually remember an app for.
- **Export / backup.** There's a CSV *import* but no obvious export. Users trust a
  self-hosted app far more when they can get their data *out* — one "Export my
  collection (CSV/JSON)" button. Also protects you: it's a poor-man's backup.
- **Bulk edit + bulk add.** Selecting 20 games to change platform or condition one
  at a time is misery. Multi-select + apply-to-all.

### Medium effort, real payoff
- **Price history, not just current price.** You already scrape PriceCharting for a
  current price — snapshot it over time and you can show "your collection is worth
  £X, up £Y this year." That's a genuinely compelling reason to open the app, and
  it's mostly a cron job + a small table you already have the plumbing for.
- **Wishlist / backlog as first-class views.** A tracker for games you *own* is
  half the story; "want to buy" and "want to play next" are the states people
  actually live in. You already track a `played` flag — a backlog view sorts
  unplayed by "how long to beat" and suddenly the app has a job to do on a Friday
  night.
- **Loan tracking.** "Who did I lend Zelda to?" is the classic collection pain.
  A borrower field + a "currently lent out" filter.
- **Smarter stats.** You have a stats tab; make it tell stories — spend-per-year,
  completion rate by platform, most expensive shelf, longest backlog. Insights, not
  just totals.
- **Better empty/onboarding states.** First-run should offer "import from Steam /
  CSV / scan your first game," not an empty grid.

### The multi-user question
The backend is multi-user and currently lets any user read any other user's
collection (§1). Decide what this product *is*: a single-household shared instance
(then lean in — add "compare shelves," shared wishlists, "who owns this?") or a
private per-user tracker (then lock the cross-user reads down). Right now it's
ambiguous, which means it's neither a good social product nor a properly private
one.

### Shipping & operability (this is product too)
- **Dockerise it.** `SETUP-GUIDE.md` is 619 lines of manual Ubuntu/nginx/MySQL/
  Let's Encrypt/fail2ban setup. A `docker-compose.yml` (php-fpm + nginx + mysql +
  a certbot sidecar) turns "a weekend of following a guide" into `docker compose
  up`. This is the difference between a project only you can run and one other
  people actually deploy.
- **One-command backup/restore** of the DB + `uploads/`. Self-hosted apps live and
  die on whether losing the VM means losing everything.
- **A real health check + error visibility.** `api/v2/_ping.php` exists — build on
  it: a status page, and ship errors somewhere you'll see them (even a log file you
  actually read) instead of `error_log` into the void.
- **Accessibility pass** on the web frontend: keyboard nav, focus states, alt text
  on covers, colour contrast in the themes. Cheap, and it's the kind of polish that
  separates "my project" from "a product."

Not everything here is worth building — pick the two or three that match how *you*
use it. But "faster and cleaner code" and "a better product" are different axes,
and you asked about both. The code sections make it something you're proud to
maintain; this section makes it something people want to use.

---

## 8. If you asked me "should I strip it back and redo it?"

**No — don't nuke it.** The bones are good and a rewrite would throw away the two
hardest, best parts (the v2 sync protocol and the iOS app) to fix problems that
are cheaper to fix in place. What you have is a *good v2 core wearing a v1 coat*.
The move is to **grow the good part and retire the bad part**, in this order:

1. **Stop the bleeding** (days): the §1 security fixes. Non-negotiable, do first.
2. **Unify auth + envelope** (a week): one `requireUser()`, one JSON shape, one
   external-fetch helper. Delete the copy-paste. Move `initializeDatabase()` into
   migrations.
3. **Fix the iOS sync bugs + add CI** (a week): the three correctness bugs, a
   delete-round-trip test, a shared scheme, GitHub Actions running `xcodebuild
   test` and the `tests/v2/*.sh` suite on push.
4. **Modularise the frontend** (steady): introduce Vite + ES modules, carve
   `games.js` into modules, extract shared utils, pull inline JS out of PHP. This
   is incremental — one module at a time, no big-bang rewrite.
5. **Decide v1's fate** (later): once the web app can talk v2, delete v1 entirely.

Do that and you've got one coherent app instead of three loosely-stapled ones,
without ever taking it offline for a rewrite.

---

## 9. Quick wins you can knock out in an afternoon

- Add `.github/workflows/` running the iOS tests and the v2 shell tests. You wrote
  the tests; make them earn their keep.
- Rotate + scrub the hardcoded TheGamesDB key.
- `#if DEBUG` around `Config.verboseNetworking` (it's always-on, even in release).
- Strip `console.*` from the JS (or add `no-console` and let the linter do it).
- Delete the dead code: the `metacritic.php` stub, the SQLite `if ($item['ID'])`
  shims in a MySQL-only app, the nginx `try_files … /api/index.php` pointing at a
  file that doesn't exist.
- Force a password change on the default `admin`/`admin` account at first login
  instead of trusting the README's "change them immediately."
- Add a migration ledger table so migrations don't rely on swallow-the-error
  idempotency.

---

## The honest summary

You're a better engineer than the weakest parts of this repo suggest — the v2 API
and the iOS sync layer prove it. The trouble is the project accreted a v1 layer, a
god-file frontend, and a security doc that all describe an app that no longer
exists. Fix the bugs in §1 this week because they can actually hurt you, then
spend your energy *converging on the good v2 core* rather than maintaining the
three-way split. Keep the specs habit, keep the tests, keep the Keychain
discipline — and point that same care at the parts that have been coasting.

Now go delete some code. The best refactor is usually the one that makes the repo
smaller.

— Fable
