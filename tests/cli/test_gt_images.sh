#!/usr/bin/env bash
# Image storage-mode classification and reconciliation.
#
# Design: docs/superpowers/specs/2026-08-04-gt-cli-images-design.md
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

mode() {
  php -r 'require $argv[1]."/src/autoload.php"; echo GameTracker\Images\StorageMode::of($argv[2]);' \
    -- "$PROJECT_ROOT" "$1"
}

blue "Storage mode classification"

assert_eq "filename" "$(mode 'cover_123_abc.jpg')"                  "a bare filename"
assert_eq "url"      "$(mode 'https://cdn.example.com/a.jpg')"      "an https URL"
assert_eq "url"      "$(mode 'http://cdn.example.com/a.jpg')"       "an http URL"
assert_eq "empty"    "$(mode '')"                                   "an empty value"
assert_eq "data-uri" "$(mode 'data:image/gif;base64,R0lGODlhAQ==')" "a data URI"

# The exact input that broke the 2026-08-03 audit: base64 contains '/', so
# taking a basename turns an inline image into a fake filename like "9k=".
assert_eq "data-uri" "$(mode 'data:image/jpeg;base64,AAAA/BBBB//9k=')" \
  "a data URI containing slashes is not a filename"

# Case must not decide it either — a storefront may send DATA: or HTTPS://.
assert_eq "data-uri" "$(mode 'DATA:image/png;base64,AAAA')" "uppercase data: scheme"
assert_eq "url"      "$(mode 'HTTPS://example.com/a.jpg')"  "uppercase https scheme"

blue "Reconciliation"

RESULT=$(php -r '
  require $argv[1]."/src/autoload.php";
  $r = GameTracker\Images\Reconciler::reconcile(
      ["a.jpg", "b.jpg", "gone.jpg"],        // referenced
      ["a.jpg", "b.jpg", "orphan.jpg"],      // on disk
      ["a.jpg", "orphan.jpg"]                // thumbs
  );
  echo implode(",", $r["orphans"]), "|",
       implode(",", $r["missing"]), "|",
       implode(",", $r["prunableThumbs"]), "|",
       $r["keptThumbs"];
' -- "$PROJECT_ROOT")

assert_eq "orphan.jpg|gone.jpg|orphan.jpg|1" "$RESULT" \
  "orphans, missing, and thumbs following their source"

blue "A thumbnail whose source is referenced is never prunable"

SAFE=$(php -r '
  require $argv[1]."/src/autoload.php";
  $r = GameTracker\Images\Reconciler::reconcile(["live.jpg"], ["live.jpg"], ["live.jpg"]);
  echo count($r["prunableThumbs"]), " ", $r["keptThumbs"];
' -- "$PROJECT_ROOT")

assert_eq "0 1" "$SAFE" "a live thumbnail is kept"

blue "The production shape: most thumbs must survive"

# 1,089 of 1,187 production thumbnails have a referenced source. A reconciler
# that judged thumbnails on their own would mark every one prunable, which is
# the failure this asserts against in miniature.
SHAPE=$(php -r '
  require $argv[1]."/src/autoload.php";
  $referenced = []; $disk = []; $thumbs = [];
  for ($i = 0; $i < 100; $i++) { $referenced[] = "live$i.jpg"; $disk[] = "live$i.jpg"; $thumbs[] = "live$i.jpg"; }
  for ($i = 0; $i < 5; $i++)   { $disk[] = "dead$i.jpg"; $thumbs[] = "dead$i.jpg"; }
  $r = GameTracker\Images\Reconciler::reconcile($referenced, $disk, $thumbs);
  echo count($r["orphans"]), " ", count($r["prunableThumbs"]), " ", $r["keptThumbs"];
' -- "$PROJECT_ROOT")

assert_eq "5 5 100" "$SHAPE" "only the 5 dead thumbs are prunable, all 100 live ones survive"

blue "gt images audit"

GT="$PROJECT_ROOT/bin/gt"
GT_CODE=0; GT_JSON=""
run_gt_json() { set +e; GT_JSON=$("$GT" "$@" 2>/dev/null); GT_CODE=$?; set -e; }

run_gt_json images audit
assert_eq "0" "$GT_CODE" "images audit exits 0"

echo "$GT_JSON" | jq -e 'has("by_mode") and has("orphans") and has("missing") and has("thumbnails")' > /dev/null \
  && { green "  PASS: reports modes, orphans, missing and thumbnails"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected audit shape: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Audit is read-only — it must never report having written.
echo "$GT_JSON" | jq -e '.dry_run != false' > /dev/null \
  && { green "  PASS: audit is read-only"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: audit reported a write"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# It must classify by storage mode, not treat every value as a path.
echo "$GT_JSON" | jq -e '.by_mode["games.front_cover_image"] | has("data-uri") and has("url") and has("filename")' > /dev/null \
  && { green "  PASS: reports per-column storage modes"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: by_mode missing storage modes"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The mismatch guard: prune consumes these numbers, so an audit pointed at the
# wrong uploads directory must say so rather than reporting every live file as
# an orphan candidate.
echo "$GT_JSON" | jq -e 'has("suspect_mismatch")' > /dev/null \
  && { green "  PASS: reports whether the numbers look like a wrong-directory mismatch"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no suspect_mismatch field"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
