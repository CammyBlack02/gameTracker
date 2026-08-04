# `gt` CLI — Mutations PR2 (create, delete, items) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete sub-project #2 by adding `create` and `delete` for games, the full `set`/`create`/`delete` trio for items, and an undo path that restores deleted rows along with their child rows and clears the tombstones the delete produced.

**Architecture:** PR1 shipped the write machinery (`AssignmentSet`, `JournalWriter`, `GamesWriter::applySet`, `gt undo`). This PR generalises it in three moves: a `ResourceWriter` interface so `UndoCommand` dispatches on the journal entry's `resource` and `operation` instead of hardcoding `GamesWriter::revertSet`; an `ItemsWriter` mirroring `GamesWriter`; and `applyCreate`/`applyDelete` on both, where delete journals the parent row *and* its cascading children so undo can reconstruct all of it.

**Tech Stack:** PHP 8.1+ (no framework, no Composer), PDO/MySQL, bash test suites under `tests/cli/` run by `tests/v2/run-all.sh`.

## Global Constraints

Copied from `docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md` and the programme spec. Every task's requirements implicitly include this section.

- **Governing constraint:** temporary website breakage is acceptable; altering or losing the games data is not. Production is the live checkout with no staging copy.
- **Exit codes:** `0` ok, `1` domain error, `2` usage error, `3` bootstrap/database. Unchanged.
- **Write SQL is permitted only under `src/Services/Write/`.** `tests/cli/test_readonly_guard.sh` greps all of `src/` for `INSERT INTO`, `UPDATE <table> SET`, `DELETE FROM`, `REPLACE INTO`, `ALTER TABLE`, `DROP TABLE|DATABASE|TRIGGER`, `TRUNCATE` and fails on any hit outside that directory. **This means `src/Write/AssignmentSet.php` must never contain the literal string `INSERT INTO`** — it may build a column list and placeholders, but the statement is assembled in the writer.
- **Backtick every column name.** `condition` is a reserved word and a real column on both `games` and `items`.
- **Column names never come from user input.** They come from a `WriteDefinition` (writes) or `FilterDefinition` (selection). Values are always bound parameters.
- **Ownership is enforced in the writer, not the caller.** Every statement is scoped by a bound `user_id`. `--admin` grants reads only; a write to another user's row is a domain error (exit 1).
- **`id`, `user_id`, `created_at`, `updated_at` are never writable.**
- **`--yes` is required when an operation affects more than one row, or when it deletes.** A single-row `set` or a `create` applies immediately. When confirmation is required and absent, the run is a dry run that writes nothing and exits 0.
- **A bulk write with no selector is refused** (exit 2). `--all` is required to target every row the user owns.
- **A bulk operation is one transaction.** If zero rows match, report `0` and exit 0 **without writing a journal entry**.
- **Journal ordering:** snapshot → write entry with `committed: false` → `BEGIN` → mutate → `COMMIT` → rewrite entry with `committed: true` and post-write `updated_at` values.
- **Conflict detection uses the POST-write `updated_at`**, never the pre-write value. The write bumps `updated_at` itself; a pre-write baseline makes undo believe every row was edited behind its back. `updated_at` has one-second resolution, so tests must deliberately cross a second boundary (`sleep 1`) or they pass against the broken version.
- **Undo does not create a journal entry.** It sets `reverted_at` on the entry it reverted; re-undoing is refused.
- **Image files are never unlinked.** `gt delete` removes database rows only. Several games share one image path in production, so unlinking would break a surviving game's cover.
- **Journal location:** `~/.gt/journal`, mode 0700, `GT_JOURNAL_DIR` overrides. Tests must set `GT_JOURNAL_DIR` to a temp dir.
- **Services take an explicit `int $userId`**; no `$_SESSION`, no `echo`, no `exit()`. Throw on failure.
- **Test isolation:** fixtures own the dedicated `gtfixture` user and clean by `user_id`, never by title prefix. All suites share one database and the v2 suites run first.
- **PHP style:** `final class`, constructor property promotion with `readonly`, `declare`-free files opening with `<?php`, namespace `GameTracker\…` matching the `src/` path (see `src/autoload.php`).

## Schema facts this plan depends on

Verified against production 2026-08-04.

| Fact | Consequence |
|---|---|
| `games`: `title` (text) and `platform` (varchar) are `NOT NULL`; `user_id` `NOT NULL`; every other writable column is nullable or defaulted | `requiredOnCreate` for games is `title`, `platform` |
| `items`: `title` (text) and `category` (varchar) are `NOT NULL`; **`platform` is nullable** | `requiredOnCreate` for items is `title`, `category` — *not* platform |
| `game_images.game_id` → `games(id)` `ON DELETE CASCADE` | deleting a game destroys its extra-image rows; undo must re-insert them |
| `item_images.item_id` → `items(id)` `ON DELETE CASCADE` | same for items (0 rows in production today, but the FK is real) |
| `game_completions.game_id` → `games(id)` `ON DELETE SET NULL` | deleting a game orphans its completions **without** firing a tombstone (it is an UPDATE). Undo must restore `game_id` |
| Triggers `trg_games_after_delete`, `trg_items_after_delete`, `trg_game_images_after_delete`, `trg_item_images_after_delete`, `trg_game_completions_after_delete` each `INSERT INTO deletions (user_id, table_name, server_id) VALUES (OLD.user_id, '<table>', OLD.id)` | every cascaded child delete also writes a tombstone; undo must clear the parent's *and* the children's |
| `deletions` has no trigger of its own | deleting from `deletions` is safe and silent |
| `updated_at` is `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` on `games`, `items`, `game_completions` | any UPDATE bumps it, which is what makes CLI writes visible to iOS delta sync |

**The child-row problem this plan solves.** The design spec's delete/undo table says only "INSERT the row with its original id, then clear its tombstones". That restores a game whose extra images have been destroyed and whose completion history has been silently unlinked. 48 production games have completion rows and 2 completions are already orphaned from earlier web-app deletes. Under the governing constraint that is data loss, so `applyDelete` journals children and `revertDelete` restores them.

## File Structure

**New files**

| File | Responsibility |
|---|---|
| `src/Services/Write/ResourceWriter.php` | Interface: one static `revert()` entry point per resource, so `UndoCommand` never grows a switch over resources |
| `src/Services/Write/Tombstones.php` | Clears `deletions` rows for a `(table_name, server_id, user_id)`. The only place that touches `deletions` |
| `src/Services/Write/ItemsWriter.php` | Items mutations — mirrors `GamesWriter` |
| `src/Write/ItemsWrites.php` | The items `WriteDefinition` |
| `src/Cli/Commands/Games/CreateCommand.php` | `gt games create` |
| `src/Cli/Commands/Games/DeleteCommand.php` | `gt games delete` |
| `src/Cli/Commands/Items/SetCommand.php` | `gt items set` |
| `src/Cli/Commands/Items/CreateCommand.php` | `gt items create` |
| `src/Cli/Commands/Items/DeleteCommand.php` | `gt items delete` |
| `tests/cli/test_gt_create_delete.sh` | create/delete behaviour for both resources |

**Modified files**

| File | Change |
|---|---|
| `src/Write/AssignmentSet.php` | add `missingRequired()`, `columnListSql()`, `placeholders()` |
| `src/Services/Write/GamesWriter.php` | implement `ResourceWriter`; add `revert()`, `applyCreate()`, `revertCreate()`, `applyDelete()`, `revertDelete()` |
| `src/Services/ItemsService.php` | add `countMatching()` (absent today) |
| `src/Cli/Commands/UndoCommand.php` | dispatch by `$entry->resource` via a reverter registry |
| `src/Cli/Application.php` | register 5 new commands; bump `VERSION` to `0.3.0` |
| `tests/cli/test_gt_undo.sh` | add create-undo, delete-undo, tombstone and child-row assertions |
| `tests/cli/fixtures.sh` | add `seed_game_children()` so delete tests have children to lose |
| `CLAUDE.md` | document the new commands |

`tests/v2/run-all.sh` needs no change: it globs `tests/cli/test_*.sh`.

---

### Task 1: Reverter dispatch seam

Pure refactor. `UndoCommand` currently calls `GamesWriter::revertSet()` unconditionally (`src/Cli/Commands/UndoCommand.php:81`), so it can only ever undo a games `set`. Everything later in this plan plugs into the seam this task creates. Behaviour is unchanged and the existing suites must still pass.

**Files:**
- Create: `src/Services/Write/ResourceWriter.php`
- Modify: `src/Services/Write/GamesWriter.php` (add `implements ResourceWriter` and a `revert()` method)
- Modify: `src/Cli/Commands/UndoCommand.php:12`, `:81`
- Test: `tests/cli/test_gt_undo.sh` (add one assertion)

**Interfaces:**
- Consumes: `JournalEntry` (`id`, `argv`, `userId`, `resource`, `operation`, `committed`, `revertedAt`, `rows`), `GamesWriter::revertSet(PDO, JournalEntry, bool): array{restored:int, skipped:int}`
- Produces: `ResourceWriter::revert(PDO $pdo, JournalEntry $entry, bool $force): array{restored:int, skipped:int}` — implemented by `GamesWriter` and later `ItemsWriter`. Throws `BadRequestException` for an operation it does not know.

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_undo.sh`, immediately before the final `summarize` line:

```bash
blue "Unknown resource in a journal entry"

# A hand-written entry naming a resource with no reverter must fail cleanly
# rather than reverting against the wrong table. This is the dispatch seam:
# before it existed, every entry was reverted as if it were a games `set`.
BOGUS_ID="2099-01-01T00-00-00-000000Z-set"
cat > "$GT_JOURNAL_DIR/$BOGUS_ID.json" <<JSON
{
  "id": "$BOGUS_ID",
  "argv": [],
  "user_id": $(fixture_user_id "$FIXTURE_USER"),
  "resource": "widgets",
  "operation": "set",
  "committed": true,
  "reverted_at": null,
  "rows": [{"id": 1, "updated_at": null, "before": {"title": "x"}}]
}
JSON

run_gt undo "$BOGUS_ID" "$USER_FLAG"
assert_eq "1" "$GT_CODE" "an unknown resource is a domain error"
assert_contains "widgets" "$GT_OUT" "names the resource it cannot revert"
rm -f "$GT_JOURNAL_DIR/$BOGUS_ID.json"
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A5 'test_gt_undo'
```
Expected: FAIL — the bogus entry is reverted as a games `set` and exits 0 with "restored 0, skipped 1", so `assert_eq "1"` fails.

- [ ] **Step 3: Create the interface**

Create `src/Services/Write/ResourceWriter.php`:

```php
<?php

namespace GameTracker\Services\Write;

use GameTracker\Journal\JournalEntry;
use PDO;

/**
 * The undo entry point for one resource.
 *
 * UndoCommand resolves a journal entry's `resource` to an implementation and
 * hands the entry over. Which operations exist, and how each is reversed, is
 * knowledge that belongs to the writer that produced the entry — not to the
 * command, which would otherwise grow a switch over every resource-operation
 * pair as this sub-project expands.
 */
interface ResourceWriter
{
    /**
     * Reverse a committed journal entry.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array;
}
```

- [ ] **Step 4: Implement it on GamesWriter**

In `src/Services/Write/GamesWriter.php`, add the import beside the existing ones:

```php
use GameTracker\Domain\BadRequestException;
```

Change the class declaration:

```php
final class GamesWriter implements ResourceWriter
```

And add this method directly above `applySet()`:

```php
    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on games"
            ),
        };
    }
```

- [ ] **Step 5: Dispatch from UndoCommand**

In `src/Cli/Commands/UndoCommand.php`, replace the `use GameTracker\Services\Write\GamesWriter;` line with:

```php
use GameTracker\Domain\BadRequestException;
use GameTracker\Services\Write\GamesWriter;
use GameTracker\Services\Write\ResourceWriter;
```

Add this constant directly below `public const NAME = 'undo';`:

```php
    /**
     * Resource name as journalled => the writer that knows how to reverse it.
     * ItemsWriter joins this map in Task 2.
     *
     * @var array<string, class-string<ResourceWriter>>
     */
    private const REVERTERS = [
        'games' => GamesWriter::class,
    ];
```

Replace line 81 (`$result = GamesWriter::revertSet($ctx->pdo, $entry, $ctx->flag('force'));`) with:

```php
        $writer = self::REVERTERS[$entry->resource] ?? null;

        if ($writer === null) {
            throw new BadRequestException(
                "cannot revert '{$entry->resource}' — no writer is registered for it"
            );
        }

        $result = $writer::revert($ctx->pdo, $entry, $ctx->flag('force'));
```

- [ ] **Step 6: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass. The new assertions pass and every pre-existing `test_gt_undo.sh` and `test_gt_set.sh` assertion still passes — this task changed no behaviour for real entries.

- [ ] **Step 7: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Services/Write/ResourceWriter.php src/Services/Write/GamesWriter.php \
        src/Cli/Commands/UndoCommand.php tests/cli/test_gt_undo.sh
git commit -m "refactor(cli): dispatch undo by journalled resource

UndoCommand called GamesWriter::revertSet unconditionally, so it could only
ever reverse a games set. Resolve the entry's resource to a ResourceWriter
instead, which is the seam create, delete and items all plug into."
```

---

### Task 2: Items writes — `gt items set`

Gives items the same `set` capability games already has, and registers `ItemsWriter` in the reverter map so items writes are undoable the moment they exist.

**Files:**
- Create: `src/Write/ItemsWrites.php`
- Create: `src/Services/Write/ItemsWriter.php`
- Create: `src/Cli/Commands/Items/SetCommand.php`
- Modify: `src/Services/ItemsService.php` (add `countMatching()`)
- Modify: `src/Cli/Commands/UndoCommand.php` (add `'items' => ItemsWriter::class`)
- Modify: `src/Cli/Application.php` (register `items set`)
- Test: `tests/cli/test_gt_items.sh`

**Interfaces:**
- Consumes: `ResourceWriter` (Task 1), `AssignmentSet::parse(WriteDefinition, Context): self`, `WriteDefinition::__construct(table, writable, booleans, notNull, requiredOnCreate)`, `FilterSet::forId(int): FilterSet`, `FilterCompiler::compile(FilterDefinition, Context): FilterSet`, `ItemsFilters::definition(): FilterDefinition`
- Produces: `ItemsWrites::definition(): WriteDefinition`; `ItemsService::countMatching(PDO, int, FilterSet): int`; `ItemsWriter::applySet(PDO, int, FilterSet, AssignmentSet, JournalWriter, array): array{journal_id:?string, matched:int, changed:int}`, `ItemsWriter::revertSet(PDO, JournalEntry, bool): array{restored:int, skipped:int}`, `ItemsWriter::assertOwned(PDO, int, int): void`, `ItemsWriter::revert(PDO, JournalEntry, bool): array`

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_items.sh`, immediately before the final `summarize` line:

```bash
blue "Items writes"

export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_JOURNAL_DIR"' EXIT

category_of() {
  fixture_mysql -N -e "
    SELECT COALESCE(i.category, 'NULL') FROM items i
    JOIN users u ON i.user_id = u.id
    WHERE i.title = '$1' AND u.username = '$FIXTURE_USER'
  "
}

PAD_ID=$(fixture_id items 'FIXTURE Xbox Pad' mine)

# Items has its own writable allowlist. played is a games column and must be
# rejected here rather than silently matching nothing.
run_gt items set "$PAD_ID" "$USER_FLAG" --set-played=1
assert_eq "2" "$GT_CODE" "a games-only column is not writable on items"

run_gt items set "$PAD_ID" "$USER_FLAG" --set-user_id=99
assert_eq "2" "$GT_CODE" "user_id is not writable on items"

run_gt items set "$USER_FLAG" --set-category=Foo
assert_eq "2" "$GT_CODE" "bulk items write with no selector = 2"

run_gt items set "$PAD_ID" "$USER_FLAG" --clear-title
assert_eq "2" "$GT_CODE" "--clear- on items.title (NOT NULL) = 2"

# category is NOT NULL on items even though it is not on games.
run_gt items set "$PAD_ID" "$USER_FLAG" --clear-category
assert_eq "2" "$GT_CODE" "--clear- on items.category (NOT NULL) = 2"

# Single row applies immediately.
run_gt_json items set "$PAD_ID" "$USER_FLAG" --set-category=Gamepad
assert_eq "0" "$GT_CODE" "single-row items set exits 0"
assert_eq "Gamepad" "$(category_of 'FIXTURE Xbox Pad')" "items set applied"

# Bulk previews without --yes, applies with it.
run_gt_json items set "$USER_FLAG" --platform=PS2 --set-notes=Bulk
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 2' > /dev/null \
  && { green "  PASS: items bulk previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected an items preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt_json items set "$USER_FLAG" --platform=PS2 --set-notes=Bulk --yes
echo "$GT_JSON" | jq -e '.matched == 2 and .changed == 2' > /dev/null \
  && { green "  PASS: items bulk applied"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected items bulk result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Another user's item is refused.
OTHER_ITEM=$(fixture_id items 'FIXTURE Not Mine Item' other)
run_gt items set "$OTHER_ITEM" "$USER_FLAG" --set-category=Hacked
assert_eq "1" "$GT_CODE" "writing another user's item = 1"
assert_eq "Cable" "$(fixture_mysql -N -e "SELECT category FROM items WHERE id = $OTHER_ITEM")" \
  "the other user's item is untouched"

# And the write is undoable through the same command games uses.
run_gt_json items set "$PAD_ID" "$USER_FLAG" --set-category=Undone
run_gt undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo of an items set exits 0"
assert_eq "Gamepad" "$(category_of 'FIXTURE Xbox Pad')" "undo restored the items before-value"
```

`tests/cli/test_gt_items.sh` already calls `seed_items`, sets `USER_FLAG` and defines `run_gt`/`GT_CODE` (lines 12-22), but it has **no `run_gt_json`** — it was a read-only suite. Add it beside `run_gt`:

```bash
GT_JSON=""
run_gt_json() {
  set +e
  GT_JSON=$("$GT" "$@" 2>/dev/null)
  GT_CODE=$?
  set -e
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A20 'test_gt_items'
```
Expected: FAIL — `gt items set` is not a registered command, so every invocation exits 2 with "unknown subcommand for 'items'. Available: list, get".

- [ ] **Step 3: Add the items write definition**

Create `src/Write/ItemsWrites.php`:

```php
<?php

namespace GameTracker\Write;

/**
 * Writable columns for items.
 *
 * Deliberately not the games list: items has category, quantity and notes but
 * no played, is_physical or star_rating. There are no boolean columns here, so
 * a valueless --set-<column> is always a usage error on items.
 *
 * Note that platform is nullable on items while it is NOT NULL on games, so
 * items requires title and category on create rather than title and platform.
 */
final class ItemsWrites
{
    public static function definition(): WriteDefinition
    {
        return new WriteDefinition(
            table: 'items',
            writable: [
                'title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'quantity', 'front_image',
                'back_image', 'notes',
            ],
            booleans: [],
            notNull: ['title', 'category'],
            requiredOnCreate: ['title', 'category'],
        );
    }
}
```

- [ ] **Step 4: Add the count helper**

In `src/Services/ItemsService.php`, add this method directly below `list()`:

```php
    /**
     * How many rows a filter selects, without fetching them.
     *
     * Writes need the count before they touch anything, both to decide whether
     * --yes is required and to report the dry run. Mirrors
     * GamesService::countMatching.
     */
    public static function countMatching(PDO $pdo, int $userId, FilterSet $filters): int
    {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE {$where}");
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }
```

- [ ] **Step 5: Add the items writer**

Create `src/Services/Write/ItemsWriter.php`. This mirrors `GamesWriter` exactly — same journal ordering, same post-write timestamp rule, same ownership scoping — against the `items` table:

```php
<?php

namespace GameTracker\Services\Write;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterSet;
use GameTracker\Write\AssignmentSet;
use PDO;
use Throwable;

/**
 * Mutating operations on items.
 *
 * The rules are GamesWriter's, applied to a different table: bound user_id on
 * every statement, snapshot then journal then mutate then mark committed, and
 * the post-write updated_at recorded as undo's conflict baseline.
 */
final class ItemsWriter implements ResourceWriter
{
    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on items"
            ),
        };
    }

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

        $snapStmt = $pdo->prepare("SELECT {$selectList} FROM items WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
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

        $id = $journal->newId('set');
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'items',
            'set',
            false,
            null,
            $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE items SET ' . $assignments->setSql() . " WHERE {$where}"
            );
            $stmt->execute(array_merge($assignments->params(), $params));
            $changed = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // Post-write timestamps: see the note in GamesWriter::applySet. A
        // pre-write baseline makes undo refuse every row it wrote.
        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'items',
            'set',
            true,
            null,
            self::withCurrentTimestamps($pdo, $rows)
        ));

        return [
            'journal_id' => $id,
            'matched' => count($rows),
            'changed' => $changed,
        ];
    }

    /**
     * @param list<array{id:int, updated_at:?string, before:array}> $rows
     * @return list<array{id:int, updated_at:?string, before:array}>
     */
    private static function withCurrentTimestamps(PDO $pdo, array $rows): array
    {
        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $pdo->prepare(
            "SELECT `id`, `updated_at` FROM items WHERE `id` IN ({$placeholders})"
        );
        $stmt->execute($ids);

        $stamps = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stamps[(int)$row['id']] = $row['updated_at'];
        }

        return array_map(
            static fn(array $row): array => [
                'id' => $row['id'],
                'updated_at' => $stamps[$row['id']] ?? $row['updated_at'],
                'before' => $row['before'],
            ],
            $rows
        );
    }

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revertSet(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $check = $pdo->prepare('SELECT `updated_at` FROM items WHERE `id` = ? AND `user_id` = ?');
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    $skipped++;
                    continue;
                }

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
                    "UPDATE items SET {$setSql} WHERE `id` = ? AND `user_id` = ?"
                );
                $stmt->execute($params);
                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    public static function assertOwned(PDO $pdo, int $userId, int $itemId): void
    {
        $stmt = $pdo->prepare('SELECT `user_id` FROM items WHERE `id` = ?');
        $stmt->execute([$itemId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== false && (int)$owner !== $userId) {
            throw new AccessDeniedException("Item {$itemId} belongs to another user");
        }
    }
}
```

- [ ] **Step 6: Add the command**

Create `src/Cli/Commands/Items/SetCommand.php`. Identical in shape to the games version, wired to the items definitions and service:

```php
<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\ItemsFilters;
use GameTracker\Services\ItemsService;
use GameTracker\Services\Write\ItemsWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\ItemsWrites;

final class SetCommand implements Command
{
    public const NAME = 'items set';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Change fields on one item or many';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            ItemsFilters::definition()->flagNames(),
            ItemsWrites::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = ItemsFilters::definition();
        $writeDef = ItemsWrites::definition();

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
                throw new UsageException("item id must be a positive integer, got '{$id}'");
            }
            if ($hasSelector) {
                throw new UsageException(
                    'pass either an id or filters, not both — filters do not narrow an id'
                );
            }
        } elseif (!$hasSelector && !$ctx->flag('all')) {
            throw new UsageException(
                'no selector given — add a filter (see `gt items list --help`) or --all to target every item'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        $matched = ItemsService::countMatching($ctx->pdo, $userId, $filters);
        $single = $id !== null;

        $needsConfirmation = !$single && $matched > 1;

        if ($needsConfirmation && !$ctx->flag('yes')) {
            return $this->preview($ctx, $matched, $assignments);
        }

        if ($single) {
            ItemsWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        $result = ItemsWriter::applySet(
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
}
```

- [ ] **Step 7: Register the command and the reverter**

In `src/Cli/Application.php`, add the import beside the other items imports:

```php
use GameTracker\Cli\Commands\Items\SetCommand as ItemsSetCommand;
```

and add to the `COMMANDS` map, after `'items get'`:

```php
        'items set' => ItemsSetCommand::class,
```

In `src/Cli/Commands/UndoCommand.php`, add the import:

```php
use GameTracker\Services\Write\ItemsWriter;
```

and extend the map added in Task 1:

```php
    private const REVERTERS = [
        'games' => GamesWriter::class,
        'items' => ItemsWriter::class,
    ];
```

- [ ] **Step 8: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass including the new items write assertions.

- [ ] **Step 9: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Write/ItemsWrites.php src/Services/Write/ItemsWriter.php \
        src/Cli/Commands/Items/SetCommand.php src/Services/ItemsService.php \
        src/Cli/Commands/UndoCommand.php src/Cli/Application.php \
        tests/cli/test_gt_items.sh
git commit -m "feat(cli): gt items set, journalled and undoable

Items gets its own writable allowlist rather than sharing games': category
and quantity exist here, played and star_rating do not, and platform is
nullable so create requires title and category instead."
```

---

### Task 3: `gt games create`

A single-row insert that applies immediately (blast radius of one, journalled, one `gt undo` away). Undoing a create deletes the row, which fires the tombstone trigger — correct, because iOS should learn the row is gone.

**Files:**
- Modify: `src/Write/AssignmentSet.php` (add `missingRequired()`, `columnListSql()`, `placeholders()`)
- Modify: `src/Services/Write/GamesWriter.php` (add `applyCreate()`, `revertCreate()`; extend `revert()`)
- Create: `src/Cli/Commands/Games/CreateCommand.php`
- Modify: `src/Cli/Application.php`
- Test: `tests/cli/test_gt_create_delete.sh` (new file)

**Interfaces:**
- Consumes: `AssignmentSet` (`columns`, `params()`, `describe()`), `WriteDefinition::$requiredOnCreate`, `JournalWriter::newId(string): string`
- Produces: `AssignmentSet::missingRequired(WriteDefinition): list<string>`, `AssignmentSet::columnListSql(): string`, `AssignmentSet::placeholders(): string`; `GamesWriter::applyCreate(PDO, int, AssignmentSet, JournalWriter, array): array{journal_id:string, id:int}`, `GamesWriter::revertCreate(PDO, JournalEntry, bool): array{restored:int, skipped:int}`

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_gt_create_delete.sh`:

```bash
#!/usr/bin/env bash
# `gt games create` / `gt games delete` and their items equivalents.
#
# Delete is the only operation that always demands --yes, and the only one
# whose undo has to reconstruct child rows and clear tombstones. Those
# assertions live in test_gt_undo.sh; this suite proves the forward direction.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
seed_items
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

count_games() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM games g JOIN users u ON g.user_id = u.id
    WHERE u.username = '$FIXTURE_USER'
  "
}

blue "games create — required columns"

run_gt games create "$USER_FLAG" --set-title="FIXTURE Created"
assert_eq "2" "$GT_CODE" "create without platform = 2"
assert_contains "platform" "$GT_OUT" "names the missing column"

run_gt games create "$USER_FLAG" --set-platform="PS2"
assert_eq "2" "$GT_CODE" "create without title = 2"
assert_contains "title" "$GT_OUT" "names the missing title"

run_gt games create "$USER_FLAG"
assert_eq "2" "$GT_CODE" "create with no assignments at all = 2"

run_gt games create "$USER_FLAG" --set-title="X" --set-platform="PS2" --set-user_id=99
assert_eq "2" "$GT_CODE" "user_id is not assignable on create"

blue "games create — applies immediately"

BEFORE=$(count_games)
run_gt_json games create "$USER_FLAG" --set-title="FIXTURE Created" --set-platform="PS2" --set-genre="Test"
assert_eq "0" "$GT_CODE" "create exits 0 without --yes"

NEW_ID=$(echo "$GT_JSON" | jq -r '.id')
echo "$GT_JSON" | jq -e '.id > 0 and .dry_run == false' > /dev/null \
  && { green "  PASS: reports the new id"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no id in output: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

assert_eq "$((BEFORE + 1))" "$(count_games)" "one row was added"

# The row belongs to the acting user, not to whoever the CLI defaults to.
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")
assert_eq "$FIXTURE_UID" \
  "$(fixture_mysql -N -e "SELECT user_id FROM games WHERE id = $NEW_ID")" \
  "the created row is owned by the acting user"

assert_eq "Test" "$(fixture_mysql -N -e "SELECT genre FROM games WHERE id = $NEW_ID")" \
  "optional columns were written"

# And it is readable through the read path built in sub-project #1.
run_gt_json games get "$NEW_ID" "$USER_FLAG"
assert_eq "0" "$GT_CODE" "the created row is readable via games get"
echo "$GT_JSON" | jq -e '.title == "FIXTURE Created"' > /dev/null \
  && { green "  PASS: games get returns the created row"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected get output: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# A journal entry exists and records the created id.
CREATE_ENTRY=$(find "$GT_JOURNAL_DIR" -name '*-create.json' | head -1)
jq -e ".committed == true and .operation == \"create\" and .rows[0].id == $NEW_ID" \
  < "$CREATE_ENTRY" > /dev/null \
  && { green "  PASS: create is journalled with its new id"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: bad create entry: $(cat "$CREATE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-pr2
chmod +x tests/cli/test_gt_create_delete.sh
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A20 'test_gt_create_delete'
```
Expected: FAIL — `gt games create` is unregistered, so every call exits 2 with "unknown subcommand for 'games'".

- [ ] **Step 3: Extend AssignmentSet**

In `src/Write/AssignmentSet.php`, add these three methods directly below `params()`:

```php
    /**
     * Required columns this assignment set does not supply.
     *
     * NOT NULL columns with no database default have to arrive with the insert;
     * letting MySQL reject it instead would surface a driver message rather
     * than a usage error naming the column.
     *
     * A column assigned NULL via --clear- counts as missing: --clear-title is
     * already rejected for NOT NULL columns, but a resource whose required
     * column is nullable would otherwise slip through.
     *
     * @return list<string>
     */
    public function missingRequired(WriteDefinition $def): array
    {
        $missing = [];

        foreach ($def->requiredOnCreate as $column) {
            if (!array_key_exists($column, $this->columns) || $this->columns[$column] === null) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * The column list for an insert, e.g. "`title`, `platform`".
     *
     * The statement itself is assembled in src/Services/Write/ — the read-only
     * guard greps all of src/ for write keywords and permits them only there.
     */
    public function columnListSql(): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => '`' . $c . '`',
            array_keys($this->columns)
        ));
    }

    /**
     * Placeholders matching columnListSql(), e.g. "?, ?".
     */
    public function placeholders(): string
    {
        return implode(', ', array_fill(0, count($this->columns), '?'));
    }
```

- [ ] **Step 4: Add applyCreate and revertCreate**

In `src/Services/Write/GamesWriter.php`, extend the `revert()` match added in Task 1 to:

```php
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            'create' => self::revertCreate($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on games"
            ),
        };
```

Add these two methods below `applySet()`:

```php
    /**
     * Insert one game owned by $userId.
     *
     * user_id is appended here rather than being assignable, so a create cannot
     * plant a row in someone else's collection. The journal entry is written
     * after the insert because the id does not exist until then; the ordering
     * still holds, since the pre-insert entry has nothing to protect — a crash
     * before the marker leaves a row that undo will not remove, which is the
     * safe direction.
     *
     * @return array{journal_id: string, id: int}
     */
    public static function applyCreate(
        PDO $pdo,
        int $userId,
        AssignmentSet $assignments,
        JournalWriter $journal,
        array $argv
    ): array {
        $id = $journal->newId('create');

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'create', false, null, []
        ));

        $pdo->beginTransaction();

        try {
            $sql = 'INSERT INTO games (' . $assignments->columnListSql() . ', `user_id`) '
                 . 'VALUES (' . $assignments->placeholders() . ', ?)';

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($assignments->params(), [$userId]));
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stamp = $pdo->prepare('SELECT `updated_at` FROM games WHERE `id` = ?');
        $stamp->execute([$newId]);
        $updatedAt = $stamp->fetchColumn();

        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'games',
            'create',
            true,
            null,
            [[
                'id' => $newId,
                'updated_at' => $updatedAt === false ? null : $updatedAt,
                'before' => [],
            ]]
        ));

        return ['journal_id' => $id, 'id' => $newId];
    }

    /**
     * Undo a create by deleting the row it made.
     *
     * The delete fires trg_games_after_delete, leaving a tombstone. That is
     * correct and deliberately not cleaned up: if iOS already synced the
     * created row, it needs to hear that the row is gone.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertCreate(PDO $pdo, JournalEntry $entry, bool $force): array
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
                    // Already gone. Nothing to undo, and not an error.
                    $skipped++;
                    continue;
                }

                // Edited since it was created: removing it would discard that
                // edit, so refuse unless forced.
                if (!$force && $current !== $row['updated_at']) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare('DELETE FROM games WHERE `id` = ? AND `user_id` = ?');
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
```

- [ ] **Step 5: Add the create command**

Create `src/Cli/Commands/Games/CreateCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\GamesWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\GamesWrites;

/**
 * Creates one game.
 *
 * No --yes: a create touches exactly one row the caller described in full, is
 * journalled, and is one `gt undo` away from being removed. Bulk import is
 * sub-project #4's job, not a flag here.
 */
final class CreateCommand implements Command
{
    public const NAME = 'games create';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Add one game';
    }

    public static function allowedOptions(): array
    {
        return GamesWrites::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        if ($args !== []) {
            throw new UsageException(
                'games create takes no positional arguments — describe the row with --set-<column>=<value>'
            );
        }

        $writeDef = GamesWrites::definition();
        $assignments = AssignmentSet::parse($writeDef, $ctx);

        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to create — pass at least --set-title=… and --set-platform=…'
            );
        }

        $missing = $assignments->missingRequired($writeDef);
        if ($missing !== []) {
            throw new UsageException(
                'games create needs ' . implode(' and ', array_map(
                    static fn(string $c): string => '--set-' . $c . '=…',
                    $missing
                ))
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        $result = GamesWriter::applyCreate(
            $ctx->pdo,
            (int)$user['id'],
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line('created game ' . $result['id']);
            $ctx->output->line('undo with: gt undo ' . $result['journal_id']);

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'id' => $result['id'],
            'journal_id' => $result['journal_id'],
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }
}
```

- [ ] **Step 6: Register it**

In `src/Cli/Application.php`, add the import:

```php
use GameTracker\Cli\Commands\Games\CreateCommand as GamesCreateCommand;
```

and add to `COMMANDS`, after `'games set'`:

```php
        'games create' => GamesCreateCommand::class,
```

- [ ] **Step 7: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass.

- [ ] **Step 8: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Write/AssignmentSet.php src/Services/Write/GamesWriter.php \
        src/Cli/Commands/Games/CreateCommand.php src/Cli/Application.php \
        tests/cli/test_gt_create_delete.sh
git commit -m "feat(cli): gt games create

user_id is appended by the writer rather than being assignable, so a create
cannot plant a row in another user's collection. Undoing a create deletes the
row and deliberately leaves the tombstone: iOS may already have synced it."
```

---

### Task 4: `gt items create`

**Files:**
- Modify: `src/Services/Write/ItemsWriter.php` (add `applyCreate()`, `revertCreate()`; extend `revert()`)
- Create: `src/Cli/Commands/Items/CreateCommand.php`
- Modify: `src/Cli/Application.php`
- Test: `tests/cli/test_gt_create_delete.sh`

**Interfaces:**
- Consumes: `AssignmentSet::missingRequired(WriteDefinition): list<string>`, `columnListSql()`, `placeholders()` (Task 3); `ItemsWrites::definition()` (Task 2)
- Produces: `ItemsWriter::applyCreate(PDO, int, AssignmentSet, JournalWriter, array): array{journal_id:string, id:int}`, `ItemsWriter::revertCreate(PDO, JournalEntry, bool): array{restored:int, skipped:int}`

- [ ] **Step 1: Write the failing test**

Insert into `tests/cli/test_gt_create_delete.sh`, immediately before the final `summarize` line:

```bash
blue "items create"

count_items() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM items i JOIN users u ON i.user_id = u.id
    WHERE u.username = '$FIXTURE_USER'
  "
}

# items requires title and category. platform is nullable here, unlike games.
run_gt items create "$USER_FLAG" --set-title="FIXTURE New Item"
assert_eq "2" "$GT_CODE" "items create without category = 2"
assert_contains "category" "$GT_OUT" "names the missing category"

run_gt items create "$USER_FLAG" --set-category="Cable"
assert_eq "2" "$GT_CODE" "items create without title = 2"

ITEMS_BEFORE=$(count_items)
run_gt_json items create "$USER_FLAG" --set-title="FIXTURE New Item" --set-category="Cable"
assert_eq "0" "$GT_CODE" "items create without platform exits 0 — platform is nullable"
assert_eq "$((ITEMS_BEFORE + 1))" "$(count_items)" "one item was added"

NEW_ITEM=$(echo "$GT_JSON" | jq -r '.id')
assert_eq "$FIXTURE_UID" \
  "$(fixture_mysql -N -e "SELECT user_id FROM items WHERE id = $NEW_ITEM")" \
  "the created item is owned by the acting user"

# A games-only column is still rejected on the items create path.
run_gt items create "$USER_FLAG" --set-title="X" --set-category="Y" --set-star_rating=5
assert_eq "2" "$GT_CODE" "a games-only column is rejected on items create"

# And undo removes it.
run_gt undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo of an items create exits 0"
assert_eq "$ITEMS_BEFORE" "$(count_items)" "undo removed the created item"
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A20 'test_gt_create_delete'
```
Expected: FAIL — "unknown subcommand for 'items'. Available: list, get, set".

- [ ] **Step 3: Add applyCreate and revertCreate to ItemsWriter**

In `src/Services/Write/ItemsWriter.php`, extend the `revert()` match:

```php
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            'create' => self::revertCreate($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on items"
            ),
        };
```

Add these methods below `applySet()`:

```php
    /**
     * @return array{journal_id: string, id: int}
     */
    public static function applyCreate(
        PDO $pdo,
        int $userId,
        AssignmentSet $assignments,
        JournalWriter $journal,
        array $argv
    ): array {
        $id = $journal->newId('create');

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'items', 'create', false, null, []
        ));

        $pdo->beginTransaction();

        try {
            $sql = 'INSERT INTO items (' . $assignments->columnListSql() . ', `user_id`) '
                 . 'VALUES (' . $assignments->placeholders() . ', ?)';

            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($assignments->params(), [$userId]));
            $newId = (int)$pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $stamp = $pdo->prepare('SELECT `updated_at` FROM items WHERE `id` = ?');
        $stamp->execute([$newId]);
        $updatedAt = $stamp->fetchColumn();

        $journal->write(new JournalEntry(
            $id,
            $argv,
            $userId,
            'items',
            'create',
            true,
            null,
            [[
                'id' => $newId,
                'updated_at' => $updatedAt === false ? null : $updatedAt,
                'before' => [],
            ]]
        ));

        return ['journal_id' => $id, 'id' => $newId];
    }

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revertCreate(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $check = $pdo->prepare('SELECT `updated_at` FROM items WHERE `id` = ? AND `user_id` = ?');
                $check->execute([$row['id'], $entry->userId]);
                $current = $check->fetchColumn();

                if ($current === false) {
                    $skipped++;
                    continue;
                }

                if (!$force && $current !== $row['updated_at']) {
                    $skipped++;
                    continue;
                }

                $stmt = $pdo->prepare('DELETE FROM items WHERE `id` = ? AND `user_id` = ?');
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
```

- [ ] **Step 4: Add the command**

Create `src/Cli/Commands/Items/CreateCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\ItemsWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\ItemsWrites;

/**
 * Creates one item. Same rule as games create: one row, no --yes, journalled.
 */
final class CreateCommand implements Command
{
    public const NAME = 'items create';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Add one item';
    }

    public static function allowedOptions(): array
    {
        return ItemsWrites::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        if ($args !== []) {
            throw new UsageException(
                'items create takes no positional arguments — describe the row with --set-<column>=<value>'
            );
        }

        $writeDef = ItemsWrites::definition();
        $assignments = AssignmentSet::parse($writeDef, $ctx);

        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to create — pass at least --set-title=… and --set-category=…'
            );
        }

        $missing = $assignments->missingRequired($writeDef);
        if ($missing !== []) {
            throw new UsageException(
                'items create needs ' . implode(' and ', array_map(
                    static fn(string $c): string => '--set-' . $c . '=…',
                    $missing
                ))
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        $result = ItemsWriter::applyCreate(
            $ctx->pdo,
            (int)$user['id'],
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line('created item ' . $result['id']);
            $ctx->output->line('undo with: gt undo ' . $result['journal_id']);

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'id' => $result['id'],
            'journal_id' => $result['journal_id'],
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }
}
```

- [ ] **Step 5: Register it**

In `src/Cli/Application.php`, add the import:

```php
use GameTracker\Cli\Commands\Items\CreateCommand as ItemsCreateCommand;
```

and add to `COMMANDS`, after `'items set'`:

```php
        'items create' => ItemsCreateCommand::class,
```

- [ ] **Step 6: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass.

- [ ] **Step 7: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Services/Write/ItemsWriter.php src/Cli/Commands/Items/CreateCommand.php \
        src/Cli/Application.php tests/cli/test_gt_create_delete.sh
git commit -m "feat(cli): gt items create

Requires title and category rather than title and platform: platform is
nullable on items and NOT NULL on games."
```

---

### Task 5: `gt games delete`, with child rows and tombstones

The largest task, and the one the governing constraint bears on hardest. Deleting a game cascades: `game_images` rows are destroyed outright, `game_completions.game_id` is set to NULL, and each cascaded delete writes its own tombstone. Restoring the parent row alone therefore returns a game with its extra images gone and its completion history silently unlinked — 48 production games have completions, and 2 completions are already orphaned from earlier web-app deletes. So the journal entry carries the children and undo puts all of it back.

**Files:**
- Create: `src/Services/Write/Tombstones.php`
- Modify: `src/Services/Write/GamesWriter.php` (add `applyDelete()`, `revertDelete()`; extend `revert()`)
- Create: `src/Cli/Commands/Games/DeleteCommand.php`
- Modify: `src/Cli/Application.php`
- Modify: `tests/cli/fixtures.sh` (add `seed_game_children()`)
- Test: `tests/cli/test_gt_create_delete.sh`, `tests/cli/test_gt_undo.sh`

**Interfaces:**
- Consumes: `GamesService::countMatching(PDO, int, FilterSet): int`, `FilterSet::forId(int)`, `FilterCompiler::compile()`, `GamesFilters::definition()`, `JournalWriter`
- Produces:
  - `Tombstones::clear(PDO $pdo, int $userId, string $table, list<int> $serverIds): int` — deletes matching `deletions` rows, returns the count removed
  - `GamesWriter::applyDelete(PDO, int $userId, FilterSet, JournalWriter, array $argv): array{journal_id:?string, deleted:int}`
  - `GamesWriter::revertDelete(PDO, JournalEntry, bool $force): array{restored:int, skipped:int}`
  - Journal row shape for a delete: `{"id": int, "updated_at": ?string, "before": {<every games column, including user_id/created_at/updated_at>}, "children": {"game_images": [<full rows>], "game_completions": [<ids>]}}`

- [ ] **Step 1: Add the child fixture**

Append to `tests/cli/fixtures.sh`:

```bash
# Give one fixture game a child row of each kind, so delete tests exercise both
# cascade behaviours rather than only the parent row.
#
# game_images.game_id is ON DELETE CASCADE — the row is destroyed and a
# tombstone is written for it. game_completions.game_id is ON DELETE SET NULL —
# the row survives, its link is nulled, and NO tombstone fires because that is
# an UPDATE. Undo has to handle both, and only a fixture with both can prove it.
seed_game_children() {
  local uid game_id
  uid=$(fixture_user_id "$FIXTURE_USER")
  game_id=$(fixture_id games 'FIXTURE Halo 3' mine)

  fixture_mysql -e "DELETE FROM game_images WHERE user_id = $uid"
  fixture_mysql -e "DELETE FROM game_completions WHERE user_id = $uid"

  fixture_mysql -e "
    INSERT INTO game_images (game_id, user_id, image_path)
    VALUES ($game_id, $uid, 'fixture-extra-1.jpg'),
           ($game_id, $uid, 'fixture-extra-2.jpg');
  "

  fixture_mysql -e "
    INSERT INTO game_completions
      (game_id, user_id, title, platform, date_completed, completion_year)
    VALUES ($game_id, $uid, 'FIXTURE Halo 3', 'Xbox 360', '2026-01-20', 2026);
  "
}
```

Column names verified against production 2026-08-04: `game_images` is
`(id, game_id, image_path, uploaded_at, user_id, updated_at)` and `item_images`
is `(id, item_id, image_path, uploaded_at, user_id, updated_at)`. `image_path`
is `NOT NULL` on both, which is why the fixture supplies it.

- [ ] **Step 2: Write the failing test**

Insert into `tests/cli/test_gt_create_delete.sh`, immediately before the final `summarize` line:

```bash
blue "games delete — always needs --yes"

seed_games
seed_game_children
HALO_ID=$(fixture_id games 'FIXTURE Halo 3' mine)

count_tombstones() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM deletions
    WHERE table_name = '$1' AND server_id = $2
  "
}

# Even a single row named by id previews rather than applying. This is the one
# place the blast-radius rule bends: a mistyped id in `set` writes a field to
# the wrong game, the same typo in `delete` removes one.
DELETE_BEFORE=$(count_games)
run_gt_json games delete "$HALO_ID" "$USER_FLAG"
assert_eq "0" "$GT_CODE" "delete without --yes exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 1' > /dev/null \
  && { green "  PASS: single-row delete previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: delete should have previewed: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "$DELETE_BEFORE" "$(count_games)" "the preview deleted nothing"

run_gt games delete "$USER_FLAG" --yes
assert_eq "2" "$GT_CODE" "bulk delete with no selector = 2"

run_gt games delete "$HALO_ID" "$USER_FLAG" --platform=PS2 --yes
assert_eq "2" "$GT_CODE" "an id together with a selector = 2"

OTHER_ID=$(fixture_id games 'FIXTURE Not Mine' other)
run_gt games delete "$OTHER_ID" "$USER_FLAG" --yes
assert_eq "1" "$GT_CODE" "deleting another user's game = 1"
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $OTHER_ID")" \
  "the other user's game survives"

blue "games delete — applies and journals its children"

run_gt_json games delete "$HALO_ID" "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "delete --yes exits 0"
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $HALO_ID")" \
  "the game is gone"

# The cascade fired: extra images destroyed, completion unlinked but alive.
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM game_images WHERE game_id = $HALO_ID")" \
  "cascaded game_images rows are gone"
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM game_completions
  WHERE title = 'FIXTURE Halo 3' AND game_id IS NULL")" \
  "the completion survives with a NULL game_id"

# Tombstones exist for the parent and for each cascaded image.
assert_eq "1" "$(count_tombstones games "$HALO_ID")" "a tombstone was written for the game"

DELETE_ENTRY=$(find "$GT_JOURNAL_DIR" -name '*-delete.json' | head -1)
jq -e '.committed == true and .operation == "delete"' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: delete is journalled and committed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: bad delete entry"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The whole row, not just the changed columns.
jq -e '.rows[0].before.title == "FIXTURE Halo 3" and .rows[0].before.platform == "Xbox 360" and (.rows[0].before | has("user_id"))' \
  < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: the entry holds the entire row"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: entry is missing row columns: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# And the children, which the parent row alone cannot reconstruct.
jq -e '(.rows[0].children.game_images | length) == 2' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: cascaded game_images are journalled"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: game_images not journalled: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

jq -e '(.rows[0].children.game_completions | length) == 1' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: unlinked completions are journalled"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: completions not journalled: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "games delete — bulk"

seed_games
run_gt_json games delete "$USER_FLAG" --platform=PS2
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 2' > /dev/null \
  && { green "  PASS: bulk delete previews 2 rows"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected bulk preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt_json games delete "$USER_FLAG" --platform=PS2 --yes
echo "$GT_JSON" | jq -e '.deleted == 2' > /dev/null \
  && { green "  PASS: bulk delete removed both rows"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected bulk delete: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "0" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM games g JOIN users u ON g.user_id = u.id
  WHERE u.username = '$FIXTURE_USER' AND g.platform = 'PS2'")" \
  "no PS2 rows remain"

blue "games delete — zero matches writes no journal entry"

JOURNAL_BEFORE=$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)
run_gt_json games delete "$USER_FLAG" --platform="NoSuchPlatform" --yes
assert_eq "0" "$GT_CODE" "zero-match delete exits 0"
assert_eq "$JOURNAL_BEFORE" "$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)" \
  "no journal entry for a zero-match delete"
```

And append to `tests/cli/test_gt_undo.sh`, before its final `summarize`:

```bash
blue "Undoing a delete restores the row, its children and clears tombstones"

seed_games
seed_game_children
UNDO_HALO=$(fixture_id games 'FIXTURE Halo 3' mine)

run_gt_json games delete "$UNDO_HALO" "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "delete for the undo test exits 0"
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM deletions WHERE table_name = 'games' AND server_id = $UNDO_HALO")" \
  "the delete left a tombstone"

run_gt undo "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "undo of a delete exits 0"

# The row comes back with its original id, which is what makes iOS delta sync
# and every foreign key line up again.
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $UNDO_HALO")" \
  "the game is restored under its original id"
assert_eq "FIXTURE Halo 3" \
  "$(fixture_mysql -N -e "SELECT title FROM games WHERE id = $UNDO_HALO")" \
  "the restored row keeps its values"

# Tombstones must go, or the next iOS sync deletes the row again locally and
# undo looks like it silently failed on the phone.
assert_eq "0" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM deletions WHERE table_name = 'games' AND server_id = $UNDO_HALO")" \
  "the game's tombstone was cleared"

# Children restored too. Without this the undo returns a game whose extra
# images were destroyed and whose completion history is silently unlinked.
assert_eq "2" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM game_images WHERE game_id = $UNDO_HALO")" \
  "cascaded game_images rows were restored"
assert_eq "0" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM deletions WHERE table_name = 'game_images' AND server_id IN (
    SELECT id FROM game_images WHERE game_id = $UNDO_HALO))" \
  "the images' tombstones were cleared"
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM game_completions WHERE game_id = $UNDO_HALO")" \
  "the completion was relinked to the restored game"

# The restore must carry a fresh updated_at. The phone has already deleted this
# row in response to the tombstone and only re-fetches rows newer than its last
# sync, so restoring the original timestamp would leave the row present on the
# server and permanently missing on the device.
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM games
  WHERE id = $UNDO_HALO AND updated_at >= NOW() - INTERVAL 1 MINUTE")" \
  "the restored row has a fresh updated_at for delta sync"

blue "Undoing a delete refuses when the id was taken"

seed_games
seed_game_children
TAKEN=$(fixture_id games 'FIXTURE Okami' mine)
run_gt games delete "$TAKEN" "$USER_FLAG" --yes

# Something else creates a row that lands on the same id. Undo must not
# overwrite it.
fixture_mysql -e "
  INSERT INTO games (id, user_id, title, platform)
  VALUES ($TAKEN, $(fixture_user_id "$FIXTURE_USER"), 'FIXTURE Squatter', 'PC');
"

run_gt undo "$USER_FLAG" --yes
assert_eq "FIXTURE Squatter" \
  "$(fixture_mysql -N -e "SELECT title FROM games WHERE id = $TAKEN")" \
  "undo refused rather than overwriting a row that took the id"
assert_contains "skipped" "$GT_OUT" "reports the skip"

blue "A delete entry cannot be reverted twice"

seed_games
seed_game_children
TWICE=$(fixture_id games 'FIXTURE Journey' mine)

run_gt_json games delete "$TWICE" "$USER_FLAG" --yes
TWICE_ENTRY=$(echo "$GT_JSON" | jq -r '.journal_id')

# Target the entry explicitly rather than relying on "most recent", so this
# asserts re-revert refusal and not merely that the stack moved on.
run_gt undo "$TWICE_ENTRY" "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "the first undo of an entry succeeds"
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $TWICE")" \
  "the row came back"

run_gt undo "$TWICE_ENTRY" "$USER_FLAG" --yes
assert_eq "1" "$GT_CODE" "re-undoing the same entry = 1"
assert_contains "already reverted" "$GT_OUT" "says the entry was already reverted"
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $TWICE")" \
  "the row is still there exactly once"
```

`tests/cli/test_gt_undo.sh` must `source fixtures.sh` and define `USER_FLAG`, `run_gt`, `run_gt_json` and `GT_JOURNAL_DIR`. It already does for the PR1 assertions; reuse them rather than redefining.

- [ ] **Step 3: Run tests to verify they fail**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A30 'test_gt_create_delete\|test_gt_undo'
```
Expected: FAIL — `gt games delete` is unregistered, so every call exits 2.

- [ ] **Step 4: Add the tombstone helper**

Create `src/Services/Write/Tombstones.php`:

```php
<?php

namespace GameTracker\Services\Write;

use PDO;

/**
 * Removes the tombstones a delete produced.
 *
 * Migration 002_deletions.php installs trg_<table>_after_delete on games,
 * items, game_images, item_images and game_completions; each writes a row into
 * `deletions`, which is how the iOS app learns about deletions during delta
 * sync. Restoring a deleted row without removing its tombstone would leave a
 * marker for a row that exists again — the next sync would delete it on the
 * phone, and undo would look like it had silently failed there.
 *
 * `deletions` has no trigger of its own, so clearing is silent.
 */
final class Tombstones
{
    /**
     * @param list<int> $serverIds
     * @return int rows removed
     */
    public static function clear(PDO $pdo, int $userId, string $table, array $serverIds): int
    {
        if ($serverIds === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($serverIds), '?'));

        // $table is a caller-supplied constant, never user input, but it is
        // bound rather than interpolated because table_name is a data column
        // here — not an identifier.
        $stmt = $pdo->prepare(
            "DELETE FROM deletions
             WHERE `user_id` = ? AND `table_name` = ? AND `server_id` IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$userId, $table], $serverIds));

        return $stmt->rowCount();
    }
}
```

- [ ] **Step 5: Add applyDelete and revertDelete**

In `src/Services/Write/GamesWriter.php`, extend the `revert()` match:

```php
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            'create' => self::revertCreate($pdo, $entry, $force),
            'delete' => self::revertDelete($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on games"
            ),
        };
```

Add these methods below `applyCreate()`:

```php
    /**
     * Delete every matching game, journalling enough to put it all back.
     *
     * "Enough" is more than the parent row. game_images.game_id is ON DELETE
     * CASCADE, so those rows are destroyed outright; game_completions.game_id
     * is ON DELETE SET NULL, so the completion survives but its link is gone —
     * and because that is an UPDATE, no tombstone fires and iOS never hears
     * about it. Restoring the parent alone therefore returns a game whose
     * extra images vanished and whose completion history was silently
     * unlinked, which the governing constraint does not permit.
     *
     * Image files are never unlinked. Several production games share one image
     * path, so removing the file would break a surviving game's cover — and
     * leaving it is what makes this operation genuinely reversible.
     *
     * @return array{journal_id: ?string, deleted: int}
     */
    public static function applyDelete(
        PDO $pdo,
        int $userId,
        FilterSet $filters,
        JournalWriter $journal,
        array $argv
    ): array {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $snapStmt = $pdo->prepare("SELECT * FROM games WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            return ['journal_id' => null, 'deleted' => 0];
        }

        $rows = [];
        foreach ($snapshot as $row) {
            $gameId = (int)$row['id'];

            $imgStmt = $pdo->prepare('SELECT * FROM game_images WHERE `game_id` = ?');
            $imgStmt->execute([$gameId]);

            $compStmt = $pdo->prepare('SELECT `id` FROM game_completions WHERE `game_id` = ?');
            $compStmt->execute([$gameId]);

            $rows[] = [
                'id' => $gameId,
                'updated_at' => $row['updated_at'],
                // The entire row: a delete has to be reconstructable, so
                // unlike `set` this is not limited to changed columns.
                'before' => $row,
                'children' => [
                    'game_images' => $imgStmt->fetchAll(PDO::FETCH_ASSOC),
                    'game_completions' => array_map(
                        static fn(array $c): int => (int)$c['id'],
                        $compStmt->fetchAll(PDO::FETCH_ASSOC)
                    ),
                ],
            ];
        }

        $id = $journal->newId('delete');
        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'delete', false, null, $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("DELETE FROM games WHERE {$where}");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'games', 'delete', true, null, $rows
        ));

        return ['journal_id' => $id, 'deleted' => $deleted];
    }

    /**
     * Restore deleted games, their children, and clear the tombstones.
     *
     * The conflict check is different in kind from set's: the row is gone, so
     * there is no updated_at to compare. What can go wrong instead is that the
     * id has been taken — by a later insert, or by a restore that already ran.
     * Overwriting that row would destroy someone else's data to undo ours, so
     * a taken id is skipped unless forced.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revertDelete(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM games WHERE `id` = ?');
                $exists->execute([$row['id']]);

                if ((int)$exists->fetchColumn() > 0 && !$force) {
                    $skipped++;
                    continue;
                }

                $before = $row['before'];

                // Ownership is not taken from the journal blindly: restoring a
                // row must not move it to a different owner than the entry
                // recorded acting as.
                $before['user_id'] = $entry->userId;

                // Drop updated_at so the column's DEFAULT CURRENT_TIMESTAMP
                // stamps the restore with now. Writing the original value back
                // would preserve history at the cost of correctness: iOS has
                // already deleted this row locally in response to the
                // tombstone, and it only re-fetches rows whose updated_at is
                // newer than its last sync. Restored with an old timestamp,
                // the row would exist on the server and stay missing on the
                // phone. created_at is kept — that one is genuinely history.
                unset($before['updated_at']);

                $columns = array_keys($before);
                $columnSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '`',
                    $columns
                ));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                // REPLACE rather than INSERT for the --force path: with an id
                // already taken, REPLACE deletes the squatter first. That
                // delete fires the tombstone trigger, which is exactly why
                // Tombstones::clear runs after this and not before.
                $stmt = $pdo->prepare(
                    "REPLACE INTO games ({$columnSql}) VALUES ({$placeholders})"
                );
                $stmt->execute(array_values($before));

                self::restoreChildren($pdo, $entry->userId, $row);

                Tombstones::clear($pdo, $entry->userId, 'games', [$row['id']]);

                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }

    /**
     * Put back what the cascade took: re-insert the destroyed game_images rows
     * under their original ids and clear their tombstones, and relink the
     * completions whose game_id was nulled.
     *
     * @param array{id:int, updated_at:?string, before:array, children?:array} $row
     */
    private static function restoreChildren(PDO $pdo, int $userId, array $row): void
    {
        $children = $row['children'] ?? [];

        $images = $children['game_images'] ?? [];
        $imageIds = [];

        foreach ($images as $image) {
            $image['user_id'] = $userId;
            $imageIds[] = (int)$image['id'];

            $columns = array_keys($image);
            $columnSql = implode(', ', array_map(
                static fn(string $c): string => '`' . $c . '`',
                $columns
            ));
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));

            $stmt = $pdo->prepare(
                "REPLACE INTO game_images ({$columnSql}) VALUES ({$placeholders})"
            );
            $stmt->execute(array_values($image));
        }

        Tombstones::clear($pdo, $userId, 'game_images', $imageIds);

        // Completions were never deleted — SET NULL only broke the link, which
        // is why there is no tombstone to clear for them.
        $completionIds = $children['game_completions'] ?? [];

        if ($completionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($completionIds), '?'));
            $stmt = $pdo->prepare(
                "UPDATE game_completions SET `game_id` = ?
                 WHERE `id` IN ({$placeholders}) AND `user_id` = ? AND `game_id` IS NULL"
            );
            $stmt->execute(array_merge([$row['id']], $completionIds, [$userId]));
        }
    }
```

- [ ] **Step 6: Add the delete command**

Create `src/Cli/Commands/Games/DeleteCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;
use GameTracker\Services\Write\GamesWriter;

/**
 * Deletes games.
 *
 * The only command that requires --yes even for a single row. A mistyped id in
 * `set` writes a field to the wrong game; the same typo here removes a game.
 * The magnitudes differ enough to justify the inconsistency with the
 * blast-radius rule every other write follows.
 */
final class DeleteCommand implements Command
{
    public const NAME = 'games delete';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Remove one game or many (always needs --yes)';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            GamesFilters::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = GamesFilters::definition();

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
            throw new UsageException(
                'no selector given — add a filter (see `gt games list --help`) or --all to delete every game'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        if ($id !== null) {
            GamesWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        $matched = GamesService::countMatching($ctx->pdo, $userId, $filters);

        if (!$ctx->flag('yes')) {
            return $this->preview($ctx, $matched);
        }

        $result = GamesWriter::applyDelete(
            $ctx->pdo,
            $userId,
            $filters,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf('deleted %d', $result['deleted']));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'deleted' => $result['deleted'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }

    private function preview(Context $ctx, int $matched): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would delete {$matched} rows");
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'matched' => $matched,
        ]);

        return 0;
    }
}
```

- [ ] **Step 7: Register it**

In `src/Cli/Application.php`, add the import:

```php
use GameTracker\Cli\Commands\Games\DeleteCommand as GamesDeleteCommand;
```

and add to `COMMANDS`, after `'games create'`:

```php
        'games delete' => GamesDeleteCommand::class,
```

- [ ] **Step 8: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass.

If the tombstone assertions fail with zero tombstones ever written, confirm the triggers exist in the *test* database — they are created by `002_deletions.php`, which needs `log_bin_trust_function_creators`:
```bash
mysql gameTracker_test -e "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='gameTracker_test';"
```
Expected: five `trg_*_after_delete` rows.

- [ ] **Step 9: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Services/Write/Tombstones.php src/Services/Write/GamesWriter.php \
        src/Cli/Commands/Games/DeleteCommand.php src/Cli/Application.php \
        tests/cli/fixtures.sh tests/cli/test_gt_create_delete.sh tests/cli/test_gt_undo.sh
git commit -m "feat(cli): gt games delete, reversible down to its child rows

The design spec journalled only the parent row. That is not enough: game_images
cascades away outright and game_completions.game_id is set to NULL without
firing a tombstone, so restoring the parent alone returns a game with its extra
images destroyed and its completion history silently unlinked. 48 production
games have completions and 2 are already orphaned from earlier web-app deletes.

Journal the children with the row, restore them on undo, and clear the
tombstones the cascade wrote. Image files are never unlinked."
```

---

### Task 6: `gt items delete`

Same shape, one cascade instead of two: `item_images.item_id` is `ON DELETE CASCADE` and there is no items equivalent of `game_completions`.

**Files:**
- Modify: `src/Services/Write/ItemsWriter.php` (add `applyDelete()`, `revertDelete()`; extend `revert()`)
- Create: `src/Cli/Commands/Items/DeleteCommand.php`
- Modify: `src/Cli/Application.php`
- Test: `tests/cli/test_gt_create_delete.sh`

**Interfaces:**
- Consumes: `Tombstones::clear()` (Task 5), `ItemsService::countMatching()` (Task 2), `ItemsWriter::assertOwned()` (Task 2)
- Produces: `ItemsWriter::applyDelete(PDO, int, FilterSet, JournalWriter, array): array{journal_id:?string, deleted:int}`, `ItemsWriter::revertDelete(PDO, JournalEntry, bool): array{restored:int, skipped:int}`

- [ ] **Step 1: Write the failing test**

Insert into `tests/cli/test_gt_create_delete.sh`, before the final `summarize`:

```bash
blue "items delete"

seed_items
CARD_ID=$(fixture_id items 'FIXTURE Memory Card' mine)

run_gt_json items delete "$CARD_ID" "$USER_FLAG"
assert_eq "0" "$GT_CODE" "items delete without --yes exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true' > /dev/null \
  && { green "  PASS: items delete previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: should have previewed: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM items WHERE id = $CARD_ID")" \
  "the preview deleted nothing"

run_gt items delete "$USER_FLAG" --yes
assert_eq "2" "$GT_CODE" "bulk items delete with no selector = 2"

OTHER_ITEM2=$(fixture_id items 'FIXTURE Not Mine Item' other)
run_gt items delete "$OTHER_ITEM2" "$USER_FLAG" --yes
assert_eq "1" "$GT_CODE" "deleting another user's item = 1"

run_gt_json items delete "$CARD_ID" "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "items delete --yes exits 0"
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM items WHERE id = $CARD_ID")" \
  "the item is gone"
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM deletions WHERE table_name = 'items' AND server_id = $CARD_ID")" \
  "a tombstone was written for the item"

run_gt undo "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "undo of an items delete exits 0"
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM items WHERE id = $CARD_ID")" \
  "the item is restored under its original id"
assert_eq "FIXTURE Memory Card" \
  "$(fixture_mysql -N -e "SELECT title FROM items WHERE id = $CARD_ID")" \
  "the restored item keeps its values"
assert_eq "0" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM deletions WHERE table_name = 'items' AND server_id = $CARD_ID")" \
  "the item's tombstone was cleared"
```

- [ ] **Step 2: Run test to verify it fails**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A20 'test_gt_create_delete'
```
Expected: FAIL — "unknown subcommand for 'items'. Available: list, get, set, create".

- [ ] **Step 3: Add applyDelete and revertDelete to ItemsWriter**

In `src/Services/Write/ItemsWriter.php`, extend the `revert()` match:

```php
        return match ($entry->operation) {
            'set' => self::revertSet($pdo, $entry, $force),
            'create' => self::revertCreate($pdo, $entry, $force),
            'delete' => self::revertDelete($pdo, $entry, $force),
            default => throw new BadRequestException(
                "cannot revert operation '{$entry->operation}' on items"
            ),
        };
```

Add these methods below `applyCreate()`:

```php
    /**
     * Delete every matching item, journalling the whole row and the
     * item_images rows the cascade destroys.
     *
     * Simpler than the games case: item_images is the only child, and there is
     * no items equivalent of game_completions' SET NULL relationship.
     *
     * @return array{journal_id: ?string, deleted: int}
     */
    public static function applyDelete(
        PDO $pdo,
        int $userId,
        FilterSet $filters,
        JournalWriter $journal,
        array $argv
    ): array {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $snapStmt = $pdo->prepare("SELECT * FROM items WHERE {$where}");
        $snapStmt->execute($params);
        $snapshot = $snapStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($snapshot === []) {
            return ['journal_id' => null, 'deleted' => 0];
        }

        $rows = [];
        foreach ($snapshot as $row) {
            $itemId = (int)$row['id'];

            $imgStmt = $pdo->prepare('SELECT * FROM item_images WHERE `item_id` = ?');
            $imgStmt->execute([$itemId]);

            $rows[] = [
                'id' => $itemId,
                'updated_at' => $row['updated_at'],
                'before' => $row,
                'children' => [
                    'item_images' => $imgStmt->fetchAll(PDO::FETCH_ASSOC),
                ],
            ];
        }

        $id = $journal->newId('delete');
        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'items', 'delete', false, null, $rows
        ));

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("DELETE FROM items WHERE {$where}");
            $stmt->execute($params);
            $deleted = $stmt->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $journal->write(new JournalEntry(
            $id, $argv, $userId, 'items', 'delete', true, null, $rows
        ));

        return ['journal_id' => $id, 'deleted' => $deleted];
    }

    /**
     * @return array{restored: int, skipped: int}
     */
    public static function revertDelete(PDO $pdo, JournalEntry $entry, bool $force): array
    {
        $restored = 0;
        $skipped = 0;

        $pdo->beginTransaction();

        try {
            foreach ($entry->rows as $row) {
                $exists = $pdo->prepare('SELECT COUNT(*) FROM items WHERE `id` = ?');
                $exists->execute([$row['id']]);

                if ((int)$exists->fetchColumn() > 0 && !$force) {
                    $skipped++;
                    continue;
                }

                $before = $row['before'];
                $before['user_id'] = $entry->userId;

                // See GamesWriter::revertDelete: dropping updated_at lets the
                // column default stamp the restore with now, which is what
                // makes the row visible to iOS delta sync after the phone has
                // already acted on the tombstone.
                unset($before['updated_at']);

                $columns = array_keys($before);
                $columnSql = implode(', ', array_map(
                    static fn(string $c): string => '`' . $c . '`',
                    $columns
                ));
                $placeholders = implode(', ', array_fill(0, count($columns), '?'));

                $stmt = $pdo->prepare(
                    "REPLACE INTO items ({$columnSql}) VALUES ({$placeholders})"
                );
                $stmt->execute(array_values($before));

                $imageIds = [];
                foreach ($row['children']['item_images'] ?? [] as $image) {
                    $image['user_id'] = $entry->userId;
                    $imageIds[] = (int)$image['id'];

                    $imgColumns = array_keys($image);
                    $imgColumnSql = implode(', ', array_map(
                        static fn(string $c): string => '`' . $c . '`',
                        $imgColumns
                    ));
                    $imgPlaceholders = implode(', ', array_fill(0, count($imgColumns), '?'));

                    $imgStmt = $pdo->prepare(
                        "REPLACE INTO item_images ({$imgColumnSql}) VALUES ({$imgPlaceholders})"
                    );
                    $imgStmt->execute(array_values($image));
                }

                Tombstones::clear($pdo, $entry->userId, 'item_images', $imageIds);
                Tombstones::clear($pdo, $entry->userId, 'items', [$row['id']]);

                $restored++;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return ['restored' => $restored, 'skipped' => $skipped];
    }
```

- [ ] **Step 4: Add the command**

Create `src/Cli/Commands/Items/DeleteCommand.php`:

```php
<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\ItemsFilters;
use GameTracker\Services\ItemsService;
use GameTracker\Services\Write\ItemsWriter;

/**
 * Deletes items. Always requires --yes, for the same reason games delete does.
 */
final class DeleteCommand implements Command
{
    public const NAME = 'items delete';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Remove one item or many (always needs --yes)';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            ItemsFilters::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = ItemsFilters::definition();

        $id = $args[0] ?? null;
        $hasSelector = array_intersect(array_keys($ctx->options), $filterDef->selectorNames()) !== [];

        if ($id !== null) {
            if (!preg_match('/^\d+$/', $id)) {
                throw new UsageException("item id must be a positive integer, got '{$id}'");
            }
            if ($hasSelector) {
                throw new UsageException(
                    'pass either an id or filters, not both — filters do not narrow an id'
                );
            }
        } elseif (!$hasSelector && !$ctx->flag('all')) {
            throw new UsageException(
                'no selector given — add a filter (see `gt items list --help`) or --all to delete every item'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        if ($id !== null) {
            ItemsWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        $matched = ItemsService::countMatching($ctx->pdo, $userId, $filters);

        if (!$ctx->flag('yes')) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line("would delete {$matched} rows");
                $ctx->output->line('re-run with --yes to apply');

                return 0;
            }

            $ctx->output->record(['dry_run' => true, 'matched' => $matched]);

            return 0;
        }

        $result = ItemsWriter::applyDelete(
            $ctx->pdo,
            $userId,
            $filters,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf('deleted %d', $result['deleted']));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'deleted' => $result['deleted'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
```

- [ ] **Step 5: Register it**

In `src/Cli/Application.php`, add the import:

```php
use GameTracker\Cli\Commands\Items\DeleteCommand as ItemsDeleteCommand;
```

and add to `COMMANDS`, after `'items create'`:

```php
        'items delete' => ItemsDeleteCommand::class,
```

- [ ] **Step 6: Run the tests to verify they pass**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh
```
Expected: exit 0, all suites pass.

- [ ] **Step 7: Commit**

```bash
cd ~/worktrees/cli-pr2
git add src/Services/Write/ItemsWriter.php src/Cli/Commands/Items/DeleteCommand.php \
        src/Cli/Application.php tests/cli/test_gt_create_delete.sh
git commit -m "feat(cli): gt items delete

item_images is the only cascade here — items has no equivalent of
game_completions' SET NULL link, so the restore path is the simpler half of
the games one."
```

---

### Task 7: Guard, docs and a full-harness verification

Closes the sub-project. The read-only guard needs no code change — PR1 already narrowed it to permit `src/Services/Write/` — but this task proves it still has teeth against everything PR2 added, and documents the new surface.

**Files:**
- Modify: `tests/cli/test_readonly_guard.sh` (add a negative control)
- Modify: `src/Cli/Application.php` (bump `VERSION`)
- Modify: `CLAUDE.md`
- Test: the whole harness

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_readonly_guard.sh`, immediately before `summarize`:

```bash
# Negative control: the guard must fail when write SQL appears outside the
# permitted directory. Without this the two checks above could both pass
# because the grep silently stopped matching, not because the tree is clean.
PROBE_DIR="$PROJECT_ROOT/src/Query"
PROBE_FILE="$PROBE_DIR/__guard_probe.php"
cat > "$PROBE_FILE" <<'PHP'
<?php
// Temporary probe written by test_readonly_guard.sh.
$sql = 'DELETE FROM games WHERE id = ?';
PHP

PROBE_LEAK=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src" \
               --include='*.php' \
               | grep -v '/src/Services/Write/' || true)
rm -f "$PROBE_FILE"

if [[ -n "$PROBE_LEAK" ]]; then
  green "  PASS: the guard catches write SQL planted outside src/Services/Write/"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: the guard did not catch a planted DELETE — it is vacuous"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# Every writer added by sub-project #2 must be inside the permitted directory.
for writer in GamesWriter ItemsWriter Tombstones; do
  if [[ -f "$PROJECT_ROOT/src/Services/Write/$writer.php" ]]; then
    green "  PASS: $writer lives under src/Services/Write/"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $writer.php is not under src/Services/Write/"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
done

# AssignmentSet builds column lists but must never contain a write statement,
# or the guard would have to permit src/Write/ too and lose its meaning.
if grep -qiE 'INSERT[[:space:]]+INTO|DELETE[[:space:]]+FROM' "$PROJECT_ROOT/src/Write/AssignmentSet.php"; then
  red "  FAIL: AssignmentSet contains write SQL — assemble statements in the writer"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: AssignmentSet builds fragments, not statements"
  PASS_COUNT=$((PASS_COUNT+1))
fi
```

- [ ] **Step 2: Run it to confirm it passes for the right reason**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A15 'readonly_guard'
```
Expected: all guard assertions PASS. Then deliberately break it to prove the control works — temporarily add `$x = 'INSERT INTO games (title) VALUES (?)';` to `src/Query/GamesFilters.php`, re-run, confirm the suite FAILS, then remove it.

- [ ] **Step 3: Bump the version**

In `src/Cli/Application.php`, change:

```php
    public const VERSION = '0.2.0';
```

to:

```php
    public const VERSION = '0.3.0';
```

- [ ] **Step 4: Document the new commands**

In `CLAUDE.md`, find the section PR1 added documenting `gt games set` and the journal (commit `05758d0`). Extend its command list to:

```markdown
### `gt` write commands

    gt games set <id|filters> --set-<col>=<val> [--yes]     change fields
    gt games create --set-title=… --set-platform=…          add one game
    gt games delete <id|filters> --yes                      remove games
    gt items set|create|delete                              same shape for items
    gt undo [--list] [<journal-id>] [--yes] [--force]       reverse a write

`--yes` is required when an operation affects more than one row, and always for
`delete`. Without it the run is a dry run that reports what would change and
writes nothing. A bulk write with no filters needs `--all`.

`create` requires the columns that are `NOT NULL` with no default: `title` and
`platform` for games, `title` and `category` for items — items' `platform` is
nullable, games' is not.

Every applied write is journalled to `~/.gt/journal` (mode 0700, override with
`GT_JOURNAL_DIR`) and reversible with `gt undo`. Undo compares each row's
post-write `updated_at` and refuses if something else has touched the row since;
`--force` overrides.

`delete` never unlinks image files — several games share one image path, so
removing the file would break a surviving game's cover. Undoing a delete
restores the row under its original id, re-inserts the child rows the cascade
destroyed (`game_images` / `item_images`), relinks `game_completions` whose
`game_id` was set to NULL, and clears the tombstones the delete wrote into
`deletions` so the next iOS sync does not delete the row again.
```

- [ ] **Step 5: Run the complete harness**

Run:
```bash
cd ~/worktrees/cli-pr2
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh; echo "run-all exit=$?"
```
Expected: `run-all exit=0`, every suite green — this is the only run that proves anything, because all suites share one database and a partial run hides cross-suite interference.

Then confirm the command surface:
```bash
cd ~/worktrees/cli-pr2 && ./bin/gt --version && ./bin/gt help
```
Expected: `gt 0.3.0`, and the command list includes `games create`, `games delete`, `items set`, `items create`, `items delete`.

- [ ] **Step 6: Commit**

```bash
cd ~/worktrees/cli-pr2
git add tests/cli/test_readonly_guard.sh src/Cli/Application.php CLAUDE.md
git commit -m "test(cli): negative control for the read-only guard, and PR2 docs

The guard's two checks could both pass because the grep stopped matching
rather than because the tree is clean. Plant a DELETE outside
src/Services/Write/, confirm the guard sees it, remove it."
```

---

## Verification before opening the PR

Per `superpowers:verification-before-completion` — evidence, not assertions.

- [ ] Full harness green: `bash tests/v2/run-all.sh; echo "exit=$?"` prints `exit=0`
- [ ] `./bin/gt help` lists all 14 commands
- [ ] The production database was never touched: every test run used `gameTracker_test` (`setup-test-db.sh` drops and recreates it) and every `gt` invocation in the suites resolves `--user=gtfixture`
- [ ] `git log --oneline main..HEAD` shows the 7 task commits
- [ ] Push and open the PR against `main`; confirm CI with `gh pr checks <n>` **and** the run's `conclusion` — a shell pipeline can exit 0 while `gh` itself failed
- [ ] Lint with `./node_modules/.bin/eslint` or `npm run lint`, never `npx eslint` (npx pulls ESLint 10, which needs Node 20; this box runs 18)

Merging and deploying are separate decisions, and Cameron's to make. Merge server-side from `~`, never from inside `/var/www/gameTracker`:

```bash
cd ~ && gh pr merge <n> -R CammyBlack02/gameTracker --merge --delete-branch
```

Then remove the worktree:

```bash
cd /var/www/gameTracker && git worktree remove ~/worktrees/cli-pr2
```
