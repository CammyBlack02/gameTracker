# `gt` CLI — Design

**Status:** approved in principle 2026-08-03. Phase A in progress.

## Goal

A first-class command-line interface to gameTracker, so the system can be
driven without the web app or the iOS app. Those become viewers over the
data, not the only way to reach it.

Two audiences, both real:

- **Cameron** — bulk edits, inspection, one-off fixes, "what's actually in
  the DB right now" without clicking through a dashboard.
- **Agents (Claude Code)** — the server is headless with no browser and no
  desktop environment, so today an agent cannot exercise any user-facing
  behaviour end to end. Every feature checkpoint bottlenecks on a human
  with a browser. The CLI removes that bottleneck for everything except
  genuinely visual QA (layout, styling, does-it-look-right).

## Architecture: one service layer, two front-ends

The decision (2026-08-03) is a shared service layer that both the CLI and
the v2 HTTP API sit on top of.

```
        bin/gt ─────────┐
                        ├──> src/Services/GamesService ──> PDO
   api/v2/games/* ──────┘
                             (api/v1 endpoints rewired here too,
                              endpoint by endpoint, as we go)
```

Services are plain PHP classes with explicit dependencies and no knowledge
of HTTP:

```php
GamesService::list(PDO $pdo, int $userId, array $opts): array
GamesService::get(PDO $pdo, int $userId, int $gameId): array
```

- **No** `$_GET` / `$_POST` / `$_SESSION` / `php://input` reads.
- **No** `header()` / `echo` / `exit()`.
- Identity is an explicit `int $userId` parameter. This is deliberate: it
  keeps the "every query is user-scoped" invariant from `CLAUDE.md` visible
  in the type signature instead of hidden in ambient session state.
- Failures throw `DomainException` subclasses carrying a stable error code
  (`not_found`, `bad_request`, …) that maps onto both v2's error envelope
  and a CLI exit code.

### Why not a thin HTTP-client CLI

Considered and rejected. v2 currently exposes only auth, cover-image,
external-image, images, metacritic, pricecharting, and sync — there is no
games / items / completions / settings / stats / admin endpoint. iOS does
its CRUD through `sync/push`. So an HTTP-only CLI would be limited to a
fraction of the system until those endpoints exist.

They have to be built anyway for Phase 5 v1-retirement. Building them as
thin wrappers over the same services the CLI uses means this work
*produces* them rather than waiting on them.

### Why PHP

Asked and answered 2026-08-03. In-process service calls require the CLI to
share the API's runtime; a CLI in another language could only speak HTTP,
which is the rejected option above. Beyond that: `php8.3` CLI is the same
interpreter serving production, so behaviour is identical rather than
merely equivalent; `database/migrate.php` is existing precedent; and no new
runtime lands on the box.

Worth recording for the future: extracting services is exactly the
prerequisite for ever porting off PHP, since it decouples domain logic from
the HTTP layer. This work is not wasted under a later rewrite.

## The parity property (the reason this helps testing)

Both front-ends run identical logic, so the same command can be executed
two ways:

```bash
gt games list --json           # in-process, direct PDO
gt --http games list --json    # over localhost against api/v2, Bearer auth
```

Identical output is the expected result. A diff means the HTTP wrapper is
wrong — wrong scoping, wrong envelope, wrong serialisation. That gives a
regression test for the entire v2 surface that needs no browser and no
human, and it is the closest available substitute for the eye-on-glass
checkpoints that currently gate every frontend PR.

Direct mode alone would not give this. It is fast and total, but it skips
auth, CSRF, and the envelope — so it can report "works" while the web app
is broken. `--http` mode is what keeps the CLI honest about what real
clients see.

## Safety, given production has no staging

`/var/www/gameTracker` is live and there is no staging copy, so a CLI that
can mutate data is a genuine footgun — more so when an agent is holding it.

- Read-only by default. Phase A and B ship **no** mutating commands.
- Mutations require an explicit, non-defaultable `--yes`.
- No unscoped bulk operations. `delete` requires specific ids.
- Every mutation appends to a CLI audit log (who, when, argv, affected
  ids) so an agent's actions are reconstructable after the fact.
- `gt` refuses to run if it cannot determine which database it is pointed
  at, rather than guessing.

## Bootstrap problem

`includes/config.php` is not CLI-safe as written:

- **`config.php:69`** — unconditional `session_start()`, no SAPI guard.
  Harmless-ish (`migrate.php` already survives it) but it writes junk
  session files on every invocation.
- **`config.php:115`** — `initializeDatabase($pdo)` fires ~20
  `CREATE TABLE` / `ALTER TABLE` statements **on every include**. For a CLI
  invoked constantly, that means DDL against production on every read-only
  command. `CLAUDE.md` already flags this for removal.

Phase A gates both behind a `GT_CLI` constant defined before the require.

**Operational wrinkle:** `config.php` is gitignored (`.gitignore:62`), so
the guard must land in the committed `config.php.example` *and* be applied
by hand to the live `includes/config.php`. That manual step is called out
explicitly in the plan; it is the same class of drift that silently broke
the nightly backups when a password rotated in one file and not the other.

## Autoloading

No composer, no `vendor/`, no autoloader exists today. `src/` gets a
~15-line PSR-4 autoloader with zero third-party dependencies, keeping
`scripts/deploy.sh` and the laptop deploy story unchanged. Composer is an
easy later upgrade if a real dependency appears; adopting it now would add
a `composer install` step to deploy for no present benefit.

## Phases

Each lands independently and leaves the tree working.

| Phase | Scope | Prod risk |
|---|---|---|
| **A** | `bin/gt` skeleton, autoloader, CLI-safe bootstrap, output formatting, `gt whoami`, `gt db:info`. No existing code paths change. | None — new files plus the `config.php` guard |
| **B** | `GamesService` read paths. `gt games list`, `gt games get <id>`, `gt platforms`. `api/games.php` read actions rewired to the service. | Touches live read paths |
| **C** | Mutating commands + safety rails + audit log | Touches live write paths |
| **D** | `--http` parity mode, plus `api/v2/games/*` as thin service wrappers. Directly advances Phase 5 v1-retirement. | New v2 surface |
| **E** | Repeat B/C for items, completions, settings, stats, admin | Incremental |

## Testing

`tests/cli/test_*.sh`, reusing the existing `tests/v2/lib.sh` helpers, wired
into `run-all.sh`.

Known constraint: the v2/CLI suites cannot run on this box today.
`setup-test-db.sh` must `DROP`/`CREATE DATABASE gameTracker_test`, and the
available MySQL user (`CammyBlack02`) holds `ALL PRIVILEGES ON gameTracker.*`
only — no global rights — so CI is currently the only authoritative run.
A one-time `GRANT ALL ON gameTracker_test.* TO 'CammyBlack02'@'localhost'`
would unblock local runs permanently and materially speed this project up.
Worth doing before Phase B.

## Out of scope

- Rewriting the web frontend or the PHP page templates.
- Replacing `sync/push` / `sync/changes` — the iOS contract stays as is.
- Removing `initializeDatabase()` outright. Phase A only gates it for CLI;
  killing it for web requests is separate Phase 2 backend work.
- Genuinely visual QA. Layout and styling still need a human with a browser.
