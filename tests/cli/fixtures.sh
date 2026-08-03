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

# Resolve user ids by username so fixtures do not assume auto-increment values.
fixture_user_id() {
  fixture_mysql -N -e "SELECT id FROM users WHERE username = '$1'"
}

seed_games() {
  local uid other
  uid=$(fixture_user_id "${TEST_USER:-testuser}")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '${TEST_USER:-testuser}' ORDER BY id LIMIT 1")

  fixture_mysql -e "DELETE FROM games WHERE title LIKE 'FIXTURE %'"

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

seed_items() {
  local uid other
  uid=$(fixture_user_id "${TEST_USER:-testuser}")
  other=$(fixture_mysql -N -e "SELECT id FROM users WHERE username <> '${TEST_USER:-testuser}' ORDER BY id LIMIT 1")

  fixture_mysql -e "DELETE FROM items WHERE title LIKE 'FIXTURE %'"

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
