# v2 Domain Endpoints as Service Wrappers (sub-project #6a) — Design

**Status:** approved 2026-08-04.

## The actual problem

Not "there are two APIs". **The same domain rules exist three times, and no
copy shares code with another.** No file under `api/` references
`src/Services/`.

| Implementation | Serves | Lines (games) |
|---|---|---|
| `src/Services/GamesService.php` | the CLI | 195 |
| `api/games.php` | the web frontend | 905 |
| `api/v2/sync/push.php` | iOS writes | 197 |

That is why a fix has to be applied three times, and why `gt games delete` can
correctly restore child rows and clear tombstones while the web delete still
gets it wrong. It is the root cause behind several of the bugs found on
2026-08-04, not a stylistic complaint.

## What this is not

**This does not retire v1.** iOS is already 100% v2 and touches no v1 endpoint;
the web frontend is 100% v1 across 13 endpoints. v2 deliberately has no
`games list` or `games update` — it is built around delta sync (`sync/changes`
and `sync/push`) for an offline-capable native client, which is not a
substitute for a web form posting `?action=update`.

Porting the web app to v2 would mean building CRUD endpoints v2 does not have,
then rewriting every JS call site, for no user-visible benefit.

The thing worth killing is the **duplication**, not the URLs. Once `api/*.php`
are thin transport wrappers over `src/Services/`, v1 still exists as a URL
namespace but holds no logic — one implementation, two front doors, one
session-authed and one token-authed. Retiring the v1 URLs after that is
cosmetic and out of scope, probably permanently.

Sequencing:

| Phase | What |
|---|---|
| **6a — this spec** | v2 domain endpoints as service wrappers + CLI-vs-HTTP parity tests |
| 6b | v1 endpoints become wrappers over the same services |
| 6c | retire v1 URLs — skipped as cosmetic |

6a first because the parity tests are what make 6b safe: they are how a
rewritten endpoint is proven to behave identically.

## Scope

Read endpoints only. Writes stay in 6b, where the parity harness already
exists to check them.

```
GET /api/v2/games/list.php?platform=PS2&unplayed&page=2
GET /api/v2/games/get.php?id=412
GET /api/v2/items/list.php?category=Controller
GET /api/v2/items/get.php?id=17
```

Each is a thin wrapper: authenticate, compile filters, call the service,
emit `v2_ok()`. No SQL, no business logic.

## The one refactor this needs

`FilterCompiler::compile(FilterDefinition, Context)` takes the CLI's `Context`,
but uses only three methods on it: `option()`, `flag()`, `intOption()`. That
narrow surface is extracted:

```php
interface OptionSource {
    public function option(string $name, ?string $default = null): ?string;
    public function flag(string $name): bool;
    public function intOption(string $name, int $default): int;
}
```

`Context` implements it unchanged. `ArrayOptions` implements it over a plain
array, which is what an HTTP endpoint hands in from `$_GET`.

This is the whole point of the sub-project in miniature: the filter vocabulary
stops being a CLI feature and becomes a domain feature that two transports
share. `GamesService::list(PDO, int, FilterSet)` already has no CLI coupling
and needs no change.

**A flag in a query string has no value** — `?unplayed` and `?unplayed=1` must
both read as true, matching the CLI's `--unplayed`. `ArrayOptions::flag()`
therefore tests key presence, exactly as `Context::flag()` does.

## `--http`

`gt --http games list …` sends the command to the v2 endpoints instead of
calling services in-process. Today `--http` is a stub that exits 2.

**Auth:** a bearer token from `GT_TOKEN`. No new command, no password handling
in the CLI, no token file to secure — consistent with `GT_USER`,
`GT_JOURNAL_DIR` and `GT_TRASH_DIR`. Absent or rejected token is a domain
error naming `GT_TOKEN`.

**Base URL:** `GT_BASE_URL`, defaulting to `https://localhost`.

`--http` is read-only in 6a. A write command with `--http` is a usage error
saying so, rather than silently falling back to in-process — a flag that
sometimes means what it says is worse than one that refuses.

## Parity testing

The point of the sub-project, and the thing that makes 6b safe.

`tests/cli/test_http_parity.sh` runs the same command both ways and asserts the
JSON matches:

```bash
gt games list --platform=PS2 --json            # in-process
gt --http games list --platform=PS2 --json     # over HTTP
```

Covered: an unfiltered list, an exact filter, a boolean filter, `--missing`,
paging, sorting, and a single `get`. Plus the negative cases that prove the
comparison is not vacuous — a filter that matches nothing must return an empty
list *both* ways, and a nonexistent id must fail the same way.

**Parity is asserted on the whole payload, not a row count.** Two
implementations agreeing on "3 rows" while disagreeing on which three is
exactly the failure this exists to catch.

## Architecture

```
bin/gt --http → Cli/Http/HttpClient          bearer token, base URL
                     ↓
              api/v2/games/list.php          thin wrapper
                     ↓
              Query/FilterCompiler(OptionSource)
                     ↓
              Services/GamesService          unchanged, already CLI-free
```

- **`src/Query/OptionSource`** — the three-method interface.
- **`src/Query/ArrayOptions`** — implements it over `$_GET`.
- **`src/Cli/Http/HttpClient`** — GET with a bearer token. Reuses
  `Import\HttpTransport` where it fits, so CI can stub it.

## Errors

Exit codes unchanged. **Usage (2):** `--http` with a write command. **Domain
(1):** `GT_TOKEN` absent or rejected; the endpoint unreachable.

The v2 endpoints use `v2_ok()` / `v2_error()` and the standard include order,
per CLAUDE.md. Never `require` a v1 file.

## Out of scope

- Write endpoints (6b).
- Retiring v1 (6c, likely never).
- Changing `sync/changes` or `sync/push` — iOS depends on both and they are a
  different interaction model.
