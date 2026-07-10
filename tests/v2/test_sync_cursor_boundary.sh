#!/usr/bin/env bash
# Fable §4: sync's cursor uses whole-second precision (server_now is
# emitted as "YYYY-MM-DDTHH:MM:SSZ" with no fractional). If a row is
# written in the same wall-clock second as server_now, a strict `>`
# comparison on the next sync misses it — data loss.
#
# Phase 3c switched the sync/changes query from `>` to `>=`. Any row
# whose updated_at == since is now included; ChangeApplier's per-serverId
# fetch makes the extra apply idempotent. This test seeds a boundary
# row with a known updated_at, calls /sync/changes?since=<that timestamp>,
# and verifies the row is returned.
source "$(dirname "$0")/lib.sh"

DB_USER="${TEST_DB_USER:-root}"
DB_NAME="${TEST_DB_NAME:-gameTracker_test}"

req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

# Pick a fixed timestamp well in the past so it doesn't collide with
# any auto-generated row.
BOUNDARY_UTC='2020-06-15 12:00:00'
BOUNDARY_ISO='2020-06-15T12:00:00Z'

# Clean any leftover boundary rows.
mysql -u"$DB_USER" "$DB_NAME" -e "DELETE FROM games WHERE title='Cursor Boundary Game'"

# Insert a game with updated_at == BOUNDARY_UTC. MySQL's DATETIME defaults
# to session timezone; we insert in UTC to match the sync query's
# CONVERT_TZ target. server_now is stored/queried in UTC on production.
mysql -u"$DB_USER" "$DB_NAME" <<SQL
SET time_zone = '+00:00';
INSERT INTO games (user_id, title, platform, created_at, updated_at)
VALUES (
  (SELECT id FROM users WHERE username='$TEST_USER'),
  'Cursor Boundary Game', 'PC',
  '$BOUNDARY_UTC', '$BOUNDARY_UTC'
);
SQL

blue "sync/changes with since == updated_at returns the boundary row"
req GET "/api/v2/sync/changes.php?since=$BOUNDARY_ISO" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "sync/changes returns 200"
if echo "$RESPONSE_BODY" | jq -e '.data.games[] | select(.title == "Cursor Boundary Game")' > /dev/null 2>&1; then
  green "  PASS: boundary row is included when since == its updated_at"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: boundary row NOT returned by /sync/changes?since=$BOUNDARY_ISO"
  red "  response snippet: $(echo "$RESPONSE_BODY" | jq '.data.games | length, .data.games[0].title // "(empty)"' 2>/dev/null || echo "$RESPONSE_BODY" | head -c 200)"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# Cleanup.
mysql -u"$DB_USER" "$DB_NAME" -e "DELETE FROM games WHERE title='Cursor Boundary Game'"

summarize
