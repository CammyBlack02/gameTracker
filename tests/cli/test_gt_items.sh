#!/usr/bin/env bash
# Filter behaviour for `gt items list` and `gt items get`.
#
# items has its own column set — no played, no star_rating, but it does have
# category — so its filter allowlist is deliberately not the games one.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_items

# Act as the dedicated fixture user, not $TEST_USER — see fixtures.sh.
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
ITEM_ID=$(fixture_id items 'FIXTURE Dual Shock' mine)
OTHER_ITEM=$(fixture_id items 'FIXTURE Not Mine Item' other)

assert_eq "FIXTURE Dual Shock" "$("$GT" items get "$ITEM_ID" "$USER_FLAG" 2>/dev/null | jq -r '.title')" "items get returns the row"

run_gt items get 999999 "$USER_FLAG"
assert_eq "1" "$GT_CODE" "missing item = 1"

run_gt items get "$OTHER_ITEM" "$USER_FLAG"
assert_eq "1" "$GT_CODE" "another user's item = 1"
assert_contains "another user" "$GT_OUT" "distinguishes denied from missing"

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

summarize
