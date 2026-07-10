#!/usr/bin/env bash
# ?user_id= override on the games/items list endpoints previously let
# any authenticated user peek at another user's collection. Locked out.
source "$(dirname "$0")/lib.sh"

DB_USER="${TEST_DB_USER:-root}"
DB_NAME="${TEST_DB_NAME:-gameTracker_test}"

blue "list endpoints: cross-user ?user_id= is ignored"

# Pre-clean.
mysql -u"$DB_USER" "$DB_NAME" -e "
  DELETE FROM games WHERE title='Other List Game';
  DELETE FROM items WHERE title='Other List Item';
  DELETE FROM users WHERE username='otheruser_list';
"

# Seed a second user with one game + one item.
mysql -u"$DB_USER" "$DB_NAME" <<SQL
INSERT INTO users (username, password_hash, role)
VALUES ('otheruser_list', '\$2y\$10\$placeholderhashthatwontauth1234567', 'user');

INSERT INTO games (user_id, title, platform)
VALUES ((SELECT id FROM users WHERE username='otheruser_list'), 'Other List Game', 'PC');

INSERT INTO items (user_id, title, category)
VALUES ((SELECT id FROM users WHERE username='otheruser_list'), 'Other List Item', 'console');
SQL

OTHER_ID=$(mysql -u"$DB_USER" "$DB_NAME" -sNe \
  "SELECT id FROM users WHERE username='otheruser_list'")

# testuser (role=user) attempts to peek.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

req GET "/api/games.php?action=list&user_id=$OTHER_ID" "" -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -q "Other List Game"; then
  red "  FAIL: cross-user peek returned other user's game"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: games.php ignores ?user_id= override"
  PASS_COUNT=$((PASS_COUNT+1))
fi

req GET "/api/items.php?action=list&user_id=$OTHER_ID" "" -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -q "Other List Item"; then
  red "  FAIL: cross-user items peek returned other user's item"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: items.php ignores ?user_id= override"
  PASS_COUNT=$((PASS_COUNT+1))
fi

# Cleanup.
mysql -u"$DB_USER" "$DB_NAME" -e "
  DELETE FROM games WHERE title='Other List Game';
  DELETE FROM items WHERE title='Other List Item';
  DELETE FROM users WHERE username='otheruser_list';
"
rm -f "$COOKIE"

summarize
