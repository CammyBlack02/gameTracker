#!/usr/bin/env bash
# The v1 create contract, pinned before findMatchingGame's cover reuse is removed.
#
# Mostly CHARACTERISATION — must pass before the change and after. The last two
# sections are the inverse: they FAIL against the current code and pass after,
# because dropping the cover reuse is the deliberate behaviour change.
#
# Why it is being dropped. createGame calls findMatchingGame(title, platform) and
# copies the matched row's cover PATH into the new row. That query has no user_id
# predicate, so it matches across every user's collection, and its fuzzy matcher
# accepts anything scoring 80% on similar_text(). Both failure modes are present
# in production:
#
#   - Three games titled "Silent Hill 2" on PlayStation 2, owned by three
#     DIFFERENT users, share one uploaded file. One user's upload was handed to
#     the other two.
#   - "Prototype " (id 713) and "Prototype 2" (id 714) share one cover URL. 714
#     was created later and matched 713 above the threshold, so it displays the
#     wrong box art.
#
# Sharing a path is also what made deleteGame's unlink destructive — see
# tests/v2/test_v1_delete_contract.sh.
source "$(dirname "$0")/lib.sh"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "${GT_DB_NAME:-gameTracker_test}" -sNe "$1"
}

login() {
  local user="$1" pass="$2" jar="$3"
  curl -sS -c "$jar" -X POST "$BASE_URL/api/auth.php?action=login" \
    -d "username=$user&password=$pass" > /dev/null
  curl -sS -b "$jar" "$BASE_URL/dashboard.php" \
    | grep -oE '<meta name="csrf-token" content="[^"]+"' \
    | sed -E 's/.*content="([^"]+)"/\1/'
}

create() {  # create <jar> <csrf> <json-body>
  curl -sS -b "$1" -X POST "$BASE_URL/api/games.php?action=create" \
    -H "X-CSRF-Token: $2" -H 'Content-Type: application/json' \
    -d "$3" -o /tmp/v1create_body -w '%{http_code}'
}

blue "Setup"

JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
CSRF=$(login "$TEST_USER" "$TEST_PASS" "$JAR")
UID_OWNER=$(db "SELECT id FROM users WHERE username = '$TEST_USER'")

OTHER=$(db "SELECT id FROM users WHERE username = 'v1crother'")
if [[ -z "$OTHER" ]]; then
  db "INSERT INTO users (username, password_hash, role) VALUES ('v1crother', 'x', 'user')"
  OTHER=$(db "SELECT id FROM users WHERE username = 'v1crother'")
fi

cleanup_rows() {
  local ids
  ids=$(db "SELECT GROUP_CONCAT(id) FROM games WHERE title LIKE 'V1CREATE %'")
  db "DELETE FROM games WHERE title LIKE 'V1CREATE %'"
  [[ -n "$ids" ]] && db "DELETE FROM deletions WHERE table_name = 'games' AND server_id IN ($ids)"
}
cleanup_rows

[[ -n "$CSRF" ]] \
  && { green "  PASS: session + CSRF token"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no CSRF token"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "guards"

CODE=$(curl -sS -b "$JAR" "$BASE_URL/api/games.php?action=create" -o /dev/null -w '%{http_code}')
assert_eq "405" "$CODE" "a non-POST create is refused"

CODE=$(curl -sS -b "$JAR" -X POST "$BASE_URL/api/games.php?action=create" \
  -H 'Content-Type: application/json' -d '{"title":"x","platform":"y"}' -o /dev/null -w '%{http_code}')
assert_eq "403" "$CODE" "a POST without a CSRF token is refused"

blue "a plain create still works"

CODE=$(create "$JAR" "$CSRF" '{"title":"V1CREATE Plain","platform":"PS2"}')
assert_eq "200" "$CODE" "create returns 200"
jq -e '.success == true' < /tmp/v1create_body > /dev/null \
  && { green "  PASS: response carries success:true"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected body: $(head -c 160 /tmp/v1create_body)"; FAIL_COUNT=$((FAIL_COUNT+1)); }
assert_eq "1" "$(db "SELECT COUNT(*) FROM games WHERE user_id = $UID_OWNER AND title = 'V1CREATE Plain'")" \
  "the row was inserted for the calling user"

blue "an explicitly supplied cover is kept"

# Characterisation: a caller-supplied cover must never be second-guessed. The
# reuse only ever filled a BLANK cover, and removing it must not change this.
CODE=$(create "$JAR" "$CSRF" '{"title":"V1CREATE Explicit","platform":"PS2","front_cover_image":"https://example.invalid/mine.jpg"}')
assert_eq "200" "$CODE" "create with an explicit cover returns 200"
assert_eq "https://example.invalid/mine.jpg" \
  "$(db "SELECT front_cover_image FROM games WHERE user_id = $UID_OWNER AND title = 'V1CREATE Explicit'")" \
  "the supplied cover is stored unchanged"

blue "BEHAVIOUR CHANGE: a blank cover is NOT filled from another row"

# Seed a donor owned by the SAME user with a local filename cover, then create a
# same-title same-platform game with no cover. Today findMatchingGame donates the
# path; after the change the new row keeps a blank cover.
db "INSERT INTO games (user_id, title, platform, front_cover_image)
    VALUES ($UID_OWNER, 'V1CREATE Donor', 'PS2', 'v1create-donor.jpg')"

CODE=$(create "$JAR" "$CSRF" '{"title":"V1CREATE Donor","platform":"PS2"}')
assert_eq "200" "$CODE" "creating a same-titled game returns 200"

INHERITED=$(db "SELECT COUNT(*) FROM games
                WHERE user_id = $UID_OWNER AND title = 'V1CREATE Donor'
                  AND front_cover_image = 'v1create-donor.jpg'")
assert_eq "1" "$INHERITED" "only the donor holds that path — the new row did not inherit it"

blue "BEHAVIOUR CHANGE: another user's cover is never donated"

# The sharper half. findMatchingGame has no user_id predicate, so today this
# hands one user's uploaded file to another. Three production rows are in exactly
# this state.
db "INSERT INTO games (user_id, title, platform, front_cover_image)
    VALUES ($OTHER, 'V1CREATE Foreign', 'PS2', 'v1create-foreign.jpg')"

CODE=$(create "$JAR" "$CSRF" '{"title":"V1CREATE Foreign","platform":"PS2"}')
assert_eq "200" "$CODE" "creating a game titled like another user's returns 200"

LEAKED=$(db "SELECT COUNT(*) FROM games
             WHERE user_id = $UID_OWNER AND title = 'V1CREATE Foreign'
               AND front_cover_image = 'v1create-foreign.jpg'")
assert_eq "0" "$LEAKED" "no cross-user cover path was copied into the caller's row"

blue "BEHAVIOUR CHANGE: updateGame does not donate covers either"

# updateGame called findMatchingGame on the same terms, and was the worse of the
# two call sites: it fired on EVERY save of a game whose cover happened to be
# blank, so a row could acquire someone else's file without the user going
# anywhere near the image fields.
db "INSERT INTO games (user_id, title, platform, front_cover_image)
    VALUES ($UID_OWNER, 'V1CREATE UpdDonor', 'PS2', 'v1create-upddonor.jpg')"
db "INSERT INTO games (user_id, title, platform, front_cover_image)
    VALUES ($UID_OWNER, 'V1CREATE UpdDonor', 'PS2', NULL)"
TARGET=$(db "SELECT id FROM games
             WHERE user_id = $UID_OWNER AND title = 'V1CREATE UpdDonor'
               AND front_cover_image IS NULL LIMIT 1")

# updateGame reads the id from the JSON body, not the query string — a request
# with it only in the URL is a 400, which would make the cover assertion below
# pass vacuously.
CODE=$(curl -sS -b "$JAR" -X POST "$BASE_URL/api/games.php?action=update" \
  -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d "{\"id\":$TARGET,\"genre\":\"Horror\"}" -o /tmp/v1create_body -w '%{http_code}')
assert_eq "200" "$CODE" "updating an unrelated field returns 200"

assert_eq "Horror" "$(db "SELECT genre FROM games WHERE id = $TARGET")" \
  "the field the caller actually changed was saved"
assert_eq "" "$(db "SELECT IFNULL(front_cover_image,'') FROM games WHERE id = $TARGET")" \
  "the blank cover stayed blank — no path was donated on save"

cleanup_rows
db "DELETE FROM games WHERE user_id = $OTHER AND title LIKE 'V1CREATE %'"
db "DELETE FROM users WHERE username = 'v1crother'"

summarize
