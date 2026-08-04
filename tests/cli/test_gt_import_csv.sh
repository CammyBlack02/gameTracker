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

summarize
