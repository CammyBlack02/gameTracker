# `gt` CLI — Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `gt import steam` and `gt import csv` so games and items can be imported without a browser, as one journalled batch that `gt undo` reverts wholesale.

**Architecture:** Sources (`SteamSource`, `CsvSource`) parse or fetch and emit a normalised `ImportRow` stream, knowing nothing about SQL. `Importer` is the only unit that writes: it matches candidates against existing rows on normalised title + platform, inserts the survivors in one transaction, and journals them under a new `import` resource whose reverter deletes what it inserted. Covers are fetched **after** the transaction commits, and a failure is non-fatal.

**Tech Stack:** PHP 8.1+ (no framework, no Composer), PDO/MySQL, bash test suites under `tests/cli/` run by `tests/v2/run-all.sh`.

**Spec:** `docs/superpowers/specs/2026-08-04-gt-cli-import-design.md`

## Global Constraints

- **Governing constraint:** temporary website breakage is acceptable; altering or losing the games data is not.
- **Exit codes:** `0` ok, `1` domain error, `2` usage, `3` bootstrap/database.
- **Write SQL only under `src/Services/Write/`.** `tests/cli/test_readonly_guard.sh` greps all of `src/` and fails on any hit elsewhere. `src/Import/` must contain **no** `INSERT INTO` / `UPDATE … SET` / `DELETE FROM` — sources parse, they do not write.
- **Backtick every column name.** `condition` is a reserved word on both `games` and `items`.
- **Column names never come from user input.** A `--map` target must be checked against the resource's `WriteDefinition` before it reaches SQL.
- **Ownership enforced in the writer**, with a bound `user_id`.
- **An import always previews and requires `--yes`.** Bulk by nature.
- **One transaction** for the inserts. Zero new rows → no journal entry.
- **Covers are fetched after COMMIT**, never inside the transaction — network I/O must not hold row locks.
- **A failed cover fetch is non-fatal**; the row keeps a NULL cover and the failure is counted.
- **Undo never unlinks downloaded files.**
- **Test isolation:** fixtures own `gtfixture` and clean by `user_id`. All suites share one database.
- **No live network in CI.** `SteamSource` takes an injectable transport; tests inject a recorded fixture.
- **PHP style:** `final class`, promoted `readonly` properties, namespace matching the `src/` path.

## Existing interfaces this builds on

Verified 2026-08-04.

| Symbol | Signature / shape |
|---|---|
| `GamesWrites::definition()` / `ItemsWrites::definition()` | `WriteDefinition(table, writable, booleans, notNull, requiredOnCreate)` |
| `AssignmentSet` | `columnListSql()`, `placeholders()`, `params()`, `missingRequired(WriteDefinition)` |
| `JournalWriter` | `newId(string $op): string`, `write(JournalEntry): string`, `read`, `recent`, `latestRevertable`, `markReverted` |
| `JournalEntry` | `__construct(id, argv, userId, resource, operation, committed, revertedAt, rows)` |
| `ResourceWriter` | `static revert(PDO, JournalEntry, bool $force): array{restored:int, skipped:int}` |
| `UndoCommand::REVERTERS` | `['games' => GamesWriter::class, 'items' => ItemsWriter::class]` |
| `gt_download_and_save_cover()` | `(PDO, int $userId, string $url, ?int $gameId, string $type): array` — `['ok'=>true, 'filename'=>…, 'url'=>…]` or `['ok'=>false, 'code'=>…, 'message'=>…]`. Does SSRF checks, magic-byte validation, thumbnail, and the row UPDATE itself. In `includes/external-image-service.php` — outside `src/`, so the read-only guard does not see it. |
| Steam settings keys | `steam_api_key`, `steam_user_id` in `settings` (`setting_key`, `setting_value`, `user_id`) |

## File Structure

**New**

| File | Responsibility |
|---|---|
| `src/Import/TitleKey.php` | `normalise(string): string` — pure, no dependencies |
| `src/Import/ImportRow.php` | One candidate: target table, column map, optional cover URL |
| `src/Import/Source.php` | Interface: `rows(): iterable<ImportRow>` |
| `src/Import/CsvProfile.php` | Named header→column mapping + table routing rule; `gameeye` preset |
| `src/Import/CsvSource.php` | Reads CSV, applies a profile, emits `ImportRow`s |
| `src/Import/HttpTransport.php` | Interface: `get(string $url): ?string`. Injectable so CI never calls Steam |
| `src/Import/CurlTransport.php` | The real transport |
| `src/Import/SteamSource.php` | Steam Web API → `ImportRow`s |
| `src/Services/Write/Importer.php` | Match, insert, journal — the only writer |
| `src/Services/Write/ImportReverter.php` | Deletes what an import inserted |
| `src/Services/Write/CoverFetcher.php` | Post-commit cover download, non-fatal |
| `src/Cli/Commands/Import/CsvCommand.php` | `gt import csv` |
| `src/Cli/Commands/Import/SteamCommand.php` | `gt import steam` |
| `src/Cli/Commands/Import/ProfilesCommand.php` | `gt import profiles` |
| `tests/cli/test_title_key.sh` | normalisation |
| `tests/cli/test_gt_import_csv.sh` | CSV end to end |
| `tests/cli/test_gt_import_steam.sh` | Steam via fixture |
| `tests/cli/fixtures/steam-owned-games.json` | recorded Steam payload |
| `tests/cli/fixtures/gameeye-sample.csv` | recorded GameEye export |

**Modified**

| File | Change |
|---|---|
| `src/Cli/Commands/UndoCommand.php` | add `'import' => ImportReverter::class` |
| `src/Cli/Application.php` | register 3 commands; bump `VERSION` to `0.4.0` |
| `CLAUDE.md` | document import |
| `import-gameeye.php` | **deleted** (dead root duplicate) |

---

### Task 1: TitleKey

The matcher's whole correctness rests on this one pure function, so it lands first and alone.

**Files:** Create `src/Import/TitleKey.php`, `tests/cli/test_title_key.sh`

**Interfaces:**
- Produces: `TitleKey::normalise(string $title): string`

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_title_key.sh`:

```bash
#!/usr/bin/env bash
# Title normalisation used for import matching.
#
# Matching correctness rests entirely on this function: too aggressive and two
# different games collide and one is silently never imported; too timid and a
# Steam rename creates a duplicate of a game already owned.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

norm() {
  php -r '
    require "'"$PROJECT_ROOT"'/src/autoload.php";
    echo GameTracker\Import\TitleKey::normalise($argv[1]);
  ' -- "$1"
}

blue "Normalisation"

assert_eq "half-life" "$(norm 'Half-Life')"            "lowercases"
assert_eq "half-life" "$(norm 'Half-Life™')"           "strips trademark"
assert_eq "half-life" "$(norm 'Half-Life®')"           "strips registered"
assert_eq "half-life" "$(norm 'Half-Life©')"           "strips copyright"
assert_eq "half-life" "$(norm '  Half-Life  ')"        "trims"
assert_eq "half life 2" "$(norm 'Half  Life   2')"     "collapses inner whitespace"
assert_eq "half-life: source" "$(norm 'Half-Life: Source')" "keeps punctuation that distinguishes titles"

blue "Distinct titles must not collide"

A=$(norm 'Half-Life 2')
B=$(norm 'Half-Life 2: Deathmatch')
if [[ "$A" != "$B" ]]; then
  green "  PASS: sequel and spin-off stay distinct"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: '$A' collided with '$B'"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

C=$(norm 'Portal')
D=$(norm 'Portal 2')
if [[ "$C" != "$D" ]]; then
  green "  PASS: numbered sequels stay distinct"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: '$C' collided with '$D'"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "Unicode is handled without mangling"

assert_eq "pokémon red" "$(norm 'Pokémon Red')" "accented characters survive lowercasing"

summarize
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd ~/worktrees/cli-import && chmod +x tests/cli/test_title_key.sh
bash tests/cli/test_title_key.sh
```
Expected: FAIL — `Class "GameTracker\Import\TitleKey" not found`.

- [ ] **Step 3: Implement**

Create `src/Import/TitleKey.php`:

```php
<?php

namespace GameTracker\Import;

/**
 * The comparison key used to decide whether an imported title is one the user
 * already owns.
 *
 * Deliberately conservative. Stripping too much makes distinct games collide,
 * and a collision means a game is silently never imported — a failure that
 * looks identical to "already owned". So this removes only noise that carries
 * no meaning: trademark furniture, case, and redundant whitespace. Punctuation
 * stays, because "Half-Life 2" and "Half-Life 2: Deathmatch" are different
 * games and the colon is what says so.
 *
 * No normalised column is stored anywhere, so this can change without a
 * migration or a stale cache.
 */
final class TitleKey
{
    public static function normalise(string $title): string
    {
        // Trademark furniture varies between storefronts for the same game.
        $clean = str_replace(["\u{2122}", "\u{00AE}", "\u{00A9}"], '', $title);

        // Any run of whitespace, including tabs and non-breaking spaces from
        // CSV exports, becomes one plain space.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        // mb_strtolower, not strtolower: the latter mangles multibyte
        // characters, so "Pokémon" would not survive intact.
        return mb_strtolower(trim($clean), 'UTF-8');
    }
}
```

- [ ] **Step 4: Run it and confirm it passes**

```bash
bash tests/cli/test_title_key.sh
```
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Import/TitleKey.php tests/cli/test_title_key.sh
git commit -m "feat(cli): TitleKey normalisation for import matching

Conservative on purpose: a collision means a game is silently never imported,
which looks identical to 'already owned'. Strips trademark furniture, case and
redundant whitespace; keeps punctuation, because the colon is what separates
Half-Life 2 from Half-Life 2: Deathmatch."
```

---

### Task 2: ImportRow, Source, CsvProfile and CsvSource

The parse half. No SQL anywhere in it.

**Files:** Create `src/Import/ImportRow.php`, `src/Import/Source.php`, `src/Import/CsvProfile.php`, `src/Import/CsvSource.php`, `tests/cli/fixtures/gameeye-sample.csv`

**Interfaces:**
- Consumes: `TitleKey`
- Produces:
  - `ImportRow::__construct(string $table, array $columns, ?string $coverUrl = null)` with readonly props `table`, `columns`, `coverUrl`
  - `Source::rows(): iterable` (of `ImportRow`), `Source::describe(): string`, `Source::skipped(): int`
  - `CsvProfile::named(string $name): self`, `CsvProfile::names(): list<string>`, `CsvProfile::fromMap(array $map): self`, readonly `$name`, `$columns` (target column => CSV header), `$skippedCategories`
  - `CsvProfile::route(array $record): ?array` — returns `['table'=>string, 'extra'=>array]` or null to skip
  - `CsvSource::__construct(string $path, CsvProfile $profile)`, plus `skipped(): int` and `skippedReasons(): array` after iteration

- [ ] **Step 1: Write the fixture and the failing test**

Create `tests/cli/fixtures/gameeye-sample.csv` — covers every routing branch including two that must be skipped:

```csv
Title,Platform,Category,ItemCondition,Notes,PricePaid,PriceCIB,ReleaseType
IMPORT Halo 3,Xbox 360,Games,Good,a note,10.00,15.00,Standard
IMPORT Portal 2,PC,Games,Mint,,5.50,9.99,Standard
IMPORT Wishlist Game,PS5,Wishlist,,,0,0,Standard
IMPORT Dreamcast,Dreamcast,Systems,Fair,console note,40.00,60.00,Standard
IMPORT Rumble Pak,N64,Game Accessories,Good,,8.00,12.00,Standard
IMPORT Amiibo Link,Switch,Toys To Life,Mint,,12.00,,Standard
IMPORT Mystery Thing,PC,SomethingUnknown,,,0,0,Standard
```

Create `tests/cli/test_gt_import_csv.sh` with only the parse assertions for now (write assertions arrive in Task 4):

```bash
#!/usr/bin/env bash
# `gt import csv` — parsing, routing and, from Task 4, the write path.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
CSV="$PROJECT_ROOT/tests/cli/fixtures/gameeye-sample.csv"

blue "CsvSource routing (gameeye profile)"

ROUTED=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  $src = new GameTracker\Import\CsvSource(
      "'"$CSV"'",
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $counts = ["games" => 0, "items" => 0];
  foreach ($src->rows() as $row) { $counts[$row->table]++; }
  echo $counts["games"], " ", $counts["items"], " ", $src->skipped();
')

assert_eq "2 3 2" "$ROUTED" "routes 2 games, 3 items, skips 2 (Wishlist + unknown category)"

ITEM_CATS=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  $src = new GameTracker\Import\CsvSource(
      "'"$CSV"'",
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $cats = [];
  foreach ($src->rows() as $row) {
      if ($row->table === "items") { $cats[] = $row->columns["category"]; }
  }
  sort($cats); echo implode(",", $cats);
')

assert_eq "Accessory,Accessory,Console" "$ITEM_CATS" "Systems becomes Console, accessories become Accessory"

blue "Unknown profile and unreadable file"

UNKNOWN=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  try { GameTracker\Import\CsvProfile::named("nope"); echo "no-throw"; }
  catch (Throwable $e) { echo get_class($e); }
')
assert_contains "UsageException" "$UNKNOWN" "an unknown profile is a usage error"

summarize
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd ~/worktrees/cli-import && chmod +x tests/cli/test_gt_import_csv.sh
bash tests/cli/test_gt_import_csv.sh
```
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement ImportRow and Source**

Create `src/Import/ImportRow.php`:

```php
<?php

namespace GameTracker\Import;

/**
 * One candidate row from a source, before anything has decided whether it is
 * new. Transport-free and SQL-free: a source produces these, and Importer is
 * the only thing that turns them into rows.
 */
final class ImportRow
{
    public function __construct(
        /** 'games' or 'items' */
        public readonly string $table,
        /** column => value, already using database column names */
        public readonly array $columns,
        /** Remote cover URL to fetch after the import commits, if any. */
        public readonly ?string $coverUrl = null,
    ) {
    }

    public function title(): string
    {
        return (string)($this->columns['title'] ?? '');
    }

    public function platform(): ?string
    {
        $platform = $this->columns['platform'] ?? null;

        return $platform === null ? null : (string)$platform;
    }
}
```

Create `src/Import/Source.php`:

```php
<?php

namespace GameTracker\Import;

/**
 * Anything that can produce candidate rows.
 *
 * The only thing Importer knows about where rows came from. A source parses or
 * fetches; it never writes, which is why src/Import/ contains no write SQL and
 * the read-only guard stays meaningful.
 */
interface Source
{
    /**
     * @return iterable<ImportRow>
     */
    public function rows(): iterable;

    /** Human-readable description for the preview, e.g. "gameeye CSV (7 records)". */
    public function describe(): string;

    /**
     * Records the source deliberately did not emit — a Wishlist entry, an
     * unrecognised category, a row with no title.
     *
     * Only meaningful once rows() has been fully drained. On the interface
     * rather than probed for with method_exists, so a new source cannot
     * silently report zero skips by forgetting to implement it.
     */
    public function skipped(): int;
}
```

- [ ] **Step 4: Implement CsvProfile**

Create `src/Import/CsvProfile.php`:

```php
<?php

namespace GameTracker\Import;

use GameTracker\Cli\UsageException;

/**
 * A named mapping from CSV headers to database columns, plus the rule deciding
 * which table a record belongs in.
 *
 * The gameeye preset reproduces api/import-gameeye.php's routing exactly
 * (verified 2026-08-04). An unrecognised Category is skipped and counted rather
 * than guessed at: silently dropping records is how an import reports success
 * while losing data.
 */
final class CsvProfile
{
    private const PRESETS = ['gameeye'];

    private function __construct(
        public readonly string $name,
        /** database column => CSV header */
        public readonly array $columns,
        /** CSV Category values that are deliberately not imported */
        public readonly array $skippedCategories,
        /** Category => ['table' => …, 'extra' => [...]] */
        private readonly array $routes,
        private readonly ?string $routeColumn,
    ) {
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::PRESETS;
    }

    public static function named(string $name): self
    {
        if ($name !== 'gameeye') {
            throw new UsageException(
                "unknown CSV profile '{$name}'. Available: " . implode(', ', self::PRESETS)
                . ' — or describe your own with --map'
            );
        }

        return new self(
            name: 'gameeye',
            columns: [
                'title'               => 'Title',
                'platform'            => 'Platform',
                'condition'           => 'ItemCondition',
                'notes'               => 'Notes',
                'price_paid'          => 'PricePaid',
                'pricecharting_price' => 'PriceCIB',
            ],
            skippedCategories: ['Wishlist'],
            routes: [
                'Games'            => ['table' => 'games', 'extra' => []],
                'Systems'          => ['table' => 'items', 'extra' => ['category' => 'Console']],
                'Controllers'      => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
                'Game Accessories' => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
                'Toys To Life'     => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
            ],
            routeColumn: 'Category',
        );
    }

    /**
     * A user-described mapping, e.g. --map=title:Name,platform:System.
     *
     * Everything lands in `games`: a hand-mapped CSV has no category vocabulary
     * to route on, and guessing would be worse than requiring the caller to
     * split the file.
     */
    public static function fromMap(array $map): self
    {
        if ($map === []) {
            throw new UsageException('--map is empty — expected <column>:<header> pairs');
        }

        return new self(
            name: 'custom',
            columns: $map,
            skippedCategories: [],
            routes: [],
            routeColumn: null,
        );
    }

    /**
     * Decide where a record goes.
     *
     * @return array{table: string, extra: array}|null null means skip
     */
    public function route(array $record): ?array
    {
        if ($this->routeColumn === null) {
            return ['table' => 'games', 'extra' => []];
        }

        $category = trim((string)($record[$this->routeColumn] ?? ''));

        if ($category === '' || in_array($category, $this->skippedCategories, true)) {
            return null;
        }

        return $this->routes[$category] ?? null;
    }
}
```

- [ ] **Step 5: Implement CsvSource**

Create `src/Import/CsvSource.php`:

```php
<?php

namespace GameTracker\Import;

use GameTracker\Cli\UsageException;

/**
 * Reads a CSV and emits ImportRows through a CsvProfile.
 *
 * Headers are matched by name, not position, so a column order change in an
 * export does not silently shift every value one field to the left.
 */
final class CsvSource implements Source
{
    private int $skipped = 0;
    /** @var array<string,int> reason => count */
    private array $skippedReasons = [];
    private int $records = 0;

    public function __construct(
        private readonly string $path,
        private readonly CsvProfile $profile,
    ) {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new UsageException("cannot read CSV file '{$this->path}'");
        }
    }

    public function describe(): string
    {
        return "{$this->profile->name} CSV ({$this->records} records read)";
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** @return array<string,int> */
    public function skippedReasons(): array
    {
        return $this->skippedReasons;
    }

    public function rows(): iterable
    {
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new UsageException("cannot open CSV file '{$this->path}'");
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === [null]) {
                throw new UsageException('CSV has no header row');
            }
            $headers = array_map(static fn($h): string => trim((string)$h), $headers);

            $missing = array_diff(array_values($this->profile->columns), $headers);
            if ($missing !== []) {
                throw new UsageException(
                    'CSV is missing columns the ' . $this->profile->name
                    . ' profile needs: ' . implode(', ', $missing)
                );
            }

            $index = array_flip($headers);
            $this->skipped = 0;
            $this->skippedReasons = [];
            $this->records = 0;

            while (($record = fgetcsv($handle)) !== false) {
                if ($record === [null]) {
                    continue; // blank line
                }
                $this->records++;

                $assoc = [];
                foreach ($index as $header => $position) {
                    $assoc[$header] = $record[$position] ?? null;
                }

                $route = $this->profile->route($assoc);
                if ($route === null) {
                    $this->skipped++;
                    $reason = trim((string)($assoc['Category'] ?? 'unroutable'));
                    $this->skippedReasons[$reason === '' ? 'unroutable' : $reason] =
                        ($this->skippedReasons[$reason === '' ? 'unroutable' : $reason] ?? 0) + 1;
                    continue;
                }

                $columns = $route['extra'];
                foreach ($this->profile->columns as $column => $header) {
                    $value = $assoc[$header] ?? null;
                    $value = $value === null ? null : trim((string)$value);
                    if ($value === '') {
                        continue; // absent, not empty-string
                    }
                    $columns[$column] = $value;
                }

                if (($columns['title'] ?? '') === '') {
                    $this->skipped++;
                    $this->skippedReasons['no title'] = ($this->skippedReasons['no title'] ?? 0) + 1;
                    continue;
                }

                yield new ImportRow($route['table'], $columns);
            }
        } finally {
            fclose($handle);
        }
    }
}
```

- [ ] **Step 6: Run it and confirm it passes**

```bash
bash tests/cli/test_gt_import_csv.sh
```
Expected: all PASS.

**Note on `skipped()` with a generator:** `rows()` is a generator, so the counters are only correct *after* full iteration. Every caller in this plan drains the source completely before reading them. If a later change reads them mid-iteration, that is a bug — the values are meaningless until the generator finishes.

- [ ] **Step 7: Commit**

```bash
git add src/Import/ImportRow.php src/Import/Source.php src/Import/CsvProfile.php \
        src/Import/CsvSource.php tests/cli/fixtures/gameeye-sample.csv tests/cli/test_gt_import_csv.sh
git commit -m "feat(cli): CSV import source with a gameeye profile

Headers are matched by name so a column reorder in an export cannot shift
every value sideways. An unrecognised Category is skipped and counted rather
than guessed at — silently dropping records is how an import reports success
while losing data."
```

---

### Task 3: Importer and ImportReverter

The write half, and the reverter that makes a whole import undoable.

**Files:** Create `src/Services/Write/Importer.php`, `src/Services/Write/ImportReverter.php`; modify `src/Cli/Commands/UndoCommand.php`

**Interfaces:**
- Consumes: `Source`, `ImportRow`, `TitleKey`, `JournalWriter`, `JournalEntry`, `GamesWrites`/`ItemsWrites`, `ResourceWriter`
- Produces:
  - `Importer::plan(PDO, int $userId, Source): array{candidates: list<ImportRow>, matched: int, skipped: int, byTable: array<string,int>}`
  - `Importer::apply(PDO, int $userId, array $candidates, JournalWriter, array $argv): array{journal_id: ?string, inserted: int, ids: list<array{table:string,id:int,coverUrl:?string}>}`
  - `ImportReverter::revert(PDO, JournalEntry, bool $force): array{restored:int, skipped:int}`

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_import_csv.sh`, before `summarize`:

```bash
blue "Importer matching (no writes yet)"

seed_games
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

# Plant a row that one CSV record should match, so the matcher has something
# to find. Trademark furniture and case differ deliberately.
fixture_mysql -e "
  INSERT INTO games (user_id, title, platform)
  VALUES ($FIXTURE_UID, 'import halo 3™', 'Xbox 360');
"

PLAN=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  require "'"$PROJECT_ROOT"'/includes/config.php";
  $src = new GameTracker\Import\CsvSource(
      "'"$CSV"'",
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $r = GameTracker\Services\Write\Importer::plan($pdo, (int)$argv[1], $src);
  echo count($r["candidates"]), " ", $r["matched"], " ", $r["skipped"];
' -- "$FIXTURE_UID")

assert_eq "4 1 2" "$PLAN" "matches the planted row on normalised title, leaving 4 candidates"

fixture_mysql -e "DELETE FROM games WHERE user_id = $FIXTURE_UID AND title = 'import halo 3™'"
```

`test_gt_import_csv.sh` must `source fixtures.sh` (already added in Task 2) and export the `GT_DB_*` environment that `run-all.sh` provides.

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd ~/worktrees/cli-import
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A6 'Importer matching'
```
Expected: FAIL — `Class "GameTracker\Services\Write\Importer" not found`.

- [ ] **Step 3: Implement Importer**

Create `src/Services/Write/Importer.php`:

```php
<?php

namespace GameTracker\Services\Write;

use GameTracker\Import\ImportRow;
use GameTracker\Import\Source;
use GameTracker\Import\TitleKey;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Write\GamesWrites;
use GameTracker\Write\ItemsWrites;
use PDO;
use Throwable;

/**
 * Turns a Source's candidate rows into database rows.
 *
 * The only unit in this sub-project that writes. Planning is separated from
 * applying so the preview and the real run share one matcher — a dry run that
 * used different logic from the apply would be worse than no dry run at all.
 */
final class Importer
{
    /**
     * Decide which candidates are new, without writing anything.
     *
     * @return array{candidates: list<ImportRow>, matched: int, skipped: int, byTable: array<string,int>}
     */
    public static function plan(PDO $pdo, int $userId, Source $source): array
    {
        $existing = self::existingKeys($pdo, $userId);

        $candidates = [];
        $matched = 0;
        $byTable = ['games' => 0, 'items' => 0];
        $seen = [];

        foreach ($source->rows() as $row) {
            $key = self::keyFor($row->table, $row->title(), $row->platform());

            // Guard against duplicates inside the source itself, not just
            // against the database. A CSV listing the same game twice would
            // otherwise import it twice.
            if (isset($existing[$key]) || isset($seen[$key])) {
                $matched++;
                continue;
            }

            $seen[$key] = true;
            $candidates[] = $row;
            $byTable[$row->table] = ($byTable[$row->table] ?? 0) + 1;
        }

        return [
            'candidates' => $candidates,
            'matched' => $matched,
            // Only meaningful once the generator has been drained, which the
            // foreach above guarantees.
            'skipped' => $source->skipped(),
            'byTable' => $byTable,
        ];
    }

    /**
     * Insert the candidates in one transaction and journal them as one entry.
     *
     * @param list<ImportRow> $candidates
     * @return array{journal_id: ?string, inserted: int, ids: list<array{table:string,id:int,coverUrl:?string}>}
     */
    public static function apply(
        PDO $pdo,
        int $userId,
        array $candidates,
        JournalWriter $journal,
        array $argv
    ): array {
        if ($candidates === []) {
            // Nothing to undo, and an empty entry would only clutter
            // `gt undo --list`.
            return ['journal_id' => null, 'inserted' => 0, 'ids' => []];
        }

        $id = $journal->newId('import');
        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'import', 'import', false, null, []
        ));

        $inserted = [];

        $pdo->beginTransaction();

        try {
            foreach ($candidates as $row) {
                $columns = self::writableOnly($row);

                $columnSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '`',
                    array_keys($columns)
                ));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                // The table name comes from ImportRow, whose only possible
                // values are set by the profile — never from user input.
                $table = $row->table === 'items' ? 'items' : 'games';

                $stmt = $pdo->prepare(
                    "INSERT INTO {$table} ({$columnSql}, `user_id`) "
                    . "VALUES ({$placeholders}, ?)"
                );
                $stmt->execute(array_merge(array_values($columns), [$userId]));

                $inserted[] = [
                    'table' => $table,
                    'id' => (int)$pdo->lastInsertId(),
                    'coverUrl' => $row->coverUrl,
                ];
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $rows = [];
        foreach ($inserted as $item) {
            $stamp = $pdo->prepare("SELECT `updated_at` FROM {$item['table']} WHERE `id` = ?");
            $stamp->execute([$item['id']]);
            $updatedAt = $stamp->fetchColumn();

            $rows[] = [
                'table' => $item['table'],
                'id' => $item['id'],
                'updated_at' => $updatedAt === false ? null : $updatedAt,
                'before' => [],
            ];
        }

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'import', 'import', true, null, $rows
        ));

        return ['journal_id' => $id, 'inserted' => count($inserted), 'ids' => $inserted];
    }

    /**
     * Drop anything the resource does not permit writing.
     *
     * A --map can name any column, so this is the boundary that stops a
     * mapping from reaching a column the resource never offered.
     *
     * @return array<string, mixed>
     */
    private static function writableOnly(ImportRow $row): array
    {
        $def = $row->table === 'items'
            ? ItemsWrites::definition()
            : GamesWrites::definition();

        $columns = [];
        foreach ($row->columns as $column => $value) {
            if ($def->isWritable($column)) {
                $columns[$column] = $value;
            }
        }

        return $columns;
    }

    /**
     * Every (table, normalised title, platform) the user already owns.
     *
     * Loaded once per run rather than a query per candidate: a 300-game Steam
     * import would otherwise issue 300 round trips. At this scale — the largest
     * library is ~1,300 rows — one pass is cheaper than any index would be,
     * and it avoids storing a normalised column that could go stale.
     *
     * @return array<string, true>
     */
    private static function existingKeys(PDO $pdo, int $userId): array
    {
        $keys = [];

        foreach (['games', 'items'] as $table) {
            $stmt = $pdo->prepare("SELECT `title`, `platform` FROM {$table} WHERE `user_id` = ?");
            $stmt->execute([$userId]);

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $keys[self::keyFor($table, (string)$row['title'], $row['platform'])] = true;
            }
        }

        return $keys;
    }

    private static function keyFor(string $table, string $title, ?string $platform): string
    {
        return $table . "\0" . TitleKey::normalise($title)
             . "\0" . TitleKey::normalise((string)$platform);
    }
}
```

- [ ] **Step 4: Implement ImportReverter**

Create `src/Services/Write/ImportReverter.php`:

```php
<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\BadRequestException;
use GameTracker\Journal\JournalEntry;
use PDO;
use Throwable;

/**
 * Reverses an import.
 *
 * Import needs its own reverter because a CSV import spans games and items
 * while a JournalEntry carries a single resource. Splitting one import across
 * two entries would let `gt undo` revert half a job, so instead each journalled
 * row records the table it went into and this deletes accordingly.
 *
 * Import only ever INSERTs, so reversing is deleting — with the same guard
 * revertCreate uses: a row edited since the import is left alone unless forced,
 * because removing it would discard that edit.
 */
final class ImportReverter implements ResourceWriter
{
    private const TABLES = ['games', 'items'];

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        if ($entry->operation !== 'import') {
            throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' as an import"
            );
        }

        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $table = $row['table'] ?? null;

                // Never interpolate a table name from a file on disk without
                // checking it against a fixed list first.
                if (!in_array($table, self::TABLES, true)) {
                    $skipped++;
                    continue;
                }

                $check = $pdo->prepare(
                    "SELECT `updated_at` FROM {$table} WHERE `id` = ? AND `user_id` = ?"
                );
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    // Already gone. Not an error.
                    $skipped++;
                    continue;
                }

                if (!$force && $current !== ($row['updated_at'] ?? null)) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare(
                    "DELETE FROM {$table} WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute([$row['id'], $entry->userId]);
                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }
}
```

- [ ] **Step 5: Register the reverter**

In `src/Cli/Commands/UndoCommand.php`, add the import:

```php
use GameTracker\Services\Write\ImportReverter;
```

and extend the map:

```php
    private const REVERTERS = [
        'games' => GamesWriter::class,
        'items' => ItemsWriter::class,
        'import' => ImportReverter::class,
    ];
```

- [ ] **Step 6: Run the harness and confirm it passes**

```bash
cd ~/worktrees/cli-import
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, everything green.

- [ ] **Step 7: Commit**

```bash
git add src/Services/Write/Importer.php src/Services/Write/ImportReverter.php \
        src/Cli/Commands/UndoCommand.php tests/cli/test_gt_import_csv.sh
git commit -m "feat(cli): Importer and a reverter that undoes a whole import

plan() and apply() share one matcher so the dry run cannot disagree with the
real thing. Existing keys load once per run rather than a query per candidate.

Import registers its own reverter: a CSV import spans games and items while a
JournalEntry carries one resource, and splitting an import across two entries
would let gt undo revert half a job."
```

---

### Task 4: `gt import csv` and `gt import profiles`

**Files:** Create `src/Cli/Commands/Import/CsvCommand.php`, `src/Cli/Commands/Import/ProfilesCommand.php`; modify `src/Cli/Application.php`

**Interfaces:**
- Consumes: `Importer::plan()`, `Importer::apply()`, `CsvSource`, `CsvProfile`, `UserResolver`, `Output`
- Produces: commands `import csv`, `import profiles`

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_import_csv.sh`, before `summarize`:

```bash
blue "gt import csv"

export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_JOURNAL_DIR"' EXIT

GT_CODE=0; GT_OUT=""; GT_JSON=""
run_gt()      { set +e; GT_OUT=$("$GT" "$@" 2>&1);      GT_CODE=$?; set -e; }
run_gt_json() { set +e; GT_JSON=$("$GT" "$@" 2>/dev/null); GT_CODE=$?; set -e; }

seed_games
seed_items
USER_FLAG="--user=$FIXTURE_USER"
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

count_imported() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM $1 t JOIN users u ON t.user_id = u.id
    WHERE u.username = '$FIXTURE_USER' AND t.title LIKE 'IMPORT %'
  "
}

run_gt import csv /no/such/file.csv "$USER_FLAG" --profile=gameeye
assert_eq "2" "$GT_CODE" "an unreadable CSV is a usage error"

run_gt import csv "$CSV" "$USER_FLAG" --profile=nope
assert_eq "2" "$GT_CODE" "an unknown profile is a usage error"

# Dry run: an import is bulk by nature, so it always previews.
run_gt_json import csv "$CSV" "$USER_FLAG" --profile=gameeye
assert_eq "0" "$GT_CODE" "dry run exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true and .would_insert == 5 and .skipped == 2' > /dev/null \
  && { green "  PASS: previews 5 inserts and 2 skips"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "0" "$(count_imported games)" "dry run wrote no games"
assert_eq "0" "$(count_imported items)" "dry run wrote no items"

run_gt_json import csv "$CSV" "$USER_FLAG" --profile=gameeye --yes
assert_eq "0" "$GT_CODE" "--yes exits 0"
assert_eq "2" "$(count_imported games)" "2 games imported"
assert_eq "3" "$(count_imported items)" "3 items imported"

# Re-running the same file must import nothing: matching works.
run_gt_json import csv "$CSV" "$USER_FLAG" --profile=gameeye --yes
echo "$GT_JSON" | jq -e '.inserted == 0 and .matched == 5' > /dev/null \
  && { green "  PASS: a second run imports nothing"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: re-run should have matched everything: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "2" "$(count_imported games)" "still 2 games after re-run"

blue "One import is one journal entry, and undo reverts all of it"

ENTRIES=$(find "$GT_JOURNAL_DIR" -name '*-import.json' | wc -l)
assert_eq "1" "$ENTRIES" "the zero-insert re-run wrote no second entry"

run_gt undo "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "undo of an import exits 0"
assert_eq "0" "$(count_imported games)" "undo removed the imported games"
assert_eq "0" "$(count_imported items)" "undo removed the imported items across both tables"

blue "gt import profiles"

run_gt_json import profiles
assert_eq "0" "$GT_CODE" "profiles exits 0"
assert_contains "gameeye" "$GT_JSON" "lists the gameeye profile"
```

- [ ] **Step 2: Run it and confirm it fails**

Expected: FAIL — "unknown command 'import'".

- [ ] **Step 3: Implement ProfilesCommand**

Create `src/Cli/Commands/Import/ProfilesCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Import\CsvProfile;

/**
 * Lists the built-in CSV profiles. Read-only.
 */
final class ProfilesCommand implements Command
{
    public const NAME = 'import profiles';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List the built-in CSV import profiles';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $rows = [];

        foreach (CsvProfile::names() as $name) {
            $profile = CsvProfile::named($name);
            $rows[] = [
                'profile' => $name,
                'columns' => implode(', ', array_map(
                    static fn(string $col, string $header): string => "{$col}<={$header}",
                    array_keys($profile->columns),
                    array_values($profile->columns)
                )),
                'skips' => implode(', ', $profile->skippedCategories),
            ];
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($rows);

            return 0;
        }

        $ctx->output->record(['profiles' => $rows]);

        return 0;
    }
}
```

- [ ] **Step 4: Implement CsvCommand**

Create `src/Cli/Commands/Import/CsvCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Import\CsvProfile;
use GameTracker\Import\CsvSource;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\CoverFetcher;
use GameTracker\Services\Write\Importer;

/**
 * Imports a CSV.
 *
 * Always previews without --yes: an import is bulk by nature, so the
 * blast-radius rule applies unconditionally rather than by row count.
 */
final class CsvCommand implements Command
{
    public const NAME = 'import csv';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Import games and items from a CSV file';
    }

    public static function allowedOptions(): array
    {
        return ['profile', 'map', 'yes'];
    }

    public function run(array $args, Context $ctx): int
    {
        $path = $args[0] ?? null;
        if ($path === null) {
            throw new UsageException('usage: gt import csv <file> [--profile=<name>|--map=<col>:<header>,…]');
        }

        $profileName = $ctx->option('profile');
        $map = $ctx->option('map');

        if ($profileName !== null && $map !== null) {
            throw new UsageException('pass either --profile or --map, not both');
        }

        $profile = $map !== null
            ? CsvProfile::fromMap(self::parseMap($map))
            : CsvProfile::named($profileName ?? 'gameeye');

        $source = new CsvSource($path, $profile);

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $plan = Importer::plan($ctx->pdo, $userId, $source);

        if (!$ctx->flag('yes')) {
            return $this->preview($ctx, $plan, $source->describe());
        }

        $result = Importer::apply(
            $ctx->pdo,
            $userId,
            $plan['candidates'],
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        // Covers are fetched only after the transaction has committed: holding
        // row locks through hundreds of HTTP requests would be far worse than
        // a cover arriving a moment late.
        $covers = CoverFetcher::fetchAll($ctx->pdo, $userId, $result['ids']);

        return $this->report($ctx, $plan, $result, $covers);
    }

    /**
     * @return array<string,string> target column => CSV header
     */
    private static function parseMap(string $raw): array
    {
        $map = [];

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, ':')) {
                throw new UsageException(
                    "--map entry '{$pair}' is not <column>:<header>"
                );
            }
            [$column, $header] = explode(':', $pair, 2);
            $column = trim($column);
            $header = trim($header);
            if ($column === '' || $header === '') {
                throw new UsageException("--map entry '{$pair}' has an empty side");
            }
            $map[$column] = $header;
        }

        return $map;
    }

    private function preview(Context $ctx, array $plan, string $describe): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would import from {$describe}");
            $ctx->output->line(sprintf(
                '  %d new (%d games, %d items), %d already owned, %d skipped',
                count($plan['candidates']),
                $plan['byTable']['games'] ?? 0,
                $plan['byTable']['items'] ?? 0,
                $plan['matched'],
                $plan['skipped']
            ));
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'source' => $describe,
            'would_insert' => count($plan['candidates']),
            'by_table' => $plan['byTable'],
            'matched' => $plan['matched'],
            'skipped' => $plan['skipped'],
        ]);

        return 0;
    }

    private function report(Context $ctx, array $plan, array $result, array $covers): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'imported %d, already owned %d, skipped %d',
                $result['inserted'],
                $plan['matched'],
                $plan['skipped']
            ));
            if ($covers['failed'] > 0) {
                $ctx->output->warn(sprintf(
                    '%d cover downloads failed — rows imported without a cover',
                    $covers['failed']
                ));
            }
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'inserted' => $result['inserted'],
            'matched' => $plan['matched'],
            'skipped' => $plan['skipped'],
            'covers_fetched' => $covers['fetched'],
            'covers_failed' => $covers['failed'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
```

- [ ] **Step 5: Register both commands**

In `src/Cli/Application.php`, add imports:

```php
use GameTracker\Cli\Commands\Import\CsvCommand as ImportCsvCommand;
use GameTracker\Cli\Commands\Import\ProfilesCommand as ImportProfilesCommand;
```

and add to `COMMANDS`, after the items entries:

```php
        'import csv' => ImportCsvCommand::class,
        'import profiles' => ImportProfilesCommand::class,
```

- [ ] **Step 6: Run the harness**

Expected: exit 0 once Task 5's `CoverFetcher` exists. **`CsvCommand` references `CoverFetcher`, which Task 6 creates** — so implement the stub now to keep this task independently green:

Create `src/Services/Write/CoverFetcher.php` with the real signature and a no-op body; Task 6 fills it in.

```php
<?php

namespace GameTracker\Services\Write;

use PDO;

/**
 * Downloads covers for freshly imported rows. Filled in by Task 6.
 */
final class CoverFetcher
{
    /**
     * @param list<array{table:string,id:int,coverUrl:?string}> $inserted
     * @return array{fetched:int, failed:int}
     */
    public static function fetchAll(PDO $pdo, int $userId, array $inserted): array
    {
        return ['fetched' => 0, 'failed' => 0];
    }
}
```

- [ ] **Step 7: Commit**

```bash
git add src/Cli/Commands/Import/ src/Services/Write/CoverFetcher.php \
        src/Cli/Application.php tests/cli/test_gt_import_csv.sh
git commit -m "feat(cli): gt import csv and gt import profiles

An import always previews without --yes — it is bulk by nature, so the
blast-radius rule applies unconditionally rather than by row count. Covers are
fetched after COMMIT so network latency never holds row locks."
```

---

### Task 5: SteamSource and `gt import steam`

**Files:** Create `src/Import/HttpTransport.php`, `src/Import/CurlTransport.php`, `src/Import/SteamSource.php`, `src/Cli/Commands/Import/SteamCommand.php`, `tests/cli/fixtures/steam-owned-games.json`, `tests/cli/test_gt_import_steam.sh`; modify `src/Cli/Application.php`

**Interfaces:**
- Produces:
  - `HttpTransport::get(string $url): ?string` — null on failure
  - `SteamSource::__construct(HttpTransport $http, string $apiKey, string $steamId)`
  - `SteamSource::credentialsFor(PDO, int $userId): array{key:string, id:string}` — throws `BadRequestException` when absent

- [ ] **Step 1: Write the fixture and failing test**

Create `tests/cli/fixtures/steam-owned-games.json`:

```json
{
  "response": {
    "game_count": 3,
    "games": [
      { "appid": 220,   "name": "IMPORT Half-Life 2",   "img_icon_url": "abc123" },
      { "appid": 620,   "name": "IMPORT Portal 2",      "img_icon_url": "def456" },
      { "appid": 12345, "name": "IMPORT Steam Only",    "img_icon_url": "ghi789" }
    ]
  }
}
```

Create `tests/cli/test_gt_import_steam.sh`:

```bash
#!/usr/bin/env bash
# `gt import steam` — driven through an injected transport.
#
# CI must never call the live Steam API: it would need a real key, it would be
# slow, and the result would change under us. SteamSource takes an HttpTransport
# so the tests can feed it a recorded payload instead.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
FIXTURE="$PROJECT_ROOT/tests/cli/fixtures/steam-owned-games.json"

seed_games
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

GT_CODE=0; GT_OUT=""
run_gt() { set +e; GT_OUT=$("$GT" "$@" 2>&1); GT_CODE=$?; set -e; }

blue "Missing credentials"

fixture_mysql -e "DELETE FROM settings WHERE user_id = $FIXTURE_UID AND setting_key IN ('steam_api_key','steam_user_id')"

run_gt import steam "--user=$FIXTURE_USER" --yes
assert_eq "1" "$GT_CODE" "absent Steam credentials are a domain error"
assert_contains "steam_api_key" "$GT_OUT" "names the missing setting"

blue "SteamSource against a recorded payload"

ROWS=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  $stub = new class("'"$FIXTURE"'") implements GameTracker\Import\HttpTransport {
      public function __construct(private string $file) {}
      public function get(string $url): ?string {
          return str_contains($url, "GetOwnedGames")
              ? file_get_contents($this->file)
              : null;   // appdetails unavailable, must be non-fatal
      }
  };
  $src = new GameTracker\Import\SteamSource($stub, "k", "1");
  $n = 0; $platform = ""; $store = "";
  foreach ($src->rows() as $row) { $n++; $platform = $row->columns["platform"]; $store = $row->columns["digital_store"]; }
  echo $n, " ", $platform, " ", $store;
')

assert_eq "3 PC Steam" "$ROWS" "parses 3 games, tagging them PC/Steam"

blue "Detail-fetch failure is non-fatal"
# The stub above returns null for appdetails, and all 3 rows still arrived —
# proving a Steam detail outage degrades rather than aborting the import.

summarize
```

- [ ] **Step 2: Run and confirm failure**

Expected: FAIL — interfaces and classes not found.

- [ ] **Step 3: Implement the transports**

Create `src/Import/HttpTransport.php`:

```php
<?php

namespace GameTracker\Import;

/**
 * The seam that keeps live network calls out of CI.
 *
 * SteamSource depends on this rather than calling curl directly, so a test can
 * feed it a recorded payload. Without it the Steam path would be untestable —
 * needing a real API key, real latency, and a result that changes underneath
 * the assertions.
 */
interface HttpTransport
{
    /** Returns the response body, or null on any failure. */
    public function get(string $url): ?string;
}
```

Create `src/Import/CurlTransport.php`:

```php
<?php

namespace GameTracker\Import;

/**
 * The real transport. Deliberately dependency-free and dull.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function get(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => 'gameTracker-gt/1.0',
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string)$body;
    }
}
```

- [ ] **Step 4: Implement SteamSource**

Create `src/Import/SteamSource.php`:

```php
<?php

namespace GameTracker\Import;

use GameTracker\Domain\BadRequestException;
use PDO;

/**
 * The user's Steam library as ImportRows.
 *
 * Detail lookups are best-effort: Steam's appdetails endpoint is rate-limited
 * and frequently unavailable, and losing a release date is not a reason to
 * abandon an import of 300 games. A row always survives a detail failure with
 * whatever the library listing gave us.
 */
final class SteamSource implements Source
{
    private int $count = 0;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $apiKey,
        private readonly string $steamId,
    ) {
    }

    /**
     * @return array{key: string, id: string}
     */
    public static function credentialsFor(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT `setting_key`, `setting_value` FROM settings
             WHERE `user_id` = ? AND `setting_key` IN ('steam_api_key', 'steam_user_id')"
        );
        $stmt->execute([$userId]);

        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[$row['setting_key']] = (string)$row['setting_value'];
        }

        $key = trim($found['steam_api_key'] ?? '');
        $id = trim($found['steam_user_id'] ?? '');

        if ($key === '' || $id === '') {
            throw new BadRequestException(
                'Steam credentials are not configured — set steam_api_key and '
                . 'steam_user_id in settings before importing'
            );
        }

        return ['key' => $key, 'id' => $id];
    }

    public function describe(): string
    {
        return "Steam library ({$this->count} games)";
    }

    /**
     * Steam's library listing has nothing to skip — every entry is a game the
     * user owns. Always zero, but implemented because Source requires it.
     */
    public function skipped(): int
    {
        return 0;
    }

    public function rows(): iterable
    {
        $url = 'https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/'
             . '?key=' . urlencode($this->apiKey)
             . '&steamid=' . urlencode($this->steamId)
             . '&format=json&include_appinfo=1';

        $body = $this->http->get($url);

        if ($body === null) {
            throw new BadRequestException(
                'could not reach the Steam API — check the key, the Steam ID, and connectivity'
            );
        }

        $data = json_decode($body, true);
        $games = $data['response']['games'] ?? null;

        if (!is_array($games)) {
            throw new BadRequestException(
                'Steam returned no game list — is the profile private?'
            );
        }

        $this->count = count($games);

        foreach ($games as $game) {
            $name = trim((string)($game['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $appId = (int)($game['appid'] ?? 0);

            $columns = [
                'title' => $name,
                'platform' => 'PC',
                'digital_store' => 'Steam',
            ];

            $columns += $this->detail($appId);

            yield new ImportRow('games', $columns, $this->coverUrl($appId));
        }
    }

    /**
     * Best-effort extra fields. Any failure returns nothing rather than
     * throwing — see the class docblock.
     *
     * @return array<string, string>
     */
    private function detail(int $appId): array
    {
        if ($appId <= 0) {
            return [];
        }

        $body = $this->http->get("https://store.steampowered.com/api/appdetails?appids={$appId}");
        if ($body === null) {
            return [];
        }

        $data = json_decode($body, true);
        $payload = $data[$appId]['data'] ?? null;

        if (!is_array($payload)) {
            return [];
        }

        $extra = [];

        $description = trim((string)($payload['short_description'] ?? ''));
        if ($description !== '') {
            $extra['description'] = $description;
        }

        $released = $payload['release_date']['date'] ?? null;
        if (is_string($released) && $released !== '') {
            $timestamp = strtotime($released);
            if ($timestamp !== false) {
                $extra['release_date'] = date('Y-m-d', $timestamp);
            }
        }

        $genres = $payload['genres'] ?? [];
        if (is_array($genres) && isset($genres[0]['description'])) {
            $extra['genre'] = (string)$genres[0]['description'];
        }

        return $extra;
    }

    private function coverUrl(int $appId): ?string
    {
        if ($appId <= 0) {
            return null;
        }

        return "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/header.jpg";
    }
}
```

- [ ] **Step 5: Implement SteamCommand**

Create `src/Cli/Commands/Import/SteamCommand.php` — same shape as `CsvCommand`, with the source swapped:

```php
<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Import\CurlTransport;
use GameTracker\Import\SteamSource;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\CoverFetcher;
use GameTracker\Services\Write\Importer;

/**
 * Imports the user's Steam library.
 */
final class SteamCommand implements Command
{
    public const NAME = 'import steam';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Import owned games from Steam';
    }

    public static function allowedOptions(): array
    {
        return ['yes'];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $credentials = SteamSource::credentialsFor($ctx->pdo, $userId);
        $source = new SteamSource(new CurlTransport(), $credentials['key'], $credentials['id']);

        $plan = Importer::plan($ctx->pdo, $userId, $source);

        if (!$ctx->flag('yes')) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line(sprintf(
                    'would import %d new games, %d already owned',
                    count($plan['candidates']),
                    $plan['matched']
                ));
                $ctx->output->line('re-run with --yes to apply');

                return 0;
            }

            $ctx->output->record([
                'dry_run' => true,
                'would_insert' => count($plan['candidates']),
                'matched' => $plan['matched'],
            ]);

            return 0;
        }

        $result = Importer::apply(
            $ctx->pdo,
            $userId,
            $plan['candidates'],
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        $covers = CoverFetcher::fetchAll($ctx->pdo, $userId, $result['ids']);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'imported %d, already owned %d, covers %d/%d',
                $result['inserted'],
                $plan['matched'],
                $covers['fetched'],
                $covers['fetched'] + $covers['failed']
            ));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'inserted' => $result['inserted'],
            'matched' => $plan['matched'],
            'covers_fetched' => $covers['fetched'],
            'covers_failed' => $covers['failed'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
```

- [ ] **Step 6: Register it**

In `src/Cli/Application.php`:

```php
use GameTracker\Cli\Commands\Import\SteamCommand as ImportSteamCommand;
```
```php
        'import steam' => ImportSteamCommand::class,
```

- [ ] **Step 7: Run the harness**

```bash
cd ~/worktrees/cli-import && chmod +x tests/cli/test_gt_import_steam.sh
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0.

- [ ] **Step 8: Commit**

```bash
git add src/Import/HttpTransport.php src/Import/CurlTransport.php src/Import/SteamSource.php \
        src/Cli/Commands/Import/SteamCommand.php src/Cli/Application.php \
        tests/cli/fixtures/steam-owned-games.json tests/cli/test_gt_import_steam.sh
git commit -m "feat(cli): gt import steam, driven through an injectable transport

The transport seam is load-bearing: without it the Steam path would need a
real API key and live latency in CI, against a result that changes underneath
the assertions. Detail lookups are best-effort — losing a release date is no
reason to abandon a 300-game import."
```

---

### Task 6: CoverFetcher

Replaces Task 4's stub with the real thing.

**Files:** Modify `src/Services/Write/CoverFetcher.php`, `tests/cli/test_gt_import_steam.sh`

**Interfaces:**
- Consumes: `gt_download_and_save_cover(PDO, int, string, ?int, string): array`
- Produces: `CoverFetcher::fetchAll(PDO, int $userId, array $inserted): array{fetched:int, failed:int}` (signature unchanged from the stub)

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_import_steam.sh`, before `summarize`:

```bash
blue "CoverFetcher is non-fatal"

# An unreachable URL must leave the row imported with a NULL cover and be
# counted, not abort the run. A CDN blip cannot be allowed to lose an import.
COVER=$(php -r '
  require "'"$PROJECT_ROOT"'/src/autoload.php";
  require "'"$PROJECT_ROOT"'/includes/config.php";
  $uid = (int)$argv[1];
  $pdo->prepare("INSERT INTO games (user_id, title, platform) VALUES (?, ?, ?)")
      ->execute([$uid, "IMPORT Cover Probe", "PC"]);
  $id = (int)$pdo->lastInsertId();
  $r = GameTracker\Services\Write\CoverFetcher::fetchAll($pdo, $uid, [
      ["table" => "games", "id" => $id, "coverUrl" => "https://invalid.invalid/none.jpg"],
      ["table" => "games", "id" => $id, "coverUrl" => null],
  ]);
  $stmt = $pdo->prepare("SELECT front_cover_image FROM games WHERE id = ?");
  $stmt->execute([$id]);
  $cover = $stmt->fetchColumn();
  $pdo->prepare("DELETE FROM games WHERE id = ?")->execute([$id]);
  echo $r["fetched"], " ", $r["failed"], " ", ($cover === null || $cover === "" ? "null" : "set");
' -- "$FIXTURE_UID")

assert_eq "0 1 null" "$COVER" "a failed fetch is counted, the row keeps a NULL cover, a null URL is not attempted"
```

- [ ] **Step 2: Run and confirm failure**

Expected: FAIL — the stub returns `0 0`, so the assertion sees `0 0 null`.

- [ ] **Step 3: Implement**

Replace `src/Services/Write/CoverFetcher.php`:

```php
<?php

namespace GameTracker\Services\Write;

use PDO;
use Throwable;

/**
 * Downloads covers for freshly imported rows.
 *
 * Runs only after the import transaction has committed. Doing this inside the
 * transaction would hold row locks through hundreds of HTTP requests — a
 * 300-game Steam import would keep the games table locked for minutes.
 *
 * Every failure is counted and swallowed. A cover is a nice-to-have; an
 * aborted import is not. gt_download_and_save_cover already handles SSRF
 * checks, magic-byte validation, the thumbnail, and the row update, so this
 * only has to decide what to do when it says no.
 */
final class CoverFetcher
{
    /**
     * @param list<array{table:string,id:int,coverUrl:?string}> $inserted
     * @return array{fetched:int, failed:int}
     */
    public static function fetchAll(PDO $pdo, int $userId, array $inserted): array
    {
        $fetched = 0;
        $failed = 0;

        require_once __DIR__ . '/../../../includes/external-image-service.php';

        foreach ($inserted as $row) {
            $url = $row['coverUrl'] ?? null;

            // Items have no cover column on this path, and a row without a
            // URL is not a failure — there was nothing to fetch.
            if ($url === null || $url === '' || ($row['table'] ?? '') !== 'games') {
                continue;
            }

            try {
                $result = gt_download_and_save_cover($pdo, $userId, $url, (int)$row['id'], 'front');
                if (($result['ok'] ?? false) === true) {
                    $fetched++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                // Deliberately swallowed: see the class docblock.
                $failed++;
            }
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }
}
```

- [ ] **Step 4: Run the harness and confirm it passes**

Expected: exit 0, and the new assertion reads `0 1 null`.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Write/CoverFetcher.php tests/cli/test_gt_import_steam.sh
git commit -m "feat(cli): fetch covers after commit, never inside the transaction

A 300-game import would otherwise hold row locks through 300 HTTP requests.
Every failure is counted and swallowed: a cover is a nice-to-have, an aborted
import is not."
```

---

### Task 7: Remove the dead importer, docs, full verification

**Files:** Delete `import-gameeye.php`; modify `src/Cli/Application.php` (`VERSION`), `CLAUDE.md`

- [ ] **Step 1: Re-confirm the file is dead before deleting it**

```bash
cd ~/worktrees/cli-import
grep -rn "import-gameeye" --include='*.php' --include='*.js' --include='*.html' . \
  | grep -v '^./api/import-gameeye.php' | grep -v '^./import-gameeye.php:'
```
Expected: exactly one live hit — `js/settings.js:364`, referencing `api/import-gameeye.php`. Anything else pointing at the **root** file means stop and reassess rather than delete.

- [ ] **Step 2: Delete it**

```bash
git rm import-gameeye.php
```

- [ ] **Step 3: Bump the version**

In `src/Cli/Application.php`, `VERSION` → `'0.4.0'`.

- [ ] **Step 4: Document it**

Add to `CLAUDE.md`, after the write-commands block:

```markdown
# Import (sub-project #4a). Always previews without --yes — an import is bulk
# by nature. The whole import is ONE journal entry, so `gt undo` reverts every
# row across both tables.
./bin/gt import steam --yes                    # needs steam_api_key +
                                               # steam_user_id in settings
./bin/gt import csv <file> --profile=gameeye --yes
./bin/gt import csv <file> --map=title:Name,platform:System --yes
./bin/gt import profiles                       # list built-in profiles

# Matching is on NORMALISED title + platform (src/Import/TitleKey): trademark
# symbols, case and redundant whitespace are ignored, punctuation is not. A
# match means skip — import only ever adds. Improving existing rows is
# `gt games set`.
#
# Covers download AFTER the transaction commits, so network latency never holds
# row locks, and a failed download is non-fatal: the row imports with a NULL
# cover and the failure is counted.
#
# src/Import/ parses and must stay free of write SQL; src/Services/Write/
# does all writing. The read-only guard enforces this.
```

- [ ] **Step 5: Full harness, plus the guard's negative control**

```bash
cd ~/worktrees/cli-import
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh; echo "run-all exit=$?"
./bin/gt --version   # expect gt 0.4.0
./bin/gt help        # expect import csv / import steam / import profiles
```
Expected: `exit=0`, all suites green.

Then confirm the guard still bites, since `src/Import/` is a new directory it must police:

```bash
printf '\n$leak = "INSERT INTO games (title) VALUES (?)";\n' >> src/Import/TitleKey.php
bash tests/cli/test_readonly_guard.sh | grep -E "FAIL|PASS: no write SQL"
git checkout src/Import/TitleKey.php
```
Expected: the suite reports FAIL for write SQL outside `src/Services/Write/`, proving the new directory is covered. Restore the file afterwards and confirm `git status` is clean.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore(cli): delete the dead root gameeye importer, document import

import-gameeye.php in the repo root was unreferenced — js/settings.js calls
the api/ copy — and had diverged from it by 906 lines, so fixes to one were
never reaching the other. It also sat in the document root taking user_id from
argv."
```

## Verification before opening the PR

- [ ] `bash tests/v2/run-all.sh; echo "exit=$?"` prints `exit=0`
- [ ] `./bin/gt --version` prints `gt 0.4.0`; `./bin/gt help` lists the three import commands
- [ ] The read-only guard fails when write SQL is planted in `src/Import/` (Step 5 above)
- [ ] Production untouched: every test used `gameTracker_test`, and no `gt` command ran against `gameTracker`
- [ ] `git log --oneline main..HEAD` shows the 7 task commits
- [ ] Push, open the PR, then confirm with `gh pr checks <n>` **and** the run's `conclusion` — a shell pipeline can exit 0 while `gh` itself failed

Merging and deploying stay separate decisions, and Cameron's. Merge from `~`, never from inside `/var/www/gameTracker`.
