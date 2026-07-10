# Phase 4 — Frontend Modularisation Roadmap

Fable §3 called the web frontend "the codebase has most obviously outgrown its structure":

- `js/games.js` = **3,239 lines**, ~62 top-level global functions, no modules, no classes
- **88 `console.*`** in games.js alone (131 across all JS)
- **4× duplicated `escapeHtml`** (JS files) + **4× more** inline in PHP pages = **8 copies**
- **~1,600 lines of inline JS** inside PHP pages (un-lintable, un-cacheable)
- No package.json, no bundler, no ESLint
- Loads the entire collection into memory (paged at 500, loops until done)

This roadmap breaks Phase 4 into small, safe PRs. Each phase should ship, deploy, and prove itself before the next starts.

---

## Phase 4a — dedupe `escapeHtml` (this session — landed)

The only cross-file duplicate with **identical bodies**, so the safe first win. Consolidated in `main.js` (loaded first on every page); the 8 duplicates deleted. Net: -40 lines, ~0 risk (main.js loads before any file that used to define it, and function-declaration hoisting means the identifier is always in scope by the time other scripts run).

## Phase 4b — dedupe `formatDate` + `getImageUrl` (design decision required)

Blocked on a UX call:

- **`formatDate`** — 3 variants:
  - `main.js:183`: `DD/MM/YYYY` (returns `'N/A'` for empty)
  - `stats.js:559` + `completions.js:689`: `Jan 15, 2025` (returns `''` for empty)
- **`getImageUrl`** — 2 variants:
  - `games.js:211`: takes `(imagePath, size)`. Handles base64 validation + external URL proxy + local `thumb` variant.
  - `stats.js:312`: takes `(imagePath)`. Simpler; returns an SVG placeholder on null.

Once we pick a canonical shape for each (or agree to keep both under different names like `formatDate` + `formatDateLong`), consolidation is a 30-minute PR.

## Phase 4c — package.json + ESLint (no CI wiring yet)

Small infrastructure PR:

- `package.json` — declares dev deps for ESLint 9 + Prettier.
- `.eslintrc.json` — flat config: `no-console: warn`, `no-var: error`, `prefer-const: warn`, `no-unused-vars: warn`.
- No CI hook — Cameron runs `npx eslint js/` manually to see the noise.
- Fixes any errors surfaced by ESLint (unused imports, `var`s that should be `const`, etc.).

## Phase 4d — strip `console.*` from `games.js` (bounded diff)

88 `console.*` calls in `games.js`. Most are debug noise. Bulk-delete via ESLint autofix + spot-check. Fable's §6 perf point: `console.*` isn't free at volume, and games.js logs per card render.

## Phase 4e — Vite + ES modules for a single sub-view

Introduce Vite:

- `package.json` gets `vite` + `@vitejs/plugin-legacy`.
- `vite.config.js` outputs bundles to `js/dist/` with content-hashed filenames.
- **Pick one small sub-view** to migrate first (candidate: `settings.js` — smallest, isolated, low blast radius). Convert its script tag to `<script type="module" src="js/dist/settings-<hash>.js">`.
- Nginx serves `js/dist/*` with `expires 1y immutable` (safe with content hashes).
- `games.js` etc. stay as classic scripts. Coexistence proven before broader migration.

Deploy note: the first deploy after this PR needs `npm ci && npm run build` on the VM. Adds a build step to the deploy loop. Worth it for the bundling + tree-shaking win.

## Phase 4f — Split `games.js`

Once Vite is proven on a small sub-view, tackle the god file. Fable's exact prescription:

- `api.js` — one fetch wrapper with shared error handling
- `render/{grid,list,coverflow}.js` — one per view mode
- `forms/game-form.js` — add/edit form logic
- `image-split.js` — the 500-line canvas splitter, imported by both games and add-item (currently duplicated)
- `utils.js` — the remaining shared helpers

Rough estimate: 2-3 sessions. Each split gets its own commit and its own manual QA pass.

## Phase 4g — Pull inline JS out of PHP

Every `<script>...</script>` block inside a PHP file becomes a proper `js/*.js` file. Un-lintable → lintable. Un-cacheable → cacheable. Fable named ~1,600 lines of inline JS across `item-detail.php` (~687), `settings.php` (~475), etc.

## Phase 4h — CSRF token rollout

Once the fetch wrapper (`api.js`) is unified, thread the CSRF token through every mutating call. This is what Phase 1 Task 10 deferred; Phase 4a-g get us to the point where it's a small change. Also update the posture doc.

## Phase 4i — Attribute-context XSS gaps

Fable §3 called out `src="${…}"` and inline `onerror="…"` handlers. A tagged-template helper (`html\`\``) that auto-escapes attribute contexts closes these. Do this once the render code is modularised.

---

## Deferred to Phase 5 (v1 retirement)

Once the frontend calls v2, delete v1. Removes ~10 PHP endpoint files and the auth-check.php nonsense that's still on some HTML routes.

## Related notes

- Fable §7 product features (PWA manifest, barcode scanning, CSV export) build on the Vite pipeline landing in 4e. Skip until then.
- Fable §6 perf: the "loads entire collection into memory" issue is fixed as part of 4f's `render/*.js` — server-side pagination + windowed render.

---

## Summary of scope

| Phase | Scope | Risk | Estimated effort |
|---|---|---|---|
| 4a | escapeHtml dedup | ~0 | 1 hour (done) |
| 4b | formatDate + getImageUrl dedup | Low (needs UX call) | 30 min once decided |
| 4c | package.json + ESLint | Low | 1-2 hours |
| 4d | Strip games.js console.* | Low | 30 min |
| 4e | Vite + first sub-view | Medium — new deploy step | 1 session |
| 4f | Split games.js | Medium-high — biggest diff | 2-3 sessions |
| 4g | Pull inline PHP-JS out | Medium | 1-2 sessions |
| 4h | CSRF token rollout | Low (small diff once 4f done) | 1 hour |
| 4i | Attribute-context XSS | Low | 1 hour |
