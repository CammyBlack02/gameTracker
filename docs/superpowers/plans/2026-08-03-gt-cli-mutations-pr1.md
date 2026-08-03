# gt CLI — Mutations PR1: writing and reversing machinery

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `gt games set` and `gt undo`, so a field can be changed on one row or many and reverted afterwards.

**Architecture:** `AssignmentSet` validates `--set-*`/`--clear-*` against a per-resource `WriteDefinition`. Row selection reuses sub-project #1's `FilterCompiler` unchanged — a single-row target is expressed as `FilterSet::forId()`. `GamesWriter` snapshots the affected rows, `JournalWriter` records their before-values outside the repo, then the `UPDATE` runs in a transaction. `gt undo` replays a journal entry backwards, refusing when a row has changed since.

**Tech Stack:** PHP 8.3 CLI, PDO/MySQL 8, PSR-4 autoloader in `src/` (no composer), bash integration tests reusing `tests/v2/lib.sh` and `tests/cli/fixtures.sh`.

**Spec:** `docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md` — read before starting.

**Scope:** This is PR1 of two. `create`, `delete`, and `items` writes are PR2 and get their own plan once the undo path has been exercised in practice.

**Worktree:** `~/worktrees/cli-mutations`, branch `feat-cli-mutations`, based on `main` at `b4d43af`. The spec is already committed there.

## Global Constraints

- **PHP 8.3.** No composer, no `vendor/`. `src/` is autoloaded by `src/autoload.php` (PSR-4, `GameTracker\` → `src/`).
- **`--yes` is required when an operation affects more than one row, or when it deletes.** Single-row `set` applies immediately.
- **Journalling is unconditional** — it does not depend on `--yes`. Every applied write is reversible.
- **Journal lives outside the repository**, at `~/.gt/journal/` mode `0700`, overridable with `GT_JOURNAL_DIR`. Anything under `/var/www/gameTracker` is inside the nginx document root and fetchable over HTTP.
- **A bulk write with no selector is refused**; `--all` is required to target every row the user owns.
- **A bulk operation is one transaction.** All matched rows change or none do. Zero matches writes no journal entry.
- **Ownership is enforced in the writer**, by a bound `user_id` on every statement. `--admin` grants cross-user reads only, never writes.
- **Write SQL is permitted only under `src/Services/Write/`.** `tests/cli/test_readonly_guard.sh` enforces this; Task 2 updates it.
- **Backtick every column name** — `condition` is a MySQL reserved word and a real column on `games`.
- **Exit codes:** `0` ok, `1` domain error, `2` usage error, `3` bootstrap/database.
- **Never write to the `gameTracker` database in tests.** Suites target `gameTracker_test` via `GT_DB_NAME`, and fixtures own the dedicated `gtfixture` user, cleaning by `user_id`.
- Run suites with `TEST_DB_USER=CammyBlack02 TEST_DB_PASS=<from ~/.my.cnf, quotes stripped>`.
- **Commits:** `type(scope): summary`, ending `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.

## Existing interfaces this plan consumes

Verified against the worktree at `b4d43af`:

- `Context`: `public readonly PDO $pdo`, `Output $output`, `?string $userRef`, `bool $http`, `array $options`; methods `option(string, ?string=null): ?string`, `flag(string): bool`, `intOption(string, int): int`. **`$options` values are `string` for `--k=v` and `true` for a bare `--k`** — `option()` returns the default for the bare form, so raw `$options` access is required to tell them apart.
- `Output`: `FORMAT_JSON`, `FORMAT_TABLE`, `format()`, `record(array)`, `rows(array, ?array)`, `warn(string)`, `error(string)`, `line(string)`.
- `FilterSet`: readonly `string $whereSql`, `array $params`, `string $orderSql`, `int $page`, `int $perPage`, `int $offset`.
- `FilterCompiler::compile(FilterDefinition, Context): FilterSet`.
- `FilterDefinition`: readonly `table`, `exact`, `like`, `booleans`, `ranges`, `missingColumns`, `sortColumns`, `defaultSort`; `flagNames(): array`.
- `GamesFilters::definition(): FilterDefinition`.
- `UserResolver::resolve(PDO, ?string): array` returning `id`, `username`, `role`.
- `UsageException` (exit 2), `GameTracker\Domain\DomainException` subclasses `NotFoundException`, `AccessDeniedException`, `BadRequestException` (exit 1).
- `Application::COMMANDS` maps full command strings to classes; `allowedOptions()` on each command is validated against, with `GLOBAL_OPTIONS = ['json','table','http','user','help','version']`.
- `tests/cli/fixtures.sh`: `FIXTURE_USER="gtfixture"`, `fixture_mysql`, `fixture_user_id`, `fixture_id <table> <title> mine|other`, `fixture_ensure_user`, `seed_games`, `seed_items`.

## File Structure

**Create:**
- `src/Write/WriteDefinition.php` — per-resource declaration of writable columns, boolean columns, non-nullable columns, and create requirements
- `src/Write/GamesWrites.php` — the `games` definition
- `src/Write/AssignmentSet.php` — validated column→value map parsed from `--set-*`/`--clear-*`
- `src/Journal/JournalEntry.php` — one journal entry as a value object
- `src/Journal/JournalWriter.php` — write, read, list, and mark entries
- `src/Services/Write/GamesWriter.php` — the only file in PR1 permitted to contain write SQL
- `src/Cli/Commands/Games/SetCommand.php`
- `src/Cli/Commands/UndoCommand.php`
- `tests/cli/test_gt_set.sh`, `tests/cli/test_gt_undo.sh`

**Modify:**
- `src/Query/FilterDefinition.php` — add `selectorNames()`
- `src/Query/FilterSet.php` — add `forId()`
- `src/Services/GamesService.php` — add `countMatching()`
- `src/Cli/Application.php` — register `games set` and `undo`
- `tests/cli/test_readonly_guard.sh` — permit write SQL under `src/Services/Write/` only (Task 2)
- `CLAUDE.md` — document the new commands (Task 4)

---

## Task 1: Assignment layer and `gt games set` dry-run

Delivers validation and the preview path. **No write SQL is added in this task**, so the read-only guard stays green untouched.

**Files:**
- Create: `src/Write/WriteDefinition.php`, `src/Write/GamesWrites.php`, `src/Write/AssignmentSet.php`, `src/Cli/Commands/Games/SetCommand.php`, `tests/cli/test_gt_set.sh`
- Modify: `src/Query/FilterDefinition.php`, `src/Query/FilterSet.php`, `src/Services/GamesService.php`, `src/Cli/Application.php`

**Interfaces:**
- Consumes: `Context`, `Output`, `UsageException`, `FilterCompiler::compile`, `GamesFilters::definition`, `UserResolver::resolve`.
- Produces:
  - `WriteDefinition::__construct(string $table, array $writable, array $booleans, array $notNull, array $requiredOnCreate)`; `flagNames(): array`; `isWritable(string): bool`; `isBoolean(string): bool`; `isNullable(string): bool`.
  - `GamesWrites::definition(): WriteDefinition`.
  - `AssignmentSet::parse(WriteDefinition $def, Context $ctx): self`; readonly `array $columns` (column → `string|int|null`); `isEmpty(): bool`; `setSql(): string` returning `` `a` = ?, `b` = ? ``; `params(): array`; `describe(): array` for output.
  - `FilterDefinition::selectorNames(): array` — flags that narrow rows, excluding `sort`/`limit`/`page`/`per-page`.
  - `FilterSet::forId(int $id): self`.
  - `GamesService::countMatching(PDO $pdo, int $userId, FilterSet $filters): int`.

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_gt_set.sh`. It covers usage errors and the bulk preview only — single-row *apply* lands in Task 2, so it is deliberately not asserted here.

```bash
#!/usr/bin/env bash
# Validation and dry-run behaviour for `gt games set`.
#
# Apply paths (single-row and bulk --yes) are asserted in Task 2, once the
# journal and writer exist. This suite proves nothing is written and that every
# malformed invocation is rejected.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
USER_FLAG="--user=$FIXTURE_USER"

GT_CODE=0
GT_OUT=""
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

GT_JSON=""
run_gt_json() {
  set +e
  GT_JSON=$("$GT" "$@" 2>/dev/null)
  GT_CODE=$?
  set -e
}

# Snapshot a column so we can prove a dry run changed nothing.
genre_of() {
  fixture_mysql -N -e "
    SELECT COALESCE(g.genre, 'NULL') FROM games g
    JOIN users u ON g.user_id = u.id
    WHERE g.title = '$1' AND u.username = '$FIXTURE_USER'
  "
}

blue "Usage errors"

run_gt games set "$USER_FLAG" --set-genre=Foo
assert_eq "2" "$GT_CODE" "bulk write with no selector = 2"
assert_contains "no selector" "$GT_OUT" "explains the missing selector"

run_gt games set "$USER_FLAG" --platform=PS2
assert_eq "2" "$GT_CODE" "nothing to set = 2"
assert_contains "nothing to set" "$GT_OUT" "says nothing was assigned"

run_gt games set "$USER_FLAG" --platform=PS2 --set-nosuchcolumn=x
assert_eq "2" "$GT_CODE" "unknown --set- column = 2"
assert_contains "nosuchcolumn" "$GT_OUT" "names the bad column"

run_gt games set "$USER_FLAG" --platform=PS2 --set-user_id=99
assert_eq "2" "$GT_CODE" "user_id is not writable = 2"

run_gt games set "$USER_FLAG" --platform=PS2 --set-id=5
assert_eq "2" "$GT_CODE" "id is not writable = 2"

run_gt games set "$USER_FLAG" --platform=PS2 --set-updated_at=now
assert_eq "2" "$GT_CODE" "updated_at is not writable = 2"

# A bare --set- flag means "true" and only makes sense on the boolean columns.
run_gt games set "$USER_FLAG" --platform=PS2 --set-title
assert_eq "2" "$GT_CODE" "valueless --set- on a non-boolean column = 2"
assert_contains "needs a value" "$GT_OUT" "explains that title needs a value"

run_gt games set "$USER_FLAG" --platform=PS2 --clear-title
assert_eq "2" "$GT_CODE" "--clear- on a NOT NULL column = 2"
assert_contains "cannot be cleared" "$GT_OUT" "explains title cannot be NULL"

run_gt games set "$USER_FLAG" --platform=PS2 --set-genre=A --clear-genre
assert_eq "2" "$GT_CODE" "setting and clearing the same column = 2"

run_gt games set 999999 "$USER_FLAG" --platform=PS2 --set-genre=A
assert_eq "2" "$GT_CODE" "an id together with a selector = 2"
assert_contains "both" "$GT_OUT" "explains id and filters are exclusive"

run_gt games set notanumber "$USER_FLAG" --set-genre=A
assert_eq "2" "$GT_CODE" "non-numeric id = 2"

blue "Dry run previews and writes nothing"

BEFORE=$(genre_of 'FIXTURE Okami')

run_gt_json games set "$USER_FLAG" --platform=PS2 --set-genre=Changed
assert_eq "0" "$GT_CODE" "bulk dry run exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 2' > /dev/null \
  && { green "  PASS: reports dry_run and the matched count"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$GT_JSON" | jq -e '.assignments.genre == "Changed"' > /dev/null \
  && { green "  PASS: preview shows the assignment"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: assignment missing: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

assert_eq "$BEFORE" "$(genre_of 'FIXTURE Okami')" "dry run changed nothing in the database"

# --all replaces a selector for whole-collection operations.
run_gt_json games set "$USER_FLAG" --all --set-genre=Changed
assert_eq "0" "$GT_CODE" "--all is accepted as a selector"
echo "$GT_JSON" | jq -e '.matched == 5' > /dev/null \
  && { green "  PASS: --all matches every row the user owns"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: --all matched wrong count: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Scoping: the other user's fixture row must never be matched.
run_gt_json games set "$USER_FLAG" --all --set-genre=X
echo "$GT_JSON" | jq -e '.matched == 5' > /dev/null \
  && { green "  PASS: another user's rows are not matched"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: scoping leak: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Zero matches"

run_gt_json games set "$USER_FLAG" --platform="NoSuchPlatform" --set-genre=A
assert_eq "0" "$GT_CODE" "zero matches exits 0"
echo "$GT_JSON" | jq -e '.matched == 0' > /dev/null \
  && { green "  PASS: reports 0 matched"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected 0 matched: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-mutations
export TEST_DB_USER=CammyBlack02
export TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')"
export GT_DB_USER="$TEST_DB_USER" GT_DB_PASS="$TEST_DB_PASS" GT_DB_NAME=gameTracker_test
bash tests/cli/test_gt_set.sh
```

Expected: every assertion fails — `games set` is not a registered command, so each run exits 2 with "unknown subcommand for 'games'".

- [ ] **Step 3: Add `selectorNames()` to `src/Query/FilterDefinition.php`**

Insert after `flagNames()`:

```php
    /**
     * Flags that narrow which rows match, excluding presentation flags.
     *
     * A write needs to know whether the caller actually restricted the row set:
     * `--limit=1` is not a selector, and treating it as one would let
     * `gt games set --limit=1 --set-played` silently target the whole table.
     *
     * @return list<string>
     */
    public function selectorNames(): array
    {
        return array_merge(
            array_keys($this->exact),
            array_keys($this->like),
            array_keys($this->booleans),
            array_keys($this->ranges),
            ['missing'],
        );
    }
```

- [ ] **Step 4: Add `forId()` to `src/Query/FilterSet.php`**

Insert after the constructor:

```php
    /**
     * A single row by id, expressed as a FilterSet so writes can reuse the same
     * plumbing as filtered selections instead of growing a second code path.
     * Paging is fixed at one row; ordering is irrelevant but must be valid SQL.
     */
    public static function forId(int $id): self
    {
        return new self('`id` = ?', [$id], '`id` ASC', 1, 1, 0);
    }
```

- [ ] **Step 5: Add `countMatching()` to `src/Services/GamesService.php`**

Insert after `list()`. This is a read and belongs on the read service, keeping the write layer free of it.

```php
    /**
     * How many of the user's games a filter matches, ignoring paging.
     *
     * Used by write commands to preview a change before applying it.
     */
    public static function countMatching(PDO $pdo, int $userId, FilterSet $filters): int
    {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE {$where}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
```

- [ ] **Step 6: Create `src/Write/WriteDefinition.php`**

```php
<?php

namespace GameTracker\Write;

/**
 * Declares which columns a resource permits writing, and how.
 *
 * Mirrors FilterDefinition: every column name that reaches SQL comes from here
 * rather than from user input, so a write cannot touch a column the resource
 * did not offer — id, user_id, created_at and updated_at are absent from every
 * writable list by design.
 */
final class WriteDefinition
{
    public function __construct(
        public readonly string $table,
        /** @var list<string> columns that may be assigned */
        public readonly array $writable,
        /** @var list<string> columns where a bare --set-<col> means 1 */
        public readonly array $booleans,
        /** @var list<string> columns that cannot be set to NULL */
        public readonly array $notNull,
        /** @var list<string> columns create() must be given */
        public readonly array $requiredOnCreate,
    ) {
    }

    public function isWritable(string $column): bool
    {
        return in_array($column, $this->writable, true);
    }

    public function isBoolean(string $column): bool
    {
        return in_array($column, $this->booleans, true);
    }

    public function isNullable(string $column): bool
    {
        return !in_array($column, $this->notNull, true);
    }

    /**
     * Every --set-/--clear- flag this resource accepts, for allowedOptions().
     * Derived so adding a writable column cannot forget to allow its flags.
     *
     * @return list<string>
     */
    public function flagNames(): array
    {
        $names = [];

        foreach ($this->writable as $column) {
            $names[] = 'set-' . $column;

            if ($this->isNullable($column)) {
                $names[] = 'clear-' . $column;
            }
        }

        return $names;
    }
}
```

- [ ] **Step 7: Create `src/Write/GamesWrites.php`**

```php
<?php

namespace GameTracker\Write;

/**
 * Writable columns for games.
 *
 * id, user_id, created_at and updated_at are deliberately absent: identity and
 * ownership are not user-assignable, and the timestamps are maintained by the
 * database (updated_at is `on update CURRENT_TIMESTAMP`, which is what makes
 * CLI writes visible to iOS delta sync).
 */
final class GamesWrites
{
    public static function definition(): WriteDefinition
    {
        return new WriteDefinition(
            table: 'games',
            writable: [
                'title', 'platform', 'genre', 'description', 'series',
                'special_edition', 'condition', 'review', 'star_rating',
                'metacritic_rating', 'played', 'price_paid',
                'pricecharting_price', 'is_physical', 'digital_store',
                'release_date', 'front_cover_image', 'back_cover_image',
            ],
            booleans: ['played', 'is_physical'],
            notNull: ['title', 'platform'],
            requiredOnCreate: ['title', 'platform'],
        );
    }
}
```

- [ ] **Step 8: Create `src/Write/AssignmentSet.php`**

```php
<?php

namespace GameTracker\Write;

use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;

/**
 * The validated set of column assignments for one write.
 *
 * Parses --set-<column>[=value] and --clear-<column> against a WriteDefinition.
 * Values are always bound; only column names from the definition are ever
 * interpolated, and they are backticked because `condition` is a reserved word.
 */
final class AssignmentSet
{
    private function __construct(
        /** @var array<string, string|int|null> column => value */
        public readonly array $columns,
    ) {
    }

    public static function parse(WriteDefinition $def, Context $ctx): self
    {
        $assignments = [];

        foreach ($ctx->options as $key => $raw) {
            if (str_starts_with($key, 'set-')) {
                $column = substr($key, 4);
                self::assertWritable($def, $column, $key);

                // A bare flag arrives as true. "set title to true" is never
                // intended, so it is only meaningful on the boolean columns.
                if ($raw === true) {
                    if (!$def->isBoolean($column)) {
                        throw new UsageException(
                            "--set-{$column} needs a value (e.g. --set-{$column}=…)"
                        );
                    }
                    $value = 1;
                } else {
                    $value = (string)$raw;
                }
            } elseif (str_starts_with($key, 'clear-')) {
                $column = substr($key, 6);
                self::assertWritable($def, $column, $key);

                if (!$def->isNullable($column)) {
                    throw new UsageException(
                        "{$column} cannot be cleared — it is NOT NULL on {$def->table}"
                    );
                }

                $value = null;
            } else {
                continue;
            }

            if (array_key_exists($column, $assignments)) {
                throw new UsageException(
                    "{$column} is assigned twice — pass either --set-{$column} or --clear-{$column}, not both"
                );
            }

            $assignments[$column] = $value;
        }

        return new self($assignments);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /**
     * The SET clause body, e.g. "`genre` = ?, `played` = ?".
     */
    public function setSql(): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => '`' . $c . '` = ?',
            array_keys($this->columns)
        ));
    }

    /**
     * @return list<string|int|null> in the same order as setSql()
     */
    public function params(): array
    {
        return array_values($this->columns);
    }

    /**
     * Assignments in a form suitable for output, with null rendered explicitly
     * so a cleared column is distinguishable from an empty string.
     */
    public function describe(): array
    {
        return $this->columns;
    }

    private static function assertWritable(WriteDefinition $def, string $column, string $flag): void
    {
        if ($column === '') {
            throw new UsageException("--{$flag} is missing a column name");
        }

        if (!$def->isWritable($column)) {
            throw new UsageException(
                "{$column} is not a writable column on {$def->table}. "
                . 'Available: ' . implode(', ', $def->writable)
            );
        }
    }
}
```

- [ ] **Step 9: Create `src/Cli/Commands/Games/SetCommand.php` (dry-run only)**

Task 2 replaces the `applyNotImplemented()` branch with the real write. Everything else here is final.

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\GamesWrites;

final class SetCommand implements Command
{
    public const NAME = 'games set';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Change fields on one game or many';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            GamesFilters::definition()->flagNames(),
            GamesWrites::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = GamesFilters::definition();
        $writeDef = GamesWrites::definition();

        $assignments = AssignmentSet::parse($writeDef, $ctx);
        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to set — pass --set-<column>=<value> or --clear-<column>'
            );
        }

        $id = $args[0] ?? null;
        $hasSelector = array_intersect(array_keys($ctx->options), $filterDef->selectorNames()) !== [];

        if ($id !== null) {
            if (!preg_match('/^\d+$/', $id)) {
                throw new UsageException("game id must be a positive integer, got '{$id}'");
            }
            if ($hasSelector) {
                throw new UsageException(
                    'pass either an id or filters, not both — filters do not narrow an id'
                );
            }
        } elseif (!$hasSelector && !$ctx->flag('all')) {
            // Without this, `gt games set --set-played` would mark an entire
            // collection played. Requiring --all makes that intent explicit.
            throw new UsageException(
                'no selector given — add a filter (see `gt games list --help`) or --all to target every game'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        $matched = GamesService::countMatching($ctx->pdo, $userId, $filters);
        $single = $id !== null;

        // --yes is required by blast radius, not by command: a single row the
        // caller named explicitly is journalled and one `gt undo` from reverted.
        $needsConfirmation = !$single && $matched > 1;

        if ($needsConfirmation && !$ctx->flag('yes')) {
            return $this->preview($ctx, $matched, $assignments);
        }

        return $this->applyNotImplemented($ctx, $matched, $assignments);
    }

    private function preview(Context $ctx, int $matched, AssignmentSet $assignments): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would update {$matched} rows");
            foreach ($assignments->describe() as $column => $value) {
                $ctx->output->line(sprintf('  %s = %s', $column, $value ?? 'NULL'));
            }
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'matched' => $matched,
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }

    /**
     * Placeholder for Task 2, which adds the journal and the writer. Reporting
     * the preview here keeps this task's deliverable coherent: validation and
     * counting work, and nothing is written.
     */
    private function applyNotImplemented(Context $ctx, int $matched, AssignmentSet $assignments): int
    {
        return $this->preview($ctx, $matched, $assignments);
    }
}
```

- [ ] **Step 10: Register the command in `src/Cli/Application.php`**

Add the import beside the other games commands:

```php
use GameTracker\Cli\Commands\Games\SetCommand as GamesSetCommand;
```

Add to `COMMANDS`, after `'games platforms'`:

```php
        'games set' => GamesSetCommand::class,
```

- [ ] **Step 11: Run the tests**

Run:
```bash
cd ~/worktrees/cli-mutations
export TEST_DB_USER=CammyBlack02
export TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')"
export GT_DB_USER="$TEST_DB_USER" GT_DB_PASS="$TEST_DB_PASS" GT_DB_NAME=gameTracker_test
bash tests/cli/test_gt_set.sh
```

Expected: all assertions pass.

Then confirm nothing regressed and the guard is still green:
```bash
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | tail -12
```

Expected: every suite passes, including `cli/test_readonly_guard.sh` — this task added no write SQL.

- [ ] **Step 12: Commit**

```bash
cd ~/worktrees/cli-mutations
git add src/Write src/Query src/Services/GamesService.php src/Cli tests/cli/test_gt_set.sh
git commit -m "$(cat <<'EOF'
feat(cli): validate assignments and preview gt games set

The assignment layer plus the dry-run half of `gt games set`. No write SQL
lands in this commit, so the read-only guard stays green and every
malformed invocation is proven rejected before anything can be applied.

WriteDefinition mirrors FilterDefinition: every column name reaching SQL
comes from the definition, never from input. id, user_id, created_at and
updated_at are absent from the writable list, so identity, ownership and
the sync timestamps cannot be assigned.

Two rules worth their code:

- A bulk write with no selector is refused; --all makes whole-collection
  intent explicit. Otherwise `gt games set --set-played` would mark an
  entire collection played.
- FilterDefinition::selectorNames() excludes paging flags, so --limit=1
  cannot masquerade as a selector and silently widen the target.

A single-row target is expressed as FilterSet::forId(), which lets writes
reuse the filter plumbing rather than growing a second code path.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Journal and the real write

Adds the only file in PR1 containing write SQL, and narrows the read-only guard to match.

**Files:**
- Create: `src/Journal/JournalEntry.php`, `src/Journal/JournalWriter.php`, `src/Services/Write/GamesWriter.php`
- Modify: `src/Cli/Commands/Games/SetCommand.php`, `tests/cli/test_readonly_guard.sh`, `tests/cli/test_gt_set.sh`

**Interfaces:**
- Consumes: `AssignmentSet`, `FilterSet`, `GamesService::countMatching`, `UserResolver::resolve`.
- Produces:
  - `JournalEntry` readonly: `string $id`, `array $argv`, `int $userId`, `string $resource`, `string $operation`, `bool $committed`, `?string $revertedAt`, `array $rows`; `toArray(): array`; `static fromArray(array): self`.
  - `JournalWriter::__construct(?string $dir = null)` — defaults to `GT_JOURNAL_DIR`, else `$HOME/.gt/journal`.
  - `JournalWriter::write(JournalEntry): string` (returns the id), `markCommitted(string $id): void`, `markReverted(string $id): void`, `read(string $id): JournalEntry`, `recent(int $limit = 20): array`, `latestRevertable(): ?JournalEntry`, `dir(): string`.
  - `GamesWriter::applySet(PDO $pdo, int $userId, FilterSet $filters, AssignmentSet $assignments, JournalWriter $journal, array $argv): array` returning `['journal_id' => string, 'matched' => int, 'changed' => int]`.
  - `GamesWriter::revertSet(PDO $pdo, JournalEntry $entry, bool $force): array` returning `['restored' => int, 'skipped' => int]`.

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_set.sh`, immediately before the final `summarize`. `GT_JOURNAL_DIR` is redirected so tests never touch the real journal.

```bash
blue "Applying writes"

export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_JOURNAL_DIR"' EXIT

OKAMI_ID=$(fixture_id games 'FIXTURE Okami' mine)

# Single row: applies immediately, no --yes required.
run_gt_json games set "$OKAMI_ID" "$USER_FLAG" --set-genre=Puzzle
assert_eq "0" "$GT_CODE" "single-row set exits 0"
assert_eq "Puzzle" "$(genre_of 'FIXTURE Okami')" "single-row set applied without --yes"
echo "$GT_JSON" | jq -e '.changed == 1 and .dry_run == false' > /dev/null \
  && { green "  PASS: reports changed=1"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# A journal file must exist for it.
assert_eq "1" "$(find "$GT_JOURNAL_DIR" -name '*-set.json' | wc -l)" "one journal entry written"
JOURNAL_FILE=$(find "$GT_JOURNAL_DIR" -name '*-set.json' | head -1)
echo "$(cat "$JOURNAL_FILE")" | jq -e '.committed == true and .operation == "set"' > /dev/null \
  && { green "  PASS: entry is marked committed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: bad entry: $(cat "$JOURNAL_FILE")"; FAIL_COUNT=$((FAIL_COUNT+1)); }
echo "$(cat "$JOURNAL_FILE")" | jq -e '.rows[0].before.genre == "Action"' > /dev/null \
  && { green "  PASS: entry records the before-value"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: before-value missing: $(cat "$JOURNAL_FILE")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Bulk without --yes still previews and writes nothing.
run_gt_json games set "$USER_FLAG" --platform=PS2 --set-genre=Nope
echo "$GT_JSON" | jq -e '.dry_run == true' > /dev/null \
  && { green "  PASS: bulk still previews without --yes"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: bulk should have previewed: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "Puzzle" "$(genre_of 'FIXTURE Okami')" "bulk preview changed nothing"

# Bulk with --yes applies to every matched row.
run_gt_json games set "$USER_FLAG" --platform=PS2 --set-genre=Bulk --yes
assert_eq "0" "$GT_CODE" "bulk --yes exits 0"
echo "$GT_JSON" | jq -e '.matched == 2 and .changed == 2' > /dev/null \
  && { green "  PASS: bulk reports matched and changed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected bulk result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "Bulk" "$(genre_of 'FIXTURE Okami')" "bulk applied to Okami"
assert_eq "Bulk" "$(genre_of 'FIXTURE Silent Hill')" "bulk applied to Silent Hill"

blue "Clearing and booleans"

run_gt games set "$OKAMI_ID" "$USER_FLAG" --clear-genre
assert_eq "0" "$GT_CODE" "--clear- exits 0"
assert_eq "NULL" "$(genre_of 'FIXTURE Okami')" "--clear- set the column to NULL"

run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-played
assert_eq "0" "$GT_CODE" "valueless boolean set exits 0"
assert_eq "1" "$(fixture_mysql -N -e "SELECT played FROM games WHERE id = $OKAMI_ID")" "--set-played wrote 1"

run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-played=0
assert_eq "0" "$(fixture_mysql -N -e "SELECT played FROM games WHERE id = $OKAMI_ID")" "--set-played=0 wrote 0"

blue "Write scoping"

OTHER_ID=$(fixture_id games 'FIXTURE Not Mine' other)
run_gt games set "$OTHER_ID" "$USER_FLAG" --set-genre=Hacked
assert_eq "1" "$GT_CODE" "writing another user's row = 1"
assert_contains "another user" "$GT_OUT" "explains the ownership failure"
assert_eq "RPG" "$(fixture_mysql -N -e "SELECT genre FROM games WHERE id = $OTHER_ID")" "the other user's row is untouched"

blue "Zero matches writes no journal entry"

BEFORE_COUNT=$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)
run_gt_json games set "$USER_FLAG" --platform="NoSuchPlatform" --set-genre=A --yes
assert_eq "0" "$GT_CODE" "zero-match apply exits 0"
assert_eq "$BEFORE_COUNT" "$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)" "no journal entry for zero matches"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/cli/test_gt_set.sh` with the environment from Task 1 Step 11.

Expected: the new assertions fail — `single-row set applied without --yes` fails because `applyNotImplemented` only previews, and no journal file is written.

- [ ] **Step 3: Create `src/Journal/JournalEntry.php`**

```php
<?php

namespace GameTracker\Journal;

/**
 * One reversible operation.
 *
 * `rows` holds a snapshot per affected row: its id, its updated_at at the time
 * of the write, and the before-values of the columns that changed. The
 * updated_at is what lets undo detect that something else has modified the row
 * since, instead of silently discarding that newer edit.
 */
final class JournalEntry
{
    public function __construct(
        public readonly string $id,
        public readonly array $argv,
        public readonly int $userId,
        public readonly string $resource,
        public readonly string $operation,
        public readonly bool $committed,
        public readonly ?string $revertedAt,
        /** @var list<array{id:int, updated_at:?string, before:array}> */
        public readonly array $rows,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'argv' => $this->argv,
            'user_id' => $this->userId,
            'resource' => $this->resource,
            'operation' => $this->operation,
            'committed' => $this->committed,
            'reverted_at' => $this->revertedAt,
            'rows' => $this->rows,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['argv'] ?? [],
            (int)$data['user_id'],
            $data['resource'],
            $data['operation'],
            (bool)($data['committed'] ?? false),
            $data['reverted_at'] ?? null,
            $data['rows'] ?? [],
        );
    }

    public function isRevertable(): bool
    {
        return $this->committed && $this->revertedAt === null;
    }
}
```

- [ ] **Step 4: Create `src/Journal/JournalWriter.php`**

```php
<?php

namespace GameTracker\Journal;

use RuntimeException;

/**
 * Reads and writes journal entries as one JSON file per operation.
 *
 * The directory lives outside the repository on purpose: everything under
 * /var/www/gameTracker is inside the nginx document root and therefore
 * fetchable over HTTP, and a journal of collection rows does not belong there.
 * GT_JOURNAL_DIR overrides it, which is how tests avoid the real journal.
 */
final class JournalWriter
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $dir ??= getenv('GT_JOURNAL_DIR') ?: null;

        if ($dir === null) {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                throw new RuntimeException('cannot locate a home directory for the journal');
            }
            $dir = $home . '/.gt/journal';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create journal directory {$dir}");
        }

        $this->dir = $dir;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * Build an id from a caller-supplied UTC timestamp and the operation name.
     * Time is passed in rather than read here so the caller controls it and the
     * value recorded matches the one reported.
     */
    public static function makeId(string $utcTimestamp, string $operation): string
    {
        return $utcTimestamp . '-' . $operation;
    }

    public function write(JournalEntry $entry): string
    {
        $this->put($entry);

        return $entry->id;
    }

    public function markCommitted(string $id): void
    {
        $entry = $this->read($id);

        $this->put(new JournalEntry(
            $entry->id,
            $entry->argv,
            $entry->userId,
            $entry->resource,
            $entry->operation,
            true,
            $entry->revertedAt,
            $entry->rows,
        ));
    }

    public function markReverted(string $id): void
    {
        $entry = $this->read($id);

        $this->put(new JournalEntry(
            $entry->id,
            $entry->argv,
            $entry->userId,
            $entry->resource,
            $entry->operation,
            $entry->committed,
            gmdate('c'),
            $entry->rows,
        ));
    }

    public function read(string $id): JournalEntry
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            throw new RuntimeException("no journal entry {$id}");
        }

        $data = json_decode((string)file_get_contents($path), true);

        if (!is_array($data)) {
            throw new RuntimeException("journal entry {$id} is not readable JSON");
        }

        return JournalEntry::fromArray($data);
    }

    /**
     * Newest first. Filenames begin with a UTC timestamp, so a reverse
     * lexicographic sort is chronological.
     *
     * @return list<JournalEntry>
     */
    public function recent(int $limit = 20): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);

        $entries = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $entries[] = JournalEntry::fromArray($data);
            }
        }

        return $entries;
    }

    public function latestRevertable(): ?JournalEntry
    {
        foreach ($this->recent(100) as $entry) {
            if ($entry->isRevertable()) {
                return $entry;
            }
        }

        return null;
    }

    private function put(JournalEntry $entry): void
    {
        $path = $this->pathFor($entry->id);
        $json = json_encode(
            $entry->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("cannot write journal entry to {$path}");
        }

        @chmod($path, 0600);
    }

    private function pathFor(string $id): string
    {
        // Ids are generated internally, but they end up in a filesystem path,
        // so refuse anything that could escape the journal directory.
        if (!preg_match('/^[0-9A-Za-z:\-]+$/', $id)) {
            throw new RuntimeException("invalid journal id '{$id}'");
        }

        return $this->dir . '/' . $id . '.json';
    }
}
```

- [ ] **Step 5: Create `src/Services/Write/GamesWriter.php`**

The only file in PR1 permitted to contain write SQL.

```php
<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterSet;
use GameTracker\Write\AssignmentSet;
use PDO;

/**
 * Mutating operations on games.
 *
 * Rules, per docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md:
 *
 *   - Every statement is scoped by a bound user_id. Ownership is enforced here
 *     rather than in the caller, so no command can forget it.
 *   - Snapshot, journal, then mutate in a transaction, then mark the entry
 *     committed. A crash leaves an uncommitted entry that undo skips, so the
 *     failure mode is "cannot undo something that may not have happened"
 *     rather than "undo applies a change that never happened".
 *   - Column names come from AssignmentSet, which validated them against a
 *     WriteDefinition. Values are always bound.
 */
final class GamesWriter
{
    /**
     * @return array{journal_id: ?string, matched: int, changed: int}
     */
    public static function applySet(
        PDO $pdo,
        int $userId,
        FilterSet $filters,
        AssignmentSet $assignments,
        JournalWriter $journal,
        array $argv
    ): array {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $columns = array_keys($assignments->columns);
        $selectList = '`id`, `updated_at`';
        foreach ($columns as $column) {
            $selectList .= ', `' . $column . '`';
        }

        $snapStmt = $pdo->prepare("SELECT {$selectList} FROM games WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            // Nothing matched: no journal entry, because there is nothing to
            // undo and an empty entry would only clutter `gt undo --list`.
            return ['journal_id' => null, 'matched' => 0, 'changed' => 0];
        }

        $rows = [];
        foreach ($snapshot as $row) {
            $before = [];
            foreach ($columns as $column) {
                $before[$column] = $row[$column];
            }

            $rows[] = [
                'id' => (int)$row['id'],
                'updated_at' => $row['updated_at'],
                'before' => $before,
            ];
        }

        $id = JournalWriter::makeId(gmdate('Y-m-d\TH-i-s\Z'), 'set');
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'games',
            'set',
            false,
            null,
            $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE games SET ' . $assignments->setSql() . " WHERE {$where}"
            );
            $stmt->execute(array_merge($assignments->params(), $params));
            $changed = $stmt->rowCount();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $journal->markCommitted($id);

        return [
            'journal_id' => $id,
            'matched' => count($rows),
            // MySQL counts rows whose values actually changed, so a write that
            // assigns a column its existing value reports fewer than matched.
            // Reported separately rather than conflated.
            'changed' => $changed,
        ];
    }

    /**
     * Restore the before-values recorded in a `set` entry.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertSet(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $check = $pdo->prepare('SELECT `updated_at` FROM games WHERE `id` = ? AND `user_id` = ?');
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    // The row no longer exists; nothing to restore into.
                    $skipped++;
                    continue;
                }

                // Something else changed this row since the write. Refuse
                // rather than discard that edit — unless explicitly forced.
                if (!$force && $current !== $row['updated_at']) {
                    $skipped++;
                    continue;
                }

                $columns = array_keys($row['before']);
                $setSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '` = ?',
                    $columns
                ));

                $params = array_values($row['before']);
                $params[] = $row['id'];
                $params[] = $entry->userId;

                $stmt = $pdo->prepare(
                    "UPDATE games SET {$setSql} WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute($params);
                $restored++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * Ownership pre-check for a single-row target, so "belongs to another user"
     * is a clear domain error rather than a silent zero-row update.
     */
    public static function assertOwned(PDO $pdo, int $userId, int $gameId): void
    {
        $stmt = $pdo->prepare('SELECT `user_id` FROM games WHERE `id` = ?');
        $stmt->execute([$gameId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== false && (int)$owner !== $userId) {
            throw new AccessDeniedException("Game {$gameId} belongs to another user");
        }
    }
}
```

- [ ] **Step 6: Wire the writer into `SetCommand`**

Replace `applyNotImplemented()` with the real apply, and add the imports. Delete the placeholder method entirely.

Add imports:

```php
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\GamesWriter;
```

Replace the `return $this->applyNotImplemented(...)` line with:

```php
        if ($single) {
            GamesWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        return $this->apply($ctx, $userId, $filters, $assignments, $matched);
```

And replace the `applyNotImplemented()` method with:

```php
    private function apply(
        Context $ctx,
        int $userId,
        FilterSet $filters,
        AssignmentSet $assignments,
        int $matched
    ): int {
        $result = GamesWriter::applySet(
            $ctx->pdo,
            $userId,
            $filters,
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'matched %d, changed %d',
                $result['matched'],
                $result['changed']
            ));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id']);
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'matched' => $result['matched'],
            'changed' => $result['changed'],
            'journal_id' => $result['journal_id'],
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }
```

- [ ] **Step 7: Narrow the read-only guard**

In `tests/cli/test_readonly_guard.sh`, replace the directory loop so writes are permitted only under `src/Services/Write/`. Replace the `for dir in src/Services src/Query; do … done` block with:

```bash
# Write SQL is permitted in exactly one place. Sub-project #2 added
# src/Services/Write/, and narrowing the guard rather than deleting it keeps the
# read layer provably read-only.
HITS=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src" \
         --include='*.php' \
         | grep -v '^[^:]*/src/Services/Write/' || true)

if [[ -z "$HITS" ]]; then
  green "  PASS: no write SQL outside src/Services/Write/"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: write SQL outside src/Services/Write/:"
  red "$HITS"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# And the permitted directory must actually contain some, or the guard is
# asserting a property of an empty set.
WRITES=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src/Services/Write" --include='*.php' || true)
if [[ -n "$WRITES" ]]; then
  green "  PASS: src/Services/Write/ contains the write SQL"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: src/Services/Write/ has no write SQL — is the guard still meaningful?"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi
```

- [ ] **Step 8: Run the tests**

Run: `bash tests/cli/test_gt_set.sh` and `bash tests/cli/test_readonly_guard.sh` with the Task 1 environment.

Expected: both pass.

- [ ] **Step 9: Negative-control the narrowed guard**

A guard that cannot fail is decoration. Prove it still catches a violation:

```bash
cd ~/worktrees/cli-mutations
cp src/Services/GamesService.php /tmp/gs.bak
printf '\n// $pdo->exec("UPDATE games SET title = ?");\n' >> src/Services/GamesService.php
bash tests/cli/test_readonly_guard.sh; echo "exit=$?"   # expect a FAIL and exit 1
cp /tmp/gs.bak src/Services/GamesService.php
bash tests/cli/test_readonly_guard.sh; echo "exit=$?"   # expect all pass, exit 0
```

Expected: the injected write is caught, and the restored file is clean.

- [ ] **Step 10: Run the full suite**

Run:
```bash
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | tail -14
```

Expected: zero failures across every suite.

- [ ] **Step 11: Commit**

```bash
cd ~/worktrees/cli-mutations
git add src/Journal src/Services/Write src/Cli tests/cli
git commit -m "$(cat <<'EOF'
feat(cli): journal every write and apply gt games set

Adds the journal and the writer, so `gt games set` now applies. Single-row
writes apply immediately; bulk still previews unless --yes is passed.

Journalling is unconditional and does not depend on --yes — that is what
makes the relaxed confirmation rule safe rather than merely convenient.
Entries live outside the repository (GT_JOURNAL_DIR, else ~/.gt/journal)
because anything under /var/www is inside the nginx document root and
fetchable over HTTP.

Each snapshotted row records its updated_at alongside the before-values,
which is what will let undo detect a concurrent edit instead of silently
discarding it.

Order is snapshot, journal, transaction, commit marker. A crash leaves an
uncommitted entry that undo skips, so the failure mode is "cannot undo
something that may not have happened" rather than the reverse.

matched and changed are reported separately: MySQL counts only rows whose
values actually differ, so assigning a column its existing value is a
no-op it does not count, and conflating the two would look like a bug.

The read-only guard is narrowed rather than deleted — write SQL is
permitted only under src/Services/Write/, and the guard now also asserts
that directory does contain some, so it cannot pass over an empty set.
Negative-controlled by injecting a write into GamesService.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `gt undo`

**Files:**
- Create: `src/Cli/Commands/UndoCommand.php`, `tests/cli/test_gt_undo.sh`
- Modify: `src/Cli/Application.php`

**Interfaces:**
- Consumes: `JournalWriter` (`recent`, `read`, `latestRevertable`, `markReverted`), `JournalEntry::isRevertable`, `GamesWriter::revertSet`.
- Produces: command registered as `undo`.

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_gt_undo.sh`.

```bash
#!/usr/bin/env bash
# `gt undo` — reverting journalled writes, and refusing when it is not safe.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
USER_FLAG="--user=$FIXTURE_USER"

export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_JOURNAL_DIR"' EXIT

GT_CODE=0
GT_OUT=""
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

GT_JSON=""
run_gt_json() {
  set +e
  GT_JSON=$("$GT" "$@" 2>/dev/null)
  GT_CODE=$?
  set -e
}

genre_of() {
  fixture_mysql -N -e "
    SELECT COALESCE(g.genre, 'NULL') FROM games g
    JOIN users u ON g.user_id = u.id
    WHERE g.title = '$1' AND u.username = '$FIXTURE_USER'
  "
}

OKAMI_ID=$(fixture_id games 'FIXTURE Okami' mine)

blue "Undo a single-row set"

assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "fixture starts as Action"
run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-genre=Changed
assert_eq "Changed" "$(genre_of 'FIXTURE Okami')" "set applied"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo exits 0"
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "undo restored the before-value"
echo "$GT_JSON" | jq -e '.restored == 1' > /dev/null \
  && { green "  PASS: reports restored=1"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected undo result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Double undo is refused"

run_gt undo "$USER_FLAG"
assert_eq "1" "$GT_CODE" "a second undo of the same entry = 1"
assert_contains "nothing to undo" "$GT_OUT" "explains there is nothing left"

blue "Undo --list"

run_gt_json undo --list "$USER_FLAG"
assert_eq "0" "$GT_CODE" "--list exits 0"
echo "$GT_JSON" | jq -e '.entries | length >= 1' > /dev/null \
  && { green "  PASS: --list shows entries"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: --list empty: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
echo "$GT_JSON" | jq -e '.entries[0].reverted_at != null' > /dev/null \
  && { green "  PASS: --list marks the reverted entry"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: reverted_at not recorded: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Bulk undo needs --yes"

run_gt games set "$USER_FLAG" --platform=PS2 --set-genre=BulkChange --yes
assert_eq "BulkChange" "$(genre_of 'FIXTURE Okami')" "bulk set applied"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "multi-row undo previews without --yes"
echo "$GT_JSON" | jq -e '.dry_run == true and .would_restore == 2' > /dev/null \
  && { green "  PASS: multi-row undo previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected a preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "BulkChange" "$(genre_of 'FIXTURE Okami')" "preview restored nothing"

run_gt_json undo "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "multi-row undo with --yes exits 0"
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "bulk undo restored Okami"
assert_eq "Horror" "$(genre_of 'FIXTURE Silent Hill')" "bulk undo restored Silent Hill"

blue "Undo refuses a row changed since"

run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-genre=First
# Simulate a web-app edit: change the row behind the CLI's back. updated_at
# has one-second resolution, so wait to guarantee a different value.
sleep 1
fixture_mysql -e "UPDATE games SET genre = 'EditedElsewhere' WHERE id = $OKAMI_ID"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo of a changed row still exits 0"
echo "$GT_JSON" | jq -e '.restored == 0 and .skipped == 1' > /dev/null \
  && { green "  PASS: refuses to clobber a newer edit"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected skipped=1: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "EditedElsewhere" "$(genre_of 'FIXTURE Okami')" "the newer edit survived"

blue "--force overrides the refusal"

JID=$(ls -1 "$GT_JOURNAL_DIR" | grep -- '-set.json' | sort | tail -1 | sed 's/\.json$//')
run_gt_json undo "$JID" "$USER_FLAG" --force
assert_eq "0" "$GT_CODE" "--force exits 0"
assert_eq "First" "$(genre_of 'FIXTURE Okami')" "--force restored over the newer edit"

blue "Unknown entry"

run_gt undo not-a-real-entry "$USER_FLAG"
assert_eq "1" "$GT_CODE" "unknown journal id = 1"

summarize
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bash tests/cli/test_gt_undo.sh` with the Task 1 environment.

Expected: fails at the first `undo` — unknown command.

- [ ] **Step 3: Create `src/Cli/Commands/UndoCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Domain\NotFoundException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\GamesWriter;

/**
 * Reverts a journalled write.
 *
 * Inherits the same confirmation rule as every other write rather than
 * special-casing itself: reverting one row applies immediately, reverting
 * several previews first. Undoing a 202-row bulk edit is a 202-row write.
 */
final class UndoCommand implements Command
{
    public const NAME = 'undo';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Revert a journalled write (see --list)';
    }

    public static function allowedOptions(): array
    {
        return ['list', 'yes', 'force'];
    }

    public function run(array $args, Context $ctx): int
    {
        $journal = new JournalWriter();

        if ($ctx->flag('list')) {
            return $this->list($ctx, $journal);
        }

        $id = $args[0] ?? null;

        $entry = $id !== null
            ? $journal->read($id)
            : $journal->latestRevertable();

        if ($entry === null) {
            throw new NotFoundException(
                'nothing to undo — no committed, unreverted journal entry found'
            );
        }

        if (!$entry->isRevertable()) {
            throw new NotFoundException(
                "nothing to undo for {$entry->id} — it is "
                . ($entry->revertedAt !== null ? 'already reverted' : 'not committed')
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        if ((int)$user['id'] !== $entry->userId) {
            throw new NotFoundException(
                "journal entry {$entry->id} belongs to a different user"
            );
        }

        $rowCount = count($entry->rows);

        if ($rowCount > 1 && !$ctx->flag('yes')) {
            return $this->preview($ctx, $entry, $rowCount);
        }

        $result = GamesWriter::revertSet($ctx->pdo, $entry, $ctx->flag('force'));

        // Only mark it reverted if something actually was. Otherwise a refusal
        // would consume the entry and leave no way to retry with --force.
        if ($result['restored'] > 0) {
            $journal->markReverted($entry->id);
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'restored %d, skipped %d',
                $result['restored'],
                $result['skipped']
            ));
            if ($result['skipped'] > 0) {
                $ctx->output->warn(
                    'skipped rows changed since the write — re-run with --force to overwrite them'
                );
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'journal_id' => $entry->id,
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
        ]);

        return 0;
    }

    private function preview(Context $ctx, JournalEntry $entry, int $rowCount): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would restore {$rowCount} rows from {$entry->id}");
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'journal_id' => $entry->id,
            'would_restore' => $rowCount,
        ]);

        return 0;
    }

    private function list(Context $ctx, JournalWriter $journal): int
    {
        $rows = [];

        foreach ($journal->recent() as $entry) {
            $rows[] = [
                'id' => $entry->id,
                'operation' => $entry->operation,
                'resource' => $entry->resource,
                'rows' => count($entry->rows),
                'committed' => $entry->committed,
                'reverted_at' => $entry->revertedAt,
            ];
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($rows);

            return 0;
        }

        $ctx->output->record(['entries' => $rows]);

        return 0;
    }
}
```

- [ ] **Step 4: Register the command**

In `src/Cli/Application.php`, add the import:

```php
use GameTracker\Cli\Commands\UndoCommand;
```

and the registry entry, after `'db info'`:

```php
        'undo' => UndoCommand::class,
```

- [ ] **Step 5: Run the tests**

Run: `bash tests/cli/test_gt_undo.sh` and `bash tests/cli/test_gt_set.sh` with the Task 1 environment.

Expected: both pass.

- [ ] **Step 6: Run the full suite**

Run:
```bash
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | tail -16
```

Expected: zero failures.

- [ ] **Step 7: Commit**

```bash
cd ~/worktrees/cli-mutations
git add src/Cli tests/cli/test_gt_undo.sh
git commit -m "$(cat <<'EOF'
feat(cli): gt undo reverts a journalled write

Reverting inherits the same confirmation rule as any other write instead
of special-casing itself: one row applies immediately, several preview
first. Undoing a 202-row bulk edit is a 202-row write.

The safety property that matters: each journalled row carries the
updated_at it had when written, and undo compares it. If the web app or
the iOS app has touched the row since, undo skips it and says so rather
than silently discarding that edit. --force overrides deliberately.

An entry is only marked reverted when something was actually restored.
Marking it on a total refusal would consume the entry and leave no way to
retry with --force.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Document and open the PR

**Files:**
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update `CLAUDE.md`**

Replace the `gt` block in the Commands section with:

```bash
# gt — the CLI (src/Cli, src/Query, src/Write, src/Journal, src/Services;
# PSR-4 via src/autoload.php). Output auto-detects: table on a terminal,
# JSON when piped. --json / --table force either.
./bin/gt help
./bin/gt whoami --user=<username|id>        # or GT_USER env
./bin/gt db info                            # target DB, schema state, ledger
./bin/gt games list --platform="PlayStation 2" --unplayed
./bin/gt games list --missing=description   # any allowlisted column
./bin/gt games get <id>
./bin/gt games platforms                    # --platform matches EXACTLY; use
                                            # this to find the stored strings
./bin/gt items list --category=Controller
./bin/gt items get <id>

# Writes (sub-project #2). --yes is required when an operation affects more
# than one row; a single row applies immediately. Every applied write is
# journalled to ~/.gt/journal (GT_JOURNAL_DIR overrides) and revertable.
./bin/gt games set <id> --set-genre=RPG
./bin/gt games set --platform="PS2" --set-platform="PlayStation 2" --yes
./bin/gt games set <id> --clear-description   # sets NULL
./bin/gt undo --list
./bin/gt undo [<journal-id>] [--yes] [--force]

# A bulk write with no selector is refused; pass --all to mean every row.
# Write SQL lives only in src/Services/Write/ — enforced by
# tests/cli/test_readonly_guard.sh.
# See docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
```

- [ ] **Step 2: Verify the whole suite one final time**

Run:
```bash
cd ~/worktrees/cli-mutations
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh > /tmp/full.log 2>&1; echo "exit=$?"
grep -c FAIL /tmp/full.log
grep -E "passed," /tmp/full.log | tail -8
```

Expected: `exit=0` and `0` FAIL lines.

- [ ] **Step 3: Lint**

```bash
cd ~/worktrees/cli-mutations
for f in bin/gt $(find src -name '*.php'); do php -l "$f" | grep -v "No syntax errors" || true; done
echo "lint done — silence above means clean"
for f in tests/cli/*.sh; do bash -n "$f" || echo "SYNTAX FAIL: $f"; done
echo "bash syntax done"
```

Expected: no output before each "done" line.

- [ ] **Step 4: Commit and push**

```bash
cd ~/worktrees/cli-mutations
git add CLAUDE.md
git commit -m "$(cat <<'EOF'
docs: document gt write commands and the journal

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
git push -u origin feat-cli-mutations
```

- [ ] **Step 5: Open the PR**

```bash
cd ~/worktrees/cli-mutations
gh pr create --base main --title "feat(cli): gt games set and gt undo (mutations PR1)" --body "$(cat <<'EOF'
## Summary

PR1 of sub-project #2: the CLI can now write, and every write is reversible.

```
gt games set 412 --set-genre=RPG                                   # applies now
gt games set --platform="PS2" --set-platform="PlayStation 2" --yes  # 121 rows
gt games set 412 --clear-description                               # sets NULL
gt undo --list
gt undo [<journal-id>] [--yes] [--force]
```

Spec: `docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md`
Plan: `docs/superpowers/plans/2026-08-03-gt-cli-mutations-pr1.md`

`create`, `delete` and `items` writes are PR2, deliberately built on an undo path proven in practice first.

## The safety model

- **`--yes` by blast radius, not by command.** More than one row, or a delete, needs confirmation. A single row named explicitly applies immediately — it is journalled and one `gt undo` from reverted, and demanding confirmation there only trains you to pass `--yes` reflexively.
- **Journalling is unconditional.** It does not depend on `--yes`. That is what makes the relaxed rule safe rather than merely convenient.
- **A bulk write with no selector is refused**; `--all` makes whole-collection intent explicit. Otherwise `gt games set --set-played` would mark an entire collection played.
- **`selectorNames()` excludes paging flags**, so `--limit=1` cannot masquerade as a selector and silently widen the target.
- **Undo compares `updated_at`.** If the web app or iOS app touched the row since, undo skips it and says so instead of discarding that edit. `--force` overrides.
- **The journal lives outside the repository** (`~/.gt/journal`, `GT_JOURNAL_DIR` overrides) because anything under `/var/www/gameTracker` is inside the nginx document root and fetchable over HTTP.

## Worth reviewing

- **`matched` and `changed` are reported separately.** MySQL counts only rows whose values actually differ, so assigning a column its existing value is a no-op it does not count. Conflating them would read as a bug.
- **An entry is only marked reverted when something was restored** — otherwise a total refusal would consume the entry and leave no way to retry with `--force`.
- **The read-only guard was narrowed, not deleted.** Write SQL is permitted only under `src/Services/Write/`, and the guard now also asserts that directory *does* contain write SQL, so it cannot pass vacuously over an empty set. Negative-controlled by injecting a write into `GamesService`.

## Test plan
- [ ] `bash tests/v2/run-all.sh` — all v2 and cli suites, zero failures
- [ ] `php -l` clean on every PHP file; `bash -n` clean on every suite
- [ ] `test_gt_set.sh` — every usage error, dry-run writes nothing, single-row applies without `--yes`, bulk requires it, `--clear-` sets NULL, valueless boolean sets 1, cross-user write refused, zero matches writes no journal entry
- [ ] `test_gt_undo.sh` — restores before-values, double-undo refused, `--list` marks reverted entries, multi-row undo previews, refuses a concurrently-modified row, `--force` overrides
- [ ] `test_readonly_guard.sh` — negative-controlled

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 6: Verify CI authoritatively**

Run `gh pr checks <n> -R CammyBlack02/gameTracker`, then confirm the run's `conclusion` is `success` via `gh run list`. A shell pipeline can exit 0 while `gh` itself failed, so check the conclusion field rather than an exit code.

---

## Self-review notes

Checked against the spec section by section.

**Covered:** commands `games set` and `undo` (Tasks 1–3); `--set-`/`--clear-`/valueless-boolean/empty-string value syntax (Task 1 Step 8); the games writable allowlist verbatim (Step 7); `--yes` by blast radius (Task 1 Step 9, Task 3 Step 3); no-selector refusal and `--all` (Step 9); one transaction per bulk operation and zero-matches-writes-no-entry (Task 2 Step 5); ownership enforced in the writer (Step 5, `assertOwned`); journal location, format, ordering, crash behaviour (Step 4); undo semantics for `set`, conflict detection, double-undo refusal (Task 3); the narrowed read-only guard with a negative control (Task 2 Steps 7 and 9); exit codes throughout; the testing list (Tasks 1–3); fixtures owning `gtfixture` (Global Constraints).

**Deliberately deferred to PR2, per the spec's Delivery shape:** `create` and `delete` for games, all `items` writes, and therefore `requiredOnCreate` (declared in `WriteDefinition` in Task 1 but unused until PR2 — declared now so the definition is complete rather than edited twice), and the tombstone-clearing behaviour on undoing a delete.

**One addition beyond the spec:** `GT_JOURNAL_DIR`. The spec fixes the journal at `~/.gt/journal`, which tests cannot safely write to. The override is test infrastructure, not a feature, and is documented as such.

**Type consistency:** verified that `AssignmentSet::parse/isEmpty/setSql/params/describe/columns`, `WriteDefinition::isWritable/isBoolean/isNullable/flagNames/writable/booleans/notNull/requiredOnCreate`, `FilterSet::forId`, `FilterDefinition::selectorNames`, `GamesService::countMatching`, `JournalWriter::makeId/write/read/recent/latestRevertable/markCommitted/markReverted/dir`, `JournalEntry::toArray/fromArray/isRevertable` and `GamesWriter::applySet/revertSet/assertOwned` are spelled identically everywhere they appear across tasks.

**One gap found and closed during review:** Task 3's test needed a `sleep 1` before simulating an external edit. `updated_at` has one-second resolution, so without it the conflict-detection assertion could pass or fail depending on timing — a flaky test asserting the single most important safety property.
