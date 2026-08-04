#!/usr/bin/env bash
# The v1 read contract, pinned before api/games.php is converted to the services.
#
# This is a refactor, so these are characterisation tests rather than TDD:
# they must pass BEFORE the change (proving they exercise the current code) and
# still pass after (proving behaviour did not move). js/games.js depends on
# every key asserted here — data.success, data.games, data.pagination.has_more,
# data.pagination.total_pages, data.game — so a silent shape change would break
# the dashboard rather than any test.
source "$(dirname "$0")/lib.sh"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "${GT_DB_NAME:-gameTracker_test}" -sNe "$1"
}

blue "Setup: session + CSRF"
COOKIE=$(mktemp); trap 'rm -f "$COOKIE"' EXIT
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

USER_ID=$(db "SELECT id FROM users WHERE username = '$TEST_USER'")

# Deterministic rows owned by the test user, so assertions do not depend on
# whatever other suites have left behind.
db "DELETE FROM games WHERE user_id = $USER_ID AND title LIKE 'V1READ %'"
db "INSERT INTO games (user_id, title, platform, genre, played, description) VALUES
     ($USER_ID, 'V1READ Alpha', 'PS2', 'RPG', 1, 'has a description'),
     ($USER_ID, 'V1READ Beta',  'PS2', 'FPS', 0, NULL),
     ($USER_ID, 'V1READ Gamma', 'PC',  'RPG', 1, '')"

blue "list: the response shape js/games.js depends on"

LIST=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=list&limit=100")

echo "$LIST" | jq -e '.success == true' > /dev/null \
  && { green "  PASS: success is true"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no success key: $(echo "$LIST" | head -c 200)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$LIST" | jq -e '.games | type == "array"' > /dev/null \
  && { green "  PASS: games is an array"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: games is not an array"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# All five pagination keys, by name. js/games.js reads has_more and
# total_pages directly, and a rename would break infinite scroll silently.
for key in page per_page total total_pages has_more; do
  echo "$LIST" | jq -e ".pagination | has(\"$key\")" > /dev/null \
    && { green "  PASS: pagination.$key present"; PASS_COUNT=$((PASS_COUNT+1)); } \
    || { red "  FAIL: pagination.$key missing"; FAIL_COUNT=$((FAIL_COUNT+1)); }
done

# Row shape: the columns the grid and list renderers read.
echo "$LIST" | jq -e '[.games[] | select(.title == "V1READ Alpha")] | length == 1' > /dev/null \
  && { green "  PASS: the seeded row is listed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: seeded row missing from list"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$LIST" | jq -e '.games[0] | has("id") and has("title") and has("platform") and has("front_cover_image")' > /dev/null \
  && { green "  PASS: rows carry the columns the renderers read"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: row shape changed"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "list: paging uses per_page, with an UNDERSCORE"

# This is the trap in converting to FilterCompiler, which reads `per-page`
# with a hyphen. If the conversion does not accept v1's spelling, the web app
# silently loses paging and starts pulling 100 rows a page regardless.
PAGED=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=list&per_page=2&page=1")
echo "$PAGED" | jq -e '(.games | length) <= 2 and .pagination.per_page == 2' > /dev/null \
  && { green "  PASS: per_page (underscore) is honoured"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: per_page ignored: $(echo "$PAGED" | jq -c .pagination)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

PAGE2=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=list&per_page=2&page=2")
echo "$PAGE2" | jq -e '.pagination.page == 2' > /dev/null \
  && { green "  PASS: page selects a page"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: page ignored: $(echo "$PAGE2" | jq -c .pagination)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# v1 list takes NO filters — the web frontend loads every page and filters in
# the browser. Pinned so the conversion does not quietly change what an
# unknown parameter does.
UNFILTERED=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=list&per_page=1000&platform=PS2")
echo "$UNFILTERED" | jq -e '[.games[] | select(.title | startswith("V1READ"))] | length == 3' > /dev/null \
  && { green "  PASS: an unsupported filter is ignored, not an error"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: platform param changed behaviour: $(echo "$UNFILTERED" | jq -c '[.games[]|select(.title|startswith("V1READ"))]|length')"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "get: single row"

GID=$(db "SELECT id FROM games WHERE user_id = $USER_ID AND title = 'V1READ Alpha'")
GET=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=get&id=$GID")

echo "$GET" | jq -e '.success == true and .game.title == "V1READ Alpha"' > /dev/null \
  && { green "  PASS: get returns .game with the right row"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected get shape: $(echo "$GET" | head -c 200)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "get: another user's row is refused"

OTHER=$(db "SELECT id FROM users WHERE username <> '$TEST_USER' ORDER BY id LIMIT 1")
if [[ -n "$OTHER" ]]; then
  db "DELETE FROM games WHERE user_id = $OTHER AND title = 'V1READ NotMine'"
  db "INSERT INTO games (user_id, title, platform) VALUES ($OTHER, 'V1READ NotMine', 'PC')"
  OID=$(db "SELECT id FROM games WHERE user_id = $OTHER AND title = 'V1READ NotMine'")
  DENIED=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=get&id=$OID")
  echo "$DENIED" | jq -e '.success == false' > /dev/null \
    && { green "  PASS: another user's game is refused"; PASS_COUNT=$((PASS_COUNT+1)); } \
    || { red "  FAIL: cross-user read leaked: $(echo "$DENIED" | head -c 200)"; FAIL_COUNT=$((FAIL_COUNT+1)); }
  db "DELETE FROM games WHERE id = $OID"
  db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $OID"
fi

blue "platforms: response shape"

PLAT=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=platforms")
echo "$PLAT" | jq -e '.success == true and (.platforms | type == "array")' > /dev/null \
  && { green "  PASS: platforms returns an array"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected platforms shape: $(echo "$PLAT" | head -c 200)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$PLAT" | jq -e '[.platforms[]] | index("PS2") != null' > /dev/null \
  && { green "  PASS: platforms includes a seeded platform"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: PS2 missing from platforms: $(echo "$PLAT" | jq -c .platforms)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "platforms: scoped to the caller"

# DELIBERATE BEHAVIOUR CHANGE, decided 2026-08-04. Unlike everything above,
# these two are NOT characterisation tests: they fail against the old
# getPlatforms and pass after the conversion.
#
# v1 returned EVERY user's platform names when no user_id was given ("for
# dropdown suggestions") and honoured a ?user_id= override — the same IDOR
# pattern Fable §1 removed from the list endpoint. GamesService::platforms is
# scoped to one user, so js/games.js's add-game datalist now suggests only
# platforms the caller already owns. The three other platform dropdowns
# (filters.js, stats.js, completions.js) derive their lists client-side from
# allGames and were already scoped, so they are unaffected.
if [[ -n "$OTHER" ]]; then
  # A platform string no other row can supply, so its absence is unambiguous.
  db "DELETE FROM games WHERE user_id = $OTHER AND title = 'V1READ Theirs'"
  db "INSERT INTO games (user_id, title, platform) VALUES ($OTHER, 'V1READ Theirs', 'V1READ-Only-Theirs')"
  TID=$(db "SELECT id FROM games WHERE user_id = $OTHER AND title = 'V1READ Theirs'")

  SCOPED=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=platforms")
  echo "$SCOPED" | jq -e '[.platforms[]] | index("V1READ-Only-Theirs") == null' > /dev/null \
    && { green "  PASS: another user's platform is not suggested"; PASS_COUNT=$((PASS_COUNT+1)); } \
    || { red "  FAIL: cross-user platform leaked: $(echo "$SCOPED" | jq -c .platforms)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

  # The override must be IGNORED, not rejected — same contract as list.
  OVERRIDE=$(curl -sS -b "$COOKIE" "$BASE_URL/api/games.php?action=platforms&user_id=$OTHER")
  echo "$OVERRIDE" | jq -e '.success == true
      and ([.platforms[]] | index("V1READ-Only-Theirs") == null)
      and ([.platforms[]] | index("PS2") != null)' > /dev/null \
    && { green "  PASS: ?user_id= is ignored, still the caller's platforms"; PASS_COUNT=$((PASS_COUNT+1)); } \
    || { red "  FAIL: ?user_id= override honoured or errored: $(echo "$OVERRIDE" | head -c 200)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

  db "DELETE FROM games WHERE id = $TID"
  db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $TID"
fi

# Clean up, tombstones included — all suites share one database.
IDS=$(db "SELECT GROUP_CONCAT(id) FROM games WHERE user_id = $USER_ID AND title LIKE 'V1READ %'")
db "DELETE FROM games WHERE user_id = $USER_ID AND title LIKE 'V1READ %'"
[[ -n "$IDS" ]] && db "DELETE FROM deletions WHERE table_name = 'games' AND server_id IN ($IDS)"

summarize
