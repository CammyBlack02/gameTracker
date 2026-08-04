# `gt` CLI — Image Reconciliation (sub-project #4b) — Design

**Status:** approved 2026-08-04.

**Depends on:** #2 (mutations, journal, undo) and #4a (import), both merged and
deployed at `3fd01f3`.

## Goal

Get images under control in both directions: stop the database being used as an
image store, and reconcile the files on disk with the rows that reference them.

## The finding that reorders this sub-project

The deferred notes framed #4b as orphan cleanup — 99 unreferenced files, 51 MB.
Measuring first found something an order of magnitude larger.

**81 rows hold ~113 MB of base64.** `games.front_cover_image` carries 47.8 MB
across 42 rows and `back_cover_image` 65.0 MB across 39; the largest single
cover is 3.1 MB. Backups are 89 MB gzipped, and base64 of already-compressed
JPEG barely compresses further — so **the database is essentially 81 images**.

Two live consequences:

- `api/v2/sync/changes.php` streams `SELECT *`, so every delta sync ships those
  rows in full to the phone. That endpoint already needed `memory_limit` raised
  to 256M and a rewrite to stream row-by-row.
- Every backup, restore and dump carries 113 MB that belongs on disk.

**And it refills.** `scripts/migrate-base64-covers.php` converted 542 rows on
2026-05-25, yet 42 remain — 12 created *after* that date, 11 of them in a single
burst between 15 and 18 July, including a 1.8 MB `Battlefield 6`.

### Why it refills

`api/games.php` takes the cover value straight from the request body in both
`createGame` (line 373) and `updateGame` (line 559), then localises exactly one
storage mode (lines 593-597):

```php
if (strpos($data['front_cover_image'], 'http://') === 0 ||
    strpos($data['front_cover_image'], 'https://') === 0) {
    $downloaded = downloadExternalImage(...);   // becomes a file
}
```

An `http(s)` URL is downloaded to a file. **A `data:` URI has no branch and is
stored verbatim.** That is the bug. Converting the existing 81 without fixing it
would buy a few months.

So the ordering is: fix the source, then convert, then reconcile the disk.

## Scope

| Part | Nature |
|---|---|
| 1. Reject base64 at the write path | the actual bug fix, in `api/games.php` |
| 2. Convert the existing 81 rows | run the existing script once |
| 3. `--broken-cover` predicate | new filter |
| 4. Orphan prune to journalled trash | new CLI command |

Parts 2-4 are cleanup that only stays clean because of part 1.

## Part 1 — a column holds a filename or a URL, never an image

`downloadExternalImage($imageUrl, $gameId = null, $type = 'front')` already
exists at `api/games.php:438`. A sibling handles the other mode:

```php
function storeDataUriImage(string $dataUri, ?int $gameId, string $type): ?string
```

It decodes, validates magic bytes, writes to `uploads/covers/`, generates the
thumbnail, and returns the bare filename — the same contract
`downloadExternalImage` already honours. The call site gains one branch beside
the existing one, in **both** `createGame` and `updateGame`; today only
`updateGame` localises anything at all, which is its own gap.

**A decode failure rejects the write with a 400.** It does not fall back to
storing the data URI, because that is precisely the behaviour being removed.

This is a v1 web-path fix, not CLI work. It is in this sub-project because
without it the rest is temporary.

## Part 2 — convert the existing 81

`scripts/migrate-base64-covers.php` already does this: idempotent, dry-run by
default, covering `games.front_cover_image`, `games.back_cover_image`,
`items.front_image`, `items.back_image`, `game_images.image_path` and
`item_images.image_path`. No new code.

What this sub-project adds is the **procedure**, because it is a 113 MB write
against production:

1. Take a backup and **verify the artifact**, not the log line — check size,
   `CREATE TABLE` count, and mysqldump's completion marker. A backup that
   reports success while producing nothing is the documented failure mode here.
2. Run without `--execute` and read the plan.
3. Run with `--execute`.
4. Re-run `scripts/audit-images.sh` and confirm the data-URI counts reach zero.
5. Confirm the games table and a fresh dump have both shrunk.

Ordered after part 1 so the conversion is not immediately re-polluted.

## Part 3 — `--broken-cover`

The 49 rows pointing at absent files are invisible to every filter built in #1:
`--missing=front_cover_image` matches NULL or empty, not a column naming a file
that no longer exists.

`--broken-cover` is added to `GamesFilters` and `ItemsFilters` as a **predicate
evaluated in PHP, not SQL** — the filesystem is not something a `WHERE` clause
can consult. That makes it structurally different from every existing filter,
which compile to SQL, so it is applied as a post-fetch pass over the result set.

**It must branch on storage mode before touching the disk.** A value is a
`data:` URI, an `http(s)` URL, or a filename, and only the third kind lives on
disk. Treating the other two as paths is exactly the bug that produced the wrong
2026-08-03 figures, and it is easy to repeat — 51 game covers and 58 item images
are external URLs that would otherwise read as broken.

Because it cannot be a `WHERE` clause, it interacts badly with pagination:
filtering after `LIMIT` would report "3 broken on this page" rather than the
truth. So `--broken-cover` **implies `--all` paging** and is documented as a
scan, not a filter.

## Part 4 — orphan prune

```
gt images audit                     # counts, per storage mode. read-only
gt images prune [--yes] [--restore=<trash-id>]
```

**Prune moves files to `~/.gt/trash/<timestamp>/`, never unlinks.** This is the
least recoverable operation in the system: a mysqldump restore brings back rows,
not files, and there is no image backup older than 2026-07-28 that contains
anything the current tree does not. Deletion here is genuinely irreversible in a
way no other CLI operation is.

Trash is outside the repository for the same reason the journal is — the
document root is HTTP-reachable.

**Thumbnails are derived, not referenced.** 1,187 thumbnails live in
`uploads/covers/thumbs/` and no database row points at any of them. Measured
2026-08-04: **1,089 have a referenced source and must be kept; 98 do not and can
go.** A naive "delete unreferenced files" sweep would destroy all 1,089. The
rule is therefore: *keep a thumbnail if its source file is referenced*, matching
`gt_thumbnail_path()`'s convention that a thumb sits at `<dir>/thumbs/<name>`.

Current candidates: 99 orphan source files (51.4 MB) plus their 98 thumbnails.

Prune is journalled like any other write, so `gt undo` reports it — but the
restore path is `--restore=<trash-id>`, moving files back, because undo's job is
rows and this operation's payload is files.

## Architecture

```
bin/gt → Cli/Commands/Images/AuditCommand      (read-only)
                            /PruneCommand
    ├─→ Images/StorageMode                  data-uri | url | filename — the
    │                                       branch everything else needs first
    ├─→ Images/Reconciler                   referenced vs on-disk, pure logic
    └─→ Services/Write/Trash                moves files, journals, restores
```

- **`src/Images/StorageMode`** — `of(string $value): string`. One place that
  decides what a column is holding, so no future audit repeats the basename bug.
- **`src/Images/Reconciler`** — takes the referenced set and the disk listing,
  returns orphans, missing and thumbnail decisions. Pure; no filesystem, no
  database, so it is testable without fixtures.
- **`src/Services/Write/Trash`** — the only unit that moves or deletes files.

`scripts/audit-images.sh` is superseded by `gt images audit` and deleted; a
shell script and a CLI command computing the same numbers differently is how
they come to disagree.

## Safety rails

Inherited from #2, plus one addition:

1. **Prune always previews and requires `--yes`.** Bulk by nature.
2. **Never unlink.** Move to trash.
3. **A file is only an orphan if no row references it in any storage mode**, and
   thumbnails follow their source.
4. **Trash is never auto-swept.** No retention policy in this sub-project —
   deleting the safety net on a timer defeats the point. Emptying it is a
   deliberate manual act.
5. Ownership scoping is unchanged; the reconciler only considers rows the acting
   user owns, and only prunes files no user references.

## Errors

Exit codes unchanged. **Usage (2):** an unknown `--restore` id. **Domain (1):** a
trash directory that cannot be written; a file that vanished between plan and
apply.

## Testing

- **`tests/cli/test_gt_images.sh`** — `StorageMode` classifies data URIs, URLs
  and filenames correctly, including a data URI containing `/` (the exact input
  that broke the first audit); orphans and missing computed correctly against a
  fixture tree; a thumbnail whose source is referenced is never pruned;
  prune previews without `--yes` and moves nothing; with `--yes` files land in
  trash and not `/dev/null`; `--restore` puts them back; `--broken-cover`
  ignores URL- and data-URI-backed rows.
- **`tests/v2/test_base64_rejected.sh`** — the part 1 fix: posting a `data:`
  cover to the v1 create and update paths stores a **filename**, not the URI,
  and an undecodable data URI is a 400 rather than a silent store.

Fixtures use a temporary tree under the test directory, never `uploads/`.

## Out of scope

- Retention or auto-emptying of trash.
- Recovering the 45 files lost in the 2025-12-05 server rebuild — established
  unrecoverable, and Cameron will replace those covers by hand.
- Migrating v1 image handling to v2 (that is #6).
- De-duplicating the shared image paths that make the web app's `deleteGame`
  unlink dangerous; the CLI already never unlinks.
