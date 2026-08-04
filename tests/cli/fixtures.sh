#!/usr/bin/env bash
# Deterministic fixture rows for the CLI suites.
#
# setup-test-db.sh seeds users only, so each suite that needs collection data
# inserts its own. Values are chosen so every filter has both a match and a
# non-match, and so one row belongs to a second user to prove scoping.
#
# Requires: GT_DB_NAME, GT_DB_USER, GT_DB_PASS, GT_DB_HOST (exported by run-all.sh)

# MYSQL_PWD rather than -p: passing a password as an argument makes mysql print
# an "insecure" warning to stderr on every single call, which buries real
# failures in noise.
fixture_mysql() {
  local host_flag=""
  if [[ -n "${GT_DB_HOST:-}" ]]; then
    host_flag="-h${GT_DB_HOST}"
  fi
  MYSQL_PWD="${GT_DB_PASS:-}" mysql $host_flag -u"${GT_DB_USER:-root}" "${GT_DB_NAME:-gameTracker_test}" "$@"
}

# Fixtures own a dedicated user rather than sharing $TEST_USER.
#
# The v2 suites create their own games under testuser ("Test Game", "Upload Test
# Game") and run before the CLI suites in run-all.sh, so sharing that account
# leaked their rows into every filter expectation. Cleaning by title prefix was
# not enough — isolation has to be by owner, which also makes these suites
# immune to run-order changes.
FIXTURE_USER="gtfixture"

# Resolve user ids by username so fixtures do not assume auto-increment values.
fixture_user_id() {
  fixture_mysql -N -e "SELECT id FROM users WHERE username = '$1'"
}

# Look up a fixture row's id by title, scoped by ownership.
#
# $1 table, $2 title, $3 "mine" (owned by the fixture user) or "other".
# Scoping matters: a title-only lookup can match another user's identically
# named row, and then `gt <res> get <id>` correctly refuses it as someone
# else's — producing a confusing empty result rather than a clear failure.
fixture_id() {
  local op="="
  [[ "$3" == "other" ]] && op="<>"

  fixture_mysql -N -e "
    SELECT t.id FROM $1 t
    JOIN users u ON t.user_id = u.id
    WHERE t.title = '$2' AND u.username $op '$FIXTURE_USER'
    LIMIT 1
  "
}

# password_hash is NOT NULL but never verified: nothing authenticates as this
# account, the CLI resolves users by name or id.
fixture_ensure_user() {
  fixture_mysql -e "
    INSERT IGNORE INTO users (username, password_hash, role)
    VALUES ('$FIXTURE_USER', 'unusable-fixture-account', 'user');
  "
}

seed_games() {
  local uid other
  fixture_ensure_user
  uid=$(fixture_user_id "$FIXTURE_USER")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '$FIXTURE_USER' ORDER BY id LIMIT 1")

  # Delete by owner, not by title: idempotent across runs and unaffected by
  # whatever other suites have left in the table.
  fixture_mysql -e "DELETE FROM games WHERE user_id = $uid"

  # played/is_physical are nullable ints, so NULL is used deliberately on two
  # rows to prove --unplayed and --digital match NULL as well as 0.
  fixture_mysql -e "
    INSERT INTO games
      (user_id, title, platform, genre, series, description, star_rating,
       played, is_physical, front_cover_image, created_at)
    VALUES
      ($uid, 'FIXTURE Halo 3',      'Xbox 360', 'FPS',    'Halo', 'desc', 5,    1,    1,    'a.jpg', '2026-01-10 00:00:00'),
      ($uid, 'FIXTURE Silent Hill', 'PS2',      'Horror', NULL,   NULL,   NULL, 0,    1,    NULL,    '2026-02-15 00:00:00'),
      ($uid, 'FIXTURE Okami',       'PS2',      'Action', NULL,   '',     4,    NULL, 0,    NULL,    '2026-03-20 00:00:00'),
      ($uid, 'FIXTURE Halo Reach',  'Xbox 360', 'FPS',    'Halo', 'desc', 3,    1,    NULL, 'b.jpg', '2026-04-01 00:00:00'),
      ($uid, 'FIXTURE Journey',     'PS3',      NULL,     NULL,   NULL,   5,    1,    0,    NULL,    '2026-05-05 00:00:00'),
      ($other, 'FIXTURE Not Mine',  'PC',       'RPG',    NULL,   'desc', 5,    1,    1,    'c.jpg', '2026-06-06 00:00:00');
  "
}

# Give one fixture game a child row of each kind, so delete tests exercise both
# cascade behaviours rather than only the parent row.
#
# game_images.game_id is ON DELETE CASCADE — the row is destroyed and a
# tombstone is written for it. game_completions.game_id is ON DELETE SET NULL —
# the row survives, its link is nulled, and NO tombstone fires because that is
# an UPDATE. Undo has to handle both, and only a fixture with both can prove it.
seed_game_children() {
  local uid game_id
  uid=$(fixture_user_id "$FIXTURE_USER")
  game_id=$(fixture_id games 'FIXTURE Halo 3' mine)

  fixture_mysql -e "DELETE FROM game_images WHERE user_id = $uid"
  fixture_mysql -e "DELETE FROM game_completions WHERE user_id = $uid"

  fixture_mysql -e "
    INSERT INTO game_images (game_id, user_id, image_path)
    VALUES ($game_id, $uid, 'fixture-extra-1.jpg'),
           ($game_id, $uid, 'fixture-extra-2.jpg');
  "

  fixture_mysql -e "
    INSERT INTO game_completions
      (game_id, user_id, title, platform, date_completed, completion_year)
    VALUES ($game_id, $uid, 'FIXTURE Halo 3', 'Xbox 360', '2026-01-20', 2026);
  "
}

seed_items() {
  local uid other
  fixture_ensure_user
  uid=$(fixture_user_id "$FIXTURE_USER")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '$FIXTURE_USER' ORDER BY id LIMIT 1")

  fixture_mysql -e "DELETE FROM items WHERE user_id = $uid"

  fixture_mysql -e "
    INSERT INTO items
      (user_id, title, platform, category, description, quantity, created_at)
    VALUES
      ($uid, 'FIXTURE Dual Shock',  'PS2',      'Controller', 'desc', 2, '2026-01-11 00:00:00'),
      ($uid, 'FIXTURE Memory Card', 'PS2',      'Storage',    NULL,   1, '2026-02-12 00:00:00'),
      ($uid, 'FIXTURE Xbox Pad',    'Xbox 360', 'Controller', '',     1, '2026-03-13 00:00:00'),
      ($other, 'FIXTURE Not Mine Item', 'PC',   'Cable',      'desc', 1, '2026-04-14 00:00:00');
  "
}
