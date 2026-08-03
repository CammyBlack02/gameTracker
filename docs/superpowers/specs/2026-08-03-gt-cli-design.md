# `gt` CLI — Design

**Status:** approved 2026-08-03. This spec covers the CLI programme as a whole,
then specifies **sub-project #1 (read + filter core)** in full. Sub-projects #2–#6
get their own specs.

## Why

A command-line interface to gameTracker, so the system can be driven without the
web app or the iOS app. Those become viewers over the data rather than the only
way to reach it.

Two audiences:

- **Cameron** — bulk edits, inspection, one-off fixes, and answering questions
  about the collection without clicking through a dashboard.
- **Agents (Claude Code)** — the server is headless: no browser, no desktop
  environment, no Chrome, and no headless-browser tooling (verified 2026-08-03).
  An agent therefore cannot exercise any user-facing behaviour end to end, so
  every feature checkpoint currently blocks on a human with a browser. The CLI
  removes that bottleneck for everything except genuinely visual QA.

## Programme shape

The full request spans six subsystems with different risk profiles. They are
sequenced by dependency, not by appeal.

| # | Sub-project | Depends on | Risk |
|---|---|---|---|
| **1** | **Read + filter core** — services, filters, `games`/`items` list+get, output | — | None (read-only) |
| 2 | Mutations + safety rails — `set`/`create`/`delete`, `--yes`, dry-run, audit log | 1 | High (live data) |
| 3 | Enrichment loops — agent-driven description/metadata backfill | 1, 2 | Medium |
| 4 | Import / repair — Steam, gameeye, covers, thumbnails | 1 | Medium |
| 5 | Ops / accounts — users, `doctor`, backup status, raw-SQL escape hatch | — | Mixed |
| 6 | Connectivity / parity testing — stand up `api/v2` domain endpoints as thin service wrappers, then verify the web and iOS paths agree with the CLI | 1, 2 | None |

**A finding that shrank the programme:** the enrichment goal ("go through each
game and add a correct description from the web") needs no AI or web-search
inside `gt`. The agent supplies the research and judgement; the CLI only needs
clean, loop-friendly primitives:

```bash
gt games list --missing=description --json   # select
#   agent researches each one
gt games set <id> --description="…"          # apply  (sub-project #2)
```

Integrating third-party metadata APIs stays optional rather than foundational.

## Governing constraint

Stated by Cameron 2026-08-03: **temporary breakage of the website is acceptable;
alteration of the games data is not.** Production is the live checkout with no
staging copy and no build step for PHP.

Consequences:

- Sub-project #1 is read-only *by construction* — every statement is a `SELECT`,
  so it cannot violate the constraint regardless of bugs.
- Sub-project #2 is where the constraint actually bites, and its safety model is
  specified separately rather than designed under pressure to ship features.
- Speed is preferred over ceremony for code-level risk. Parity-proving rituals
  are not required before changing the website's own code paths.

## Sub-project #1 — Read + filter core

### Commands

```
gt games list [filters]     gt items list [filters]     gt whoami
gt games get <id>           gt items get <id>           gt db info
gt games platforms
```

`items` is the accessories table. It is included because accessories were named
as a real need and it is the same pattern for roughly thirty extra lines.

`whoami` and `db info` already exist from Phase A. `db:info` is **renamed** to
`db info` to match the grammar decision below.

### Grammar

**Noun-verb**, space separated, as in git/docker/kubectl: `gt games list`, not
`gt games:list`. It reads naturally, tab-completes in stages, and scales as the
surface grows. Phase A shipped a colon (`db:info`) before this was decided; that
rename is part of this sub-project.

### Read-only guarantee

No command in #1 issues anything but `SELECT`. This is enforced mechanically, not
by promise: a CI test greps `src/Services/` and `src/Query/` for
`INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE` and fails if any appears.
Sub-project #2 lifts the guard explicitly when it introduces writes.

### Filters

AND-combined. Applied against a **column allowlist**, so an unknown column is a
usage error and SQL injection is structurally impossible rather than merely
avoided.

| Flag | Meaning |
|---|---|
| `--platform=<v>` | exact match |
| `--genre=<v>`, `--series=<v>` | exact match |
| `--title-like=<v>` | substring match |
| `--played` / `--unplayed` | boolean `played` |
| `--physical` / `--digital` | boolean `is_physical` |
| `--rating-min=<n>` / `--rating-max=<n>` | `star_rating` bounds |
| `--missing=<column>` | column is `NULL` or empty string |
| `--added-since=<date>` / `--added-before=<date>` | `created_at` bounds |
| `--limit=<n>` | alias for `--per-page`; the interactive shorthand |
| `--page=<n>`, `--per-page=<n>` | explicit paging. Defaults: page 1, 100 per page, hard cap 1000 |
| `--sort=<column>` | ordering, allowlisted. A leading `-` means descending (`--sort=-title`). Default `-created_at`, matching the current endpoint's `ORDER BY created_at DESC` |

`--missing=<column>` is load-bearing: it generalises every enrichment case, so
the backfill loop needs no new flags as more fields become interesting. It takes
any allowlisted column rather than hardcoding `--no-cover`, `--no-description`
and friends.

`--title-like` replaces the `--title~=` syntax floated during design — same
behaviour, less punctuation.

The table above is the **`games`** allowlist. `items` gets its own allowlist
derived from its own columns, sharing only the flags that genuinely apply
(`--title-like`, `--missing`, the paging and sort flags). Filters are per-resource
rather than one global set, so `gt items list --played` is a usage error instead
of silently matching nothing against a column that does not exist.

### Architecture

```
bin/gt → src/Cli/Application ─→ src/Cli/Commands/Games/ListCommand
                                        │
                                        ├─→ src/Query/GameFilters
                                        │      (flags → WHERE fragment + bound params)
                                        └─→ src/Services/GamesService
                                               (SELECT, returns arrays, throws DomainException)
                                                        │
                                                       PDO
api/games.php ─── untouched in this sub-project ──────→ PDO
```

`GameFilters` is a separate unit from `GamesService` deliberately. Sub-project #2's
`gt games set` must select rows with the *same* vocabulary; if filtering lived
inside the service's read method, mutations would grow a second, divergent
dialect. Its contract is narrow: given parsed flags, return a `WHERE` fragment
plus bound parameters, or throw on an unknown column.

Services keep the rules established in Phase A:

- Input arrives as arguments. No `$_GET` / `$_POST` / `$_SESSION` / `php://input`.
- No `header()` / `echo` / `exit()`. Results are returned; failures are thrown.
- Identity is an explicit `int $userId`, so the "every query is user-scoped"
  invariant from `CLAUDE.md` is visible in the signature rather than hidden in
  session state.

### The v1 rewire is deferred

`api/games.php` keeps its own read logic for now. Rewiring it onto the service
delivers no CLI capability, so it does not belong in the critical path to a
working CLI. It gets its own sub-project, and given the governing constraint it
can be done directly without a parity-proving ritual.

The cost is acknowledged: two implementations of game-listing exist until then,
and they can drift.

### Output

**TTY detection.** A table when a human is looking, JSON when piped or
redirected. `--json` and `--table` force either. This replaces Phase A's
JSON-always default, which optimised for the agent at the human's expense.

```
$ gt games list --platform=PS2 --unplayed --limit=3
id    title                     platform  played  rating
----  ------------------------  --------  ------  ------
412   Silent Hill 2             PS2       no      -
418   Okami                     PS2       no      4

$ gt games list --missing=description --json | jq -r '.games[].id'
412
418
```

The table shows a readable subset of columns; JSON always carries the full row so
nothing is lost to formatting. Warnings and errors go to STDERR so a pipe into
`jq` never breaks.

### Errors

Phase A's exit codes, unchanged:

| Code | Meaning |
|---|---|
| 0 | success |
| 1 | domain error (not found, access denied, bad input) |
| 2 | usage error (unknown command, unknown flag, bad `--missing` column) |
| 3 | bootstrap / database unavailable |

Domain failures carry a stable slug (`not_found`, `access_denied`,
`bad_request`) so the same failure maps onto a CLI exit code, a v1 HTTP status,
and a v2 error envelope without re-deciding per caller.

### Testing

- `tests/cli/test_gt_games.sh` — fixture games inserted into `gameTracker_test`,
  then assertions that each filter returns exactly the expected ids. Includes a
  row owned by a second user to prove scoping holds.
- `tests/cli/test_gt.sh` — Phase A's suite, updated for the `db info` rename and
  TTY-detected output.
- The read-only grep guard described above.

**Known constraint:** the suites cannot currently run on this box.
`setup-test-db.sh` must `DROP`/`CREATE DATABASE gameTracker_test`, and the
available MySQL user (`CammyBlack02`) holds `ALL PRIVILEGES ON gameTracker.*`
only — no global rights. CI is therefore the only authoritative run today.
A one-time `GRANT ALL ON gameTracker_test.* TO 'CammyBlack02'@'localhost'`
would unblock local runs permanently and speed up every sub-project after this
one. It needs mysql root, so it is Cameron's to run.

### Autoloading

`src/` uses a ~20-line PSR-4 autoloader with no third-party dependencies, added
in Phase A. The project has no composer, no `vendor/`, and no dependency manager;
keeping it that way leaves `scripts/deploy.sh` and the laptop deploy story
unchanged. The namespace layout is PSR-4, so swapping in composer's autoloader
later is mechanical if a real dependency ever appears.

### Out of scope for #1

Writes of any kind, enrichment, imports, raw SQL, `--http` mode, remote
invocation from another machine, and the v1 rewire. Each belongs to a later
sub-project.

## Why PHP

Asked and answered 2026-08-03. In-process service calls require the CLI to share
the API's runtime; a CLI in another language could only speak HTTP, which would
limit it to v2's surface — and v2 has no domain endpoints at all. Beyond that,
`php8.3` CLI is the same interpreter serving production, so behaviour is
identical rather than merely equivalent, and `database/migrate.php` is existing
precedent for a PHP CLI in this repo.

Worth recording: extracting services is also the prerequisite for ever porting
off PHP, since it decouples domain logic from the transport. This work is not
wasted under a later rewrite.

## Related findings (not part of this spec)

Discovered while building Phase A, tracked separately because both are
production-state issues rather than CLI design:

1. `schema_migrations` does not exist on production — `database/migrate.php` has
   never run there, so the ledger is not the source of truth despite what
   `CLAUDE.md` says.
2. Production's untracked `includes/config.php` still defines
   `initializeDatabase()`, long after the committed template stopped calling it,
   so every web request fires ~20 DDL statements.
3. `000_baseline.php` groups three `ALTER` statements under one `try`, so the
   first failure (column already exists) skips the index and foreign-key
   statements that follow. This is why production is missing the
   `games.user_id`, `items.user_id` and `game_images.user_id` foreign keys, and
   why running `migrate.php` today would record migrations as applied without
   achieving their intent. Fix the guard granularity before running it.
