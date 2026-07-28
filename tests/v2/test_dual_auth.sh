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

# --- Case 7: Session + POST + WRONG CSRF → 403
# Distinct from case 6: exercises the hash_equals mismatch branch rather
# than the "token absent" branch.
blue "Case 7: session + POST + wrong CSRF → 403 invalid_csrf"
req POST "/api/v2/_ping.php" "" -b "$COOKIE" -H "X-CSRF-Token: deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef"
assert_eq "403" "$HTTP_STATUS" "session POST wrong CSRF = 403"
assert_contains '"error":"invalid_csrf"' "$RESPONSE_BODY" "invalid_csrf code"

# --- Case 8: Bearer precedence — invalid Bearer alongside a valid session
# must still 401, not silently fall through to the session credential.
blue "Case 8: invalid Bearer + valid session cookie → 401 (fails closed)"
req GET "/api/v2/_ping.php" "" -b "$COOKIE" -H "Authorization: Bearer 1111111111111111111111111111111111111111111111111111111111111111"
assert_eq "401" "$HTTP_STATUS" "bad Bearer beats good session = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "invalid_token code"

# --- Case 9: DELETE counts as mutating for the CSRF gate
blue "Case 9: session + DELETE + no CSRF → 405 or 403, never 200"
req DELETE "/api/v2/_ping.php" "" -b "$COOKIE"
[[ "$HTTP_STATUS" == "403" || "$HTTP_STATUS" == "405" ]] \
  && green "  PASS: session DELETE without CSRF rejected ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) \
  || { red "  FAIL: session DELETE without CSRF got $HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Case 10: read-only endpoints are GET-only (method guards)
blue "Case 10: POST to a read-only v2 endpoint → 405"
req POST "/api/v2/images/cover.php?id=1" "" -b "$COOKIE" -H "X-CSRF-Token: $CSRF"
assert_eq "405" "$HTTP_STATUS" "POST to images/cover.php = 405"
assert_contains '"error":"method_not_allowed"' "$RESPONSE_BODY" "method_not_allowed code"

# --- Case 11: a read-only GET still works for a session caller with no CSRF
blue "Case 11: session + GET on read-only endpoint, no CSRF → not 403"
req GET "/api/v2/pricecharting.php?title=Halo&platform=Xbox" "" -b "$COOKIE"
[[ "$HTTP_STATUS" != "403" ]] \
  && green "  PASS: read-only GET not CSRF-gated ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) \
  || { red "  FAIL: read-only GET wrongly demanded CSRF"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Case 12: state-changing GET (external-image) DOES demand CSRF from a
# session caller, because SameSite=Lax sends the cookie on cross-site
# top-level navigation. Bearer callers (iOS) are unaffected — case 13.
blue "Case 12: session + GET external-image, no CSRF → 403 invalid_csrf"
req GET "/api/v2/external-image.php?url=https://example.com/x.jpg&game_id=1" "" -b "$COOKIE"
assert_eq "403" "$HTTP_STATUS" "session GET external-image no CSRF = 403"
assert_contains '"error":"invalid_csrf"' "$RESPONSE_BODY" "invalid_csrf code"

# --- Case 13: the same call with a Bearer token is NOT CSRF-gated (iOS path).
# Any status other than 403 proves the gate didn't fire; the download itself
# may well fail on the dummy URL, which is fine.
blue "Case 13: Bearer + GET external-image → not CSRF-gated"
req GET "/api/v2/external-image.php?url=https://example.com/x.jpg&game_id=1" "" -H "Authorization: Bearer $TOKEN"
[[ "$HTTP_STATUS" != "403" ]] \
  && green "  PASS: Bearer path not CSRF-gated ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) \
  || { red "  FAIL: Bearer path wrongly demanded CSRF"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Cleanup
blue "Cleanup: revoke Bearer token"
curl -sS -X POST "$BASE_URL/api/v2/auth/revoke.php" \
  -H "Authorization: Bearer $TOKEN" > /dev/null
rm -f "$COOKIE"

summarize
