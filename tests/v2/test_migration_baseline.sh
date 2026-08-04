#!/usr/bin/env bash
# 000_baseline must repair a database that already has the column but not the
# foreign key.
#
# The block reads:
#
#     try {
#         ALTER TABLE games ADD COLUMN user_id INT NOT NULL
#         ALTER TABLE games ADD INDEX idx_user_id (user_id)
#         ALTER TABLE games ADD FOREIGN KEY (user_id) REFERENCES users(id) ...
#     } catch (PDOException $e) { /* Column already exists, ignore. */ }
#
# Against any database where the column already exists — which is every
# database this migration was written to upgrade — the first statement throws
# and the catch swallows it, so the index and the FK never run. Production has
# carried three missing user_id foreign keys since, which means ON DELETE
# CASCADE does not exist there: deleting a user orphans their 1,261 games
# instead of removing them.
#
# This reproduces that state by dropping an FK (leaving the column in place,
# exactly as prod is) and asserting the migration puts it back.
source "$(dirname "$0")/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DB="${GT_DB_NAME:-${TEST_DB_NAME:-gameTracker_test}}"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "$DB" -sNe "$1"
}

fk_name_for() {
  db "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
      WHERE CONSTRAINT_SCHEMA = '$DB' AND TABLE_NAME = '$1'
        AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users' LIMIT 1"
}

fk_count_for() {
  db "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE CONSTRAINT_SCHEMA = '$DB' AND TABLE_NAME = '$1'
        AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME = 'users'"
}

run_baseline() {
  php -r '
    require $argv[1] . "/includes/config.php";
    $migration = require $argv[1] . "/database/migrations/000_baseline.php";
    $migration($pdo);
    echo "ok";
  ' -- "$PROJECT_ROOT" 2>&1 | tail -1
}

blue "Setup: reproduce production's shape — column present, FK absent"

ORIGINAL_FK=$(fk_name_for game_images)
if [[ -z "$ORIGINAL_FK" ]]; then
  red "  SETUP FAILED: game_images has no user_id FK to begin with"
  FAIL_COUNT=$((FAIL_COUNT+1))
  summarize
fi

db "ALTER TABLE game_images DROP FOREIGN KEY \`$ORIGINAL_FK\`"
assert_eq "0" "$(fk_count_for game_images)" "the FK is gone, the column remains"

# The column must still be there — that is the whole point. If it were absent,
# ADD COLUMN would succeed and the bug would not reproduce.
assert_eq "1" "$(db "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '$DB' AND TABLE_NAME = 'game_images'
                       AND COLUMN_NAME = 'user_id'")" \
  "the user_id column is still present, as it is on production"

blue "Running 000_baseline must restore the foreign key"

RESULT=$(run_baseline)
assert_contains "ok" "$RESULT" "the migration ran without throwing"

assert_eq "1" "$(fk_count_for game_images)" \
  "000_baseline re-added the missing user_id foreign key"

blue "And the migration stays idempotent"

RESULT2=$(run_baseline)
assert_contains "ok" "$RESULT2" "a second run does not throw"
assert_eq "1" "$(fk_count_for game_images)" "a second run does not duplicate the FK"

# Restore whatever state we could not repair, so a failure here does not leave
# the shared test database poisoned for every suite that runs after this one.
if [[ "$(fk_count_for game_images)" == "0" ]]; then
  db "ALTER TABLE game_images ADD CONSTRAINT \`$ORIGINAL_FK\`
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE" || true
  red "  NOTE: restored the FK manually — the migration did not"
fi

summarize
