#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

# Get fresh token
req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

# Seed: insert a game + a cover image file for the test user
mkdir -p "$PROJECT_ROOT/uploads/covers/thumbs"

# Create real JPEGs so getimagesize works
php -r '
$img = imagecreatetruecolor(100, 100);
imagejpeg($img, "'"$PROJECT_ROOT"'/uploads/covers/test_image.jpg", 90);
$img2 = imagecreatetruecolor(50, 50);
imagejpeg($img2, "'"$PROJECT_ROOT"'/uploads/covers/thumbs/test_image.jpg", 80);
'

USER_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM users WHERE username='testuser'")
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO games (user_id, title, platform, front_cover_image)
  VALUES ($USER_ID, 'Test Game', 'Test Platform', 'test_image.jpg');
"
GAME_ID=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Test Game'")

blue "GET /api/v2/images/cover.php without token returns 401"
req GET "/api/v2/images/cover.php?id=$GAME_ID&size=thumb"
assert_eq "401" "$HTTP_STATUS" "no token = 401"

blue "GET cover thumb returns image bytes"
curl -sS -o /tmp/v2_cover_thumb "$BASE_URL/api/v2/images/cover.php?id=$GAME_ID&size=thumb" \
  -H "Authorization: Bearer $TOKEN"
SIZE=$(php -r 'list($w, $h) = @getimagesize("/tmp/v2_cover_thumb") ?: [0, 0]; echo "$w";')
assert_eq "50" "$SIZE" "thumb width is 50"

blue "GET cover full returns full-size image"
curl -sS -o /tmp/v2_cover_full "$BASE_URL/api/v2/images/cover.php?id=$GAME_ID&size=full" \
  -H "Authorization: Bearer $TOKEN"
SIZE=$(php -r 'list($w, $h) = @getimagesize("/tmp/v2_cover_full") ?: [0, 0]; echo "$w";')
assert_eq "100" "$SIZE" "full width is 100"

blue "GET cover for another user's game returns 404"
# Create a second user with their own game
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT INTO users (username, password_hash, role) VALUES
    ('otheruser', 'x', 'user');
  INSERT INTO games (user_id, title, platform, front_cover_image)
  VALUES ((SELECT id FROM users WHERE username='otheruser'), 'Other Game', 'X', 'uploads/covers/other.jpg');
"
OTHER_GAME=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe "SELECT id FROM games WHERE title='Other Game'")
req GET "/api/v2/images/cover.php?id=$OTHER_GAME&size=thumb" "" -H "Authorization: Bearer $TOKEN"
assert_eq "404" "$HTTP_STATUS" "other user's game = 404"

# Cleanup
rm -f "$PROJECT_ROOT/uploads/covers/test_image.jpg" "$PROJECT_ROOT/uploads/covers/thumbs/test_image.jpg"

summarize
