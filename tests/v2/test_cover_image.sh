#!/usr/bin/env bash
# Integration tests for api/v2/cover-image.php.
# Covers auth wiring, param validation, method guard, and the dual-auth
# session path. Not covered: a real TheGamesDB hit (needs network + a key)
# and upstream_auth_failed (no easy curl_exec mock) — see
# docs/superpowers/specs/2026-07-20-cover-image-v2-migration-design.md
source "$(dirname "$0")/lib.sh"

# --- Setup: Bearer token
blue "Setup: mint a Bearer token"
req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS&device_name=cover-image-test"
assert_eq "200" "$HTTP_STATUS" "token mint = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
[[ ${#TOKEN} -eq 64 ]] || { red "  FAIL: bad token length"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- Case 1: no credentials → 401 missing_token
blue "Case 1: no auth → 401 missing_token"
req GET "/api/v2/cover-image.php?title=Halo"
assert_eq "401" "$HTTP_STATUS" "no auth = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "missing_token code"

# --- Case 2: authed + missing title → 400 bad_request
blue "Case 2: authed, no title → 400 bad_request"
req GET "/api/v2/cover-image.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "400" "$HTTP_STATUS" "missing title = 400"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 3: authed + empty title → 400 bad_request
blue "Case 3: authed, empty title → 400 bad_request"
req GET "/api/v2/cover-image.php?title=" "" -H "Authorization: Bearer $TOKEN"
assert_eq "400" "$HTTP_STATUS" "empty title = 400"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 4: whitespace-only title is also empty after trim → 400
blue "Case 4: authed, whitespace-only title → 400 bad_request"
req GET "/api/v2/cover-image.php?title=%20%20" "" -H "Authorization: Bearer $TOKEN"
assert_eq "400" "$HTTP_STATUS" "whitespace title = 400"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 5: wrong HTTP method → 405 method_not_allowed
# The method guard must fire BEFORE auth, so this holds even with a token.
blue "Case 5: POST not allowed → 405"
req POST "/api/v2/cover-image.php?title=Halo" "" -H "Authorization: Bearer $TOKEN"
assert_eq "405" "$HTTP_STATUS" "POST = 405"
assert_contains '"error":"method_not_allowed"' "$RESPONSE_BODY" "method_not_allowed code"

# --- Case 6: method guard precedes auth (no credentials at all)
blue "Case 6: POST with no auth → 405, not 401"
req POST "/api/v2/cover-image.php?title=Halo"
assert_eq "405" "$HTTP_STATUS" "method guard runs before auth"

# --- Case 7: dual-auth session path reaches the endpoint.
# A GET changes no state, so no CSRF token is needed. Proves the session
# credential works here and that we get past auth to param validation.
blue "Setup: log in via v1 for a session cookie"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

blue "Case 7: session cookie + GET, no title → 400 (auth passed)"
req GET "/api/v2/cover-image.php" "" -b "$COOKIE"
assert_eq "400" "$HTTP_STATUS" "session auth accepted, then 400 on missing title"
assert_contains '"error":"bad_request"' "$RESPONSE_BODY" "bad_request code"

# --- Case 8: API-key-dependent path. Which error we get depends on whether
# a key is configured, so accept either and fail only on something else.
#   no key  -> 500 api_key_missing
#   key set -> 404 not_found (nonsense title) or 502 upstream_auth_failed
blue "Case 8: nonsense title → api_key_missing / not_found / upstream_auth_failed"
req GET "/api/v2/cover-image.php?title=___definitely_not_a_real_game___" "" -H "Authorization: Bearer $TOKEN"
if [[ "$HTTP_STATUS" == "500" ]] && echo "$RESPONSE_BODY" | grep -q '"error":"api_key_missing"'; then
    green "  PASS: api_key_missing path exercised (no key configured)"
    PASS_COUNT=$((PASS_COUNT+1))
elif [[ "$HTTP_STATUS" == "404" ]] && echo "$RESPONSE_BODY" | grep -qE '"error":"(not_found|no_boxart)"'; then
    green "  PASS: key configured — not_found/no_boxart path exercised"
    PASS_COUNT=$((PASS_COUNT+1))
elif [[ "$HTTP_STATUS" == "502" ]] && echo "$RESPONSE_BODY" | grep -q '"error":"upstream_auth_failed"'; then
    green "  PASS: upstream_auth_failed path exercised (key present but rejected)"
    PASS_COUNT=$((PASS_COUNT+1))
else
    red "  FAIL: unexpected status=$HTTP_STATUS body=$RESPONSE_BODY"
    FAIL_COUNT=$((FAIL_COUNT+1))
fi

# --- Regression: v1 api/cover-image.php is retired (phase 5/05)
#
# Deliberately not `assert_eq 404`. nginx answers a missing .php with a 404,
# but this harness — and CI — runs `php -S` with router.php, whose catch-all
# (router.php:23) hands any nonexistent path to index.php and returns
# 200 text/html. A bare status assertion would pass on prod and fail in CI.
# What actually matters is that no v1 cover-image contract is served, so
# assert on the tree and on the response shape instead.
blue "Regression: v1 /api/cover-image.php is retired"
V1_ENDPOINT="$(cd "$(dirname "$0")/../.." && pwd)/api/cover-image.php"

if [[ -e "$V1_ENDPOINT" ]]; then
    red "  FAIL: api/cover-image.php still present in the tree"
    FAIL_COUNT=$((FAIL_COUNT+1))
else
    green "  PASS: api/cover-image.php is gone from the tree"
    PASS_COUNT=$((PASS_COUNT+1))
fi

# A live v1 endpoint answers with the v1 envelope even unauthenticated
# ({"success":false,"message":"Authentication required"}), so treating any
# `"success"` key or an image_url as failure keeps this assertion honest —
# it fails if the file is restored, rather than passing on a 401.
req GET "/api/cover-image.php?title=Halo"
if [[ "$HTTP_STATUS" == "404" ]]; then
    green "  PASS: v1 URL 404s (no fallback handler, as on nginx)"
    PASS_COUNT=$((PASS_COUNT+1))
elif echo "$RESPONSE_BODY" | grep -qE '"success"|image_url'; then
    red "  FAIL: v1 endpoint still answering (status=$HTTP_STATUS) body=$RESPONSE_BODY"
    FAIL_COUNT=$((FAIL_COUNT+1))
else
    green "  PASS: no v1 contract served (status=$HTTP_STATUS, router catch-all)"
    PASS_COUNT=$((PASS_COUNT+1))
fi

# --- Cleanup
blue "Cleanup: revoke Bearer token"
curl -sS -X POST "$BASE_URL/api/v2/auth/revoke.php" \
  -H "Authorization: Bearer $TOKEN" > /dev/null
rm -f "$COOKIE"

summarize
