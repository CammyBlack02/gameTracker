#!/usr/bin/env bash
# Deleting a game keeps its completion history and unlinks it — and the phone
# must find out.
#
# The decision (Cameron, 2026-08-04) is that deleting a game must NOT destroy the
# completion row, only unlink it. `game_completions.game_id` is ON DELETE SET
# NULL, so the FK already did that. The defect was that nothing downstream ever
# learned:
#
#   - an FK-driven SET NULL does NOT fire `on update CURRENT_TIMESTAMP` (verified
#     on MySQL 8.0.45: updated_at identical before and after), and
#   - it is an UPDATE, so no deletion tombstone fires either.
#
# api/v2/sync/changes.php only returns rows newer than the client's cursor, so the
# phone kept a completion pointing at a game id that no longer existed, for good.
# Two production rows were already in that state.
#
# Both write paths now perform the UPDATE themselves so updated_at bumps. This
# suite asserts the OUTCOME rather than the mechanism: that the row survives
# unlinked, and that a delta sync taken from before the delete actually carries it.
source "$(dirname "$0")/lib.sh"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "${GT_DB_NAME:-gameTracker_test}" -sNe "$1"
}

COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null
CSRF=$(curl -sS -b "$COOKIE" "$BASE_URL/dashboard.php" \
  | grep -oE '<meta name="csrf-token" content="[^"]+"' | sed -E 's/.*content="([^"]+)"/\1/')
UID_T=$(db "SELECT id FROM users WHERE username = '$TEST_USER'")

# Ids are collected as they are created, NOT looked up at cleanup time. This suite
# deletes its own games as the thing under test, so by the end there is no row left
# to resolve a title to an id — a title-based tombstone cleanup finds nothing and
# leaks. That leak is not harmless: tests/v2/test_smoke.sh asserts an ABSOLUTE
# tombstone count for testuser, so a stray tombstone from here fails a suite that
# runs later and has nothing to do with this one.
SEEDED_IDS=()

purge_db() {
  db "DELETE FROM game_completions WHERE title LIKE 'UNLINK %'"
  db "DELETE FROM games WHERE title LIKE 'UNLINK %'"
  for gid in ${SEEDED_IDS[@]+"${SEEDED_IDS[@]}"}; do
    db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $gid"
  done
}

# Split deliberately. purge_db is safe to call before the run; the EXIT trap also
# removes the cookie jar, and calling the combined version up front deleted the jar
# straight after login and ran the whole suite unauthenticated.
cleanup() {
  purge_db
  rm -f "$COOKIE"
}

# One trap doing both jobs — a second `trap ... EXIT` silently replaces the first.
trap cleanup EXIT
purge_db

blue "web delete: the completion survives, unlinked, and syncs"

db "INSERT INTO games (user_id, title, platform) VALUES ($UID_T, 'UNLINK WebGame', 'PS2')"
G=$(db "SELECT id FROM games WHERE user_id = $UID_T AND title = 'UNLINK WebGame'")
SEEDED_IDS+=("$G")
db "INSERT INTO game_completions (user_id, game_id, title, platform, updated_at)
    VALUES ($UID_T, $G, 'UNLINK WebComp', 'PS2', NOW())"
C=$(db "SELECT id FROM game_completions WHERE game_id = $G")

assert_eq "yes" "$([[ -n "$C" ]] && echo yes || echo no)" "fixture seeded a linked completion"

BEFORE_TS=$(db "SELECT updated_at FROM game_completions WHERE id = $C")

# The cursor the phone would hold: strictly after the completion's current
# updated_at, so a sync now returns nothing for it. That is what makes the
# post-delete assertion meaningful rather than trivially true.
CURSOR=$(db "SELECT DATE_FORMAT(DATE_ADD(updated_at, INTERVAL 1 SECOND), '%Y-%m-%dT%H:%i:%sZ')
             FROM game_completions WHERE id = $C")

PRE=$(curl -sS -b "$COOKIE" "$BASE_URL/api/v2/sync/changes.php?since=$CURSOR")
assert_eq "0" "$(echo "$PRE" | jq --argjson c "$C" '[.data.game_completions[]? | select(.id == $c)] | length')" \
  "before the delete, that cursor returns nothing for the completion"

# One second, so a bumped updated_at is distinguishable at MySQL's precision.
sleep 2

CODE=$(curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/games.php?action=delete&id=$G" \
  -H "X-CSRF-Token: $CSRF" -o /dev/null -w '%{http_code}')
assert_eq "200" "$CODE" "the web delete succeeded"

assert_eq "" "$(db "SELECT id FROM games WHERE id = $G")" "the game is gone"

# The decision: keep the history, just unlink it.
assert_eq "1" "$(db "SELECT COUNT(*) FROM game_completions WHERE id = $C")" \
  "the completion row SURVIVED — history is not destroyed"
assert_eq "NULL" "$(db "SELECT IFNULL(CAST(game_id AS CHAR), 'NULL') FROM game_completions WHERE id = $C")" \
  "and it is unlinked"

AFTER_TS=$(db "SELECT updated_at FROM game_completions WHERE id = $C")
[[ "$AFTER_TS" != "$BEFORE_TS" ]] \
  && { green "  PASS: updated_at moved ($BEFORE_TS -> $AFTER_TS)"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: updated_at unchanged at $BEFORE_TS — the unlink is invisible"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The assertion that actually matters: the phone hears about it through the real
# endpoint, using the cursor it held before the delete.
POST=$(curl -sS -b "$COOKIE" "$BASE_URL/api/v2/sync/changes.php?since=$CURSOR")
assert_eq "1" "$(echo "$POST" | jq --argjson c "$C" '[.data.game_completions[]? | select(.id == $c)] | length')" \
  "delta sync now carries the unlinked completion to the phone"
assert_eq "null" "$(echo "$POST" | jq -r --argjson c "$C" '.data.game_completions[]? | select(.id == $c) | .game_id')" \
  "and the row it carries has a null game_id"

blue "CLI delete: identical behaviour, so the two paths agree"

GT="$(cd "$(dirname "$0")/../.." && pwd)/bin/gt"

db "INSERT INTO games (user_id, title, platform) VALUES ($UID_T, 'UNLINK CliGame', 'PS2')"
G2=$(db "SELECT id FROM games WHERE user_id = $UID_T AND title = 'UNLINK CliGame'")
SEEDED_IDS+=("$G2")
db "INSERT INTO game_completions (user_id, game_id, title, platform, updated_at)
    VALUES ($UID_T, $G2, 'UNLINK CliComp', 'PS2', NOW())"
C2=$(db "SELECT id FROM game_completions WHERE game_id = $G2")
BEFORE2=$(db "SELECT updated_at FROM game_completions WHERE id = $C2")

sleep 2
"$GT" games delete "$G2" --yes --user="$UID_T" > /dev/null 2>&1

assert_eq "1" "$(db "SELECT COUNT(*) FROM game_completions WHERE id = $C2")" \
  "the CLI also keeps the completion row"
assert_eq "NULL" "$(db "SELECT IFNULL(CAST(game_id AS CHAR), 'NULL') FROM game_completions WHERE id = $C2")" \
  "the CLI also unlinks it"

AFTER2=$(db "SELECT updated_at FROM game_completions WHERE id = $C2")
[[ "$AFTER2" != "$BEFORE2" ]] \
  && { green "  PASS: the CLI also bumps updated_at"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: CLI unlink is invisible to sync"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "gt undo still relinks after the explicit unlink"

# The unlink is now an explicit UPDATE rather than an FK side effect, so undo has
# to keep working — it relinks from the completion ids snapshotted in the journal.
# `gt undo --list --json` returns {"entries":[...]}, newest first. Pick the most
# recent uncommitted-revert delete rather than entry 0, so an unrelated `set` from
# another suite cannot be selected by accident.
JOURNAL_ID=$("$GT" undo --list --json 2>/dev/null \
  | jq -r '[.entries[] | select(.operation == "delete" and .reverted_at == null)][0].id // empty')
if [[ -n "$JOURNAL_ID" ]]; then
  "$GT" undo "$JOURNAL_ID" --yes --user="$UID_T" > /dev/null 2>&1
  assert_eq "$G2" "$(db "SELECT IFNULL(CAST(game_id AS CHAR),'') FROM game_completions WHERE id = $C2")" \
    "undo relinked the completion to the restored game"
else
  red "  FAIL: no journal entry found to undo"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

summarize
