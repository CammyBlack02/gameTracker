#!/usr/bin/env bash
# Verifies the test harness itself works by hitting the existing /api/auth.php?action=check
source "$(dirname "$0")/lib.sh"

blue "Smoke: hitting existing /api/auth.php?action=check"
req GET "/api/auth.php?action=check"
assert_eq "200" "$HTTP_STATUS" "endpoint returns 200"
assert_contains '"success":true' "$RESPONSE_BODY" "response is success:true"
assert_contains '"authenticated":false' "$RESPONSE_BODY" "anonymous user is not authenticated"

blue "Migrations: api_tokens table exists after running migrate.php"
php database/migrate.php > /dev/null
TABLES=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW TABLES LIKE 'api_tokens'" 2>&1)
assert_contains "api_tokens" "$TABLES" "api_tokens table exists"

blue "Migrations: deletions table exists"
TABLES=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW TABLES LIKE 'deletions'" 2>&1)
assert_contains "deletions" "$TABLES" "deletions table exists"

blue "Migrations: game_images has updated_at column"
COLS=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "SHOW COLUMNS FROM game_images LIKE 'updated_at'" 2>&1)
assert_contains "updated_at" "$COLS" "game_images.updated_at exists"

blue "Migrations: delete trigger writes a tombstone"
USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES ($USER_ID, '_trigger_test', '_p');
  DELETE FROM games WHERE title='_trigger_test';
"
TOMBS=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT COUNT(*) FROM deletions WHERE table_name='games' AND user_id=$USER_ID")
assert_eq "1" "$TOMBS" "deletion via SQL creates a tombstone row"

summarize
