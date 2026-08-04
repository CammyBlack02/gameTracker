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
- **SSRF on caller-supplied URLs** (Phase 1): every fetch of a URL the
  caller supplies — `image-proxy`, `download-cover`, `games.php`'s
  `downloadExternalImage()`, `v2/images/cover.php`'s external HTTPS
  branch, and `includes/external-image-service.php` — routes through
  `includes/http-fetch.php`. **Scope clarified 2026-08-04:** this entry
  used to open with "every external-URL fetch", which reads as covering
  all outbound traffic. It does not — see the ungated third-party
  surface under Known-open. It resolves the
  host and rejects any private/loopback/link-local/reserved IP
  (including `169.254.169.254` cloud metadata) via
  `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. TLS
  verification is always on. Redirects are followed manually so each
  hop revalidates.
- **Cross-user data isolation** (Phase 1):
  - `steam-import.php` `deletePCGames`, dedup SELECT, and INSERT are all
    user-scoped.
  - `games.php` and `items.php` list endpoints ignore `?user_id=` override.
  - `admin.php?action=list` requires admin role.
  - `games.php?action=platforms` is user-scoped (#6b). It was the last
    v1 read left returning cross-user data — with no `user_id` it listed
    every user's platform names "for dropdown suggestions," and a
    `?user_id=` override pivoted to any chosen user outright. Now runs
    through `GamesService::platforms()`, which takes an explicit
    `$userId`; the override is ignored rather than rejected, matching
    `list`. Platform names are low-sensitivity on their own, but the
    override was a working cross-user read primitive and the
    "suggestions" framing is exactly how such things survive review.
- **Information disclosure** (Phase 1): v1 endpoints no longer return
  `$e->getFile()` / `getLine()` / `getMessage()` in JSON response
  bodies. Detail is logged server-side via `error_log()`.
- **Method safety** (Phase 1): every mutating v1 action requires POST
  (returns 405 otherwise). Combined with `SameSite=Lax`, this closes
  the GET-triggered CSRF vector.
- **CSRF token enforcement on v1** (Phase 4h/02-03): live, and covering
  every *authenticated* mutating action. `includes/csrf.php` answers a
  missing or invalid token with a 403 that ends the request, across
  `games.php`, `items.php`, `settings.php`, `completions.php`,
  `admin.php`, `upload.php`, `steam-import.php`, `stats.php` and
  `import-gameeye.php`. POST-only and `SameSite=Lax` are additional
  layers rather than substitutes. **Recorded 2026-08-04**, late: this
  landed in 4h and the doc still listed it as known-open, which is the
  failure mode this file exists to avoid. The one gap is `api/auth.php`
  — see Known-open.
- **v2 dual-auth** (Phase 5): `v2_require_auth()` accepts a Bearer token
  (iOS) *or* an active session (browser). The browser is deliberately
  never issued a Bearer token — an HttpOnly session cookie cannot be read
  by injected JS, whereas any token the frontend could attach could also
  be exfiltrated by XSS. The session path enforces CSRF on every mutating
  method, and `validateCsrfToken()` uses `hash_equals`.
  - Accepting sessions widened the browser-reachable v2 surface from
    nothing to every `v2_require_auth()` caller. The read-only endpoints
    (`images/cover.php`, `images/extra.php`, `pricecharting.php`,
    `metacritic.php`) now carry explicit `v2_require_method('GET')`
    guards, so what a session can reach is deliberate.
  - `external-image.php` is the one endpoint that changes state on GET
    (it must, because iOS calls it with GET). Since `SameSite=Lax` sends
    the cookie on a top-level cross-site navigation, that shape is
    CSRF-able for session callers, so it calls
    `v2_require_csrf_if_session()` and demands a token on *every* method.
    Bearer callers are exempt: a browser never attaches a Bearer token
    automatically, so there is no forgery vector to close.
  - v2 validates CSRF via `includes/csrf-core.php`, a dependency-free
    file, specifically so that including CSRF helpers does not drag
    `includes/auth.php` (and the v1 surface) into every v2 request.

## Known-open (accepted risk, tracked)

- **CSRF on `api/auth.php`** — the only v1 endpoint with no token check.
  Login and register are pre-session, so there is no session token to
  bind to; a token there would need a separate pre-session mechanism.
  Logout is POST-only but tokenless, so forced-logout is open at
  nuisance level. Its remaining writes are `rate_limits` bookkeeping.
  **This entry replaces a stale one** which claimed the CSRF helper was
  "only used on `change-admin-credentials.php`" and that enforcement
  waited on Phase 4. Both were false as of 2026-08-04 — see Mitigated.
- **Ungated fetches to hardcoded third-party hosts** — the SSRF helper
  covers caller-supplied URLs only. Requests to known third-party hosts
  use raw cURL and bypass it: `api/steam-import.php` (Steam),
  `includes/external-apis.php` (PriceCharting, Wikipedia),
  `api/v2/cover-image.php` (TheGamesDB), `src/Import/CurlTransport.php`
  (Steam, from the CLI). The first two contain no call into the helper.
  A fixed hostname is a much smaller surface than an attacker-supplied
  URL, which is why this is accepted rather than urgent — **but one case
  is not fixed at all**: `includes/external-apis.php` scrapes a
  PriceCharting search page, extracts a product URL out of the returned
  HTML, and fetches it with `CURLOPT_FOLLOWLOCATION` on. The target is
  chosen by remote content and no hop is revalidated. Routing that one
  call through `includes/http-fetch.php` is the cheapest real win here.
  Tracked 2026-08-04, found while documenting the architecture.
- **XSS in attribute contexts** — most text is escaped via `escapeHtml`,
  but a handful of image-attribute paths remain (Fable §3). Addressed
  in Phase 4.
- **Deploy-time schema changes** — `initializeDatabase()` in
  `includes/config.php` runs ~20 `CREATE TABLE`/`ALTER TABLE`
  statements on every request. Correctness + performance hazard, not
  a security hazard directly. Addressed in Phase 2 (backend
  unification).
- **`download-cover.php` still accepts GET** — the endpoint downloads
  an external image to disk. Post-Phase-1 the SSRF risk is closed, but
  the endpoint still allows a Lax-cookie'd cross-site GET to fill the
  uploads directory with images. Impact is disk-fill, not user data.
  Fold into Phase 4 alongside the frontend rewrite.
- **Committed secrets in history** — as of Phase 1 the TheGamesDB key
  is removed from `HEAD` and moved to `THEGAMESDB_API_KEY` env var (or
  per-user setting). The old value remains reachable in git history
  until an optional `git filter-repo` scrub is run. See the Phase-1
  plan Task 6 Step 6 for the destructive-rewrite procedure.

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

- [`FABLE-SUGGESTIONS.md`](FABLE-SUGGESTIONS.md) — the audit that drove
  the Phase 1 work.
- [`docs/superpowers/plans/2026-07-10-security-fixes-phase-1.md`](docs/superpowers/plans/2026-07-10-security-fixes-phase-1.md)
  — the plan document with per-task rationale.
- [`includes/http-fetch.php`](includes/http-fetch.php) — SSRF-safe fetch
  helper.
- [`includes/csrf.php`](includes/csrf.php) — CSRF token infra (waiting
  on frontend rewrite for full application).
- Regression tests: `tests/v2/test_ssrf.sh`,
  `tests/v2/test_steam_import_scoping.sh`,
  `tests/v2/test_v2_cover_ssrf.sh`,
  `tests/v2/test_admin_scoping.sh`,
  `tests/v2/test_list_scoping.sh`,
  `tests/v2/test_v1_read_contract.sh`,
  `tests/v2/test_error_disclosure.sh`,
  `tests/v2/test_method_guards.sh`.
