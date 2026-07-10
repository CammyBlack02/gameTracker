#!/usr/bin/env bash
# Verifies /api/v2/images/cover.php cannot be tricked into fetching
# internal metadata endpoints via a client-writable front_cover_image.
source "$(dirname "$0")/lib.sh"

DB_USER="${TEST_DB_USER:-root}"
DB_NAME="${TEST_DB_NAME:-gameTracker_test}"

req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "v2 cover.php blocks stored SSRF"

# Pre-clean.
mysql -u"$DB_USER" "$DB_NAME" -e "DELETE FROM games WHERE title='SSRF Cover Test'"

# Seed a game owned by testuser with a metadata-endpoint URL in front_cover_image.
mysql -u"$DB_USER" "$DB_NAME" <<SQL
INSERT INTO games (user_id, title, platform, front_cover_image)
VALUES (
  (SELECT id FROM users WHERE username='$TEST_USER'),
  'SSRF Cover Test', 'PC', 'https://169.254.169.254/latest/meta-data/'
);
SQL

GAME_ID=$(mysql -u"$DB_USER" "$DB_NAME" -sNe \
  "SELECT id FROM games WHERE title='SSRF Cover Test' ORDER BY id DESC LIMIT 1")

req GET "/api/v2/images/cover.php?id=$GAME_ID" "" -H "Authorization: Bearer $TOKEN"
# Post-fix behaviour: 403 forbidden (SSRF blocked). 404 also acceptable
# if the helper's exception is mapped to 'not_found'.
if [[ "$HTTP_STATUS" == "403" || "$HTTP_STATUS" == "404" ]]; then
  green "  PASS: stored SSRF blocked (HTTP $HTTP_STATUS)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: status=$HTTP_STATUS — server may have fetched 169.254"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "v2 cover.php still serves a bare-filename cover (regression check)"
# Insert a game pointing at a bare filename (won't exist on disk in test DB,
# so we expect 404 with error_code=not_found — not a 5xx / no unhandled exception).
mysql -u"$DB_USER" "$DB_NAME" <<SQL
UPDATE games SET front_cover_image='does-not-exist.jpg'
WHERE title='SSRF Cover Test';
SQL
req GET "/api/v2/images/cover.php?id=$GAME_ID" "" -H "Authorization: Bearer $TOKEN"
assert_eq "404" "$HTTP_STATUS" "bare-filename missing = 404 (not 500)"

# Cleanup.
mysql -u"$DB_USER" "$DB_NAME" -e "DELETE FROM games WHERE title='SSRF Cover Test'"

summarize
