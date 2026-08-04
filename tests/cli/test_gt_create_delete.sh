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
