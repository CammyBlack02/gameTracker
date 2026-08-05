# `gt games platforms` — Counts and Filters — Design

**Status:** approved 2026-08-05.

**Depends on:** the CLI read + filter core
(`docs/superpowers/specs/2026-08-03-gt-cli-design.md`), merged and deployed. The
`gt` programme was closed as feature-complete on 2026-08-04; this is a
deliberate reopening, scoped to one command.

## Goal

Answer "how many games do I own per platform, physical only?" from the CLI
without dropping to `gt sql`.

Today `gt games platforms` lists distinct platform names and nothing else, so
the question can only be answered with a hand-written aggregate:

```sql
SELECT platform, COUNT(*) FROM games
WHERE user_id = 1 AND is_physical = 1
GROUP BY platform ORDER BY 2 DESC
```

That works, but a read-only SQL escape hatch is the wrong home for a question
this ordinary. `games list` already understands `--physical`; the summary view
should too.

## Governing constraint

`GamesService::platforms()` has a second consumer:
`api/games.php?action=platforms` feeds the add/edit platform datalist in
`js/games.js`, which expects a flat array of strings.
`tests/v2/test_v1_read_contract.sh` asserts that shape.

**The web contract does not move.** Only the CLI's own JSON shape changes.

## Command surface

```
gt games platforms [selectors] [--sort=<key>]
```

`allowedOptions()` returns `GamesFilters::definition()->selectorNames()` plus
`sort`. `selectorNames()` is already defined as the flags that narrow which rows
match, excluding presentation flags — precisely the right vocabulary for a
summary. That yields:

```
--physical   --digital     --played      --unplayed
--platform   --genre       --series      --condition
--digital-store            --title-like
--rating-min --rating-max  --added-since --added-before
--missing=<column>
```

Because `Application` validates every long option against `allowedOptions()`,
the paging flags become usage errors (exit 2) with no explicit rejection code:
`--limit`, `--page` and `--per-page` are simply not in the list. Paging a
22-row aggregate is meaningless, and silently ignoring the flag would be worse
than refusing it.

`--sort` accepts `platform`, `-platform`, `games`, `-games`; the default is
`platform`, preserving today's alphabetical order. A `-` prefix means
descending, matching the filter core's convention. Any other value is a
`UsageException` naming the four. Ties always break on `platform ASC`, so output
is deterministic regardless of sort key.

The command's original purpose — finding the exact string to pass to
`--platform`, because the stored values are not what you would guess
("PlayStation 2", not "PS2") — is why alphabetical stays the default.
`--sort=-games` is for reading the collection, not for filtering it.

## Output

Table:

```
platform  games
--------  -----
PS2       2
PS3       1
Xbox 360  2
```

Both columns are left-aligned. `Output::rows()` formats every column with
`%-{width}s` and right-aligning numerics would mean special-casing the shared
table renderer for one command; consistency with every other `gt` table wins.

JSON:

```json
{"platforms":[{"platform":"PS2","games":2},{"platform":"PS3","games":1}]}
```

`games` is cast to `int` — PDO returns aggregate values as strings, and a
quoted count in JSON would force every consumer to coerce it.

**This breaks the CLI's JSON shape.** `platforms` was an array of strings;
`jq -r '.platforms[]'` becomes `jq -r '.platforms[].platform'`. Accepted
deliberately: the alternative shapes (a parallel `counts` map, or shipping both
a string array and an object array) either force consumers to join two
structures or duplicate the same data in every response, where the two copies
can drift. `Application::VERSION` goes to `0.9.0` to mark it.

## Implementation

Four files.

**`src/Query/FilterCompiler.php`** — extract the condition-building loops into

```php
public static function compileWhere(FilterDefinition $def, OptionSource $ctx): array  // [whereSql, params]
```

`compile()` calls it, then appends sort and paging. No behaviour change to
`compile()`.

This split exists because a summary must compile a WHERE clause *without*
compiling a sort. `compile()` validates `--sort` against the resource's
`sortColumns`, and `games` is a `COUNT(*)` alias rather than a column on
`games`, so routing this command through `compile()` would reject
`--sort=games`.

**`src/Query/FilterSet.php`** — add

```php
public static function forSummary(string $whereSql, array $params, string $orderSql): self
```

with paging fixed at `(1, 1, 0)` and commented as not applicable to an
aggregate. `forId()` sets the precedent: it already documents its ordering as
irrelevant-but-necessarily-valid SQL.

**`src/Services/GamesService.php`** — add

```php
public static function platformCounts(PDO $pdo, int $userId, FilterSet $filters): array
```

returning `[['platform' => string, 'games' => int], …]`. The query keeps the
existing scoping and exclusions and splices the filter in:

```sql
SELECT `platform`, COUNT(*) AS `games` FROM games
WHERE `user_id` = ? AND `platform` IS NOT NULL AND `platform` != ''
  [AND <whereSql>]
GROUP BY `platform`
ORDER BY <orderSql>
```

`orderSql` arrives complete, tiebreaker included — the command builds
``` `games` DESC, `platform` ASC ``` for `--sort=-games` and ``` `platform` ASC ```
for the default. The service splices it verbatim and appends nothing, keeping
`FilterSet`'s existing contract that `orderSql` is a ready-to-splice `ORDER BY`
body.

Then reimplement `platforms()` on top of it:

```php
return array_column(
    self::platformCounts($pdo, $userId, FilterSet::forSummary('', [], '`platform` ASC')),
    'platform'
);
```

One query is now the single source of truth for how platforms are scoped to a
user and how blank platform values are excluded. `platforms()` keeps returning a
string array, so `api/games.php` and the datalist are untouched.

**`src/Cli/Commands/Games/PlatformsCommand.php`** — the wiring: allowed
options, compile the filter, map `--sort` to an `ORDER BY` body, call
`platformCounts()`, emit via `rows()` or `record()`. `description()` becomes
"List platforms with a game count, with filters".

## Behaviour decisions

**A platform with no matching rows is absent, not `games: 0`.** `GROUP BY`
does this naturally, and it is the right answer to "what is in this filtered
set" — a `0` row would imply the platform is present-but-empty. Against the
live collection `--physical` drops nothing; against the test fixtures it drops
PS3 entirely, which is what makes this testable.

**`--digital` matches `is_physical = 0 OR is_physical IS NULL`.** Inherited
unchanged from `FilterCompiler`'s nullable-boolean rule, which exists so rows
whose value was never set do not vanish from both `--physical` and `--digital`.
The live collection happens to have zero `NULL`s, which is why its physical and
digital counts sum exactly to the total; that identity is a property of the
data, not a guarantee of the query.

**`--physical --digital` together returns no rows**, not an error. The
conditions are ANDed and contradict, exactly as they do on `games list`. Table
mode prints `(no rows)`; JSON mode emits `{"platforms":[]}`.

## Testing

`tests/cli/test_gt_games.sh`, replacing the existing `games platforms` block.
The fixtures in `tests/cli/fixtures.sh` already carry the needed mix and do not
change:

| platform | rows | `is_physical` |
| --- | --- | --- |
| Xbox 360 | Halo 3, Halo Reach | `1`, `NULL` |
| PS2 | Silent Hill, Okami | `1`, `0` |
| PS3 | Journey | `0` |
| PC | (other user's row) | — |

Assertions:

- unfiltered counts are `PS2=2, PS3=1, Xbox 360=2`
- `--physical` gives `PS2=1, Xbox 360=1` and **omits PS3**
- `--digital` gives `PS2=1, PS3=1, Xbox 360=1` — proves the `NULL` row counts
  as digital
- `--physical --unplayed` composes selectors
- `--sort=-games` orders by count descending; the default is alphabetical
- `--sort=bogus` exits 2
- `--limit=1` exits 2
- PC never appears (existing cross-user scoping check, retained)

`tests/v2/test_v1_read_contract.sh` is **not modified**. Its assertion that
`.platforms` is an array of strings is the regression guard proving the web
datalist still works.

## Out of scope

- An `api/v2/games/platforms.php` endpoint. There is none today, so `--http`
  gains nothing to drive.
- Right-aligned numeric columns in `Output::rows()`.
- A total row. `gt whoami` already reports collection totals.

## Documentation

No architecture-diagram change. `docs/architecture/3-refactor.md` already maps
`api/games.php` reads — list, get, platforms — onto `src/Services`, and this
work adds a method inside that boundary without moving the path onto or off it.
