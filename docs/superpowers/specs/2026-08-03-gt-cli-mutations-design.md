# `gt` CLI — Mutations and Safety Rails (sub-project #2) — Design

**Status:** approved 2026-08-03.

**Depends on:** sub-project #1 (read + filter core), merged and deployed as of
`b4d43af`. Programme overview:
`docs/superpowers/specs/2026-08-03-gt-cli-design.md`.

## Goal

Let the CLI write to the collection, so bulk edits and agent-driven enrichment
become possible without the web app. This is the sub-project that unblocks the
two jobs Cameron named first: retagging fields across many rows, and backfilling
the 202 games that have no description.

## Governing constraint

Stated by Cameron: **temporary website breakage is acceptable; altering or losing
the games data is not.** Production is the live checkout with no staging copy.

Sub-project #1 satisfied that constraint by being read-only. #2 cannot, so the
constraint has to be met by design instead: every write is previewed before it
happens and reversible after it happens.

## Commands

```
gt games set <id> --set-<field>=<value> [--yes]        # single row
gt games set <filters> --set-<field>=<value> [--yes]   # bulk, filters from #1
gt games create --set-title=… --set-platform=… [--yes]
gt games delete <id> [--yes]
gt games delete <filters> [--yes]
gt items set / create / delete                          # same shape
gt undo [--list] [<journal-id>] [--force]
```

**Selection versus assignment.** Row selection reuses sub-project #1's filter
vocabulary verbatim (`--platform`, `--missing`, `--title-like`, …). Every
assignment is prefixed `--set-`. This is why `FilterCompiler` was built as a unit
separate from the read services — the same selector logic serves both.

```bash
# retag a platform across many rows
gt games set --platform="PS2" --set-platform="PlayStation 2" --yes

# the enrichment loop's write half
gt games set 412 --set-description="…" --yes
```

**A row is selected by id when a positional argument is given, and by filters
otherwise.** Passing both is a usage error: `gt games set 412 --platform=PS2`
reads as if the filter narrows the id, which it does not.

### Value syntax

- `--set-<field>=<value>` assigns a value.
- `--set-<field>` with no value assigns `1` (true), and is only valid on the
  boolean-ish integer columns `played` and `is_physical`. On any other column it
  is a usage error, because "set title to true" is never intended.
- `--set-<field>=0` assigns false on those same columns.
- `--clear-<field>` assigns `NULL`. Required as a separate flag because
  `--set-description=NULL` cannot be distinguished from the literal string
  `"NULL"`. `--clear-` on a `NOT NULL` column is a usage error.
- `--set-<field>=` (empty right-hand side) assigns the empty string, which is
  distinct from `--clear-`. Both are accepted; `--missing=<column>` matches
  either, so the distinction rarely matters in practice.

### Writable columns

Per-resource allowlists, mirroring the filter design. `id`, `user_id`,
`created_at` and `updated_at` are never writable.

**games:** `title`, `platform`, `genre`, `description`, `series`,
`special_edition`, `condition`, `review`, `star_rating`, `metacritic_rating`,
`played`, `price_paid`, `pricecharting_price`, `is_physical`, `digital_store`,
`release_date`, `front_cover_image`, `back_cover_image`

**items:** `title`, `platform`, `category`, `description`, `condition`,
`price_paid`, `pricecharting_price`, `quantity`, `front_image`, `back_image`,
`notes`

`create` additionally requires the `NOT NULL` columns with no default:
`title` and `platform` for games; `title` and `category` for items.

## Safety rails

**1. Dry-run is the default.** Without `--yes` nothing is written. The command
reports how many rows would change and shows the assignments:

```
$ gt games set --platform="PS2" --set-platform="PlayStation 2"
would update 0 rows matching --platform=PS2
  (no rows matched — did you mean "PlayStation 2"? see: gt games platforms)
```

Single-row writes require `--yes` too. Consistency matters more than the
keystrokes: an agent scripting 202 invocations passes the flag once in a loop,
while a human typing one command gets the same confirmation everywhere.

**2. A bulk write with no selector is refused.** `--all` is required to target
every row the user owns. Without this rule, `gt games set --set-played --yes`
would mark an entire collection played, and that is the single most damaging
typo the syntax permits.

**3. No arbitrary row cap.** 202 rows is a legitimate target; a cap would only
teach the operator to pass `--force` reflexively. The dry-run count is the guard.

**5. A bulk operation is one transaction.** All matched rows change or none do;
partial application never occurs. If zero rows match, the command reports
`0 rows` and exits 0 **without writing a journal entry** — there is nothing to
undo, and an empty entry would clutter `gt undo --list`.

**6. `create` writes one row per invocation.** There is no bulk create; importing
many rows at once is sub-project #4's job.

**4. Ownership is enforced in the writer, not the caller.** Every statement is
scoped by a bound `user_id`. `--admin` does not grant cross-user *writes* — it
exists for reads only. A write to another user's row is a domain error.

## Journal and undo

### Location

`~/.gt/journal/`, mode `0700`. **Outside the repository, deliberately.** Anything
under `/var/www/gameTracker` is inside the nginx document root and therefore
fetchable over HTTP; a journal of collection rows does not belong there. Being
outside the repo also keeps the production working tree clean.

### Entry format

One JSON file per operation, named `<UTC timestamp>-<operation>.json` —
e.g. `2026-08-03T20-14-02Z-set.json`. The basename is the journal id used by
`gt undo <journal-id>`.

```json
{
  "id": "2026-08-03T20-14-02Z-set",
  "argv": ["games", "set", "--platform=PS2", "--set-platform=PlayStation 2", "--yes"],
  "user_id": 1,
  "resource": "games",
  "operation": "set",
  "committed": true,
  "reverted_at": null,
  "rows": [
    { "id": 412, "updated_at": "2026-07-02 11:04:19", "before": { "platform": "PS2" } }
  ]
}
```

`before` holds only the columns the operation changed, except for `delete`, which
stores the entire row so it can be reconstructed.

### Ordering and crash behaviour

snapshot → write journal file → `BEGIN` → mutate → `COMMIT` → set
`"committed": true`.

A crash before the commit marker leaves an entry `undo` will skip, so the failure
mode is "cannot undo something that may not have happened" rather than "undo
applies a change that was never made". Failures land on the safe side.

### Undo semantics

| Operation | Undo |
|---|---|
| `set` | `UPDATE` each row back to its `before` values |
| `create` | `DELETE` the created row |
| `delete` | `INSERT` the row with its original id, then clear its tombstones |

`gt undo` reverts the most recent committed, unreverted entry. `gt undo --list`
shows recent entries newest-first. `gt undo <journal-id>` targets one.

**Undo is itself a write, so it follows the same rule as every other write:** it
prints what it would restore and requires `--yes` to act. `--list` is read-only
and needs nothing. Exempting undo would be a defensible convenience, but a
recovery action is exactly when a hasty command is most likely, and seeing the
row count before restoring is worth one extra flag.

Undo marks the entry `reverted_at`; re-undoing the same entry is refused. Undo
does not itself create a journal entry — that would invite recursion for no
benefit.

### Conflict detection

Each journalled row records its `updated_at`. On undo, if the row's current
`updated_at` differs, something else has changed it since — the web app, the iOS
app, or another CLI run — and undo **refuses** rather than silently discarding
that newer edit. `--force` overrides, per-command, and reports what it
overwrote.

This is the difference between an undo that is safe to reach for and one that is
its own hazard.

### Tombstones on undoing a delete

Migration `002_deletions.php` installs `trg_<table>_after_delete` triggers that
write a tombstone into `deletions` on every row delete, which is how the iOS app
learns about deletions during delta sync. All five triggers exist in production
(verified 2026-08-03).

So undoing a delete must **also remove the matching `deletions` rows** for that
`(table_name, server_id)`. Restoring the row alone would leave a tombstone for a
row that exists again; the next iOS sync would delete it locally, and undo would
look like it had silently failed on the phone.

## Architecture

```
bin/gt → Cli/Commands/Games/SetCommand
              ├─→ Query/GamesFilters + FilterCompiler   (row selection, reused from #1)
              ├─→ Write/AssignmentSet                    (parse --set-/--clear- vs allowlist)
              ├─→ Journal/JournalWriter                  (snapshot, commit marker)
              └─→ Services/Write/GamesWriter             (UPDATE/INSERT/DELETE, transactional)
```

New units, each with one responsibility:

- **`src/Write/AssignmentSet`** — turns `--set-*`/`--clear-*` options into a
  validated column→value map plus its bound parameters. Knows the writable
  allowlist; knows nothing about SQL execution or the CLI.
- **`src/Journal/JournalWriter`** — writes and reads journal entries, and finds
  the newest revertable one. Knows nothing about games or items.
- **`src/Services/Write/GamesWriter`, `ItemsWriter`** — execute the mutation
  inside a transaction, scoped by a bound `user_id`. Same rules as the read
  services otherwise: no `$_SESSION`, no `echo`, no `exit()`, throw on failure.

### The read-only guard changes rather than disappears

`tests/cli/test_readonly_guard.sh` currently fails if write SQL appears anywhere
in `src/Services` or `src/Query`. It becomes: write SQL is permitted **only**
under `src/Services/Write/`. The read services and the query layer stay provably
read-only, and the guard keeps its teeth instead of being deleted the moment it
becomes inconvenient.

## Sync correctness

Three properties hold without extra work, all verified against the live schema:

- `updated_at` is `DEFAULT_GENERATED on update CURRENT_TIMESTAMP`, so any
  `UPDATE` bumps it and iOS delta sync picks the change up.
- `DELETE` fires the tombstone triggers, so deletions propagate.
- `INSERT` is visible through `created_at`/`updated_at`.

One edge worth knowing: MySQL does not bump `updated_at` when an `UPDATE` sets a
column to the value it already holds. A no-op write therefore does not sync — and
also does not need to.

## Image files are never touched

`gt delete` removes database rows only. Image files stay on disk.

The web app's `deleteGame` unlinks cover files unconditionally, but
`createGame`'s `findMatchingGame` reuses another game's image *path* when a new
game matches an existing title and platform — so several rows can reference one
file. Five games are in that situation in production right now (verified
2026-08-03). Deleting one of them via the web app breaks its twin's cover art.

That is a pre-existing web-app bug, out of scope here, but it settles the CLI's
behaviour: never unlink. Orphaned files cost a little disk space; a broken cover
on a surviving game is data loss. Leaving files in place is also what makes
`delete` genuinely reversible — undo restores the row and the image is still
there.

## Errors

Exit codes are unchanged: `0` success, `1` domain error, `2` usage error,
`3` bootstrap/database.

**Usage errors (2):** unknown `--set-<field>`; a field that exists but is not
writable; `--set-<field>` with no value on a non-boolean column; `--clear-` on a
`NOT NULL` column; a bulk write with neither filters nor `--all`; both an id and
filters; `create` missing a required column.

**Domain errors (1):** the target row does not exist; the row belongs to another
user; undo refused because the row changed since journalling; undo of an
already-reverted entry.

## Testing

- **`tests/cli/test_gt_set.sh`** — dry-run writes nothing; `--yes` applies;
  no-selector refused; `--all` accepted; unknown and unwritable fields rejected;
  bulk counts correct; `--clear-` sets NULL; `--set-played` valueless sets 1;
  a cross-user write is refused.
- **`tests/cli/test_gt_create_delete.sh`** — create requires its `NOT NULL`
  columns; created row is readable via `games get`; delete removes the row;
  delete leaves image files on disk; a tombstone row is written.
- **`tests/cli/test_gt_undo.sh`** — set→undo restores before-values;
  create→undo removes the row; delete→undo restores the row **and** clears its
  tombstones; undo refuses when `updated_at` changed since journalling;
  `--force` overrides it; double-undo refused; an uncommitted entry is skipped.
- **`tests/cli/test_readonly_guard.sh`** — updated per the architecture section,
  with a negative control proving it still fails when write SQL appears outside
  `src/Services/Write/`.

Fixtures continue to own the dedicated `gtfixture` user and clean by `user_id`,
per the isolation lesson from #1: all suites share one database, and the v2
suites create their own rows under `testuser` before the CLI suites run.

## Delivery shape

This spec is one cohesive sub-project but a large one — `set`, `create`, `delete`
and `undo` across two resources. The implementation plan may sequence it into
more than one pull request, most naturally:

1. `AssignmentSet` + `JournalWriter` + `games set` + `gt undo` — the writing and
   reversing machinery, proven on the one operation that matters most.
2. `create` and `delete`, plus `items` for all three.

Splitting that way means the undo path is exercised before anything irreversible
in feel (`delete`) is built on top of it.

## Out of scope

- Users and accounts, `doctor`, and the raw-SQL escape hatch — sub-project #5.
- Steam/gameeye imports, cover and thumbnail repair — sub-project #4.
- The enrichment loop itself — sub-project #3. It needs no new code: it is a loop
  over `gt games list --missing=description` and `gt games set <id>
  --set-description=…`, with the research supplied by the agent.
- Fixing the web app's shared-image-file unlink bug.
- **Image reconciliation**, deferred to sub-project #4 by decision on
  2026-08-03. Because `gt delete` never unlinks, orphaned files accumulate;
  measured against production on that date:

  | | |
  |---|---|
  | Referenced local files | 1,142 |
  | Files on disk in `uploads/covers` | 1,190 |
  | Orphans (on disk, unreferenced) | 99 — 52 MB, of which 28 match the doubled-filename bug's pattern |
  | Missing (referenced, absent from disk) | 51 — 48 plausible filenames, 3 base64 remnants (`2Q==`, `9k=`) |
  | **Games with a broken front cover** | **43** |

  Three constraints for whoever builds it:

  - **Prune must move files to trash, not unlink them.** A mysqldump restore
    does not bring image files back, so deletion here is the least recoverable
    operation in the system. `~/.gt/trash/<timestamp>/`, journalled.
  - **Thumbnails are derived, not referenced.** 1,187 files live in
    `uploads/covers/thumbs/` and no database row points at them. A naive
    "delete unreferenced files" sweep would destroy every thumbnail. Keep a
    thumb if *its source* is referenced.
  - **Those 43 broken covers are invisible to every filter built in #1.**
    `--missing=front_cover_image` matches NULL or empty, not a column pointing
    at a file that no longer exists. #4 needs either audit output or a
    `--broken-cover` predicate that stats the disk.

  Repairing them requires this sub-project's write machinery — clearing the dead
  column, or re-fetching via `api/v2/cover-image.php` — so the ordering works
  out without #4 blocking on anything else.
- Retiring `initializeDatabase()` or repairing the production migration ledger,
  tracked separately.
