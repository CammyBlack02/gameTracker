#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "POST /api/v2/auth/token.php with wrong password returns 401"
req POST "/api/v2/auth/token.php" "username=testuser&password=WRONG"
assert_eq "401" "$HTTP_STATUS" "wrong password = 401"
assert_contains '"error":"invalid_credentials"' "$RESPONSE_BODY" "error code"

blue "POST /api/v2/auth/token.php with valid credentials returns a token"
req POST "/api/v2/auth/token.php" "username=testuser&password=test_password&device_name=test-iphone"
assert_eq "200" "$HTTP_STATUS" "valid login = 200"
TOKEN=$(echo "$RESPONSE_BODY" | jq -r '.data.token')
USER_ID=$(echo "$RESPONSE_BODY" | jq -r '.data.user_id')
[[ ${#TOKEN} -eq 64 ]] && green "  PASS: token is 64 chars (hex)" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: token length is ${#TOKEN}"; FAIL_COUNT=$((FAIL_COUNT+1)); }
[[ "$USER_ID" != "null" && -n "$USER_ID" ]] && green "  PASS: user_id present" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: user_id missing"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Save token for later tests
echo "$TOKEN" > tests/v2/.last_token

blue "Ping endpoint with valid token returns 200"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "valid token = 200"
assert_contains '"pong":true' "$RESPONSE_BODY" "response is pong"

blue "POST /api/v2/auth/revoke.php revokes the token"
req POST "/api/v2/auth/revoke.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "200" "$HTTP_STATUS" "revoke succeeds"

blue "Ping endpoint with revoked token returns 401"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer $TOKEN"
assert_eq "401" "$HTTP_STATUS" "revoked token = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "error code"

summarize
