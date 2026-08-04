#!/usr/bin/env bash
# The v1 delete contract, pinned before api/games.php's deleteGame is converted
# to share GamesWriter's row mechanics.
#
# Mostly CHARACTERISATION: these must pass BEFORE the conversion (proving they
# exercise the current code) and still pass after. The last section is the
# inverse — it FAILS against the current code and passes after — because not
# unlinking image files is the deliberate behaviour change.
#
# The admin-override section is the trap worth pinning. deleteGame lets an admin
# delete another user's game, but GamesWriter::applyDelete scopes every statement
# to a single $userId. A naive conversion silently removes the override, exactly
# as it nearly did for getGame in the reads half.
source "$(dirname "$0")/lib.sh"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
COVERS="$ROOT/uploads/covers"
mkdir -p "$COVERS"

db() {
  MYSQL_PWD="${GT_DB_PASS:-${TEST_DB_PASS:-}}" \
    mysql -u"${GT_DB_USER:-${TEST_DB_USER:-root}}" "${GT_DB_NAME:-gameTracker_test}" -sNe "$1"
}

# Log in and capture the CSRF token the same way test_base64_rejected.sh does:
# it lives in $_SESSION and is rendered into authenticated HTML as a meta tag.
login() {
  local user="$1" pass="$2" jar="$3"
  curl -sS -c "$jar" -X POST "$BASE_URL/api/auth.php?action=login" \
    -d "username=$user&password=$pass" > /dev/null
  curl -sS -b "$jar" "$BASE_URL/dashboard.php" \
    | grep -oE '<meta name="csrf-token" content="[^"]+"' \
    | sed -E 's/.*content="([^"]+)"/\1/'
}

blue "Setup: two sessions — an owner and an admin"

OWNER_JAR=$(mktemp); ADMIN_JAR=$(mktemp)
trap 'rm -f "$OWNER_JAR" "$ADMIN_JAR"' EXIT

OWNER_CSRF=$(login "$TEST_USER" "$TEST_PASS" "$OWNER_JAR")
ADMIN_CSRF=$(login admin admin "$ADMIN_JAR")

[[ -n "$OWNER_CSRF" ]] \
  && { green "  PASS: owner session + CSRF token"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no owner CSRF token"; FAIL_COUNT=$((FAIL_COUNT+1)); }
[[ -n "$ADMIN_CSRF" ]] \
  && { green "  PASS: admin session + CSRF token"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no admin CSRF token"; FAIL_COUNT=$((FAIL_COUNT+1)); }

UID_OWNER=$(db "SELECT id FROM users WHERE username = '$TEST_USER'")

# Every fixture row is owned explicitly and cleaned by user_id, never by title
# prefix — all suites share one database.
seed() {  # seed <owner_id> <title> [front_cover]
  db "INSERT INTO games (user_id, title, platform, front_cover_image)
      VALUES ($1, '$2', 'PS2', $([[ -n "${3:-}" ]] && echo "'$3'" || echo NULL))"
  db "SELECT id FROM games WHERE user_id = $1 AND title = '$2' ORDER BY id DESC LIMIT 1"
}

purge() {  # purge <game_id>
  db "DELETE FROM games WHERE id = $1"
  db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $1"
}

# requireCsrfToken reads X-CSRF-Token before falling back to a csrf_token POST
# field, so the header is the right channel here.
del() {  # del <cookie-jar> <csrf> <query-string>
  curl -sS -b "$1" -X POST "$BASE_URL/api/games.php?action=delete&$3" \
    -H "X-CSRF-Token: $2" -o /tmp/v1del_body -w '%{http_code}'
}

blue "guards: method and CSRF"

G1=$(seed "$UID_OWNER" 'V1DEL Guarded')
CODE=$(curl -sS -b "$OWNER_JAR" "$BASE_URL/api/games.php?action=delete&id=$G1" -o /dev/null -w '%{http_code}')
assert_eq "405" "$CODE" "a non-POST delete is refused"

CODE=$(curl -sS -b "$OWNER_JAR" -X POST "$BASE_URL/api/games.php?action=delete&id=$G1" -o /dev/null -w '%{http_code}')
assert_eq "403" "$CODE" "a POST without a CSRF token is refused"

assert_eq "$G1" "$(db "SELECT id FROM games WHERE id = $G1")" "the row survived both refusals"

blue "arguments"

CODE=$(del "$OWNER_JAR" "$OWNER_CSRF" "id=")
assert_eq "400" "$CODE" "a missing id is a 400"

CODE=$(del "$OWNER_JAR" "$OWNER_CSRF" "id=99999999")
assert_eq "404" "$CODE" "an unknown id is a 404"

blue "ownership"

OTHER=$(db "SELECT id FROM users WHERE username <> '$TEST_USER' AND role <> 'admin' ORDER BY id LIMIT 1")
if [[ -z "$OTHER" ]]; then
  db "INSERT INTO users (username, password_hash, role) VALUES ('v1delother', 'x', 'user')"
  OTHER=$(db "SELECT id FROM users WHERE username = 'v1delother'")
fi

THEIRS=$(seed "$OTHER" 'V1DEL Theirs')
CODE=$(del "$OWNER_JAR" "$OWNER_CSRF" "id=$THEIRS")
assert_eq "403" "$CODE" "a non-admin cannot delete another user's game"
assert_eq "$THEIRS" "$(db "SELECT id FROM games WHERE id = $THEIRS")" "their row survived"

# THE TRAP. deleteGame grants admins a cross-user override; applyDelete scopes
# to one user and has none. Converting without carrying this across silently
# removes a capability the admin dashboard depends on.
CODE=$(del "$ADMIN_JAR" "$ADMIN_CSRF" "id=$THEIRS")
assert_eq "200" "$CODE" "an ADMIN can delete another user's game"
assert_eq "" "$(db "SELECT id FROM games WHERE id = $THEIRS")" "the admin's delete removed the row"
db "DELETE FROM deletions WHERE table_name = 'games' AND server_id = $THEIRS"

blue "the delete itself"

G2=$(seed "$UID_OWNER" 'V1DEL WithChildren')
db "INSERT INTO game_images (user_id, game_id, image_path) VALUES ($UID_OWNER, $G2, 'v1del-extra.jpg')"
CODE=$(del "$OWNER_JAR" "$OWNER_CSRF" "id=$G2")

assert_eq "200" "$CODE" "delete returns 200"
echo "$(cat /tmp/v1del_body)" | jq -e '.success == true' > /dev/null \
  && { green "  PASS: response carries success:true"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected body: $(head -c 160 /tmp/v1del_body)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

assert_eq "" "$(db "SELECT id FROM games WHERE id = $G2")" "the parent row is gone"
assert_eq "0" "$(db "SELECT COUNT(*) FROM game_images WHERE game_id = $G2")" "child game_images cascaded away"
assert_eq "1" "$(db "SELECT COUNT(*) FROM deletions WHERE table_name = 'games' AND server_id = $G2")" \
  "a tombstone was written, so the phone hears about it"
db "DELETE FROM deletions WHERE server_id = $G2"

blue "BEHAVIOUR CHANGE: delete must stop unlinking image files"

# NOT characterisation. These two fail against the current code and pass after.
#
# api/games.php unlinks the cover unconditionally, but createGame's
# findMatchingGame reuses another game's image PATH when title and platform
# match — so several rows can reference one file. Three production games share
# one path right now. Deleting any of them breaks the other two's cover art,
# which is real data loss on rows the user did not touch.
#
# The CLI already settled this: never unlink. An orphaned file costs disk; a
# broken cover on a surviving game is unrecoverable. `gt images prune` sweeps
# the orphans, and leaving files is also what makes a delete reversible.
SHARED="v1del-shared-$$.jpg"
printf 'not-a-real-jpeg' > "$COVERS/$SHARED"

TWIN_A=$(seed "$UID_OWNER" 'V1DEL TwinA' "$SHARED")
TWIN_B=$(seed "$UID_OWNER" 'V1DEL TwinB' "$SHARED")

del "$OWNER_JAR" "$OWNER_CSRF" "id=$TWIN_A" > /dev/null

assert_eq "yes" "$([[ -f "$COVERS/$SHARED" ]] && echo yes || echo no)" \
  "a cover file shared with a surviving game is NOT unlinked"
assert_eq "$TWIN_B" "$(db "SELECT id FROM games WHERE id = $TWIN_B")" "the twin still exists"
assert_eq "$SHARED" "$(db "SELECT front_cover_image FROM games WHERE id = $TWIN_B")" \
  "the twin still references the file"

# The same rule, stated for the unshared case: delete never unlinks, full stop.
# A conditional "only unlink when unshared" would need a reference count on every
# delete and would still race a concurrent create that reuses the path.
LONE="v1del-lone-$$.jpg"
printf 'not-a-real-jpeg' > "$COVERS/$LONE"
SOLO=$(seed "$UID_OWNER" 'V1DEL Solo' "$LONE")
del "$OWNER_JAR" "$OWNER_CSRF" "id=$SOLO" > /dev/null

assert_eq "yes" "$([[ -f "$COVERS/$LONE" ]] && echo yes || echo no)" \
  "an unshared cover file is left on disk too — gt images prune owns cleanup"

# Cleanup: rows, tombstones and the fixture files.
IDS=$(db "SELECT GROUP_CONCAT(id) FROM games WHERE title LIKE 'V1DEL %'")
db "DELETE FROM games WHERE title LIKE 'V1DEL %'"
[[ -n "$IDS" ]] && db "DELETE FROM deletions WHERE table_name = 'games' AND server_id IN ($IDS)"
for g in $G1 $G2 $TWIN_A $TWIN_B $SOLO $THEIRS; do purge "$g" 2>/dev/null; done
db "DELETE FROM users WHERE username = 'v1delother'"
rm -f "$COVERS/$SHARED" "$COVERS/$LONE"

summarize
