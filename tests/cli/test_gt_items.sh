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
