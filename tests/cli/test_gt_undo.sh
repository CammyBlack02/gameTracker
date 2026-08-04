#!/usr/bin/env bash
# `gt undo` — reverting journalled writes, and refusing when it is not safe.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
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

genre_of() {
  fixture_mysql -N -e "
    SELECT COALESCE(g.genre, 'NULL') FROM games g
    JOIN users u ON g.user_id = u.id
    WHERE g.title = '$1' AND u.username = '$FIXTURE_USER'
  "
}

OKAMI_ID=$(fixture_id games 'FIXTURE Okami' mine)

blue "Undo a single-row set"

assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "fixture starts as Action"
run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-genre=Changed
assert_eq "Changed" "$(genre_of 'FIXTURE Okami')" "set applied"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo exits 0"
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "undo restored the before-value"
echo "$GT_JSON" | jq -e '.restored == 1' > /dev/null \
  && { green "  PASS: reports restored=1"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected undo result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Undo works across a second boundary"

# Regression guard. The journal must record updated_at from AFTER the write:
# the write bumps it, so recording the pre-write value made undo think every
# row had been edited behind its back. That was invisible whenever a write
# landed in the same second as the row's previous state, so this sleep is the
# whole point of the test.
run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-genre=SecondBoundary
sleep 2
run_gt_json undo "$USER_FLAG"
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "undo restores after a second has elapsed"
echo "$GT_JSON" | jq -e '.restored == 1 and .skipped == 0' > /dev/null \
  && { green "  PASS: nothing was skipped as a phantom conflict"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: phantom conflict: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Double undo is refused"

run_gt undo "$USER_FLAG"
assert_eq "1" "$GT_CODE" "a second undo of the same entry = 1"
assert_contains "nothing to undo" "$GT_OUT" "explains there is nothing left"

blue "Undo --list"

run_gt_json undo --list "$USER_FLAG"
assert_eq "0" "$GT_CODE" "--list exits 0"
echo "$GT_JSON" | jq -e '.entries | length >= 1' > /dev/null \
  && { green "  PASS: --list shows entries"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: --list empty: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
echo "$GT_JSON" | jq -e '.entries[0].reverted_at != null' > /dev/null \
  && { green "  PASS: --list marks the reverted entry"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: reverted_at not recorded: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Bulk undo needs --yes"

run_gt games set "$USER_FLAG" --platform=PS2 --set-genre=BulkChange --yes
assert_eq "BulkChange" "$(genre_of 'FIXTURE Okami')" "bulk set applied"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "multi-row undo previews without --yes"
echo "$GT_JSON" | jq -e '.dry_run == true and .would_restore == 2' > /dev/null \
  && { green "  PASS: multi-row undo previews"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected a preview: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "BulkChange" "$(genre_of 'FIXTURE Okami')" "preview restored nothing"

run_gt_json undo "$USER_FLAG" --yes
assert_eq "0" "$GT_CODE" "multi-row undo with --yes exits 0"
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "bulk undo restored Okami"
assert_eq "Horror" "$(genre_of 'FIXTURE Silent Hill')" "bulk undo restored Silent Hill"

blue "Undo refuses a row changed since"

run_gt games set "$OKAMI_ID" "$USER_FLAG" --set-genre=First
# Simulate a web-app edit: change the row behind the CLI's back. updated_at
# has one-second resolution, so wait to guarantee a different value.
sleep 1
fixture_mysql -e "UPDATE games SET genre = 'EditedElsewhere' WHERE id = $OKAMI_ID"

run_gt_json undo "$USER_FLAG"
assert_eq "0" "$GT_CODE" "undo of a changed row still exits 0"
echo "$GT_JSON" | jq -e '.restored == 0 and .skipped == 1' > /dev/null \
  && { green "  PASS: refuses to clobber a newer edit"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected skipped=1: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "EditedElsewhere" "$(genre_of 'FIXTURE Okami')" "the newer edit survived"

blue "--force overrides the refusal"

JID=$(ls -1 "$GT_JOURNAL_DIR" | grep -- '-set.json' | sort | tail -1 | sed 's/\.json$//')
run_gt_json undo "$JID" "$USER_FLAG" --force
assert_eq "0" "$GT_CODE" "--force exits 0"
# The journal stores the value from BEFORE the write, so undoing the
# --set-genre=First entry restores Action — not First, which is what that write
# put there. --force means "restore my before-value even though someone else
# has since changed the row", and EditedElsewhere is what gets overwritten.
assert_eq "Action" "$(genre_of 'FIXTURE Okami')" "--force restored the before-value over the newer edit"
echo "$GT_JSON" | jq -e '.restored == 1 and .skipped == 0' > /dev/null \
  && { green "  PASS: --force reports restored=1"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected force result: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Unknown entry"

run_gt undo not-a-real-entry "$USER_FLAG"
assert_eq "1" "$GT_CODE" "unknown journal id = 1"

blue "Unknown resource in a journal entry"

# A hand-written entry naming a resource with no reverter must fail cleanly
# rather than reverting against the wrong table. This is the dispatch seam:
# before it existed, every entry was reverted as if it were a games `set`.
BOGUS_ID="2099-01-01T00-00-00-000000Z-set"
cat > "$GT_JOURNAL_DIR/$BOGUS_ID.json" <<JSON
{
  "id": "$BOGUS_ID",
  "argv": [],
  "user_id": $(fixture_user_id "$FIXTURE_USER"),
  "resource": "widgets",
  "operation": "set",
  "committed": true,
  "reverted_at": null,
  "rows": [{"id": 1, "updated_at": null, "before": {"title": "x"}}]
}
JSON

run_gt undo "$BOGUS_ID" "$USER_FLAG"
assert_eq "1" "$GT_CODE" "an unknown resource is a domain error"
assert_contains "widgets" "$GT_OUT" "names the resource it cannot revert"
rm -f "$GT_JOURNAL_DIR/$BOGUS_ID.json"

summarize
