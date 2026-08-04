#!/usr/bin/env bash
# `gt import csv` — parsing, routing, matching and the write path.
#
# Design: docs/superpowers/specs/2026-08-04-gt-cli-import-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
CSV="$PROJECT_ROOT/tests/cli/fixtures/gameeye-sample.csv"

blue "CsvSource routing (gameeye profile)"

ROUTED=$(php -r '
  require $argv[1] . "/src/autoload.php";
  $src = new GameTracker\Import\CsvSource(
      $argv[2],
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $counts = ["games" => 0, "items" => 0];
  foreach ($src->rows() as $row) { $counts[$row->table]++; }
  echo $counts["games"], " ", $counts["items"], " ", $src->skipped();
' -- "$PROJECT_ROOT" "$CSV")

assert_eq "2 3 2" "$ROUTED" "routes 2 games, 3 items, skips 2 (Wishlist + unknown category)"

ITEM_CATS=$(php -r '
  require $argv[1] . "/src/autoload.php";
  $src = new GameTracker\Import\CsvSource(
      $argv[2],
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $cats = [];
  foreach ($src->rows() as $row) {
      if ($row->table === "items") { $cats[] = $row->columns["category"]; }
  }
  sort($cats); echo implode(",", $cats);
' -- "$PROJECT_ROOT" "$CSV")

assert_eq "Accessory,Accessory,Console" "$ITEM_CATS" "Systems becomes Console, accessories become Accessory"

blue "Unknown profile"

UNKNOWN=$(php -r '
  require $argv[1] . "/src/autoload.php";
  try { GameTracker\Import\CsvProfile::named("nope"); echo "no-throw"; }
  catch (Throwable $e) { echo get_class($e); }
' -- "$PROJECT_ROOT")
assert_contains "UsageException" "$UNKNOWN" "an unknown profile is a usage error"

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
  require $argv[1] . "/src/autoload.php";
  require $argv[1] . "/includes/config.php";
  $src = new GameTracker\Import\CsvSource(
      $argv[2],
      GameTracker\Import\CsvProfile::named("gameeye")
  );
  $r = GameTracker\Services\Write\Importer::plan($pdo, (int)$argv[3], $src);
  echo count($r["candidates"]), " ", $r["matched"], " ", $r["skipped"];
' -- "$PROJECT_ROOT" "$CSV" "$FIXTURE_UID")

assert_eq "4 1 2" "$PLAN" "matches the planted row on normalised title, leaving 4 candidates"

fixture_mysql -e "DELETE FROM games WHERE user_id = $FIXTURE_UID AND title = 'import halo 3™'"

blue "gt import csv"

export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_JOURNAL_DIR"' EXIT

GT_CODE=0; GT_OUT=""; GT_JSON=""
run_gt()      { set +e; GT_OUT=$("$GT" "$@" 2>&1);         GT_CODE=$?; set -e; }
run_gt_json() { set +e; GT_JSON=$("$GT" "$@" 2>/dev/null); GT_CODE=$?; set -e; }

seed_games
seed_items
USER_FLAG="--user=$FIXTURE_USER"

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

summarize
