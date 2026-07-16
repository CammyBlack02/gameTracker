#!/usr/bin/env bash
# Every mutating v1 action returns 405 on GET. SameSite=Lax does not
# fully protect GET-triggered mutations; POST-only is the interim
# defence until full CSRF token enforcement lands with Phase 4.
source "$(dirname "$0")/lib.sh"

COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

blue "Mutating actions reject GET"

# Format: path[|expected_status_regex]
ENDPOINTS=(
  "games.php?action=create"
  "games.php?action=update"
  "games.php?action=delete"
  "items.php?action=create"
  "items.php?action=update"
  "items.php?action=delete"
  "completions.php?action=create"
  "completions.php?action=update"
  "completions.php?action=delete"
  "admin.php?action=reset_password"
  "admin.php?action=delete"
  "auth.php?action=logout"
  "auth.php?action=register"
  "steam-import.php?action=import"
  "steam-import.php?action=delete_pc_games"
  "settings.php?action=set_background"
  "settings.php?action=remove_background"
  "settings.php?action=set_steam"
  "upload.php"
  "import-gameeye.php"
)

for endpoint in "${ENDPOINTS[@]}"; do
  req GET "/api/$endpoint" "" -b "$COOKIE"
  assert_eq "405" "$HTTP_STATUS" "GET /api/$endpoint = 405"
done

rm -f "$COOKIE"

summarize
