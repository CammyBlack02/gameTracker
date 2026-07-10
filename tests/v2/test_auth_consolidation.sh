#!/usr/bin/env bash
# Every v1 endpoint now delegates auth to requireUser() in includes/auth.php.
# Anonymous /api/ requests get JSON 401; anonymous HTML pages 302 to /index.php.
source "$(dirname "$0")/lib.sh"

blue "API v1: unauthenticated requests return 401 JSON"
for endpoint in \
  "admin.php?action=list" \
  "games.php?action=list" \
  "completions.php?action=list" \
  "stats.php?action=overview" \
  "items.php?action=list" \
  "settings.php?action=get" \
  "cover-image.php?title=x" \
  "steam-import.php?action=test_connection" \
  "download-cover.php?url=https://example.com/x.png" \
  "game-metadata.php?title=x" \
  ; do
  req GET "/api/$endpoint"
  assert_eq "401" "$HTTP_STATUS" "GET /api/$endpoint anonymous = 401"
done

blue "API v1: authenticated non-admin gets 403 on admin-only actions"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null
req GET "/api/admin.php?action=list" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "admin?list as non-admin = 403"
rm -f "$COOKIE"

summarize
