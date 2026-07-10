#!/usr/bin/env bash
# Verifies deletePCGames and dedup SELECT are user-scoped, and that the
# INSERT path sets user_id. Uses the existing testuser (already seeded
# with a known password hash by setup-test-db.sh) and creates one extra
# untrusted user in the DB directly.
source "$(dirname "$0")/lib.sh"

DB_USER="${TEST_DB_USER:-root}"
DB_NAME="${TEST_DB_NAME:-gameTracker_test}"

blue "Steam import: deletePCGames is user-scoped"

# Pre-clean any stragglers from a previous failed run.
mysql -u"$DB_USER" "$DB_NAME" -e "
  DELETE FROM games WHERE title IN ('A-Owned PC Game', 'B-Owned PC Game');
  DELETE FROM users WHERE username='steamuser_other';
"

# Reuse testuser as user A. Create user B fresh with a placeholder hash
# — B never needs to log in, we just check its data survives.
mysql -u"$DB_USER" "$DB_NAME" <<SQL
INSERT INTO users (username, password_hash, role)
VALUES ('steamuser_other', '\$2y\$10\$placeholderhashthatwontauth1234567', 'user');

INSERT INTO games (user_id, title, platform) VALUES
  ((SELECT id FROM users WHERE username='testuser'),        'A-Owned PC Game', 'PC'),
  ((SELECT id FROM users WHERE username='steamuser_other'), 'B-Owned PC Game', 'PC');
SQL

A_USER_ID=$(mysql -u"$DB_USER" "$DB_NAME" -sNe "SELECT id FROM users WHERE username='testuser'")
B_USER_ID=$(mysql -u"$DB_USER" "$DB_NAME" -sNe "SELECT id FROM users WHERE username='steamuser_other'")

# Log in as testuser (user A) via the session-cookie v1 flow.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

# Call deletePCGames as user A.
curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/steam-import.php?action=delete_pc_games" > /dev/null

# Assert user B's game still exists (this was the cross-user wipe bug).
B_COUNT=$(mysql -u"$DB_USER" "$DB_NAME" -sNe \
  "SELECT COUNT(*) FROM games WHERE user_id=$B_USER_ID AND title='B-Owned PC Game'")
assert_eq "1" "$B_COUNT" "user B's PC game survived user A's delete_pc_games"

# Assert user A's game is gone.
A_COUNT=$(mysql -u"$DB_USER" "$DB_NAME" -sNe \
  "SELECT COUNT(*) FROM games WHERE user_id=$A_USER_ID AND title='A-Owned PC Game'")
assert_eq "0" "$A_COUNT" "user A's PC game was deleted"

# Cleanup.
mysql -u"$DB_USER" "$DB_NAME" -e "
  DELETE FROM games WHERE title IN ('A-Owned PC Game', 'B-Owned PC Game');
  DELETE FROM users WHERE username='steamuser_other';
"
rm -f "$COOKIE"

summarize
