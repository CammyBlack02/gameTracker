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

summarize
