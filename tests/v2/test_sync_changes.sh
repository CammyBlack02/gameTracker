#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "GET /api/v2/sync/changes.php with since=0 returns all user rows"
req GET "/api/v2/sync/changes.php?since=1970-01-01T00:00:00Z" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "200 ok"

HAS_GAMES=$(echo "$RESPONSE_BODY" | jq '.data.games | type')
assert_eq '"array"' "$HAS_GAMES" "games is an array"

HAS_ITEMS=$(echo "$RESPONSE_BODY" | jq '.data.items | type')
assert_eq '"array"' "$HAS_ITEMS" "items is an array"

HAS_COMPLETIONS=$(echo "$RESPONSE_BODY" | jq '.data.game_completions | type')
assert_eq '"array"' "$HAS_COMPLETIONS" "game_completions is an array"

HAS_DELETIONS=$(echo "$RESPONSE_BODY" | jq '.data.deletions | type')
assert_eq '"array"' "$HAS_DELETIONS" "deletions is an array"

HAS_NOW=$(echo "$RESPONSE_BODY" | jq -r '.data.server_now')
[[ "$HAS_NOW" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T ]] && green "  PASS: server_now is ISO-8601" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: server_now=$HAS_NOW"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Seed a game that should show up
USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES ($USER_ID, 'Sync Test Game', 'TestPlatform');
"

req GET "/api/v2/sync/changes.php?since=1970-01-01T00:00:00Z" "" -H "Authorization: Bearer $TOKEN"
GAME_COUNT=$(echo "$RESPONSE_BODY" | jq '.data.games | length')
[[ "$GAME_COUNT" -ge 1 ]] && green "  PASS: at least one game returned" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: game_count=$GAME_COUNT"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "GET with since=NOW returns no new rows"
NOW_ISO=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
sleep 1
req GET "/api/v2/sync/changes.php?since=$NOW_ISO" "" -H "Authorization: Bearer $TOKEN"
GAME_COUNT=$(echo "$RESPONSE_BODY" | jq '.data.games | length')
assert_eq "0" "$GAME_COUNT" "no new games after NOW"

blue "Deletion shows up in tombstones"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Sync Test Game'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "DELETE FROM games WHERE id=$GAME_ID"
req GET "/api/v2/sync/changes.php?since=$NOW_ISO" "" -H "Authorization: Bearer $TOKEN"
DEL_COUNT=$(echo "$RESPONSE_BODY" | jq "[.data.deletions[] | select(.table_name==\"games\" and .server_id==$GAME_ID)] | length")
assert_eq "1" "$DEL_COUNT" "deletion tombstone present"

summarize
