#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Push: new game (no server_id) gets accepted and assigned an id"
JSON='{
  "games": {
    "new": [
      {"client_id": "phone-uuid-1", "title": "Phone-Created Game", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
assert_eq "200" "$HTTP_STATUS" "push succeeds"

RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "new row accepted"
NEW_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].server_id')
[[ "$NEW_ID" =~ ^[0-9]+$ ]] && green "  PASS: server_id assigned: $NEW_ID" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: server_id=$NEW_ID"; FAIL_COUNT=$((FAIL_COUNT+1)); }
CLIENT_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].client_id')
assert_eq "phone-uuid-1" "$CLIENT_ID" "client_id echoed back"

blue "Push: modified game with up-to-date last_synced_at is accepted"
# Get the row's current updated_at as ISO 8601 UTC
LAST_SYNCED=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "
  SELECT DATE_FORMAT(CONVERT_TZ(updated_at, @@session.time_zone, '+00:00'), '%Y-%m-%dT%H:%i:%sZ')
  FROM games WHERE id=$NEW_ID")
JSON='{
  "games": {
    "modified": [
      {"server_id": '"$NEW_ID"', "last_synced_at": "'"$LAST_SYNCED"'", "title": "Phone Edited", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "modified row accepted when no conflict"

blue "Push: modified game with stale last_synced_at returns conflict"
# Bump the server row's updated_at by directly updating it (simulate web-side edit)
sleep 2
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "UPDATE games SET title='Web Edited' WHERE id=$NEW_ID"
# Use the OLD last_synced_at — the one captured before the web edit above
JSON='{
  "games": {
    "modified": [
      {"server_id": '"$NEW_ID"', "last_synced_at": "'"$LAST_SYNCED"'", "title": "Phone Edited Again", "platform": "Switch"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "conflict" "$RESULT" "stale modified returns conflict"
SERVER_VER_TITLE=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].server_version.title')
assert_eq "Web Edited" "$SERVER_VER_TITLE" "conflict includes server version"
DB_TITLE=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT title FROM games WHERE id=$NEW_ID")
assert_eq "Web Edited" "$DB_TITLE" "conflict left DB unchanged (still has web version, not phone version)"

blue "Push: new row missing required column returns rejected (per-row, not 500)"
# Try to insert a game without platform (which is NOT NULL in the schema)
JSON='{
  "games": {
    "new": [
      {"client_id": "phone-uuid-bad", "title": "Missing Platform"}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
assert_eq "200" "$HTTP_STATUS" "bad row still gets 200 (per-row error, not batch fail)"
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "rejected" "$RESULT" "bad row marked rejected"
REASON=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].reason')
assert_eq "db_error" "$REASON" "reason is db_error"

blue "Push: deletion is processed"
JSON='{
  "games": {
    "deleted": [
      {"server_id": '"$NEW_ID"'}
    ]
  }
}'
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/sync/push.php" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d "$JSON" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
RESULT=$(echo "$RESPONSE_BODY" | jq -r '.data.games[0].result')
assert_eq "accepted" "$RESULT" "deletion accepted"

DB_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT COUNT(*) FROM games WHERE id=$NEW_ID")
assert_eq "0" "$DB_COUNT" "row actually deleted"

DEL_COUNT=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT COUNT(*) FROM deletions WHERE table_name='games' AND server_id=$NEW_ID")
assert_eq "1" "$DEL_COUNT" "tombstone written"

summarize
