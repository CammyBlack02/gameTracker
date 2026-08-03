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
