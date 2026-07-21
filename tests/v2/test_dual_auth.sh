#!/usr/bin/env bash
# Dual-auth tests — verify v2_require_auth accepts both Bearer tokens
# (iOS) and browser session cookies with CSRF. See design doc:
# docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md
source "$(dirname "$0")/lib.sh"

# --- Setup: obtain a Bearer token and a session cookie for the test user.

blue "Setup: mint a Bearer token"
req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS&device_name=dual-auth-test"
assert_eq "200" "$HTTP_STATUS" "token mint = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
[[ ${#TOKEN} -eq 64 ]] || { red "  FAIL: bad token length"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "Setup: log in via v1 to establish a session cookie"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

# CSRF token lives in $_SESSION and is rendered on authenticated HTML pages
# as <meta name="csrf-token">. Fetch a page with the cookie and grep out the token.
blue "Setup: extract CSRF token from an authed page"
CSRF=$(curl -sS -b "$COOKIE" "$BASE_URL/dashboard.php" \
  | grep -oE '<meta name="csrf-token" content="[^"]+"' \
  | sed -E 's/.*content="([^"]+)"/\1/')
[[ -n "$CSRF" && ${#CSRF} -ge 32 ]] && green "  PASS: CSRF token captured (${#CSRF} chars)" && PASS_COUNT=$((PASS_COUNT+1)) \
  || { red "  FAIL: CSRF token capture failed"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Case 1: Bearer valid → 200 (Bearer path, GET)
blue "Case 1: valid Bearer + GET → 200"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "Bearer GET = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "pong body"

# --- Case 2: No credentials → 401 missing_token
blue "Case 2: no Bearer, no cookie → 401 missing_token"
req GET "/api/v2/_ping.php"
assert_eq "401" "$HTTP_STATUS" "no creds = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "missing_token code"

# --- Case 3: Invalid Bearer → 401 invalid_token
blue "Case 3: invalid Bearer → 401 invalid_token"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer 0000000000000000000000000000000000000000000000000000000000000000"
assert_eq "401" "$HTTP_STATUS" "bad Bearer = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "invalid_token code"

# --- Case 4: Session cookie + GET → 200 (session path, no CSRF needed)
blue "Case 4: session cookie + GET → 200"
req GET "/api/v2/_ping.php" "" -b "$COOKIE"
assert_eq "200" "$HTTP_STATUS" "session GET = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "pong body"

# --- Case 5: Session cookie + POST + valid CSRF → 200
blue "Case 5: session + POST + valid CSRF → 200"
req POST "/api/v2/_ping.php" "" -b "$COOKIE" -H "X-CSRF-Token: $CSRF"
assert_eq "200" "$HTTP_STATUS" "session POST + CSRF = 200"

# --- Case 6: Session cookie + POST + no CSRF → 403 invalid_csrf
blue "Case 6: session + POST + missing CSRF → 403 invalid_csrf"
req POST "/api/v2/_ping.php" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "session POST no CSRF = 403"
assert_contains '"error":"invalid_csrf"' "$RESPONSE_BODY" "invalid_csrf code"

# --- Cleanup
blue "Cleanup: revoke Bearer token"
curl -sS -X POST "$BASE_URL/api/v2/auth/revoke.php" \
  -H "Authorization: Bearer $TOKEN" > /dev/null
rm -f "$COOKIE"

summarize
