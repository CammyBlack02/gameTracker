# Architecture diagrams — design

**Date:** 2026-08-04
**Status:** implemented — this spec ships on the branch that implements it
(`docs-architecture-diagrams`). Corrections below marked *(corrected 2026-08-04)*
were wrong in the approved version and were caught by review against the code;
they are fixed here so the errors are not regenerated from this document.

## Problem

gameTracker has no architecture documentation between the three-box ASCII sketch
in `README.md:48-63` and the code itself. That sketch shows iOS, server and web
as three boxes; everything below it — the two API generations, the partially
completed service convergence, the journal/tombstone write path — has to be
rediscovered by reading source.

Three specific costs:

1. **Reload cost after a gap.** The server is an intermittently-powered laptop
   and work happens in bursts. Subsystems whose rules are subtle (image storage
   modes, sync cursor boundary, undo semantics) get re-derived each time.
2. **The refactor has no visible shape.** Sub-project #6b is collapsing three
   copies of the same rules onto `src/Services`. Nothing states how far that has
   got, so "what's left" lives only in memory and commit messages.
3. **Nothing to show another person.** No single readable overview exists.

## Goals

- Re-orienting after a gap, without file-level detail that rots.
- Making the refactor's current state and target legible.
- Explaining the system to another person.

**Explicit non-goal: navigation aid.** These diagrams are not a code map. No
function names, no line numbers, and file paths only where the path *is* the
architectural fact (`src/Services`, `api/v2/`). Someone asking "which file do I
edit?" should use the code, not these.

## Deliverable

Nine mermaid diagrams across four levels, in `docs/architecture/`, plus a
published Artifact page rendering all nine on one page.

The mermaid source is identical in both places — GitHub renders mermaid in
Markdown and Artifacts render it natively — so there is one copy, not two. The
repo is the source of truth; the artifact is a reading view, republished from the
same blocks.

### Level 1 — The system from outside

**1. System context.** nginx → php-fpm → MySQL 8 on one box, with three
consumers (browser, iPhone, `gt` running on the box itself) and the external
services it reaches: TheGamesDB, Steam, PriceCharting, Wikipedia. Includes the
ops edges — DuckDNS tracking a rotating WAN IP, Let's Encrypt TLS.

*(corrected 2026-08-04)* **Metacritic is not one of them.** `api/metacritic.php`
and `api/v2/metacritic.php` both return a fixed "no longer supported" response
and fetch nothing; scores are entered manually. Do not draw it as a dependency.

*(corrected 2026-08-04)* Must encode: there are **two** exits to the internet and
only one is gated. `includes/http-fetch.php` gates fetches of *caller-supplied*
URLs (`api/games.php`, `api/download-cover.php`, `api/image-proxy.php`,
`api/v2/images/cover.php`, `includes/external-image-service.php`), rejecting
private/loopback/link-local/reserved IPs and revalidating each redirect hop.
Requests to a **hardcoded** third-party host bypass it entirely via raw cURL:
`api/steam-import.php`, `includes/external-apis.php`, `api/v2/cover-image.php`
and `src/Import/CurlTransport.php` — the first two contain no call into the guard
at all. Do not write "every external fetch goes through the guard"; that is the
claim in `includes/http-fetch.php`'s own docblock and it is false. Sharpest
consequence to encode: `includes/external-apis.php` scrapes a PriceCharting
search page, extracts a product URL from the returned HTML, and fetches it with
redirect-following on — an ungated fetch whose target is set by remote content.

**2. User journey.** One game from "add it" to "it's on the phone": form → v1
auth + CSRF check → cover fetched and written to a file → `INSERT` carrying that
filename → success response → the phone's next delta sync collects it. This is
the diagram that makes the other eight legible to a newcomer, so it is
deliberately the least detailed.

*(corrected 2026-08-04)* Must encode the **real** ordering: on the v1 web path
the cover is fetched **before** the INSERT, its filename goes into the INSERT
itself, and the response is sent afterwards — so the user's request blocks on the
network. There are **no transactions anywhere in `api/games.php`**, so there is
nothing to commit and no row locks to protect. Failure behaviour on this path: a
URL that will not fetch stays in the column *as a URL* and nothing is counted; an
undecodable `data:` URI is a hard 400 and no row is created.

The post-commit-fetch design is real but belongs to the **CLI import path** —
`src/Services/Write/Importer.php` commits, then covers are fetched, a failed
download is non-fatal, the row keeps a NULL cover and the failure is counted. If
diagram 2 mentions it, it must be attributed to the import path and explicitly
distinguished from the web create path.

### Level 2 — The two generations

**3. v1 vs v2, side by side.** Session cookie vs bearer token; `{success,
message}` vs `{data}` / `{error}`; which client each serves; the fixed v2 include
order.

Must encode:
- Tokens are 32 random bytes shown as 64 hex chars; the DB stores **SHA-256**,
  deliberately not bcrypt (cheap deterministic lookup).
- **v2 never includes a v1 file.** Phase 2c removed the old proxy pattern that
  set `$_SESSION` and reshaped v1 responses through an output buffer.
- *(corrected 2026-08-04)* **CSRF token enforcement on v1 is live**, not pending.
  The check runs on the mutating paths of nine endpoints — `api/games.php`,
  `api/items.php`, `api/settings.php`, `api/completions.php`, `api/admin.php`,
  `api/upload.php`, `api/steam-import.php`, `api/stats.php`,
  `api/import-gameeye.php` — and answers a missing or invalid token with a 403
  that ends the request. POST-only mutation and `SameSite=Lax` are additional
  layers, **not** substitutes. The approved version of this spec said the latter
  two "stand in for full CSRF enforcement" and that `includes/csrf.php` was
  waiting on the frontend rewrite; both were false.
- *(corrected 2026-08-04)* v2 serves the iPhone app **and** the browser: `js/api.js`
  has a session-credentialed v2 GET helper that the game form uses. Do not draw
  the phone as v2's only consumer.

**4. Data model.** All twelve tables with FK delete rules **on the edges**,
because those rules are the behaviour:

```
api_tokens.user_id        -> users.id  CASCADE
games.user_id             -> users.id  CASCADE
items.user_id             -> users.id  CASCADE
settings.user_id          -> users.id  CASCADE
game_completions.user_id  -> users.id  CASCADE
game_images.user_id       -> users.id  CASCADE
item_images.user_id       -> users.id  CASCADE
game_images.game_id       -> games.id  CASCADE
item_images.item_id       -> items.id  CASCADE
game_completions.game_id  -> games.id  SET NULL   <-- the odd one out
```

Must encode: `game_completions.game_id` is the lone `SET NULL`, and that is
precisely the sync bug the writes half of #6b closes by relinking child rows.

Also annotate: `deletions` holds tombstones rather than domain data;
`schema_migrations` is the ledger; `game_images` and `item_images` are both
**empty as of 2026-08-04** (0 rows), so the extra-images feature is effectively
unused. State the row counts as a dated snapshot, not a fact.

### Level 3 — The refactor in flight

**5. Convergence map.** Which paths reach `src/Services` today versus which still
carry their own SQL. Verified against `origin/main` at `3379fce`:

| Path | Rules live where |
|---|---|
| `api/games.php` reads (list, get, platforms) | `src/Services` |
| `api/games.php` writes (create, update, delete) | own SQL |
| `api/items.php` | own SQL |
| `api/v2/games/` list + get | `src/Services` |
| `api/v2/items/` list + get | `src/Services` |
| `api/v2/sync/changes.php`, `push.php` | own SQL |
| `gt` CLI (`src/Cli`) | `src/` natively |

The useful insight this diagram carries: it is **not** "three copies of
everything," it is a partially completed convergence. Colour-code by state and be
honest that `api/items.php` and both sync endpoints are entirely untouched.

**6. Target end-state — labelled aspirational.** `src/Services` as the single
home for the rules, with v1, v2 and the CLI as three thin transport adapters
differing only in auth and response shape.

This diagram is an interpretation of direction, not a statement about existing
code. It must be visually and textually marked as such — it is the one most
likely to be wrong.

### Level 4 — The three subsystems that don't fit in your head

**7. Write path: journal, tombstones, undo.** Write SQL confined to
`src/Services/Write/`; every applied write journalled to `~/.gt/journal`;
`deletions` tombstones; undo's `updated_at` guard.

Worth showing that the confinement is enforced in three layers by
`tests/cli/test_readonly_guard.sh`, since a guard is only as good as its
non-vacuity: no write SQL outside the permitted directory, the permitted
directory must actually *contain* some, and the matching pattern is probed
against known write statements so it cannot silently stop matching.

Must encode: **a restore deliberately takes a fresh `updated_at`.** Restoring the
original timestamp would leave the row present on the server and permanently
missing on the phone, because `sync/changes.php` only returns rows newer than the
client's cursor. Also: delete never unlinks image files, because several games
share one path — which is what makes delete reversible.

**8. iOS delta sync.** The `since` cursor; `changes.php` streaming **five**
tables — games, items, `game_completions`, `game_images`, `item_images` — plus
deletion tombstones, and ending with `server_now` *(corrected 2026-08-04: the
approved version listed four and omitted `item_images`; diagram 2's summary of
the same response must match)*; `push.php` conflict
detection comparing the client's base `updated_at` against the server's current
value, returning the full `server_version` on conflict.

Must encode: the comparison is **`>=`, not `>`**. MySQL `updated_at` has
second precision, so a strict `>` misses any row whose timestamp rounds down.
`ChangeApplier` looks rows up by `server_id` before insert/update, which is what
makes the resulting re-delivery harmless.

**9. Image storage pipeline.** The four `StorageMode` values — `filename`, `url`,
`data-uri`, `empty` — and that an image column holds a *reference*, never an
image. Thumbnails are derived and referenced by nothing, so they follow their
source.

Must encode:
- `ImageIndex` does all I/O at the edge; `Reconciler` is pure (no filesystem, no
  database) and therefore exhaustively testable without fixture trees.
- `prune` **never unlinks** — files move to `~/.gt/trash/<id>/` and `--restore`
  brings them back. No retention policy and no `empty` command, deliberately.
- `audit` and `prune` derive the uploads directory from the same `config.php`
  that provides `$pdo`, so disk and database always describe one install; if most
  referenced files look absent, `audit` warns and `prune` refuses.

## File layout

```
docs/architecture/README.md        index, how to read, when to update
                  1-outside.md     diagrams 1-2
                  2-apis.md        diagrams 3-4
                  3-refactor.md    diagrams 5-6
                  4-subsystems.md  diagrams 7-9
```

`README.md` gets a link to the index. The existing ASCII sketch in `README.md`
stays — it is a fine thumbnail — with a pointer to the detail.

## Anti-rot mechanism

Diagrams in git that nobody updates are worse than none. Each diagram carries a
one-line **"Goes stale when…"** note naming the change that invalidates it:

| Diagram | Goes stale when |
|---|---|
| 1 Context | a new external service is called, or the box's stack changes |
| 2 Journey | the create path or cover-download ordering changes |
| 3 v1/v2 | auth or response shape changes on either generation |
| 4 Data model | a migration adds/removes a table or changes a delete rule |
| 5 Convergence | **any path moves onto or off `src/Services`** |
| 6 Target | the intended end-state changes |
| 7 Write path | journal format, tombstone handling or undo guards change |
| 8 Sync | the cursor comparison, streamed tables or conflict rule changes |
| 9 Images | a storage mode is added, or trash/prune policy changes |

Diagram 5 is the one that will move most, and **the writes half of #6b should
update it in the same PR.** Row counts in diagram 4 are dated snapshots and are
allowed to drift; they carry an "as of" date rather than a staleness claim.

## Verification

Documentation, so no test suite applies. What is checked:

1. **Every mermaid block parses and renders.** Confirmed on the published
   artifact, and spot-checked on GitHub after the PR is up. A diagram that fails
   to parse renders as a raw code block on GitHub — silent, not loud.
2. **Every factual claim traced to code or database**, not memory. The
   convergence table, FK delete rules and row counts in this spec were read from
   `origin/main` at `3379fce` and the live database on 2026-08-04.
3. **No stated file path or directory that does not exist.**
4. Diagram 6 carries an explicit aspirational marker.

## Out of scope

- Diagrams of the iOS app's internal structure (SwiftUI view hierarchy,
  SwiftData model layer). The sync boundary is covered; the app's insides are
  their own topic.
- A request-lifecycle / include-order diagram. That is a navigation aid, and
  navigation is an explicit non-goal.
- Any code change. This PR is documentation only.
