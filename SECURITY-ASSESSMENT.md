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
- **SSRF** (Phase 1): every external-URL fetch — `image-proxy`,
  `download-cover`, `download-external-image`, `games.php`
  `downloadExternalImage()`, and `v2/images/cover.php`'s external HTTPS
  branch — routes through `includes/http-fetch.php`. It resolves the
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
- **Information disclosure** (Phase 1): v1 endpoints no longer return
  `$e->getFile()` / `getLine()` / `getMessage()` in JSON response
  bodies. Detail is logged server-side via `error_log()`.
- **Method safety** (Phase 1): every mutating v1 action requires POST
  (returns 405 otherwise). Combined with `SameSite=Lax`, this closes
  the GET-triggered CSRF vector.

## Known-open (accepted risk, tracked)

- **Full CSRF token enforcement** — the API layer has a
  `validateCsrfToken()` helper but it's only used on
  `change-admin-credentials.php`. Threading the token through the current
  3239-line `js/games.js` would be surgery on an unstable codebase; this
  waits for Phase 4 (frontend modularisation). Meanwhile, mitigations:
  SameSite=Lax + POST-only enforcement.
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
  `tests/v2/test_error_disclosure.sh`,
  `tests/v2/test_method_guards.sh`.
