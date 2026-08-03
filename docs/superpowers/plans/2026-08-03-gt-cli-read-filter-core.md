# gt CLI — Read + Filter Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `gt games list/get/platforms` and `gt items list/get` with an allowlisted filter vocabulary, on a read-only service layer, so the collection can be queried without the web app.

**Architecture:** A per-resource `FilterDefinition` declares which flags exist and which columns they may touch. `FilterCompiler` turns parsed flags into a parameterised `WHERE` fragment (`FilterSet`), which services consume to run `SELECT`s and return arrays. The CLI layer parses argv, dispatches noun-verb commands, and renders either a table or JSON. `api/games.php` is not touched.

**Tech Stack:** PHP 8.3 CLI, PDO/MySQL 8, PSR-4 autoloader in `src/` (no composer), bash integration tests reusing `tests/v2/lib.sh`.

**Spec:** `docs/superpowers/specs/2026-08-03-gt-cli-design.md` — read before starting.

**Worktree:** `~/worktrees/cli-phase-b`, branch `feat-cli-phase-b`, stacked on PR #80 (`feat-cli-phase-a`). Uncommitted Phase A-era files exist in `src/Services/` and `src/Domain/` — Task 2 replaces `src/Services/GamesService.php` wholesale; `src/Domain/*` is kept as-is.

## Global Constraints

- **PHP 8.3.** No composer, no `vendor/`, no third-party dependencies. `src/` is autoloaded by the existing `src/autoload.php` (PSR-4, `GameTracker\` → `src/`).
- **Read-only.** No `INSERT`, `UPDATE`, `DELETE`, `REPLACE`, `ALTER`, `DROP` or `TRUNCATE` anywhere in `src/Services/` or `src/Query/`. Enforced by a test in Task 6.
- **Explicit identity.** Every service method takes `int $userId` and binds it. No `$_SESSION`, `$_GET`, `$_POST`, `php://input`, `header()`, `echo` or `exit()` in `src/Services/` or `src/Query/`.
- **Noun-verb grammar.** `gt games list`, not `gt games:list`.
- **Exit codes:** `0` success, `1` domain error, `2` usage error, `3` bootstrap/database.
- **Stream discipline.** Data on STDOUT, warnings and errors on STDERR, so `| jq` never breaks.
- **Filters are AND-only**, against a per-resource column allowlist. Unknown flag or unknown column is a usage error.
- **Paging defaults:** page 1, per-page 100, hard cap 1000. **Default sort:** `-created_at`.
- **`api/games.php` and every other `api/` file stay untouched.**
- **Backtick every column name** in generated SQL — `condition` is a MySQL reserved word and is a real column on both tables.
- **Commits:** `type(scope): summary`, ending with `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>`.
- **Tests cannot run locally** unless `GRANT ALL ON gameTracker_test.* TO 'CammyBlack02'@'localhost'` has been granted. If `bash tests/v2/run-all.sh` fails with `Access denied for user 'root'`, that grant is missing — push and let CI run, and say so rather than claiming tests passed.

## Reference: actual columns (verified 2026-08-03)

`games`: `id, title, platform, genre, description, series, special_edition, condition, review, star_rating, metacritic_rating, played, price_paid, pricecharting_price, is_physical, digital_store, front_cover_image, back_cover_image, created_at, updated_at, release_date, user_id`

`items`: `id, title, platform, category, description, condition, price_paid, pricecharting_price, front_image, back_image, notes, quantity, created_at, updated_at, user_id`

`played` and `is_physical` are `int`, nullable — so "false" must match `0 OR NULL`, not just `0`.

## File Structure

**Create:**
- `src/Cli/UsageException.php` — thrown for bad flags/columns; maps to exit 2
- `src/Query/FilterSet.php` — compiled result: where fragment, params, order, paging
- `src/Query/FilterDefinition.php` — per-resource declaration of legal flags and columns
- `src/Query/FilterCompiler.php` — `FilterDefinition` + options → `FilterSet`
- `src/Query/GamesFilters.php` — the `games` definition
- `src/Query/ItemsFilters.php` — the `items` definition
- `src/Services/ItemsService.php` — `list`, `get`
- `src/Cli/Commands/Games/ListCommand.php`, `GetCommand.php`, `PlatformsCommand.php`
- `src/Cli/Commands/Items/ListCommand.php`, `GetCommand.php`
- `tests/cli/fixtures.sh` — seeds deterministic rows into `gameTracker_test`
- `tests/cli/test_gt_games.sh`, `tests/cli/test_gt_items.sh`, `tests/cli/test_readonly_guard.sh`

**Modify:**
- `src/Cli/Output.php` — TTY detection
- `src/Cli/Application.php` — two-token dispatch, `UsageException` handling, new registry
- `src/Cli/Context.php` — `intOption` throws `UsageException`
- `src/Cli/Commands/DbInfoCommand.php` — name becomes `db info`
- `src/Cli/Commands/WhoamiCommand.php` — add `allowedOptions()`
- `src/Services/GamesService.php` — replaced: `list` takes a `FilterSet`
- `tests/cli/test_gt.sh` — `db info` rename, TTY expectations
- `CLAUDE.md` — commands section

---

## Task 1: Grammar, TTY output, and usage errors

Reworks Phase A's single-token dispatch into noun-verb, makes output TTY-aware, and introduces the usage-error type the filter layer needs.

**Files:**
- Create: `src/Cli/UsageException.php`
- Modify: `src/Cli/Output.php`, `src/Cli/Application.php`, `src/Cli/Context.php`, `src/Cli/Commands/DbInfoCommand.php`, `src/Cli/Commands/WhoamiCommand.php`
- Test: `tests/cli/test_gt.sh`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `GameTracker\Cli\UsageException extends RuntimeException` — caught by `Application`, mapped to exit 2.
  - `Output::__construct(?string $format = null)` — `null` means auto-detect.
  - `Output::rows(array $rows, ?array $columns = null)`, `Output::record(array $data)`, `Output::warn/error/line(string)` — unchanged signatures.
  - `Context::intOption(string $name, int $default): int` — now throws `UsageException`.
  - `Command::allowedOptions(): array` — already declared on the interface.
  - `Application::COMMANDS` keys are full command strings: `'whoami'`, `'db info'`.

- [ ] **Step 1: Write the failing test**

Replace the `No-database invocations` and `Usage errors` blocks in `tests/cli/test_gt.sh`, and change every `db:info` to `db info`. The full updated file:

```bash
#!/usr/bin/env bash
# Integration tests for bin/gt.
#
# Reuses tests/v2/lib.sh for assertion helpers and counters. These tests do not
# need the PHP dev server — gt talks to the database in-process — but they do
# need the GT_DB_* environment run-all.sh exports, pointing at gameTracker_test.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-design.md
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

# lib.sh runs under `set -e`, so a non-zero exit from gt would abort the script
# before we could assert on it. Capture both streams and the code deliberately.
GT_OUT=""
GT_CODE=0
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

# Stdout only. Used for every jq assertion: combining streams would let a
# STDERR warning corrupt otherwise-valid JSON and produce a confusing failure.
GT_JSON=""
run_gt_json() {
  set +e
  GT_JSON=$("$GT" "$@" 2>/dev/null)
  GT_CODE=$?
  set -e
}

# --- Invocations that must work with no database at all
blue "No-database invocations"

run_gt --version
assert_eq "0" "$GT_CODE" "--version exits 0"
assert_contains "^gt [0-9]" "$GT_OUT" "--version prints a version"

run_gt help
assert_eq "0" "$GT_CODE" "help exits 0"
assert_contains "games list" "$GT_OUT" "help lists games list"
assert_contains "db info" "$GT_OUT" "help lists db info"

run_gt
assert_eq "2" "$GT_CODE" "bare gt is a usage error"

# --- Usage errors
blue "Usage errors"

run_gt nosuchcommand
assert_eq "2" "$GT_CODE" "unknown command = 2"
assert_contains "unknown command" "$GT_OUT" "names the problem"

# A known resource with an unknown verb should list the verbs that do exist,
# rather than the generic unknown-command message.
run_gt games nosuchverb
assert_eq "2" "$GT_CODE" "unknown subcommand = 2"
assert_contains "games" "$GT_OUT" "mentions the resource"

run_gt --bogus-flag whoami
assert_eq "2" "$GT_CODE" "unknown option = 2"

run_gt --http whoami
assert_eq "2" "$GT_CODE" "--http refuses until a later sub-project"
assert_contains "not implemented" "$GT_OUT" "explains why --http failed"

# --- whoami
blue "whoami"

run_gt whoami
assert_eq "1" "$GT_CODE" "ambiguous user = 1"
assert_contains "multiple users" "$GT_OUT" "refuses to guess between users"

run_gt_json whoami --user="$TEST_USER"
assert_eq "0" "$GT_CODE" "explicit --user resolves"
echo "$GT_JSON" | jq -e '.username == "'"$TEST_USER"'"' > /dev/null \
  && { green "  PASS: reports the requested username"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: username mismatch: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$GT_JSON" | jq -e '.games | type == "number"' > /dev/null \
  && { green "  PASS: games count is numeric"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: games not numeric: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt whoami --user=ghost_user_that_does_not_exist
assert_eq "1" "$GT_CODE" "unknown user = 1"
assert_contains "no such user" "$GT_OUT" "names the missing user"

# --- db info
blue "db info"

run_gt_json db info
assert_eq "0" "$GT_CODE" "db info exits 0"

echo "$GT_JSON" | jq -e '.database == "'"${GT_DB_NAME:-gameTracker_test}"'"' > /dev/null \
  && { green "  PASS: reports the database it is pointed at"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: wrong database: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# setup-test-db.sh bootstraps the schema via migrate.php, so unlike production
# the test database genuinely has a populated ledger.
echo "$GT_JSON" | jq -e '.ledger_present == true and .migrations_applied >= 1' > /dev/null \
  && { green "  PASS: migration ledger present and populated"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: ledger state unexpected: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The old colon form must be gone, not silently aliased.
run_gt db:info
assert_eq "2" "$GT_CODE" "db:info is no longer a command"

# --- Output discipline
blue "Output discipline"

# Captured output is not a TTY, so JSON is the auto-detected default. This both
# guards the pipe-safety property and proves TTY detection picks JSON here.
set +e
"$GT" db info 2>/dev/null | jq -e . > /dev/null
JQ_CODE=$?
set -e
assert_eq "0" "$JQ_CODE" "non-TTY output auto-detects JSON and parses"

run_gt db info --table
assert_eq "0" "$GT_CODE" "--table renders without error"
assert_contains "database" "$GT_OUT" "table output includes the database row"

summarize
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -30`

Expected: `cli/test_gt.sh` fails — `help lists games list` fails, `db info` is an unknown command, and `db:info` still succeeds.

If instead the run dies with `Access denied for user 'root'`, the local grant is missing (see Global Constraints). Push the branch and read CI's log for `cli/test_gt.sh` instead.

- [ ] **Step 3: Create `src/Cli/UsageException.php`**

```php
<?php

namespace GameTracker\Cli;

use RuntimeException;

/**
 * The caller typed something wrong: unknown flag, unknown column, malformed
 * value. Application maps this to exit 2, keeping it distinct from a domain
 * error (exit 1) where the request was well-formed but could not be satisfied.
 */
final class UsageException extends RuntimeException
{
}
```

- [ ] **Step 4: Add TTY detection to `src/Cli/Output.php`**

Replace the constructor and add a resolver. Everything else in the file stays as it is.

```php
    public const FORMAT_JSON = 'json';
    public const FORMAT_TABLE = 'table';

    private readonly string $format;

    /**
     * @param string|null $format Explicit --json/--table, or null to auto-detect.
     */
    public function __construct(?string $format = null)
    {
        // A human at a terminal wants a table; a pipe or redirect wants JSON.
        // Auto-detection means neither audience has to remember a flag, and it
        // is why the test suite (which captures output) gets JSON for free.
        $this->format = $format ?? (stream_isatty(STDOUT) ? self::FORMAT_TABLE : self::FORMAT_JSON);
    }
```

- [ ] **Step 5: Make `Context::intOption` throw `UsageException`**

In `src/Cli/Context.php`, change the import and the throw:

```php
use GameTracker\Cli\UsageException;
```

```php
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new UsageException("--{$name} must be an integer, got '{$raw}'");
        }
```

Remove the now-unused `use GameTracker\Domain\BadRequestException;` line.

- [ ] **Step 6: Rewrite `src/Cli/Application.php` for two-token dispatch**

```php
<?php

namespace GameTracker\Cli;

use GameTracker\Cli\Commands\DbInfoCommand;
use GameTracker\Cli\Commands\WhoamiCommand;
use GameTracker\Domain\DomainException;
use PDO;
use Throwable;

/**
 * Argument parsing and command dispatch for bin/gt.
 */
final class Application
{
    public const VERSION = '0.2.0';

    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_BOOTSTRAP = 3;

    /**
     * Keys are full command strings. Dispatch prefers the two-token match, so
     * "games list" wins over any single-token "games", which is what makes the
     * noun-verb grammar work without a nested registry.
     *
     * @var array<string, class-string<Command>>
     */
    private const COMMANDS = [
        'whoami' => WhoamiCommand::class,
        'db info' => DbInfoCommand::class,
    ];

    /** Global flags consumed here, never passed to commands. */
    private const GLOBAL_OPTIONS = ['json', 'table', 'http', 'user', 'help', 'version'];

    /**
     * Handle the invocations that must work without a database — `gt help`,
     * `gt --version`, and a bare `gt`. bin/gt calls this before requiring
     * config.php, so a broken or unconfigured connection still leaves the CLI
     * self-documenting instead of failing to explain itself.
     *
     * @return int|null Exit code if handled, null to continue with a database.
     */
    public static function earlyExit(array $argv): ?int
    {
        array_shift($argv);

        $wantsHelp = false;
        $positional = [];

        foreach ($argv as $arg) {
            if ($arg === '--version' || $arg === '-V') {
                fwrite(STDOUT, 'gt ' . self::VERSION . "\n");
                return self::EXIT_OK;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                $wantsHelp = true;
                continue;
            }
            if (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
            }
        }

        if ($wantsHelp && $positional === []) {
            self::usage();
            return self::EXIT_OK;
        }

        if ($argv === []) {
            self::usage();
            return self::EXIT_USAGE;
        }

        return null;
    }

    public static function main(array $argv, ?PDO $pdo): int
    {
        array_shift($argv); // script name

        $format = null; // null => Output auto-detects
        $userRef = getenv('GT_USER') ?: null;
        $http = false;
        $args = [];
        $options = [];

        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '-')) {
                $args[] = $arg;
                continue;
            }

            switch (true) {
                case $arg === '--json':
                    $format = Output::FORMAT_JSON;
                    break;
                case $arg === '--table':
                    $format = Output::FORMAT_TABLE;
                    break;
                case $arg === '--http':
                    $http = true;
                    break;
                case $arg === '-h' || $arg === '--help':
                    $args[] = 'help';
                    break;
                case $arg === '--version' || $arg === '-V':
                    fwrite(STDOUT, 'gt ' . self::VERSION . "\n");
                    return self::EXIT_OK;
                case str_starts_with($arg, '--user='):
                    $userRef = substr($arg, strlen('--user='));
                    break;
                case str_starts_with($arg, '--'):
                    // Command-specific option. Validated against the resolved
                    // command's allowedOptions() below, so a typo is an error
                    // rather than a silently ignored flag.
                    $body = substr($arg, 2);
                    if (str_contains($body, '=')) {
                        [$name, $value] = explode('=', $body, 2);
                        $options[$name] = $value;
                    } else {
                        $options[$body] = true;
                    }
                    break;
                default:
                    fwrite(STDERR, "error: unknown option '{$arg}'\n");
                    return self::EXIT_USAGE;
            }
        }

        $output = new Output($format);

        if ($args === [] || $args[0] === 'help') {
            self::usage();
            return $args === [] ? self::EXIT_USAGE : self::EXIT_OK;
        }

        [$name, $args] = self::resolve($args);

        if ($name === null) {
            self::reportUnknown($output, $args);
            return self::EXIT_USAGE;
        }

        /** @var class-string<Command> $class */
        $class = self::COMMANDS[$name];

        $unknown = array_diff(array_keys($options), $class::allowedOptions(), self::GLOBAL_OPTIONS);
        if ($unknown !== []) {
            $output->error(
                "unknown option '--" . reset($unknown) . "' for `gt {$name}`. "
                . 'Run `gt help` for the list.'
            );
            return self::EXIT_USAGE;
        }

        if ($http) {
            $output->error('--http is not implemented yet (planned for a later sub-project).');
            return self::EXIT_USAGE;
        }

        if (!$pdo instanceof PDO) {
            $output->error('no database connection available — check includes/config.php');
            return self::EXIT_BOOTSTRAP;
        }

        $ctx = new Context($pdo, $output, $userRef, $http, $options);

        try {
            return (new $class())->run($args, $ctx);
        } catch (UsageException $e) {
            $output->error($e->getMessage());
            return self::EXIT_USAGE;
        } catch (DomainException $e) {
            $output->error($e->getMessage());
            return self::EXIT_ERROR;
        } catch (Throwable $e) {
            // Full detail is fine here: the CLI runs for the operator, unlike an
            // HTTP response body where CLAUDE.md forbids leaking exception text.
            $output->error($e->getMessage());
            return self::EXIT_ERROR;
        }
    }

    /**
     * Two-token match first, then single-token.
     *
     * @return array{0: string|null, 1: array} Command name (null if unknown) and remaining args.
     */
    private static function resolve(array $args): array
    {
        if (count($args) >= 2) {
            $two = $args[0] . ' ' . $args[1];
            if (isset(self::COMMANDS[$two])) {
                return [$two, array_slice($args, 2)];
            }
        }

        if (isset(self::COMMANDS[$args[0]])) {
            return [$args[0], array_slice($args, 1)];
        }

        return [null, $args];
    }

    /**
     * A known resource with an unknown verb is a different mistake from an
     * unknown resource, and deserves a different message: list the verbs that
     * do exist rather than making the user run `gt help` to find them.
     */
    private static function reportUnknown(Output $output, array $args): void
    {
        $resource = $args[0];
        $verbs = [];

        foreach (array_keys(self::COMMANDS) as $command) {
            if (str_starts_with($command, $resource . ' ')) {
                $verbs[] = substr($command, strlen($resource) + 1);
            }
        }

        if ($verbs !== []) {
            $output->error(
                "unknown subcommand for '{$resource}'. Available: " . implode(', ', $verbs)
            );
            return;
        }

        $output->error("unknown command '{$resource}'. Run `gt help` for the list.");
    }

    private static function usage(): void
    {
        $lines = [
            'gt ' . self::VERSION . ' — gameTracker command line',
            '',
            'Usage: gt [global options] <command> [args] [filters]',
            '',
            'Commands:',
        ];

        foreach (self::COMMANDS as $name => $class) {
            $lines[] = sprintf('  %-16s %s', $name, $class::description());
        }

        $lines = array_merge($lines, [
            '  help             Show this message',
            '',
            'Global options:',
            '  --json           Force machine-readable output',
            '  --table          Force human-readable table output',
            '                   (default: table on a terminal, JSON when piped)',
            '  --user=<ref>     Act as this user (username or id). Env: GT_USER',
            '  --http           Drive api/v2 over HTTP instead of in-process (not yet)',
            '  --version, -V    Print version',
            '',
            'Exit codes: 0 ok, 1 error, 2 usage, 3 bootstrap/database',
        ]);

        fwrite(STDOUT, implode("\n", $lines) . "\n");
    }
}
```

- [ ] **Step 7: Rename the `db:info` command**

In `src/Cli/Commands/DbInfoCommand.php`, change the constant and add the options method:

```php
    public const NAME = 'db info';
```

```php
    public static function allowedOptions(): array
    {
        return [];
    }
```

- [ ] **Step 8: Add `allowedOptions()` to `WhoamiCommand`**

In `src/Cli/Commands/WhoamiCommand.php`:

```php
    public static function allowedOptions(): array
    {
        return [];
    }
```

- [ ] **Step 9: Run the tests**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -40`

Expected: `cli/test_gt.sh` passes every assertion, and no other suite regresses.

- [ ] **Step 10: Commit**

```bash
cd ~/worktrees/cli-phase-b
git add src/Cli tests/cli/test_gt.sh
git commit -m "$(cat <<'EOF'
refactor(cli): noun-verb grammar and TTY-aware output

Phase A shipped db:info and a JSON-always default before either was
decided. The approved spec settles both: noun-verb grammar (gt db info)
and output that auto-detects — a table on a terminal, JSON when piped —
so neither a human nor an agent has to remember a flag.

Dispatch now prefers a two-token match over a single-token one, which is
what lets "games list" coexist with "whoami" without a nested registry.
A known resource with an unknown verb now lists the verbs that do exist.

Adds UsageException so a mistyped flag or column exits 2 while a
well-formed but unsatisfiable request still exits 1.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Filter layer and `gt games list`

The core of this sub-project. Introduces the filter compiler, replaces `GamesService`, and ships `gt games list` with the full flag vocabulary.

**Files:**
- Create: `src/Query/FilterSet.php`, `src/Query/FilterDefinition.php`, `src/Query/FilterCompiler.php`, `src/Query/GamesFilters.php`, `src/Cli/Commands/Games/ListCommand.php`, `tests/cli/fixtures.sh`
- Modify: `src/Services/GamesService.php` (replace wholesale), `src/Cli/Application.php` (register the command)
- Test: `tests/cli/test_gt_games.sh`

**Interfaces:**
- Consumes: `UsageException`, `Context::option/intOption/flag`, `Output::rows`, `UserResolver::resolve`.
- Produces:
  - `FilterSet` with readonly `string $whereSql` (no leading `AND`, `''` when empty), `array $params`, `string $orderSql`, `int $page`, `int $perPage`, `int $offset`.
  - `FilterDefinition::__construct(string $table, array $exact, array $like, array $booleans, array $ranges, array $missingColumns, array $sortColumns, string $defaultSort)`.
  - `FilterDefinition::flagNames(): array` — every flag it accepts, for `allowedOptions()`.
  - `FilterCompiler::compile(FilterDefinition $def, Context $ctx): FilterSet`.
  - `GamesFilters::definition(): FilterDefinition`.
  - `GamesService::list(PDO $pdo, int $userId, FilterSet $filters): array` returning `['games' => list<array>, 'pagination' => array]`.

- [ ] **Step 1: Write the fixture helper**

Create `tests/cli/fixtures.sh`. Assertions use titles rather than ids, because auto-increment ids are not stable across runs.

```bash
#!/usr/bin/env bash
# Deterministic fixture rows for the CLI suites.
#
# setup-test-db.sh seeds users only, so each suite that needs collection data
# inserts its own. Values are chosen so every filter has both a match and a
# non-match, and so one row belongs to a second user to prove scoping.
#
# Requires: GT_DB_NAME, GT_DB_USER, GT_DB_PASS, GT_DB_HOST (exported by run-all.sh)

fixture_mysql() {
  local host_flag=""
  if [[ -n "${GT_DB_HOST:-}" ]]; then
    host_flag="-h${GT_DB_HOST}"
  fi
  mysql $host_flag -u"${GT_DB_USER:-root}" ${GT_DB_PASS:+-p"$GT_DB_PASS"} "${GT_DB_NAME:-gameTracker_test}" "$@"
}

# Resolve user ids by username so fixtures do not assume auto-increment values.
fixture_user_id() {
  fixture_mysql -N -e "SELECT id FROM users WHERE username = '$1'"
}

seed_games() {
  local uid other
  uid=$(fixture_user_id "${TEST_USER:-testuser}")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '${TEST_USER:-testuser}' ORDER BY id LIMIT 1")

  fixture_mysql -e "DELETE FROM games WHERE title LIKE 'FIXTURE %'"

  # played/is_physical are nullable ints, so NULL is used deliberately on one
  # row to prove --unplayed and --digital match NULL as well as 0.
  fixture_mysql -e "
    INSERT INTO games
      (user_id, title, platform, genre, series, description, star_rating,
       played, is_physical, front_cover_image, created_at)
    VALUES
      ($uid, 'FIXTURE Halo 3',      'Xbox 360', 'FPS',    'Halo', 'desc', 5,    1,    1,    'a.jpg', '2026-01-10 00:00:00'),
      ($uid, 'FIXTURE Silent Hill', 'PS2',      'Horror', NULL,   NULL,   NULL, 0,    1,    NULL,    '2026-02-15 00:00:00'),
      ($uid, 'FIXTURE Okami',       'PS2',      'Action', NULL,   '',     4,    NULL, 0,    NULL,    '2026-03-20 00:00:00'),
      ($uid, 'FIXTURE Halo Reach',  'Xbox 360', 'FPS',    'Halo', 'desc', 3,    1,    NULL, 'b.jpg', '2026-04-01 00:00:00'),
      ($uid, 'FIXTURE Journey',     'PS3',      NULL,     NULL,   NULL,   5,    1,    0,    NULL,    '2026-05-05 00:00:00'),
      ($other, 'FIXTURE Not Mine',  'PC',       'RPG',    NULL,   'desc', 5,    1,    1,    'c.jpg', '2026-06-06 00:00:00');
  "
}

seed_items() {
  local uid other
  uid=$(fixture_user_id "${TEST_USER:-testuser}")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '${TEST_USER:-testuser}' ORDER BY id LIMIT 1")

  fixture_mysql -e "DELETE FROM items WHERE title LIKE 'FIXTURE %'"

  fixture_mysql -e "
    INSERT INTO items
      (user_id, title, platform, category, description, quantity, created_at)
    VALUES
      ($uid, 'FIXTURE Dual Shock',  'PS2',      'Controller', 'desc', 2, '2026-01-11 00:00:00'),
      ($uid, 'FIXTURE Memory Card', 'PS2',      'Storage',    NULL,   1, '2026-02-12 00:00:00'),
      ($uid, 'FIXTURE Xbox Pad',    'Xbox 360', 'Controller', '',     1, '2026-03-13 00:00:00'),
      ($other, 'FIXTURE Not Mine Item', 'PC',   'Cable',      'desc', 1, '2026-04-14 00:00:00');
  "
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/cli/test_gt_games.sh`.

```bash
#!/usr/bin/env bash
# Filter behaviour for `gt games list`.
#
# Assertions compare sorted title lists rather than ids: auto-increment values
# are not stable across runs, titles are.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
USER_FLAG="--user=${TEST_USER:-testuser}"

seed_games

GT_CODE=0
GT_OUT=""
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

# Sorted, comma-joined titles with the FIXTURE prefix stripped, so expectations
# read as "Halo 3,Okami" rather than as raw JSON.
titles() {
  set +e
  "$GT" games list "$USER_FLAG" "$@" 2>/dev/null \
    | jq -r '.games[].title' \
    | sed 's/^FIXTURE //' \
    | sort \
    | paste -sd, -
  set -e
}

assert_titles() {
  local expected="$1"; shift
  local label="$1"; shift
  local actual
  actual=$(titles "$@")
  assert_eq "$expected" "$actual" "$label"
}

blue "Scoping"
# The other user's row must never appear, with or without filters.
assert_titles "Halo 3,Halo Reach,Journey,Okami,Silent Hill" "only the caller's games are listed"
run_gt games list "$USER_FLAG"
assert_eq "0" "$GT_CODE" "games list exits 0"

blue "Exact-match filters"
assert_titles "Okami,Silent Hill" "--platform=PS2" --platform=PS2
assert_titles "Halo 3,Halo Reach" "--genre=FPS" --genre=FPS
assert_titles "Halo 3,Halo Reach" "--series=Halo" --series=Halo

blue "Substring filter"
assert_titles "Halo 3,Halo Reach" "--title-like=Halo" --title-like=Halo
assert_titles "Okami" "--title-like=kam" --title-like=kam

blue "Boolean filters"
assert_titles "Halo 3,Halo Reach,Journey" "--played" --played
# Okami has played = NULL, so --unplayed must match NULL as well as 0.
assert_titles "Okami,Silent Hill" "--unplayed matches 0 and NULL" --unplayed
assert_titles "Halo 3,Silent Hill" "--physical" --physical
# Halo Reach has is_physical = NULL.
assert_titles "Halo Reach,Journey,Okami" "--digital matches 0 and NULL" --digital

blue "Range filters"
assert_titles "Halo 3,Journey,Okami" "--rating-min=4" --rating-min=4
assert_titles "Halo Reach,Okami" "--rating-max=4" --rating-max=4
assert_titles "Halo 3,Journey" "--rating-min=5" --rating-min=5

blue "Date filters"
assert_titles "Halo Reach,Journey,Okami" "--added-since" --added-since=2026-03-01
assert_titles "Halo 3,Silent Hill" "--added-before" --added-before=2026-03-01

blue "--missing"
# Silent Hill and Journey have NULL descriptions; Okami has an empty string.
assert_titles "Journey,Okami,Silent Hill" "--missing=description covers NULL and ''" --missing=description
assert_titles "Journey,Okami,Silent Hill" "--missing=front_cover_image" --missing=front_cover_image
assert_titles "Journey" "--missing=genre" --missing=genre

blue "Combined filters are ANDed"
assert_titles "Okami,Silent Hill" "--platform=PS2 --unplayed" --platform=PS2 --unplayed
assert_titles "Halo 3" "--series=Halo --rating-min=5" --series=Halo --rating-min=5
assert_titles "" "contradictory filters return nothing" --platform=PS2 --genre=FPS

blue "Sorting and paging"
assert_eq "Halo 3" "$("$GT" games list "$USER_FLAG" --sort=title --limit=1 2>/dev/null | jq -r '.games[0].title' | sed 's/^FIXTURE //')" "--sort=title ascending"
assert_eq "Silent Hill" "$("$GT" games list "$USER_FLAG" --sort=-title --limit=1 2>/dev/null | jq -r '.games[0].title' | sed 's/^FIXTURE //')" "--sort=-title descending"
assert_eq "2" "$("$GT" games list "$USER_FLAG" --limit=2 2>/dev/null | jq '.games | length')" "--limit caps rows"
assert_eq "5" "$("$GT" games list "$USER_FLAG" 2>/dev/null | jq '.pagination.total')" "pagination.total counts all matches"
assert_eq "2" "$("$GT" games list "$USER_FLAG" --per-page=3 2>/dev/null | jq '.pagination.total_pages')" "total_pages reflects per-page"
assert_eq "2" "$("$GT" games list "$USER_FLAG" --per-page=3 --page=2 2>/dev/null | jq '.games | length')" "page 2 returns the remainder"

blue "Usage errors"
run_gt games list "$USER_FLAG" --missing=not_a_column
assert_eq "2" "$GT_CODE" "unknown --missing column = 2"
assert_contains "not_a_column" "$GT_OUT" "names the bad column"

run_gt games list "$USER_FLAG" --sort=not_a_column
assert_eq "2" "$GT_CODE" "unknown --sort column = 2"

run_gt games list "$USER_FLAG" --page=abc
assert_eq "2" "$GT_CODE" "non-integer --page = 2"

run_gt games list "$USER_FLAG" --nonsense-flag
assert_eq "2" "$GT_CODE" "unknown flag = 2"

# user_id must not be filterable — that would be the cross-user override the
# list endpoint had removed as an IDOR (Fable §1).
run_gt games list "$USER_FLAG" --missing=user_id
assert_eq "2" "$GT_CODE" "user_id is not an allowlisted column"

blue "Table output"
run_gt games list "$USER_FLAG" --table --limit=1
assert_eq "0" "$GT_CODE" "--table renders"
assert_contains "title" "$GT_OUT" "table has a title header"

summarize
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd ~/worktrees/cli-phase-b && bash tests/cli/test_gt_games.sh`

Expected: fails immediately — `games list` is not a registered command, so every assertion returns empty.

- [ ] **Step 4: Create `src/Query/FilterSet.php`**

```php
<?php

namespace GameTracker\Query;

/**
 * A compiled filter: the SQL fragment plus everything needed to run it.
 *
 * Immutable and transport-free. Services splice $whereSql into their own query
 * and bind $params; nothing here knows about the CLI or HTTP.
 */
final class FilterSet
{
    public function __construct(
        /** Conditions joined by AND, with no leading AND and no WHERE keyword. Empty when unfiltered. */
        public readonly string $whereSql,
        /** Positional parameters for $whereSql, in order. */
        public readonly array $params,
        /** Ready-to-splice ORDER BY body, e.g. "`created_at` DESC". */
        public readonly string $orderSql,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $offset,
    ) {
    }
}
```

- [ ] **Step 5: Create `src/Query/FilterDefinition.php`**

```php
<?php

namespace GameTracker\Query;

/**
 * Declares which filter flags a resource accepts and which columns they may
 * touch.
 *
 * Every column that reaches SQL comes from this object, never from user input,
 * which is what makes injection structurally impossible rather than merely
 * avoided. It is also why filters are per-resource: `gt items list --played`
 * should be a usage error, not a query against a column items does not have.
 */
final class FilterDefinition
{
    public function __construct
    (
        public readonly string $table,
        /** flag => column, matched with = */
        public readonly array $exact,
        /** flag => column, matched with LIKE %value% */
        public readonly array $like,
        /** flag => [column, bool] — true means "= 1", false means "= 0 OR IS NULL" */
        public readonly array $booleans,
        /** flag => [column, operator] */
        public readonly array $ranges,
        /** columns permitted as --missing=<column> */
        public readonly array $missingColumns,
        /** columns permitted as --sort=<column> */
        public readonly array $sortColumns,
        /** default sort, "-" prefix for descending */
        public readonly string $defaultSort,
    ) {
    }

    /**
     * Every flag this resource accepts, for the command's allowedOptions().
     * Keeping this derived means adding a filter cannot forget to allow it.
     *
     * @return list<string>
     */
    public function flagNames(): array
    {
        return array_merge(
            array_keys($this->exact),
            array_keys($this->like),
            array_keys($this->booleans),
            array_keys($this->ranges),
            ['missing', 'sort', 'limit', 'page', 'per-page'],
        );
    }
}
```

- [ ] **Step 6: Create `src/Query/FilterCompiler.php`**

```php
<?php

namespace GameTracker\Query;

use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;

/**
 * Turns parsed CLI options into a FilterSet.
 *
 * Column names are looked up in the FilterDefinition and backticked; values are
 * always bound. `condition` is a MySQL reserved word and a real column on both
 * tables, which is why quoting is unconditional rather than case-by-case.
 */
final class FilterCompiler
{
    public const DEFAULT_PER_PAGE = 100;
    public const MAX_PER_PAGE = 1000;

    public static function compile(FilterDefinition $def, Context $ctx): FilterSet
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
            // "0 or never set" — otherwise rows with NULL vanish from both
            // --played and --unplayed, which reads as data loss to the user.
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
                    "--missing=" . $missing . " is not a filterable column on {$def->table}. "
                    . 'Available: ' . implode(', ', $def->missingColumns)
                );
            }
            $conditions[] = '(' . self::quote($missing) . ' IS NULL OR ' . self::quote($missing) . " = '')";
        }

        return new FilterSet(
            implode(' AND ', $conditions),
            $params,
            self::orderSql($def, $ctx),
            ...self::paging($ctx)
        );
    }

    private static function orderSql(FilterDefinition $def, Context $ctx): string
    {
        $raw = $ctx->option('sort') ?? $def->defaultSort;

        $descending = str_starts_with($raw, '-');
        $column = $descending ? substr($raw, 1) : $raw;

        if (!in_array($column, $def->sortColumns, true)) {
            throw new UsageException(
                "--sort={$raw} is not a sortable column on {$def->table}. "
                . 'Available: ' . implode(', ', $def->sortColumns)
            );
        }

        return self::quote($column) . ($descending ? ' DESC' : ' ASC');
    }

    /**
     * @return array{0: int, 1: int, 2: int} page, perPage, offset
     */
    private static function paging(Context $ctx): array
    {
        // --limit is the interactive shorthand for --per-page.
        $perPage = $ctx->intOption('limit', $ctx->intOption('per-page', self::DEFAULT_PER_PAGE));
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $ctx->intOption('page', 1));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private static function quote(string $column): string
    {
        return '`' . $column . '`';
    }
}
```

- [ ] **Step 7: Create `src/Query/GamesFilters.php`**

Note `user_id` appears in no list — it must not be filterable, or the cross-user override removed as an IDOR would return through the back door.

```php
<?php

namespace GameTracker\Query;

/**
 * The games filter vocabulary.
 *
 * user_id is deliberately absent from every list. Filtering by it would
 * reintroduce the cross-user override that was removed from the list endpoint
 * as an IDOR (Fable §1); scoping belongs to the service's explicit $userId.
 */
final class GamesFilters
{
    public static function definition(): FilterDefinition
    {
        return new FilterDefinition(
            table: 'games',
            exact: [
                'platform' => 'platform',
                'genre' => 'genre',
                'series' => 'series',
                'condition' => 'condition',
                'digital-store' => 'digital_store',
            ],
            like: [
                'title-like' => 'title',
            ],
            booleans: [
                'played' => ['played', true],
                'unplayed' => ['played', false],
                'physical' => ['is_physical', true],
                'digital' => ['is_physical', false],
            ],
            ranges: [
                'rating-min' => ['star_rating', '>='],
                'rating-max' => ['star_rating', '<='],
                'added-since' => ['created_at', '>='],
                'added-before' => ['created_at', '<'],
            ],
            missingColumns: [
                'title', 'platform', 'genre', 'description', 'series',
                'special_edition', 'condition', 'review', 'star_rating',
                'metacritic_rating', 'price_paid', 'pricecharting_price',
                'digital_store', 'front_cover_image', 'back_cover_image',
                'release_date',
            ],
            sortColumns: [
                'id', 'title', 'platform', 'genre', 'series', 'star_rating',
                'metacritic_rating', 'price_paid', 'created_at', 'updated_at',
                'release_date',
            ],
            defaultSort: '-created_at',
        );
    }
}
```

- [ ] **Step 8: Replace `src/Services/GamesService.php`**

```php
<?php

namespace GameTracker\Services;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Query\FilterSet;
use PDO;

/**
 * Read paths for the games collection.
 *
 * Rules, per docs/superpowers/specs/2026-08-03-gt-cli-design.md:
 *
 *   - No $_GET / $_POST / $_SESSION / php://input. Input arrives as arguments.
 *   - No header() / echo / exit(). Results are returned, failures are thrown.
 *   - SELECT only. A CI test enforces this.
 *   - Identity is an explicit int $userId, always bound, so the "every query is
 *     user-scoped" invariant from CLAUDE.md is visible in the signature.
 *
 * Row shaping matches what api/games.php produces, so a later sub-project can
 * swap that endpoint onto this service without the frontend noticing.
 */
final class GamesService
{
    /** Columns api/games.php's list action selected, in its order. */
    private const LIST_COLUMNS = '`id`, `title`, `platform`, `genre`, `series`,
                   `special_edition`, `condition`, `star_rating`,
                   `metacritic_rating`, `played`, `price_paid`,
                   `pricecharting_price`, `is_physical`, `digital_store`,
                   `front_cover_image`, `back_cover_image`, `created_at`,
                   `updated_at`';

    /**
     * @return array{games: list<array>, pagination: array}
     */
    public static function list(PDO $pdo, int $userId, FilterSet $filters): array
    {
        // The caller's id is bound first and unconditionally; filters can only
        // ever narrow the result, never widen it past this user.
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = $total > 0 ? (int)ceil($total / $filters->perPage) : 1;

        // LIMIT/OFFSET cannot be bound in MySQL. Both are clamped ints from
        // FilterCompiler, never raw input.
        $sql = 'SELECT ' . self::LIST_COLUMNS . ', 0 AS extra_image_count
                FROM games
                WHERE ' . $where . '
                ORDER BY ' . $filters->orderSql . '
                LIMIT ' . $filters->perPage . ' OFFSET ' . $filters->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $games = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $games[] = self::normaliseListRow($row);
        }

        return [
            'games' => $games,
            'pagination' => [
                'page' => $filters->page,
                'per_page' => $filters->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page < $totalPages,
            ],
        ];
    }

    /**
     * A single game with its extra images.
     *
     * $isAdmin reproduces the endpoint's admin override, which could read any
     * user's game. Passing it explicitly keeps that escalation visible at the
     * call site instead of being resolved in here from ambient state.
     */
    public static function get(PDO $pdo, int $userId, int $gameId, bool $isAdmin = false): array
    {
        if ($gameId <= 0) {
            throw new BadRequestException('Game ID is required');
        }

        $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            throw new NotFoundException("No game with id {$gameId}");
        }

        // Kept separate from the lookup so "does not exist" and "not yours"
        // stay distinguishable, matching the endpoint's 404/403 split.
        if (!$isAdmin && (int)$game['user_id'] !== $userId) {
            throw new AccessDeniedException("Game {$gameId} belongs to another user");
        }

        $imagesStmt = $pdo->prepare(
            'SELECT * FROM game_images WHERE game_id = ? ORDER BY uploaded_at DESC'
        );
        $imagesStmt->execute([$gameId]);
        $game['extra_images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        return self::normaliseDetailRow($game);
    }

    /**
     * Distinct non-empty platform names for one user.
     *
     * Unlike the endpoint this has no global mode: the endpoint's unfiltered
     * branch returned every user's platforms for dropdown suggestions, which is
     * a web-form concern with no CLI equivalent. Scoped only, matching the
     * user-scoping invariant.
     *
     * @return list<string>
     */
    public static function platforms(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT `platform` FROM games
             WHERE `user_id` = ? AND `platform` IS NOT NULL AND `platform` != ''
             ORDER BY `platform`"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function normaliseListRow(array $row): array
    {
        $row['played'] = (bool)$row['played'];
        $row['is_physical'] = (bool)$row['is_physical'];
        $row['star_rating'] = $row['star_rating'] !== null ? (int)$row['star_rating'] : null;
        $row['metacritic_rating'] = $row['metacritic_rating'] !== null
            ? (int)$row['metacritic_rating']
            : null;

        foreach (['genre', 'series', 'special_edition'] as $field) {
            if (empty($row[$field])) {
                $row[$field] = null;
            }
        }

        return $row;
    }

    /**
     * Narrower than the list variant on purpose: the endpoint's detail action
     * did not collapse empty genre/series/special_edition to null.
     */
    private static function normaliseDetailRow(array $row): array
    {
        $row['played'] = (bool)$row['played'];
        $row['is_physical'] = (bool)$row['is_physical'];
        $row['star_rating'] = $row['star_rating'] !== null ? (int)$row['star_rating'] : null;
        $row['metacritic_rating'] = $row['metacritic_rating'] !== null
            ? (int)$row['metacritic_rating']
            : null;

        return $row;
    }
}
```

- [ ] **Step 9: Create `src/Cli/Commands/Games/ListCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;

final class ListCommand implements Command
{
    public const NAME = 'games list';

    /** Columns worth showing in a terminal; JSON always carries the full row. */
    private const TABLE_COLUMNS = ['id', 'title', 'platform', 'played', 'star_rating'];

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List games, with filters';
    }

    public static function allowedOptions(): array
    {
        return GamesFilters::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(GamesFilters::definition(), $ctx);

        $result = GamesService::list($ctx->pdo, (int)$user['id'], $filters);

        if ($ctx->output->format() === 'table') {
            $ctx->output->rows($result['games'], self::TABLE_COLUMNS);
            $ctx->output->line(sprintf(
                '(page %d of %d, %d total)',
                $result['pagination']['page'],
                $result['pagination']['total_pages'],
                $result['pagination']['total']
            ));
            return 0;
        }

        $ctx->output->record($result);

        return 0;
    }
}
```

- [ ] **Step 10: Register the command**

In `src/Cli/Application.php`, add the import and the registry entry:

```php
use GameTracker\Cli\Commands\Games\ListCommand as GamesListCommand;
```

```php
        'games list' => GamesListCommand::class,
```

- [ ] **Step 11: Wire the new suite into the runner**

`tests/v2/run-all.sh` already globs `tests/cli/test_*.sh`, so `test_gt_games.sh` is picked up automatically. Confirm with:

Run: `grep -n "tests/cli" ~/worktrees/cli-phase-b/tests/v2/run-all.sh`

Expected: the `for test in "$PROJECT_ROOT"/tests/cli/test_*.sh` loop is present.

- [ ] **Step 12: Run the tests**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -60`

Expected: `cli/test_gt_games.sh` passes every assertion; `cli/test_gt.sh` still passes.

- [ ] **Step 13: Commit**

```bash
cd ~/worktrees/cli-phase-b
git add src/Query src/Services/GamesService.php src/Cli tests/cli
git commit -m "$(cat <<'EOF'
feat(cli): gt games list with an allowlisted filter vocabulary

Adds the filter layer and the first real query command. FilterCompiler
turns parsed flags into a parameterised WHERE fragment using column names
drawn from a per-resource FilterDefinition, so a column name can never
originate in user input — injection is structurally impossible rather
than merely avoided, and an unknown column is a usage error.

--missing=<column> generalises the enrichment case, so backfilling a new
field later needs no new flag.

Two details worth recording:

- played and is_physical are nullable ints, so --unplayed and --digital
  match "0 OR NULL". Matching only 0 would silently hide rows whose value
  was never set, which reads as data loss.
- user_id appears in no allowlist. Filtering by it would reintroduce the
  cross-user override removed from the list endpoint as an IDOR
  (Fable §1); scoping stays the service's bound $userId.

Column names are backticked unconditionally because `condition` is a
MySQL reserved word and a real column on both games and items.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: `gt games get` and `gt games platforms`

**Files:**
- Create: `src/Cli/Commands/Games/GetCommand.php`, `src/Cli/Commands/Games/PlatformsCommand.php`
- Modify: `src/Cli/Application.php`
- Test: `tests/cli/test_gt_games.sh` (append)

**Interfaces:**
- Consumes: `GamesService::get(PDO, int, int, bool)`, `GamesService::platforms(PDO, int)`.
- Produces: commands registered as `games get` and `games platforms`.

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_games.sh`, immediately before the final `summarize`:

```bash
blue "games get"

FIXTURE_ID=$(fixture_mysql -N -e "SELECT id FROM games WHERE title = 'FIXTURE Okami' LIMIT 1")
OTHER_ID=$(fixture_mysql -N -e "SELECT id FROM games WHERE title = 'FIXTURE Not Mine' LIMIT 1")

assert_eq "FIXTURE Okami" "$("$GT" games get "$FIXTURE_ID" "$USER_FLAG" 2>/dev/null | jq -r '.title')" "games get returns the row"
assert_eq "true" "$("$GT" games get "$FIXTURE_ID" "$USER_FLAG" 2>/dev/null | jq -e '.extra_images | type == "array"' 2>/dev/null && echo true)" "games get includes extra_images"

run_gt games get 999999 "$USER_FLAG"
assert_eq "1" "$GT_CODE" "missing game = 1"
assert_contains "999999" "$GT_OUT" "names the missing id"

# Another user's game must be refused, and refused differently from missing.
run_gt games get "$OTHER_ID" "$USER_FLAG"
assert_eq "1" "$GT_CODE" "another user's game = 1"
assert_contains "another user" "$GT_OUT" "distinguishes denied from missing"

run_gt games get "$USER_FLAG"
assert_eq "2" "$GT_CODE" "games get with no id = 2"

run_gt games get notanumber "$USER_FLAG"
assert_eq "2" "$GT_CODE" "non-numeric id = 2"

blue "games platforms"

assert_eq "PS2,PS3,Xbox 360" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq -r '.platforms[]' | sort | paste -sd, -)" "platforms are distinct, sorted, and scoped"
# PC belongs only to the other user's fixture row.
assert_eq "0" "$("$GT" games platforms "$USER_FLAG" 2>/dev/null | jq '[.platforms[] | select(. == "PC")] | length')" "platforms excludes other users"
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd ~/worktrees/cli-phase-b && bash tests/cli/test_gt_games.sh 2>&1 | tail -25`

Expected: the new `games get` and `games platforms` assertions fail — both are unknown subcommands.

- [ ] **Step 3: Create `src/Cli/Commands/Games/GetCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\GamesService;

final class GetCommand implements Command
{
    public const NAME = 'games get';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show one game by id, with its extra images';
    }

    public static function allowedOptions(): array
    {
        return ['admin'];
    }

    public function run(array $args, Context $ctx): int
    {
        $id = $args[0] ?? null;

        if ($id === null) {
            throw new UsageException('usage: gt games get <id>');
        }

        if (!preg_match('/^\d+$/', $id)) {
            throw new UsageException("game id must be a positive integer, got '{$id}'");
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        // --admin opts into the endpoint's cross-user read, and only works for
        // a user whose role actually is admin.
        $isAdmin = $ctx->flag('admin') && ($user['role'] ?? '') === 'admin';

        $game = GamesService::get($ctx->pdo, (int)$user['id'], (int)$id, $isAdmin);

        $ctx->output->record($game);

        return 0;
    }
}
```

- [ ] **Step 4: Create `src/Cli/Commands/Games/PlatformsCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\GamesService;

final class PlatformsCommand implements Command
{
    public const NAME = 'games platforms';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List the distinct platforms in your collection';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $platforms = GamesService::platforms($ctx->pdo, (int)$user['id']);

        if ($ctx->output->format() === 'table') {
            $ctx->output->rows(array_map(
                static fn(string $p): array => ['platform' => $p],
                $platforms
            ));
            return 0;
        }

        $ctx->output->record(['platforms' => $platforms]);

        return 0;
    }
}
```

- [ ] **Step 5: Register both commands**

In `src/Cli/Application.php`:

```php
use GameTracker\Cli\Commands\Games\GetCommand as GamesGetCommand;
use GameTracker\Cli\Commands\Games\PlatformsCommand as GamesPlatformsCommand;
```

```php
        'games get' => GamesGetCommand::class,
        'games platforms' => GamesPlatformsCommand::class,
```

- [ ] **Step 6: Run the tests**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -50`

Expected: all `cli/` suites pass.

- [ ] **Step 7: Commit**

```bash
cd ~/worktrees/cli-phase-b
git add src/Cli tests/cli/test_gt_games.sh
git commit -m "$(cat <<'EOF'
feat(cli): gt games get and gt games platforms

games get keeps the endpoint's 404-vs-403 distinction — "no game with id
N" and "belongs to another user" are different messages, because
collapsing them would make a permissions problem look like a typo.

The cross-user admin read is opt-in via --admin and additionally requires
the resolved user's role to be admin, so the escalation cannot happen by
accident.

games platforms is scoped to the caller, unlike the endpoint's default
branch which returned every user's platforms for dropdown suggestions.
That is a web-form concern with no CLI equivalent.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: Items

**Files:**
- Create: `src/Query/ItemsFilters.php`, `src/Services/ItemsService.php`, `src/Cli/Commands/Items/ListCommand.php`, `src/Cli/Commands/Items/GetCommand.php`, `tests/cli/test_gt_items.sh`
- Modify: `src/Cli/Application.php`

**Interfaces:**
- Consumes: `FilterCompiler::compile`, `FilterDefinition`, `FilterSet`, `UserResolver::resolve`.
- Produces: `ItemsFilters::definition(): FilterDefinition`; `ItemsService::list(PDO, int, FilterSet): array` returning `['items' => …, 'pagination' => …]`; `ItemsService::get(PDO, int, int, bool): array`; commands `items list`, `items get`.

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_gt_items.sh`.

```bash
#!/usr/bin/env bash
# Filter behaviour for `gt items list` and `gt items get`.
#
# items has its own column set — no played, no star_rating, but it does have
# category — so its filter allowlist is deliberately not the games one.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
USER_FLAG="--user=${TEST_USER:-testuser}"

seed_items

GT_CODE=0
GT_OUT=""
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

titles() {
  set +e
  "$GT" items list "$USER_FLAG" "$@" 2>/dev/null \
    | jq -r '.items[].title' \
    | sed 's/^FIXTURE //' \
    | sort \
    | paste -sd, -
  set -e
}

assert_titles() {
  local expected="$1"; shift
  local label="$1"; shift
  assert_eq "$expected" "$(titles "$@")" "$label"
}

blue "items list"
assert_titles "Dual Shock,Memory Card,Xbox Pad" "only the caller's items are listed"
assert_titles "Dual Shock,Memory Card" "--platform=PS2" --platform=PS2
assert_titles "Dual Shock,Xbox Pad" "--category=Controller" --category=Controller
assert_titles "Dual Shock" "--title-like=Dual" --title-like=Dual
assert_titles "Memory Card,Xbox Pad" "--missing=description covers NULL and ''" --missing=description
assert_titles "Dual Shock" "filters are ANDed" --platform=PS2 --category=Controller

run_gt items list "$USER_FLAG"
assert_eq "0" "$GT_CODE" "items list exits 0"

blue "items filters are per-resource"
# played exists on games, not items. It must be rejected, not ignored.
run_gt items list "$USER_FLAG" --played
assert_eq "2" "$GT_CODE" "--played is not an items filter"

run_gt items list "$USER_FLAG" --missing=star_rating
assert_eq "2" "$GT_CODE" "star_rating is not an items column"

run_gt items list "$USER_FLAG" --sort=played
assert_eq "2" "$GT_CODE" "played is not an items sort column"

blue "items get"
ITEM_ID=$(fixture_mysql -N -e "SELECT id FROM items WHERE title = 'FIXTURE Dual Shock' LIMIT 1")
OTHER_ITEM=$(fixture_mysql -N -e "SELECT id FROM items WHERE title = 'FIXTURE Not Mine Item' LIMIT 1")

assert_eq "FIXTURE Dual Shock" "$("$GT" items get "$ITEM_ID" "$USER_FLAG" 2>/dev/null | jq -r '.title')" "items get returns the row"

run_gt items get 999999 "$USER_FLAG"
assert_eq "1" "$GT_CODE" "missing item = 1"

run_gt items get "$OTHER_ITEM" "$USER_FLAG"
assert_eq "1" "$GT_CODE" "another user's item = 1"
assert_contains "another user" "$GT_OUT" "distinguishes denied from missing"

summarize
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd ~/worktrees/cli-phase-b && bash tests/cli/test_gt_items.sh 2>&1 | tail -20`

Expected: fails — `items list` is not a registered command.

- [ ] **Step 3: Create `src/Query/ItemsFilters.php`**

```php
<?php

namespace GameTracker\Query;

/**
 * The items filter vocabulary.
 *
 * Deliberately not the games vocabulary: items has category and quantity but
 * no played, is_physical or star_rating. Sharing one global filter set would
 * make `gt items list --played` silently match nothing instead of telling the
 * caller the flag does not apply here.
 *
 * user_id is absent for the same reason as in GamesFilters.
 */
final class ItemsFilters
{
    public static function definition(): FilterDefinition
    {
        return new FilterDefinition(
            table: 'items',
            exact: [
                'platform' => 'platform',
                'category' => 'category',
                'condition' => 'condition',
            ],
            like: [
                'title-like' => 'title',
            ],
            booleans: [],
            ranges: [
                'added-since' => ['created_at', '>='],
                'added-before' => ['created_at', '<'],
            ],
            missingColumns: [
                'title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'front_image',
                'back_image', 'notes', 'quantity',
            ],
            sortColumns: [
                'id', 'title', 'platform', 'category', 'price_paid',
                'quantity', 'created_at', 'updated_at',
            ],
            defaultSort: '-created_at',
        );
    }
}
```

- [ ] **Step 4: Create `src/Services/ItemsService.php`**

```php
<?php

namespace GameTracker\Services;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Query\FilterSet;
use PDO;

/**
 * Read paths for items (accessories). Same rules as GamesService: SELECT only,
 * explicit bound $userId, no transport concerns.
 */
final class ItemsService
{
    private const LIST_COLUMNS = '`id`, `title`, `platform`, `category`,
                   `description`, `condition`, `price_paid`,
                   `pricecharting_price`, `quantity`, `front_image`,
                   `back_image`, `notes`, `created_at`, `updated_at`';

    /**
     * @return array{items: list<array>, pagination: array}
     */
    public static function list(PDO $pdo, int $userId, FilterSet $filters): array
    {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = $total > 0 ? (int)ceil($total / $filters->perPage) : 1;

        $sql = 'SELECT ' . self::LIST_COLUMNS . '
                FROM items
                WHERE ' . $where . '
                ORDER BY ' . $filters->orderSql . '
                LIMIT ' . $filters->perPage . ' OFFSET ' . $filters->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::normalise($row);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $filters->page,
                'per_page' => $filters->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page < $totalPages,
            ],
        ];
    }

    public static function get(PDO $pdo, int $userId, int $itemId, bool $isAdmin = false): array
    {
        if ($itemId <= 0) {
            throw new BadRequestException('Item ID is required');
        }

        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new NotFoundException("No item with id {$itemId}");
        }

        if (!$isAdmin && (int)$item['user_id'] !== $userId) {
            throw new AccessDeniedException("Item {$itemId} belongs to another user");
        }

        $imagesStmt = $pdo->prepare(
            'SELECT * FROM item_images WHERE item_id = ? ORDER BY id DESC'
        );
        $imagesStmt->execute([$itemId]);
        $item['extra_images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        return self::normalise($item);
    }

    private static function normalise(array $row): array
    {
        $row['quantity'] = $row['quantity'] !== null ? (int)$row['quantity'] : null;

        return $row;
    }
}
```

- [ ] **Step 5: Create `src/Cli/Commands/Items/ListCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\ItemsFilters;
use GameTracker\Services\ItemsService;

final class ListCommand implements Command
{
    public const NAME = 'items list';

    private const TABLE_COLUMNS = ['id', 'title', 'platform', 'category', 'quantity'];

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List items (accessories), with filters';
    }

    public static function allowedOptions(): array
    {
        return ItemsFilters::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(ItemsFilters::definition(), $ctx);

        $result = ItemsService::list($ctx->pdo, (int)$user['id'], $filters);

        if ($ctx->output->format() === 'table') {
            $ctx->output->rows($result['items'], self::TABLE_COLUMNS);
            $ctx->output->line(sprintf(
                '(page %d of %d, %d total)',
                $result['pagination']['page'],
                $result['pagination']['total_pages'],
                $result['pagination']['total']
            ));
            return 0;
        }

        $ctx->output->record($result);

        return 0;
    }
}
```

- [ ] **Step 6: Create `src/Cli/Commands/Items/GetCommand.php`**

```php
<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\ItemsService;

final class GetCommand implements Command
{
    public const NAME = 'items get';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show one item by id';
    }

    public static function allowedOptions(): array
    {
        return ['admin'];
    }

    public function run(array $args, Context $ctx): int
    {
        $id = $args[0] ?? null;

        if ($id === null) {
            throw new UsageException('usage: gt items get <id>');
        }

        if (!preg_match('/^\d+$/', $id)) {
            throw new UsageException("item id must be a positive integer, got '{$id}'");
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $isAdmin = $ctx->flag('admin') && ($user['role'] ?? '') === 'admin';

        $item = ItemsService::get($ctx->pdo, (int)$user['id'], (int)$id, $isAdmin);

        $ctx->output->record($item);

        return 0;
    }
}
```

- [ ] **Step 7: Register both commands**

In `src/Cli/Application.php`:

```php
use GameTracker\Cli\Commands\Items\GetCommand as ItemsGetCommand;
use GameTracker\Cli\Commands\Items\ListCommand as ItemsListCommand;
```

```php
        'items list' => ItemsListCommand::class,
        'items get' => ItemsGetCommand::class,
```

- [ ] **Step 8: Run the tests**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -60`

Expected: all three `cli/` suites pass.

- [ ] **Step 9: Commit**

```bash
cd ~/worktrees/cli-phase-b
git add src tests/cli/test_gt_items.sh
git commit -m "$(cat <<'EOF'
feat(cli): gt items list and gt items get

Accessories, reusing the filter layer with their own allowlist. items has
category and quantity but no played, is_physical or star_rating, so its
FilterDefinition is deliberately different from games'. A shared global
filter set would make `gt items list --played` match nothing silently
instead of reporting that the flag does not apply.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: Read-only guard and documentation

Makes the read-only guarantee mechanical, and documents the commands.

**Files:**
- Create: `tests/cli/test_readonly_guard.sh`
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing consumed by later tasks in this plan. The guard is what a later mutation sub-project must consciously amend.

- [ ] **Step 1: Write the test**

Create `tests/cli/test_readonly_guard.sh`. This one is expected to pass on first run — it asserts a property the earlier tasks already established, and exists to stop a future change from breaking it silently.

```bash
#!/usr/bin/env bash
# The read + filter core is read-only by construction.
#
# Sub-project #1 promises that no CLI command can alter collection data. That
# promise is only worth something if it is mechanical, so this test greps the
# service and query layers for write statements instead of trusting review.
#
# When a mutation sub-project lands, it must consciously amend this test rather
# than discover it by accident — that is the point.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Read-only guard"

# Word boundaries matter: "updated_at" and "deleted_at" are legitimate column
# names and must not trip the UPDATE/DELETE patterns.
WRITE_PATTERN='\b(INSERT[[:space:]]+INTO|UPDATE[[:space:]]+[a-z`]|DELETE[[:space:]]+FROM|REPLACE[[:space:]]+INTO|ALTER[[:space:]]+TABLE|DROP[[:space:]]+(TABLE|DATABASE|TRIGGER)|TRUNCATE)\b'

for dir in src/Services src/Query; do
  if [[ ! -d "$PROJECT_ROOT/$dir" ]]; then
    red "  FAIL: $dir does not exist"
    FAIL_COUNT=$((FAIL_COUNT+1))
    continue
  fi

  HITS=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/$dir" || true)

  if [[ -z "$HITS" ]]; then
    green "  PASS: $dir contains no write statements"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $dir contains write SQL:"
    red "$HITS"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
done

# Guard the guard: if the pattern stopped matching real write SQL, the checks
# above would pass vacuously.
PROBE=$(printf 'INSERT INTO games (title) VALUES ("x");\nUPDATE `games` SET title = "y";\nDELETE FROM games;\n')
PROBE_HITS=$(echo "$PROBE" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "3" "$PROBE_HITS" "the write pattern still matches real write SQL"

# And confirm it does not fire on legitimate column names.
BENIGN=$(printf 'ORDER BY `updated_at` DESC\nSELECT `created_at` FROM games\n')
BENIGN_HITS=$(echo "$BENIGN" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "0" "$BENIGN_HITS" "the write pattern ignores updated_at/created_at"

summarize
```

- [ ] **Step 2: Run it**

Run: `cd ~/worktrees/cli-phase-b && bash tests/cli/test_readonly_guard.sh`

Expected: 4 assertions pass. If a service genuinely contains write SQL, that is a real finding — stop and report it rather than weakening the pattern.

- [ ] **Step 3: Update `CLAUDE.md`**

Replace the `gt` block in the Commands section with:

```bash
# gt — the CLI (src/Cli, src/Query, src/Services; PSR-4 via src/autoload.php).
# Talks to the database in-process. Output auto-detects: table on a terminal,
# JSON when piped. --json / --table force either.
# Read-only as of sub-project #1 — enforced by tests/cli/test_readonly_guard.sh.
./bin/gt help
./bin/gt whoami --user=<username|id>        # or GT_USER env
./bin/gt db info                            # target DB, schema state, ledger
./bin/gt games list --platform=PS2 --unplayed
./bin/gt games list --missing=description   # any allowlisted column
./bin/gt games get <id>
./bin/gt games platforms
./bin/gt items list --category=Controller
./bin/gt items get <id>

# Filters are AND-only, per-resource, and allowlisted: an unknown flag or
# column exits 2 rather than being ignored. See
# docs/superpowers/specs/2026-08-03-gt-cli-design.md
```

- [ ] **Step 4: Run the full suite**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -60`

Expected: every suite passes, `cli/` and `v2/` alike.

- [ ] **Step 5: Commit**

```bash
cd ~/worktrees/cli-phase-b
git add tests/cli/test_readonly_guard.sh CLAUDE.md
git commit -m "$(cat <<'EOF'
test(cli): enforce the read-only guarantee mechanically

Sub-project #1 promises no CLI command can alter collection data. A
promise in a design doc is not enforcement, so this greps src/Services
and src/Query for write statements and fails if any appear.

The pattern uses word boundaries so updated_at and created_at do not trip
the UPDATE/DELETE checks, and the test verifies both directions — that
the pattern still matches real write SQL, and that it ignores those
column names — so it cannot pass vacuously if the regex rots.

A mutation sub-project must consciously amend this test. That is the
point: lifting the guarantee should be a visible decision.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: Open the pull request

**Files:** none — this task only pushes and opens the PR.

- [ ] **Step 1: Verify the whole suite once more**

Run: `cd ~/worktrees/cli-phase-b && bash tests/v2/run-all.sh 2>&1 | tail -20`

Expected: zero failures. If the local grant is missing and the run cannot start, say so explicitly and rely on CI — do not claim the suite passed.

- [ ] **Step 2: Lint every PHP file touched**

Run:
```bash
cd ~/worktrees/cli-phase-b
for f in bin/gt $(find src -name '*.php'); do php -l "$f" | grep -v "No syntax errors" || true; done
echo "lint done — silence above means clean"
```

Expected: no output before the final line.

- [ ] **Step 3: Push**

```bash
cd ~/worktrees/cli-phase-b
git push -u origin feat-cli-phase-b
```

- [ ] **Step 4: Open the PR against `feat-cli-phase-a`**

This PR is stacked on PR #80, so its base must be `feat-cli-phase-a`, not `main`. Basing it on `main` would show Phase A's commits as part of this diff.

```bash
cd ~/worktrees/cli-phase-b
gh pr create --base feat-cli-phase-a --title "feat(cli): gt read + filter core (sub-project #1)" --body "$(cat <<'EOF'
## Summary

Sub-project #1 of the `gt` CLI: query the collection without the web app or the iOS app.

```
gt games list --platform=PS2 --unplayed
gt games list --missing=description --json | jq -r '.games[].id'
gt games get <id>          gt games platforms
gt items list --category=Controller        gt items get <id>
```

Spec: `docs/superpowers/specs/2026-08-03-gt-cli-design.md`
Plan: `docs/superpowers/plans/2026-08-03-gt-cli-read-filter-core.md`

**Stacked on #80** — base is `feat-cli-phase-a`.

## What is in it

- `src/Query/` — `FilterDefinition` declares a resource's legal flags and columns; `FilterCompiler` turns parsed flags into a parameterised `WHERE` fragment. Column names always come from the definition, never from input, so injection is structurally impossible and an unknown column exits 2.
- `src/Services/` — `GamesService` and `ItemsService`. `SELECT` only, explicit bound `int $userId`, no transport concerns.
- `src/Cli/` — noun-verb dispatch (`gt games list`), TTY-detected output, per-command flag allowlists.
- `--missing=<column>` generalises the enrichment case, so backfilling a new field later needs no new flag.

## Behaviour worth reviewing

- **`--unplayed` and `--digital` match `0 OR NULL`.** `played` and `is_physical` are nullable ints; matching only `0` would hide rows whose value was never set, which reads as data loss.
- **`user_id` is in no allowlist.** Filtering by it would reintroduce the cross-user override removed from the list endpoint as an IDOR (Fable §1).
- **Filters are per-resource.** `gt items list --played` is a usage error, not a silent empty result.
- **Column names are backticked unconditionally** — `condition` is a MySQL reserved word and a real column on both tables.
- **`games platforms` is scoped to the caller**, unlike the endpoint's unfiltered branch which returned every user's platforms for dropdown suggestions.

## Not in it

Writes of any kind, enrichment, imports, raw SQL, `--http` mode, and the v1 rewire. `api/games.php` is untouched. `tests/cli/test_readonly_guard.sh` enforces the read-only property by grepping the service and query layers, and verifies its own pattern in both directions so it cannot pass vacuously.

## Test plan
- [ ] `bash tests/v2/run-all.sh` — all `v2/` and `cli/` suites
- [ ] `php -l` clean on every touched file
- [ ] Filter assertions cover each flag, `--missing` on NULL *and* empty string, AND-combination, sorting both directions, paging, and cross-user scoping
- [ ] Usage errors verified: unknown flag, unknown `--missing` column, unknown `--sort` column, non-integer `--page`, `--missing=user_id`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 5: Verify CI authoritatively**

Run: `gh pr checks <n> -R CammyBlack02/gameTracker` and confirm the run's `conclusion` is `success` via `gh run list`. A shell pipeline can exit 0 while `gh` itself failed, so check the conclusion field rather than an exit code.

---

## Self-review notes

Checked against the spec:

- Commands (`games list/get/platforms`, `items list/get`, `whoami`, `db info`) — Tasks 1–4. ✅
- Noun-verb grammar and the `db:info` rename — Task 1. ✅
- Read-only guarantee, enforced mechanically — Task 5. ✅
- Full filter table including `--missing`, `--title-like`, `--limit` as a `--per-page` alias, `--sort` with `-` for descending, defaults page 1 / per-page 100 / cap 1000 / `-created_at` — Task 2, `FilterCompiler` and `GamesFilters`. ✅
- Per-resource allowlists, with `gt items list --played` a usage error — Task 4. ✅
- `GameFilters` separate from the service so mutations can reuse the vocabulary — Task 2 (`src/Query/`). ✅
- TTY-detected output, `--json`/`--table` overrides, STDERR discipline — Task 1. ✅
- Exit codes 0/1/2/3 with slugs — Task 1 (`UsageException` plus the existing `DomainException`). ✅
- Fixtures with a cross-user row proving scoping — Task 2. ✅
- `api/games.php` untouched — no task modifies it. ✅

Two deliberate deviations from the spec, both narrowing rather than widening:

1. The spec listed `--platform` and `--category` only implicitly for items ("the flags that genuinely apply"). Task 4 includes both, plus `--condition`, because those columns exist on `items` and the flags are useful. Named here so it is a visible choice.
2. `GamesService::platforms()` drops the endpoint's global (all-users) mode and is scoped to the caller. The global branch exists to populate a web dropdown; exposing every user's platforms through the CLI would contradict the user-scoping invariant for no benefit.

Naming consistency verified across tasks: `FilterSet` properties (`whereSql`, `params`, `orderSql`, `page`, `perPage`, `offset`), `FilterDefinition::flagNames()`, `FilterCompiler::compile()`, `GamesFilters::definition()` / `ItemsFilters::definition()`, `GamesService::list/get/platforms`, `ItemsService::list/get`, and `Command::allowedOptions()` are used identically everywhere they appear.
