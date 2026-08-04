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

blue "gt images prune"

source "$(dirname "$0")/fixtures.sh"
seed_games
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

# A throwaway uploads tree and trash dir. Never the real ones.
export GT_UPLOADS_DIR="$(mktemp -d)"
export GT_TRASH_DIR="$(mktemp -d)"
export GT_JOURNAL_DIR="$(mktemp -d)"
trap 'rm -rf "$GT_UPLOADS_DIR" "$GT_TRASH_DIR" "$GT_JOURNAL_DIR"' EXIT

mkdir -p "$GT_UPLOADS_DIR/covers/thumbs" "$GT_UPLOADS_DIR/extras/thumbs"
printf 'live'   > "$GT_UPLOADS_DIR/covers/live.jpg"
printf 'livet'  > "$GT_UPLOADS_DIR/covers/thumbs/live.jpg"
printf 'orphan' > "$GT_UPLOADS_DIR/covers/orphan.jpg"
printf 'orpht'  > "$GT_UPLOADS_DIR/covers/thumbs/orphan.jpg"

# One fixture game references live.jpg; nothing references orphan.jpg.
fixture_mysql -e "
  UPDATE games SET front_cover_image = 'live.jpg'
  WHERE user_id = $FIXTURE_UID AND title = 'FIXTURE Halo 3';
"

run_gt() { set +e; GT_OUT=$("$GT" "$@" 2>&1); GT_CODE=$?; set -e; }

blue "Preview moves nothing"

run_gt images prune
assert_eq "0" "$GT_CODE" "prune without --yes exits 0"
[[ -f "$GT_UPLOADS_DIR/covers/orphan.jpg" ]] \
  && { green "  PASS: the preview moved nothing"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: preview moved a file"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Applying moves orphans to trash — and nowhere else"

run_gt_json images prune --yes
assert_eq "0" "$GT_CODE" "prune --yes exits 0"

TRASH_ID=$(echo "$GT_JSON" | jq -r '.trash_id')

[[ ! -f "$GT_UPLOADS_DIR/covers/orphan.jpg" ]] \
  && { green "  PASS: the orphan left uploads"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: orphan still in uploads"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The assertion that matters. "Gone from uploads" is also what a bug that
# unlinks them would produce, so prove the bytes are recoverable.
if [[ -f "$GT_TRASH_DIR/$TRASH_ID/covers/orphan.jpg" ]]; then
  green "  PASS: the orphan is IN TRASH, not deleted"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: orphan not found in trash — was it unlinked?"; FAIL_COUNT=$((FAIL_COUNT+1))
  find "$GT_TRASH_DIR" -type f | head -5
fi

if [[ -f "$GT_TRASH_DIR/$TRASH_ID/covers/thumbs/orphan.jpg" ]]; then
  green "  PASS: the orphan's thumbnail followed it"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: orphan thumbnail not in trash"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "A referenced file and its thumbnail are untouched"

[[ -f "$GT_UPLOADS_DIR/covers/live.jpg" ]] \
  && { green "  PASS: the referenced file survived"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: prune moved a referenced file"; FAIL_COUNT=$((FAIL_COUNT+1)); }

[[ -f "$GT_UPLOADS_DIR/covers/thumbs/live.jpg" ]] \
  && { green "  PASS: the live thumbnail survived"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: prune destroyed a live thumbnail"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Restore puts them back"

run_gt images prune "--restore=$TRASH_ID"
assert_eq "0" "$GT_CODE" "restore exits 0"
[[ -f "$GT_UPLOADS_DIR/covers/orphan.jpg" ]] \
  && { green "  PASS: restore returned the file"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: restore did not return the file"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt images prune "--restore=no-such-batch"
assert_eq "2" "$GT_CODE" "restoring an unknown batch is a usage error"

blue "--broken-cover"

# Four rows, one of each storage mode plus a genuinely broken one. Only the
# last must match: treating a URL or a data URI as a path is the bug that
# invented 42 broken covers on 2026-08-03.
# Control EVERY row's cover, not just the four of interest. seed_games gives
# some rows an 'a.jpg'/'b.jpg' cover that is absent from this temp tree, and
# those are then correctly reported broken — which would look like a predicate
# bug rather than an uncontrolled fixture.
fixture_mysql -e "
  UPDATE games SET front_cover_image = 'live.jpg', back_cover_image = NULL
    WHERE user_id = $FIXTURE_UID;
  UPDATE games SET front_cover_image = 'https://example.com/remote.jpg'
    WHERE user_id = $FIXTURE_UID AND title = 'FIXTURE Silent Hill';
  UPDATE games SET front_cover_image = 'data:image/gif;base64,AAAA/BBBB//9k='
    WHERE user_id = $FIXTURE_UID AND title = 'FIXTURE Okami';
  UPDATE games SET front_cover_image = 'vanished.jpg'
    WHERE user_id = $FIXTURE_UID AND title = 'FIXTURE Journey';
"

run_gt_json games list "--user=$FIXTURE_USER" --broken-cover
assert_eq "0" "$GT_CODE" "--broken-cover exits 0"

BROKEN=$(echo "$GT_JSON" | jq -r '[.games[].title] | sort | join(",")')
assert_eq "FIXTURE Journey" "$BROKEN" \
  "only the row naming an absent file is broken — not the URL, not the data URI"

# The count must be the true total, not a page's worth.
echo "$GT_JSON" | jq -e '.pagination.total == 1 and .pagination.has_more == false' > /dev/null \
  && { green "  PASS: reports the true total, not a page count"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: pagination misreports a scan: $(echo "$GT_JSON" | jq -c .pagination)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# A row whose file exists must never be flagged.
echo "$GT_JSON" | jq -e '[.games[].title] | index("FIXTURE Halo 3") == null' > /dev/null \
  && { green "  PASS: a row whose file exists is not flagged"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: flagged a row whose file is present"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
