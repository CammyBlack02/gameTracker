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

blue "items create"

count_items() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM items i JOIN users u ON i.user_id = u.id
    WHERE u.username = '$FIXTURE_USER'
  "
}

# items requires title and category. platform is nullable here, unlike games.
run_gt items create "$USER_FLAG" --set-title="FIXTURE New Item"
assert_eq "2" "$GT_CODE" "items create without category = 2"
assert_contains "category" "$GT_OUT" "names the missing category"

run_gt items create "$USER_FLAG" --set-category="Cable"
assert_eq "2" "$GT_CODE" "items create without title = 2"

ITEMS_BEFORE=$(count_items)
run_gt_json items create "$USER_FLAG" --set-title="FIXTURE New Item" --set-category="Cable"
assert_eq "0" "$GT_CODE" "items create without platform exits 0 — platform is nullable"
assert_eq "$((ITEMS_BEFORE + 1))" "$(count_items)" "one item was added"

NEW_ITEM=$(echo "$GT_JSON" | jq -r '.id')
assert_eq "$FIXTURE_UID" \
  "$(fixture_mysql -N -e "SELECT user_id FROM items WHERE id = $NEW_ITEM")" \
  "the created item is owned by the acting user"

# A games-only column is still rejected on the items create path.
run_gt items create "$USER_FLAG" --set-title="X" --set-category="Y" --set-star_rating=5
assert_eq "2" "$GT_CODE" "a games-only column is rejected on items create"

# And undo removes it.
run_gt undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo of an items create exits 0"
assert_eq "$ITEMS_BEFORE" "$(count_items)" "undo removed the created item"

blue "games delete — always needs --yes"

seed_games
seed_game_children
HALO_ID=$(fixture_id games 'FIXTURE Halo 3' mine)

count_tombstones() {
  fixture_mysql -N -e "
    SELECT COUNT(*) FROM deletions
    WHERE table_name = '$1' AND server_id = $2
  "
}

# Even a single row named by id previews rather than applying. This is the one
# place the blast-radius rule bends: a mistyped id in `set` writes a field to
# the wrong game, the same typo in `delete` removes one.
DELETE_BEFORE=$(count_games)
run_gt_json games delete "$HALO_ID" "$USER_FLAG"
assert_eq "0" "$GT_CODE" "delete without --yes exits 0"
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 1' > /dev/null \
  && { green "  PASS: single-row delete previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: delete should have previewed: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "$DELETE_BEFORE" "$(count_games)" "the preview deleted nothing"

run_gt games delete "$USER_FLAG" --yes
assert_eq "2" "$GT_CODE" "bulk delete with no selector = 2"

run_gt games delete "$HALO_ID" "$USER_FLAG" --platform=PS2 --yes
assert_eq "2" "$GT_CODE" "an id together with a selector = 2"

OTHER_ID=$(fixture_id games 'FIXTURE Not Mine' other)
run_gt games delete "$OTHER_ID" "$USER_FLAG" --yes
assert_eq "1" "$GT_CODE" "deleting another user's game = 1"
assert_eq "1" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $OTHER_ID")" \
  "the other user's game survives"

blue "games delete — applies and journals its children"

run_gt_json games delete "$HALO_ID" "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "delete --yes exits 0"
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE id = $HALO_ID")" \
  "the game is gone"

# The cascade fired: extra images destroyed, completion unlinked but alive.
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM game_images WHERE game_id = $HALO_ID")" \
  "cascaded game_images rows are gone"
assert_eq "1" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM game_completions
  WHERE title = 'FIXTURE Halo 3' AND game_id IS NULL")" \
  "the completion survives with a NULL game_id"

# Tombstones exist for the parent and for each cascaded image.
assert_eq "1" "$(count_tombstones games "$HALO_ID")" "a tombstone was written for the game"

DELETE_ENTRY=$(find "$GT_JOURNAL_DIR" -name '*-delete.json' | head -1)
jq -e '.committed == true and .operation == "delete"' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: delete is journalled and committed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: bad delete entry"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The whole row, not just the changed columns.
jq -e '.rows[0].before.title == "FIXTURE Halo 3" and .rows[0].before.platform == "Xbox 360" and (.rows[0].before | has("user_id"))' \
  < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: the entry holds the entire row"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: entry is missing row columns: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# And the children, which the parent row alone cannot reconstruct.
jq -e '(.rows[0].children.game_images | length) == 2' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: cascaded game_images are journalled"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: game_images not journalled: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

jq -e '(.rows[0].children.game_completions | length) == 1' < "$DELETE_ENTRY" > /dev/null \
  && { green "  PASS: unlinked completions are journalled"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: completions not journalled: $(cat "$DELETE_ENTRY")"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "games delete — bulk"

seed_games
run_gt_json games delete "$USER_FLAG" --platform=PS2
echo "$GT_JSON" | jq -e '.dry_run == true and .matched == 2' > /dev/null \
  && { green "  PASS: bulk delete previews 2 rows"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected bulk preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt_json games delete "$USER_FLAG" --platform=PS2 --yes
echo "$GT_JSON" | jq -e '.deleted == 2' > /dev/null \
  && { green "  PASS: bulk delete removed both rows"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected bulk delete: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "0" "$(fixture_mysql -N -e "
  SELECT COUNT(*) FROM games g JOIN users u ON g.user_id = u.id
  WHERE u.username = '$FIXTURE_USER' AND g.platform = 'PS2'")" \
  "no PS2 rows remain"

blue "games delete — zero matches writes no journal entry"

JOURNAL_BEFORE=$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)
run_gt_json games delete "$USER_FLAG" --platform="NoSuchPlatform" --yes
assert_eq "0" "$GT_CODE" "zero-match delete exits 0"
assert_eq "$JOURNAL_BEFORE" "$(find "$GT_JOURNAL_DIR" -name '*.json' | wc -l)" \
  "no journal entry for a zero-match delete"

summarize
