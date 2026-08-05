#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

req POST "/api/v2/auth/token.php" "username=testuser&password=test_password"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')

blue "Proxies require auth"
req GET "/api/v2/external-image.php?url=https://example.com/x.jpg"
assert_eq "401" "$HTTP_STATUS" "external-image no-auth = 401"

req GET "/api/v2/pricecharting.php?title=halo&platform=xbox360"
assert_eq "401" "$HTTP_STATUS" "pricecharting no-auth = 401"

req GET "/api/v2/metacritic.php?title=halo&platform=xbox360"
assert_eq "401" "$HTTP_STATUS" "metacritic no-auth = 401"

blue "Proxies validate input with auth"
# Bad URL — no scheme — should be a 400 from the v1 logic.
req GET "/api/v2/external-image.php?url=not-a-url" "" -H "Authorization: Bearer $TOKEN"
[[ "$HTTP_STATUS" == "400" || "$HTTP_STATUS" == "500" ]] && green "  PASS: bad URL = 4xx/5xx ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Missing title param for pricecharting.
req GET "/api/v2/pricecharting.php" "" -H "Authorization: Bearer $TOKEN"
[[ "$HTTP_STATUS" =~ ^[45] ]] && green "  PASS: missing title returns error ($HTTP_STATUS)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: status=$HTTP_STATUS"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Note: we don't test the success path because it requires hitting real
# external services. That gets validated in manual smoke after deployment.

blue "Proxy: external-image rejects another user's game_id"
# Set up otheruser's game if not already
mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -e "
  INSERT IGNORE INTO users (username, password_hash, role) VALUES ('otheruser_proxy', 'x', 'user');
  INSERT IGNORE INTO games (user_id, title, platform) VALUES (
    (SELECT id FROM users WHERE username='otheruser_proxy'),
    'Other Proxy Game', 'X'
  );
"
OTHER_GAME=$(mysql -u"${TEST_DB_USER:-root}" "${TEST_DB_NAME:-gameTracker_test}" -sNe \
  "SELECT id FROM games WHERE title='Other Proxy Game'")

req GET "/api/v2/external-image.php?url=https://example.com/x.jpg&game_id=$OTHER_GAME" "" \
  -H "Authorization: Bearer $TOKEN"
assert_eq "404" "$HTTP_STATUS" "external-image rejects cross-user game_id"
assert_contains '"error":"not_found"' "$RESPONSE_BODY" "error code is not_found"

# --- Regression: v1 api/metacritic.php is retired (phase 5/06)
#
# Deliberately not `assert_eq 404`, matching the precedent set by
# test_cover_image.sh in phase 5/05. nginx answers a missing .php with a 404,
# but this harness — and CI — runs `php -S` with router.php, whose catch-all
# (router.php:23) hands any nonexistent path to index.php and returns
# 200 text/html. A bare status assertion would pass on prod and fail in CI.
# What matters is that no v1 metacritic contract is served, so assert on the
# tree and on the response shape instead.
blue "Regression: v1 /api/metacritic.php is retired"
V1_METACRITIC="$(cd "$(dirname "$0")/../.." && pwd)/api/metacritic.php"

if [[ -e "$V1_METACRITIC" ]]; then
    red "  FAIL: api/metacritic.php still present in the tree"
    FAIL_COUNT=$((FAIL_COUNT+1))
else
    green "  PASS: api/metacritic.php is gone from the tree"
    PASS_COUNT=$((PASS_COUNT+1))
fi

# A live v1 endpoint answers with the v1 envelope even unauthenticated
# ({"success":false,"message":"Authentication required"}), so treating any
# `"success"` key or a `rating` field as failure keeps this honest — it fails
# if the file is restored, rather than passing on a 401.
req GET "/api/metacritic.php?title=halo&platform=xbox360"
if [[ "$HTTP_STATUS" == "404" ]]; then
    green "  PASS: v1 URL 404s (no fallback handler, as on nginx)"
    PASS_COUNT=$((PASS_COUNT+1))
elif echo "$RESPONSE_BODY" | grep -qE '"success"|"rating"'; then
    red "  FAIL: v1 endpoint still answering (status=$HTTP_STATUS) body=$RESPONSE_BODY"
    FAIL_COUNT=$((FAIL_COUNT+1))
else
    green "  PASS: no v1 contract served (status=$HTTP_STATUS, router catch-all)"
    PASS_COUNT=$((PASS_COUNT+1))
fi

# The v2 replacement must still answer, so retiring v1 cannot silently take
# the working endpoint with it.
req GET "/api/v2/metacritic.php?title=halo&platform=xbox360" "" -H "Authorization: Bearer $TOKEN"
assert_contains '"unavailable"' "$RESPONSE_BODY" "v2 metacritic still answers with unavailable"

summarize
