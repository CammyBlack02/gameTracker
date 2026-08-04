#!/usr/bin/env bash
# An image column may hold a filename or a URL. Never an image.
#
# api/games.php localised http(s) URLs in updateGame and nothing at all in
# createGame, so a data: URI was stored verbatim. By 2026-08-04 that was ~113 MB
# of base64 across 81 rows — most of the database — and still arriving months
# after scripts/migrate-base64-covers.php converted 542 of them.
#
# Design: docs/superpowers/specs/2026-08-04-gt-cli-images-design.md
source "$(dirname "$0")/lib.sh"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "${GT_DB_NAME:-gameTracker_test}" -sNe "$1"
}

# A 1x1 GIF: small enough to inline, and a genuinely decodable image.
TINY_GIF="data:image/gif;base64,R0lGODlhAQABAIAAAP8AAAAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=="

blue "Setup: log in via v1 for a session cookie"
COOKIE=$(mktemp)
trap 'rm -f "$COOKIE"' EXIT
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

# v1 mutations require the CSRF token, which lives in $_SESSION and is rendered
# on authenticated HTML pages as <meta name="csrf-token">. Same idiom as
# test_dual_auth.sh.
CSRF=$(curl -sS -b "$COOKIE" "$BASE_URL/dashboard.php" \
  | grep -oE '<meta name="csrf-token" content="[^"]+"' \
  | sed -E 's/.*content="([^"]+)"/\1/')
[[ -n "$CSRF" ]] \
  && { green "  PASS: CSRF token captured"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no CSRF token"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "createGame must not store a data URI"

CREATE=$(curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/games.php?action=create" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d "{\"title\":\"B64 Create Probe\",\"platform\":\"PC\",\"front_cover_image\":\"$TINY_GIF\"}")

NEW_ID=$(db "SELECT id FROM games WHERE title = 'B64 Create Probe' ORDER BY id DESC LIMIT 1")

if [[ -n "$NEW_ID" ]]; then
  green "  PASS: the game was created"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: no row created: $CREATE"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

if [[ -n "$NEW_ID" ]]; then
  STORED_PREFIX=$(db "SELECT LEFT(front_cover_image, 5) FROM games WHERE id = $NEW_ID")
  if [[ "$STORED_PREFIX" != "data:" ]]; then
    green "  PASS: the created row does not hold a data URI"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: created row still holds a data URI"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  # Assert the SHAPE, not the length. A 1x1 test GIF inlines to 82 bytes, so a
  # "shorter than N" check passes happily on a stored data URI — which is
  # exactly the bug. A filename has an image extension and no scheme.
  STORED=$(db "SELECT COALESCE(front_cover_image,'') FROM games WHERE id = $NEW_ID")
  if [[ "$STORED" =~ ^[A-Za-z0-9._-]+\.(jpg|jpeg|png|gif|webp)$ ]]; then
    green "  PASS: stored a filename ($STORED)"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: not a filename: '${STORED:0:60}'"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  # And the file it names must actually exist on disk.
  if [[ -n "$STORED" && -f "$(dirname "$0")/../../uploads/covers/$STORED" ]]; then
    green "  PASS: the decoded file exists on disk"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: uploads/covers/$STORED is missing"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  blue "updateGame must not store a data URI either"

  curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/games.php?action=update" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
    -d "{\"id\":$NEW_ID,\"front_cover_image\":\"$TINY_GIF\"}" > /dev/null

  STORED2=$(db "SELECT COALESCE(front_cover_image,'') FROM games WHERE id = $NEW_ID")
  if [[ "$STORED2" =~ ^[A-Za-z0-9._-]+\.(jpg|jpeg|png|gif|webp)$ ]]; then
    green "  PASS: update stored a filename ($STORED2)"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: update did not store a filename: '${STORED2:0:60}'"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  blue "An undecodable data URI is rejected, not stored"

  BEFORE_BAD=$(db "SELECT COALESCE(front_cover_image,'') FROM games WHERE id = $NEW_ID")
  curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/games.php?action=update" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
    -d "{\"id\":$NEW_ID,\"front_cover_image\":\"data:image/gif;base64,!!!not-base64!!!\"}" > /dev/null

  AFTER_BAD=$(db "SELECT COALESCE(front_cover_image,'') FROM games WHERE id = $NEW_ID")
  if [[ "$AFTER_BAD" == "$BEFORE_BAD" ]]; then
    green "  PASS: a bad data URI left the existing value untouched"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: bad URI changed the value to '$AFTER_BAD'"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  blue "An http URL is still accepted (existing behaviour must not regress)"

  curl -sS -b "$COOKIE" -X POST "$BASE_URL/api/games.php?action=update" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
    -d "{\"id\":$NEW_ID,\"front_cover_image\":\"https://invalid.invalid/x.jpg\"}" > /dev/null

  URLVAL=$(db "SELECT COALESCE(front_cover_image,'') FROM games WHERE id = $NEW_ID")
  if [[ "$URLVAL" == https://* ]]; then
    green "  PASS: an unfetchable URL is kept as a URL"; PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: expected the URL to remain, got '$URLVAL'"; FAIL_COUNT=$((FAIL_COUNT+1))
  fi

  # Clean up completely, tombstone included. Deleting a game fires
  # trg_games_after_delete, and test_smoke.sh asserts testuser has exactly ONE
  # games tombstone — so leaving ours behind breaks a suite that has nothing to
  # do with this one. All suites share one database; leave it as you found it.
  db "DELETE FROM games WHERE id = $NEW_ID"
  db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $NEW_ID"
fi

summarize
