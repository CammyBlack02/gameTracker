# `gt doctor` — Design

**Status:** approved 2026-08-04.

**Part of:** sub-project #5 (ops / accounts).

## Goal

Turn "I wonder if X is still true" into one command.

## Why

On 2026-08-04 a single session found four problems that had been sitting
silently, each discovered by ad-hoc measurement rather than by anything
reporting it:

| Found | Had been true for |
|---|---|
| The image audit figures were artefacts — 42 fabricated broken covers | 1 day |
| 113 MB of base64 in `games`, and a write path still refilling it | months |
| Five missing `user_id` foreign keys, not the three on record | months |
| Three fixes that never reached the gitignored `config.php` | months |

None would have surfaced on its own. The most valuable pattern of that session
was *measure before building* — the "43 broken covers" job turned out not to
exist, while the real problem was two orders of magnitude larger and invisible.

`doctor` makes that measurement cheap and repeatable. Most of its checks already
existed that day as one-off commands typed by hand.

## Decisions

| Question | Decision | Why |
|---|---|---|
| Exit code | **non-zero if any check fails** | makes it usable unattended — a cron line or CI step that actually alerts, rather than a report nobody runs |
| Fixes | **report only** | every fix already has a home (`migrate.php`, `gt images prune`, the backup script). A doctor that also mutates is two tools in one coat |
| Config check | **assert known-good properties, not a diff** | `config.php` legitimately differs from its template by credentials; a diff would false-positive forever |

## Command

```
gt doctor [--json]
```

Read-only. No `--yes`, no confirmation, nothing to journal.

**Exit codes:** `0` all checks pass · `1` one or more failed · `3`
bootstrap/database. Deliberately reusing the existing vocabulary — a failed
check is a domain problem, which is what `1` already means.

## Checks

Each returns a status, a one-line summary, and where relevant the command that
fixes it.

### 1. Schema

- `schema_migrations` exists and every migration on disk is recorded
- No `user_id` column lacking its foreign key to `users`
- `includes/config.php` no longer defines `initializeDatabase`

Sourced from `gt db info`, which already computes most of this.

### 2. Config integrity

This is the check the 2026-08-04 session most wanted and did not have.
`includes/config.php` is gitignored, so three separate fixes landed in
`config.php.example` and never reached the live file. Rather than diff, assert
the properties that matter:

- does **not** define `initializeDatabase` (no per-request DDL)
- **does** guard `session_start()` with `!defined('GT_CLI')` — without it every
  `gt` invocation writes a junk session file
- **does** throw rather than `die()` on a CLI connection failure — `die()` exits
  0, so `gt` would report success on a broken database

The same failure mode took out the nightly backups for the app's entire life
(stale credentials in `~/.my.cnf` while the app's own config had rotated), so
this check generalises beyond the three specific properties.

### 3. Backups

Never trust the log line. `~/backup-gameTracker.sh` reported "Backup completed"
while writing 0-byte dumps for its entire existence.

- a dump exists and is newer than 48 hours
- it is non-trivially sized
- it contains `CREATE TABLE`
- it contains mysqldump's `Dump completed` marker

The marker is the one that matters: a truncated dump has the first two and not
the last.

### 4. Images

- rows whose filename-backed image is absent from disk
- rows still holding a `data:` URI (should be zero — the write path rejects them
  now, so any recurrence means a new path was introduced)
- orphan files on disk

Reuses `ImageIndex` and `Reconciler` from #4b rather than recomputing. Orphans
are reported as **informational, not a failure** — 99 unreferenced files are
untidy, not broken, and failing on them would make `doctor` permanently red and
therefore ignored.

## Architecture

```
bin/gt → Cli/Commands/DoctorCommand
    └─→ Diagnostics/Check              one result: name, status, summary, remedy
        ├─→ Diagnostics/SchemaCheck
        ├─→ Diagnostics/ConfigCheck
        ├─→ Diagnostics/BackupCheck
        └─→ Diagnostics/ImageCheck
```

- **`src/Diagnostics/Check`** — the result value object. `PASS`, `FAIL`, `INFO`.
  Only `FAIL` influences the exit code, which is what keeps orphan files from
  making the command permanently red.
- **Each check is independent** and returns a list of `Check`s. A check that
  throws is reported as a failure naming the exception, never allowed to abort
  the run — a doctor that dies on its first problem is useless precisely when
  it is needed.

`src/Diagnostics/` is read-only; the read-only guard already forbids write SQL
outside `src/Services/Write/` and will cover it.

## Out of scope

- Fixing anything.
- Checking the iOS app, nginx, or TLS certificates — those have their own tools
  and failure modes.
- Historical trending. `doctor` answers "is it true now".
