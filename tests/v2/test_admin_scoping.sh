#!/usr/bin/env bash
# admin.php?action=list previously let any authenticated user list all
# users + counts + emails. Now admin-only.
source "$(dirname "$0")/lib.sh"

blue "admin.php list is admin-only"

# testuser is seeded as role='user'.
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

req GET "/api/admin.php?action=list" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "non-admin cannot list users"
assert_contains "Admin access required" "$RESPONSE_BODY" "error message says admin required"

rm -f "$COOKIE"

summarize
