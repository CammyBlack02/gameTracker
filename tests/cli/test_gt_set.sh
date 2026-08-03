#!/usr/bin/env bash
# Validation and dry-run behaviour for `gt games set`.
#
# Apply paths (single-row and bulk --yes) are asserted in Task 2, once the
# journal and writer exist. This suite proves nothing is written and that every
# malformed invocation is rejected.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
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

# Snapshot a column so we can prove a dry run changed nothing.
genre_of() {
  fixture_mysql -N -e "
    SELECT COALESCE(g.genre, 'NULL') FROM games g
    JOIN users u ON g.user_id = u.id
    WHERE g.title = '$1' AND u.username = '$FIXTURE_USER'
  "
}

blue "Usage errors"

run_gt games set "$USER_FLAG" --set-genre=Foo
assert_eq "2" "$GT_CODE" "bulk write with no selector = 2"
assert_contains "no selector" "$GT_OUT" "explains the missing selector"

run_gt games set "$USER_FLAG" --platform=PS2
assert_eq "2" "$GT_CODE" "nothing to set = 2"
assert_contains "nothing to set" "$GT_OUT" "says nothing was assigned"

run_gt games set "$USER_FLAG" --platform=PS2 --set-nosuchcolumn=x
assert_eq "2" "$GT_CODE" "unknown --set- column = 2"
assert_contains "nosuchcolumn" "$GT_OUT" "names the bad column"

run_gt games set "$USER_FLAG" --platform=PS2 --set-user_id=99
assert_eq "2" "$GT_CODE" "user_id is not writable = 2"

run_gt games set "$USER_FLAG" --platform=PS2 --set-id=5
assert_eq "2" "$GT_CODE" "id is not writable = 2"

run_gt games set "$USER_FLAG" --platform=PS2 --set-updated_at=now
assert_eq "2" "$GT_CODE" "updated_at is not writable = 2"

# A bare --set- flag means "true" and only makes sense on the boolean columns.
run_gt games set "$USER_FLAG" --platform=PS2 --set-title
assert_eq "2" "$GT_CODE" "valueless --set- on a non-boolean column = 2"
assert_contains "needs a value" "$GT_OUT" "explains that title needs a value"

run_gt games set "$USER_FLAG" --platform=PS2 --clear-title
assert_eq "2" "$GT_CODE" "--clear- on a NOT NULL column = 2"
assert_contains "cannot be cleared" "$GT_OUT" "explains title cannot be NULL"

run_gt games set "$USER_FLAG" --platform=PS2 --set-genre=A --clear-genre
assert_eq "2" "$GT_CODE" "setting and clearing the same column = 2"

run_gt games set 999999 "$USER_FLAG" --platform=PS2 --set-genre=A
assert_eq "2" "$GT_CODE" "an id together with a selector = 2"
assert_contains "both" "$GT_OUT" "explains id and filters are exclusive"

run_gt games set notanumber "$USER_FLAG" --set-genre=A
assert_eq "2" "$GT_CODE" "non-numeric id = 2"

blue "Dry run previews and writes nothing"

BEFORE=$(genre_of 'FIXTURE Okami')

run_gt_json games set "$USER_FLAG" --platform=PS2 --set-genre=Changed
assert_eq "0" "$GT_CODE" "bulk dry run exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 2' > /dev/null \
  && { green "  PASS: reports dry_run and the matched count"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$GT_JSON" | jq -e '.assignments.genre == "Changed"' > /dev/null \
  && { green "  PASS: preview shows the assignment"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: assignment missing: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

assert_eq "$BEFORE" "$(genre_of 'FIXTURE Okami')" "dry run changed nothing in the database"

# --all replaces a selector for whole-collection operations.
run_gt_json games set "$USER_FLAG" --all --set-genre=Changed
assert_eq "0" "$GT_CODE" "--all is accepted as a selector"
echo "$GT_JSON" | jq -e '.matched == 5' > /dev/null \
  && { green "  PASS: --all matches every row the user owns"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: --all matched wrong count: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Scoping: the other user's fixture row must never be matched.
run_gt_json games set "$USER_FLAG" --all --set-genre=X
echo "$GT_JSON" | jq -e '.matched == 5' > /dev/null \
  && { green "  PASS: another user's rows are not matched"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: scoping leak: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Zero matches"

run_gt_json games set "$USER_FLAG" --platform="NoSuchPlatform" --set-genre=A
assert_eq "0" "$GT_CODE" "zero matches exits 0"
echo "$GT_JSON" | jq -e '.matched == 0' > /dev/null \
  && { green "  PASS: reports 0 matched"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected 0 matched: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
