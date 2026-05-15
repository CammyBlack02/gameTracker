#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Seed: insert a game
USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform) VALUES ($USER_ID, 'Upload Test Game', 'TestPlatform');
"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Upload Test Game'")

# Create a real JPEG to upload
php -r '$img = imagecreatetruecolor(200, 200); imagejpeg($img, "/tmp/v2_upload.jpg", 90);'

blue "POST cover-upload without token returns 401"
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$GAME_ID&face=front" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
assert_eq "401" "$HTTP_STATUS" "no token = 401"

blue "POST cover-upload with token saves the file and updates the games row"
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$GAME_ID&face=front" \
  -H "Authorization: Bearer $TOKEN" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
RESPONSE_BODY=$(cat /tmp/v2_body)
assert_eq "200" "$HTTP_STATUS" "200 ok"

PATH_RETURNED=$(echo "$RESPONSE_BODY" | jq -r '.data.path')
[[ "$PATH_RETURNED" == uploads/covers/* ]] && green "  PASS: returned path is under uploads/covers" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: path=$PATH_RETURNED"; FAIL_COUNT=$((FAIL_COUNT+1)); }

[[ -f "$PROJECT_ROOT/$PATH_RETURNED" ]] && green "  PASS: file exists on disk" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: file missing: $PATH_RETURNED"; FAIL_COUNT=$((FAIL_COUNT+1)); }

THUMB_PATH=$(dirname "$PATH_RETURNED")/thumbs/$(basename "$PATH_RETURNED")
[[ -f "$PROJECT_ROOT/$THUMB_PATH" ]] && green "  PASS: thumbnail generated" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: thumb missing: $THUMB_PATH"; FAIL_COUNT=$((FAIL_COUNT+1)); }

DB_PATH=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT front_cover_image FROM games WHERE id=$GAME_ID")
assert_eq "$PATH_RETURNED" "$DB_PATH" "games.front_cover_image updated"

blue "POST cover-upload for another user's game returns 404"
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT IGNORE INTO users (username, password_hash, role) VALUES ('otheruser2', 'x', 'user');
  INSERT INTO games (user_id, title, platform) VALUES (
    (SELECT id FROM users WHERE username='otheruser2'), 'Other Upload', 'X');
"
OTHER_GAME=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Other Upload'")
curl -sS -o /tmp/v2_body -w "%{http_code}" \
  -X POST "$BASE_URL/api/v2/games/cover-upload.php?game_id=$OTHER_GAME&face=front" \
  -H "Authorization: Bearer $TOKEN" \
  -F "image=@/tmp/v2_upload.jpg" > /tmp/v2_status
HTTP_STATUS=$(cat /tmp/v2_status)
assert_eq "404" "$HTTP_STATUS" "other user's game = 404"

# Cleanup
rm -f "$PROJECT_ROOT/$PATH_RETURNED" "$PROJECT_ROOT/$THUMB_PATH"

summarize
