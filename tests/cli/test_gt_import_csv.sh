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

summarize
