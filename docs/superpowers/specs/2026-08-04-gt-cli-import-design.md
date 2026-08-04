# `gt` CLI — Import (sub-project #4a) — Design

**Status:** approved 2026-08-04.

**Depends on:** sub-project #2 (mutations and safety rails), merged as #83 and
deployed at `16bb9d7`. Programme overview:
`docs/superpowers/specs/2026-08-03-gt-cli-design.md`.

## Goal

Let the CLI pull games and items in from external sources, so importing no
longer requires a browser. Today ~1,375 lines of import logic live only in web
endpoints, which an agent on this headless box cannot invoke — the exact gap the
CLI exists to close.

## Scope decision

Sub-project #4 as originally roadmapped bundled **import** with **image
reconciliation**. These are independent subsystems: import brings new rows in
from outside; image repair fixes existing rows and disk hygiene. They share
nothing but the write layer.

Split by decision on 2026-08-04. This spec covers import only. Image
reconciliation gets its own spec, and its constraints remain recorded in
`docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md`.

## Governing constraint

Unchanged from #2: **temporary website breakage is acceptable; altering or
losing the games data is not.** Import is the highest-volume write in the
system — a single run can insert hundreds of rows — so the design leans on #2's
machinery rather than inventing new safety.

## Decisions

| Question | Decision | Why |
|---|---|---|
| Sources | Steam API, and a generic column-mapped CSV with a GameEye profile | One CSV engine handles GameEye as a case, so other exports need no new code |
| On match | **Skip** | Keeps import's job narrow: get new rows in. Improving existing rows is #3's job via `gt games set` |
| Matching | Normalised title + platform | No schema change; materially fewer duplicates than exact match |
| Cover art | Download to `uploads/covers` | The only covers that survived the 2025-12-05 server rebuild were ones with a regeneration source; hotlinks rot (3 of 51 already dead) |
| Web endpoints | Left untouched | Unifying CLI and HTTP on one service layer is #6's job, and it comes with parity tests |

### Why not match on a Steam appid

Matching on Steam's stable id would be immune to renames, and is the correct
long-term answer. It needs a new column on `games`, which needs an honest
migration path — and production has no `schema_migrations` table and still runs
per-request DDL (see `gametracker-prod-schema-drift`). Deferred until that is
fixed rather than pulling a schema change into this sub-project.

## Commands

```
gt import steam [--yes] [--user=<ref>]
gt import csv <file> [--profile=gameeye] [--map=<col>:<header>,…] [--yes]
gt import profiles
```

**Grammar note.** Every other command is noun-verb (`gt games list`); `import`
is a verb. The alternative, `gt games import --source=steam`, does not work: a
GameEye CSV writes to **both** `games` and `items`, so it is not a games-scoped
operation. `import` becomes the resource, and dispatch stays two-token.

`gt import profiles` lists the built-in CSV profiles and their column mappings.
Read-only, needs no confirmation.

## Architecture

```
bin/gt → Cli/Commands/Import/SteamCommand
                            /CsvCommand
                            /ProfilesCommand
    ├─→ Import/SteamSource | CsvSource     fetch or parse → ImportRow stream
    ├─→ Import/CsvProfile                  header → column mapping (gameeye preset)
    ├─→ Import/TitleKey                    normalisation used for matching
    ├─→ Services/Write/Importer            match, insert, journal (transactional)
    └─→ Services/Write/CoverFetcher        download cover → uploads/covers
```

New units, each with one responsibility:

- **`src/Import/ImportRow`** — one candidate row: target table (`games` or
  `items`), a column→value map, and an optional cover URL. Knows nothing about
  SQL or the CLI.
- **`src/Import/Source`** — interface, `rows(): iterable<ImportRow>`. The only
  thing `Importer` knows about a source.
- **`src/Import/SteamSource`** — Steam Web API. **Takes an injectable HTTP
  transport**; this is load-bearing, not decoration, because CI must never call
  the live Steam API. Tests inject a recorded JSON fixture.
- **`src/Import/CsvSource`** — reads a CSV, maps headers to columns via a
  `CsvProfile`, emits `ImportRow`s.
- **`src/Import/CsvProfile`** — a named header→column mapping plus a routing
  rule deciding which table a row targets. Ships with the `gameeye` profile.
- **`src/Import/TitleKey`** — `normalise(string): string`. Pure function, no
  dependencies, trivially testable.
- **`src/Services/Write/Importer`** — the only unit that writes. Matches,
  inserts, journals, all in one transaction.
- **`src/Services/Write/CoverFetcher`** — downloads a cover into
  `uploads/covers`, reusing `includes/external-image-service.php`.

Write SQL stays under `src/Services/Write/`, so
`tests/cli/test_readonly_guard.sh` keeps its teeth unchanged.

## Matching

```
TitleKey::normalise():
  strip ™ ® ©
  collapse internal whitespace to one space
  trim
  casefold (mb_strtolower, UTF-8)
```

A candidate is a duplicate when a row exists for the same `user_id` with the
same normalised title **and** the same platform. Platform equality keeps the
false-skip risk low: two genuinely different games sharing a normalised title on
the same platform is vanishingly rare, while `Half-Life™` vs `Half-Life` is not.

Normalisation is applied to both sides at comparison time. **No normalised
column is stored**, so no schema change and no risk of a stale cache; the cost
is that matching cannot use an index. That is acceptable at this scale — the
largest library here is 1,261 rows, and the matcher loads existing
`(normalised_title, platform)` pairs for the user once per run rather than
issuing a query per candidate.

## The GameEye profile

Derived from `api/import-gameeye.php` (verified 2026-08-04):

| CSV `Category` | Target |
|---|---|
| `Wishlist` | **skipped** — not owned |
| `Systems` | `items`, with `category = 'Console'` |
| `Controllers`, `Game Accessories`, `Toys To Life` | `items`, with `category = 'Accessory'` |
| `Games` | `games` |
| anything else | skipped, and counted in the summary |

Columns read: `Title`, `Platform`, `Category`, `ItemCondition`, `Notes`,
`PricePaid`, `PriceCIB`, `YourPrice`, `ReleaseType`, `CreatedAt`, `Ownership`.

An unrecognised `Category` is skipped rather than guessed at, and the count is
reported — silently dropping rows is how an import looks successful while losing
data.

## Steam

Credentials come from the existing per-user `settings` table, keys
`steam_api_key` and `steam_user_id`. Missing credentials are a domain error
naming both keys, not a crash.

Two endpoints, as today:
- `IPlayerService/GetOwnedGames` for the library
- `store.steampowered.com/api/appdetails` for per-game detail

Imported rows get `platform = 'PC'` and `digital_store = 'Steam'`, matching the
existing convention (187 of 267 PC games already carry it).

## Journal and undo

A CSV import writes two tables, but `JournalEntry` carries a single `resource`
and `UndoCommand` dispatches on it. Rather than splitting one import across two
entries — which would make `gt undo` revert half a job — import registers its own
reverter:

```php
private const REVERTERS = [
    'games'  => GamesWriter::class,
    'items'  => ItemsWriter::class,
    'import' => ImportReverter::class,   // new
];
```

Each journalled row records the table it went into:

```json
{
  "id": "2026-08-04T13-02-11-004821Z-import",
  "resource": "import",
  "operation": "import",
  "committed": true,
  "rows": [
    { "table": "games", "id": 1300, "updated_at": "2026-08-04 13:02:11", "before": {} }
  ]
}
```

Because import only ever INSERTs, reverting is deleting those rows, using the
same "has this changed since my write?" guard as `revertCreate`: a row whose
`updated_at` has moved is skipped unless `--force`. One entry per import, so
`gt undo` reverts the whole batch — something the web importers have never
offered.

Deleting an imported row fires the tombstone trigger, which is correct: if iOS
has already synced it, it needs to hear the row is gone.

## Safety rails

Inherited from #2 rather than reinvented:

1. **An import always previews and requires `--yes`.** It is bulk by nature, so
   the blast-radius rule applies unconditionally. The unconfirmed run reports
   what would be inserted, matched and skipped, and writes nothing.
2. **One transaction.** All rows insert or none do.
3. **Zero new rows writes no journal entry**, consistent with `set` and
   `delete`.
4. **Ownership is enforced in the writer**, with a bound `user_id`. `--admin`
   grants reads only.
5. **A failed cover download is non-fatal.** The row imports with a NULL cover
   and the failure is counted in the summary. A CDN blip must not abort a
   200-game import.
6. **Undo never unlinks downloaded covers**, per #2's rule. They become orphans
   for the image sub-project to prune; a surviving row with a broken cover is
   worse than a little wasted disk.

## Dead code removed

`import-gameeye.php` in the repository root (498 lines) is deleted. Evidence,
gathered 2026-08-04:

- Nothing references it. `js/settings.js:364` calls `api/import-gameeye.php`.
- It has diverged from the `api/` copy by 906 diff lines, so a fix to one has
  not been reaching the other — the same failure mode as the untracked
  `config.php` twin in the schema-drift note.
- It sits in the nginx document root and takes `user_id` from `argv` in CLI
  mode.

Access logs (23 May – 4 Aug 2026) show zero hits on **both** importers, so they
establish only that no GameEye import has run in that window; they do not
distinguish between the two files. The case rests on the code references.

`api/import-gameeye.php` and `api/steam-import.php` are **left alone** — they
work, and replacing them belongs to #6 where parity tests make it safe.

## Errors

Exit codes unchanged: `0` ok, `1` domain error, `2` usage, `3`
bootstrap/database.

**Usage (2):** unknown `--profile`; malformed `--map`; CSV file missing or
unreadable; a mapping naming a column that is not writable on the target table;
both `--profile` and `--map`.

**Domain (1):** Steam credentials absent from settings; Steam API unreachable or
returning an error payload; a CSV whose headers do not satisfy the chosen
profile.

A source failure writes nothing — sources are fully drained and validated before
`Importer` opens its transaction.

## Testing

- **`tests/cli/test_gt_import_csv.sh`** — profile mapping routes games and items
  correctly; `Wishlist` and unknown categories are skipped and counted; dry run
  writes nothing; `--yes` applies; a second run of the same file inserts nothing
  (matching works); `--map` overrides; a bad mapping exits 2; the whole import is
  one journal entry and `gt undo` removes every row across both tables.
- **`tests/cli/test_gt_import_steam.sh`** — drives `SteamSource` against a
  recorded JSON fixture through the injectable transport. **No live network in
  CI.** Covers: missing credentials exit 1; a library with a game already owned
  skips it; `platform` and `digital_store` are set; a cover fetch failure leaves
  the row imported with a NULL cover and is reported.
- **`tests/cli/test_title_key.sh`** — normalisation: trademark symbols, doubled
  whitespace, case, and that two genuinely different titles do not collide.
- **`tests/cli/test_readonly_guard.sh`** — unchanged, but must still pass:
  `Importer` and `CoverFetcher` live under `src/Services/Write/`.

Fixtures own the dedicated `gtfixture` user and clean by `user_id`, per the
isolation lesson from #1.

## Out of scope

- **Image reconciliation** — orphan prune, `--broken-cover`, thumbnail rules.
  Its own spec.
- **Migrating the web endpoints onto this service** — #6, with parity tests.
- **A Steam `appid` column** — blocked on the migration ledger.
- **Updating existing rows** — skip-on-match; enrichment is #3.
- **Bulk import of arbitrary formats** beyond CSV.
