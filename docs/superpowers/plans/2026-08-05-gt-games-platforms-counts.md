# `gt games platforms` Counts and Filters — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `gt games platforms` a per-platform game count and the games filter
vocabulary, so the physical-only split no longer needs a hand-written `gt sql`
aggregate.

**Architecture:** A new `GamesService::platformCounts()` runs the `GROUP BY`, taking
its conditions from a `FilterSet` exactly as `list()` does. A new
`FilterCompiler::compileWhere()` compiles the WHERE half of the filter vocabulary
without compiling a sort, because this command sorts by a `COUNT(*)` alias that is
not a column on `games`. The existing `platforms()` is reimplemented on top of
`platformCounts()` so user scoping and the blank-platform exclusion live in one
query, and keeps returning a string array because `api/games.php` feeds the web
datalist from it.

**Tech Stack:** PHP 8, PDO/MySQL, bash test suites under `tests/` driven by
`tests/v2/run-all.sh`.

**Design:** `docs/superpowers/specs/2026-08-05-gt-games-platforms-counts-design.md`

## Global Constraints

- Work in the worktree, never in `/var/www/gameTracker` — that checkout is live
  production. `includes/config.php` is gitignored and has already been copied in;
  never commit it.
- `GamesService::platforms()` must keep returning `list<string>`.
  `tests/v2/test_v1_read_contract.sh` asserts it and must not be edited.
- Services are SELECT-only; `tests/cli/test_readonly_guard.sh` enforces it.
- Every query is user-scoped with an explicit bound `int $userId`, bound first
  and unconditionally, so filters can only narrow.
- Column names reaching SQL come from `FilterDefinition`, never from user input.
- Run the suite with `tests/v2/run-all.sh`, which exports
  `GT_DB_NAME=gameTracker_test`. Never run tests with production `GT_DB_*`.
- Exit codes: 0 ok, 1 error, 2 usage, 3 bootstrap.

---

### Task 1: Counts in the output

Adds the count and changes the CLI's JSON shape. No filters and no `--sort` yet.

**Files:**
- Modify: `src/Query/FilterSet.php` (add `forSummary()` after `forId()`, ~line 40)
- Modify: `src/Services/GamesService.php:150-160` (rewrite `platforms()`, add `platformCounts()`)
- Modify: `src/Cli/Commands/Games/PlatformsCommand.php` (whole file)
- Modify: `src/Cli/Application.php:36` (version bump)
- Test: `tests/cli/test_gt_games.sh:147-151` (replace the `games platforms` block)

**Interfaces:**
- Consumes: `FilterSet` (existing constructor), `Output::rows()`, `Output::record()`,
  `UserResolver::resolve()`.
- Produces:
  - `FilterSet::forSummary(string $whereSql, array $params, string $orderSql): self`
  - `GamesService::platformCounts(PDO $pdo, int $userId, FilterSet $filters): array`
    returning `list<array{platform: string, games: int}>`

- [ ] **Step 1: Write the failing test**

In `tests/cli/test_gt_games.sh`, replace the existing block:

```bash
blue "games platforms"

assert_eq "PS2,PS3,Xbox 360" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq -r '.platforms[]' | sort | paste -sd, -)" "platforms are distinct, sorted, and scoped"
# PC belongs only to the other user's fixture row.
assert_eq "0" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq '[.platforms[] | select(. == "PC")] | length')" "platforms excludes other users"
```

with:

```bash
blue "games platforms"

# "platform=count" pairs in the order the command emitted them, so the same
# helper serves both the count assertions and the --sort ones.
plat_counts() {
  set +e
  "$GT" games platforms "$USER_FLAG" "$@" 2>/dev/null \
    | jq -r '.platforms[] | "\(.platform)=\(.games)"' \
    | paste -sd, -
  set -e
}

# Fixtures: PS2 = Silent Hill + Okami, PS3 = Journey, Xbox 360 = Halo 3 + Reach.
assert_eq "PS2=2,PS3=1,Xbox 360=2" "$(plat_counts)" "counts are per platform, alphabetical by default"

# PC belongs only to the other user's fixture row.
assert_eq "0" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq '[.platforms[] | select(.platform == "PC")] | length')" "platforms excludes other users"

# A quoted count would make every consumer coerce it.
assert_eq "number" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq -r '.platforms[0].games | type')" "games is a JSON number"
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd <worktree> && tests/v2/run-all.sh 2>&1 | grep -A 8 "games platforms"
```

Expected: FAIL on "counts are per platform" with `actual:` showing empty output —
`jq` cannot read `.platform` from a plain string, so the pairs come out blank.

- [ ] **Step 3: Add `FilterSet::forSummary()`**

In `src/Query/FilterSet.php`, after `forId()`:

```php
    /**
     * An aggregate selection: conditions and ordering, no paging.
     *
     * A GROUP BY summary returns one row per group and is meant to be read
     * whole, so paging is fixed rather than exposed — the same reasoning that
     * makes forId() fix paging at a single row. The paging fields still have to
     * be valid, so they describe the first page of one row and callers ignore
     * them.
     */
    public static function forSummary(string $whereSql, array $params, string $orderSql): self
    {
        return new self($whereSql, $params, $orderSql, 1, 1, 0);
    }
```

- [ ] **Step 4: Add `platformCounts()` and rewrite `platforms()`**

In `src/Services/GamesService.php`, replace the whole existing `platforms()`
method (lines 150-160) with:

```php
    /**
     * Per-platform game counts for one user.
     *
     * A platform with no matching rows is absent rather than present with a
     * zero: the question this answers is "what is in this filtered set", and a
     * zero row would instead claim the platform is owned but empty.
     *
     * @return list<array{platform: string, games: int}>
     */
    public static function platformCounts(PDO $pdo, int $userId, FilterSet $filters): array
    {
        // Same shape as list(): the caller's id is bound first and
        // unconditionally, so a filter can only ever narrow the result.
        $where = "`user_id` = ? AND `platform` IS NOT NULL AND `platform` != ''";
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        // orderSql arrives complete, tiebreaker included, and is spliced
        // verbatim — it is built from a fixed map in the command, never input.
        $stmt = $pdo->prepare(
            "SELECT `platform`, COUNT(*) AS `games` FROM games
             WHERE {$where}
             GROUP BY `platform`
             ORDER BY {$filters->orderSql}"
        );
        $stmt->execute($params);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'platform' => (string)$row['platform'],
                'games' => (int)$row['games'],
            ];
        }

        return $rows;
    }

    /**
     * The distinct platform names a user owns.
     *
     * Delegates to platformCounts() so the user scoping and the blank-platform
     * exclusion have one definition instead of two queries that can drift.
     *
     * Still a list of strings: api/games.php builds the add/edit platform
     * datalist in js/games.js from this, and
     * tests/v2/test_v1_read_contract.sh pins that shape.
     *
     * @return list<string>
     */
    public static function platforms(PDO $pdo, int $userId): array
    {
        return array_column(
            self::platformCounts($pdo, $userId, FilterSet::forSummary('', [], '`platform` ASC')),
            'platform'
        );
    }
```

`FilterSet` is already imported at the top of the file — no new `use` needed.

- [ ] **Step 5: Rewrite the command**

Replace the body of `src/Cli/Commands/Games/PlatformsCommand.php` from its class
docblock down, keeping the namespace and imports, and adding no new imports yet:

```php
/**
 * Platform names are matched exactly by --platform, and the stored values are
 * not always what you would guess ("PlayStation 2", not "PS2"), so this is how
 * you find the string to filter on. The count makes it a collection summary as
 * well, which is what stops the per-platform split needing a gt sql aggregate.
 */
final class PlatformsCommand implements Command
{
    public const NAME = 'games platforms';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List platforms with a game count, with filters';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        $counts = GamesService::platformCounts(
            $ctx->pdo,
            (int)$user['id'],
            FilterSet::forSummary('', [], '`platform` ASC')
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($counts);

            return 0;
        }

        $ctx->output->record(['platforms' => $counts]);

        return 0;
    }
}
```

Add one import to the existing `use` block:

```php
use GameTracker\Query\FilterSet;
```

- [ ] **Step 6: Bump the version**

`src/Cli/Application.php:36` — the JSON shape just broke, so this is a minor bump:

```php
    public const VERSION = '0.9.0';
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
cd <worktree> && tests/v2/run-all.sh
```

Expected: the three new `games platforms` assertions PASS, and
`cli/test_gt_games.sh` plus `v2/test_v1_read_contract.sh` both end `0 failed`.
The v1 suite passing unmodified is the proof the web datalist still gets strings.

- [ ] **Step 8: Commit**

```bash
git add src/Query/FilterSet.php src/Services/GamesService.php \
        src/Cli/Commands/Games/PlatformsCommand.php src/Cli/Application.php \
        tests/cli/test_gt_games.sh
git commit -m "feat(cli): count games per platform in gt games platforms

platforms JSON becomes an array of {platform, games} objects, so 0.9.0.
GamesService::platforms keeps its string-array shape for the web datalist
and is now derived from the same GROUP BY."
```

---

### Task 2: Filter selectors

**Files:**
- Modify: `src/Query/FilterCompiler.php:22-79` (extract `compileWhere()` from `compile()`)
- Modify: `src/Cli/Commands/Games/PlatformsCommand.php` (`allowedOptions()` and `run()`)
- Test: `tests/cli/test_gt_games.sh` (append to the `games platforms` block)

**Interfaces:**
- Consumes: `FilterSet::forSummary()` and `GamesService::platformCounts()` from Task 1;
  `FilterDefinition::selectorNames(): list<string>` and `GamesFilters::definition()`, both existing.
- Produces: `FilterCompiler::compileWhere(FilterDefinition $def, OptionSource $ctx): array`
  returning `[string $whereSql, list<mixed> $params]`.

- [ ] **Step 1: Write the failing test**

Append inside the `games platforms` block in `tests/cli/test_gt_games.sh`, before
`summarize`:

```bash
# is_physical across the fixtures: Halo 3 = 1, Reach = NULL, Silent Hill = 1,
# Okami = 0, Journey = 0. So --physical drops PS3 from the output entirely
# rather than showing it as zero, and --digital has to match the NULL row.
assert_eq "PS2=1,Xbox 360=1" "$(plat_counts --physical)" "--physical counts only physical rows and omits empty platforms"
assert_eq "PS2=1,PS3=1,Xbox 360=1" "$(plat_counts --digital)" "--digital matches is_physical = 0 or NULL"

# Silent Hill is the only physical row that is also unplayed.
assert_eq "PS2=1" "$(plat_counts --physical --unplayed)" "selectors compose"

run_gt games platforms "$USER_FLAG" --limit=1
assert_eq "2" "$GT_CODE" "--limit is a usage error on a summary"
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd <worktree> && tests/v2/run-all.sh 2>&1 | grep -A 20 "games platforms"
```

Expected: the three `plat_counts` assertions FAIL with `actual:` empty —
`allowedOptions()` is `[]`, so `--physical` is rejected as an unknown option and
the command writes nothing to stdout. The `--limit` assertion already PASSES for
the same reason; that is fine, Step 4 must keep it passing.

- [ ] **Step 3: Extract `compileWhere()`**

In `src/Query/FilterCompiler.php`, replace the `compile()` method — everything
from its signature through its `return new FilterSet(...)` — with these two
methods. The loop bodies are moved verbatim, including their comments:

```php
    public static function compile(FilterDefinition $def, OptionSource $ctx): FilterSet
    {
        [$whereSql, $params] = self::compileWhere($def, $ctx);
        [$page, $perPage, $offset] = self::paging($ctx);

        return new FilterSet(
            $whereSql,
            $params,
            self::orderSql($def, $ctx),
            $page,
            $perPage,
            $offset
        );
    }

    /**
     * The WHERE half of compile(), without sort or paging.
     *
     * An aggregate needs the conditions but must not have --sort validated
     * against this resource's sortColumns: `gt games platforms --sort=games`
     * orders by a COUNT(*) alias, which is not a column on games.
     *
     * @return array{0: string, 1: list<mixed>} whereSql, params
     */
    public static function compileWhere(FilterDefinition $def, OptionSource $ctx): array
    {
        $conditions = [];
        $params = [];

        foreach ($def->exact as $flag => $column) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' = ?';
                $params[] = $value;
            }
        }

        foreach ($def->like as $flag => $column) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' LIKE ?';
                $params[] = '%' . $value . '%';
            }
        }

        foreach ($def->booleans as $flag => [$column, $wanted]) {
            if (!$ctx->flag($flag)) {
                continue;
            }
            // played and is_physical are nullable ints, so "false" has to mean
            // "0 or never set". Matching only 0 would make rows whose value was
            // never set vanish from both --played and --unplayed, which reads as
            // data loss to whoever ran the query.
            $conditions[] = $wanted
                ? self::quote($column) . ' = 1'
                : '(' . self::quote($column) . ' = 0 OR ' . self::quote($column) . ' IS NULL)';
        }

        foreach ($def->ranges as $flag => [$column, $operator]) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' ' . $operator . ' ?';
                $params[] = $value;
            }
        }

        $missing = $ctx->option('missing');
        if ($missing !== null) {
            if (!in_array($missing, $def->missingColumns, true)) {
                throw new UsageException(
                    '--missing=' . $missing . " is not a filterable column on {$def->table}. "
                    . 'Available: ' . implode(', ', $def->missingColumns)
                );
            }
            $conditions[] = '(' . self::quote($missing) . ' IS NULL OR ' . self::quote($missing) . " = '')";
        }

        return [implode(' AND ', $conditions), $params];
    }
```

- [ ] **Step 4: Wire the filters into the command**

In `src/Cli/Commands/Games/PlatformsCommand.php`, change `allowedOptions()` to:

```php
    public static function allowedOptions(): array
    {
        // selectorNames() rather than flagNames(): it is already defined as the
        // flags that narrow which rows match, excluding presentation flags. That
        // makes --limit/--page/--per-page unknown options here, which is what we
        // want — paging an aggregate is meaningless, and silently ignoring the
        // flag would be worse than refusing it.
        return array_merge(GamesFilters::definition()->selectorNames(), ['sort']);
    }
```

and in `run()`, replace the `FilterSet::forSummary('', [], '`platform` ASC')`
argument with a compiled filter:

```php
        [$whereSql, $params] = FilterCompiler::compileWhere(GamesFilters::definition(), $ctx);

        $counts = GamesService::platformCounts(
            $ctx->pdo,
            (int)$user['id'],
            FilterSet::forSummary($whereSql, $params, '`platform` ASC')
        );
```

Add two imports to the existing `use` block:

```php
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\GamesFilters;
```

`'sort'` is allowed here but not yet read; Task 3 gives it meaning. Allowing it
one task early keeps `allowedOptions()` from being edited twice.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd <worktree> && tests/v2/run-all.sh
```

Expected: every `games platforms` assertion PASSES, including `--limit` still
exiting 2. `cli/test_gt_games.sh` must still end `0 failed` — its many
`games list` assertions are the regression check on the `compile()` extraction,
since they exercise every filter that moved.

- [ ] **Step 6: Commit**

```bash
git add src/Query/FilterCompiler.php \
        src/Cli/Commands/Games/PlatformsCommand.php tests/cli/test_gt_games.sh
git commit -m "feat(cli): filter gt games platforms by the games selectors

--physical answers the question this started as. compileWhere() splits the
WHERE half out of compile() because a summary sorts by a COUNT(*) alias,
which compile() would reject as a non-column."
```

---

### Task 3: `--sort`

**Files:**
- Modify: `src/Cli/Commands/Games/PlatformsCommand.php` (add `SORTS` and `orderSql()`)
- Test: `tests/cli/test_gt_games.sh` (append to the `games platforms` block)

**Interfaces:**
- Consumes: `FilterCompiler::compileWhere()` from Task 2, `Context::option()`,
  `UsageException` (thrown from a command is turned into exit 2 by `Application`).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Write the failing test**

Append inside the `games platforms` block in `tests/cli/test_gt_games.sh`, before
`summarize`:

```bash
# PS2 and Xbox 360 both have 2, so this also pins the platform-ASC tiebreaker.
assert_eq "PS2=2,Xbox 360=2,PS3=1" "$(plat_counts --sort=-games)" "--sort=-games ranks by count descending"
assert_eq "PS3=1,PS2=2,Xbox 360=2" "$(plat_counts --sort=games)" "--sort=games ranks by count ascending"
assert_eq "Xbox 360=2,PS3=1,PS2=2" "$(plat_counts --sort=-platform)" "--sort=-platform reverses the names"

run_gt games platforms "$USER_FLAG" --sort=bogus
assert_eq "2" "$GT_CODE" "an unsortable key is a usage error"
assert_contains "platform" "$GT_OUT" "the sort error names the valid keys"
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd <worktree> && tests/v2/run-all.sh 2>&1 | grep -A 30 "games platforms"
```

Expected: the three ordering assertions FAIL, each `actual:` showing the
alphabetical `PS2=2,PS3=1,Xbox 360=2` because `--sort` is accepted but ignored.
`--sort=bogus` FAILS too, exiting 0 instead of 2.

- [ ] **Step 3: Implement the sort map**

In `src/Cli/Commands/Games/PlatformsCommand.php`, add the constant directly below
`public const NAME`:

```php
    /**
     * The sort vocabulary, as ready-to-splice ORDER BY bodies.
     *
     * A fixed map rather than a column allowlist because "games" is a COUNT(*)
     * alias, and because the count needs a tiebreaker while the platform name —
     * the GROUP BY key, so unique per row — cannot tie. Nothing here comes from
     * input; the key is looked up, never interpolated.
     */
    private const SORTS = [
        'platform' => '`platform` ASC',
        '-platform' => '`platform` DESC',
        'games' => '`games` ASC, `platform` ASC',
        '-games' => '`games` DESC, `platform` ASC',
    ];
```

add the resolver as a private method after `run()`:

```php
    private static function orderSql(Context $ctx): string
    {
        // Alphabetical by default: this command's first job is finding the exact
        // string to pass to --platform, and --sort=-games is for reading the
        // collection rather than filtering it.
        $sort = $ctx->option('sort') ?? 'platform';

        if (!isset(self::SORTS[$sort])) {
            throw new UsageException(
                "--sort={$sort} is not sortable on a platform summary. "
                . 'Available: ' . implode(', ', array_keys(self::SORTS))
            );
        }

        return self::SORTS[$sort];
    }
```

and use it in `run()`, replacing the hardcoded order:

```php
            FilterSet::forSummary($whereSql, $params, self::orderSql($ctx))
```

Add one import to the existing `use` block:

```php
use GameTracker\Cli\UsageException;
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
cd <worktree> && tests/v2/run-all.sh
```

Expected: every suite ends `0 failed`.

- [ ] **Step 5: Verify against the live collection, read-only**

The fixtures are five rows; this checks the real 898. Production credentials come
from `includes/config.php` when `GT_DB_*` is unset, and every command here is a
read:

```bash
cd <worktree>
./bin/gt --user=CammyBlack02 games platforms --physical --sort=-games --table
./bin/gt --user=CammyBlack02 games platforms --digital --sort=-games --table
```

Expected: physical rows sum to 673 with Xbox 360 = 136 first, digital rows sum to
225 with PC = 201 first, and both together reconcile to the 898 in `gt whoami`.

- [ ] **Step 6: Commit**

```bash
git add src/Cli/Commands/Games/PlatformsCommand.php tests/cli/test_gt_games.sh
git commit -m "feat(cli): sort gt games platforms by name or count

Default stays alphabetical so the command still serves its original job of
finding the string --platform wants."
```

---

## Self-Review

**Spec coverage.** Command surface → Tasks 1-3. Table and JSON output, int cast,
the documented shape break → Task 1. Selector vocabulary and paging-flag
rejection → Task 2. `--sort` with its four keys, default, and tiebreaker →
Task 3. `platformCounts` and the `platforms()` delegation → Task 1.
`compileWhere` → Task 2. `forSummary` → Task 1. Version bump → Task 1.
Absent-not-zero and the `NULL`-is-digital rule → Task 2's assertions.
Out-of-scope items are absent from every task, as intended. The spec's
"no architecture-diagram change" needs no task.

**Placeholders.** None: every code step carries the literal code, and every test
step carries the literal assertions and the expected failure text.

**Type consistency.** `platformCounts(PDO, int, FilterSet)` returning
`list<array{platform: string, games: int}>` is defined in Task 1 and called
unchanged in Task 2. `compileWhere(FilterDefinition, OptionSource)` returning
`[string, list]` is defined in Task 2 and destructured exactly that way in the
same task. `forSummary(string, array, string)` is defined in Task 1 and called
with three arguments in Tasks 1-3. `orderSql(Context)` is private to the command
and introduced in Task 3 only. `selectorNames()` and `GamesFilters::definition()`
are pre-existing and used with their real signatures.

**One deliberate wrinkle**, called out at the step itself: Task 2's `--limit`
assertion passes before its implementation step, because `allowedOptions()` is
`[]` until then. It is included there anyway so the rejection is pinned at the
moment the option list opens up.
