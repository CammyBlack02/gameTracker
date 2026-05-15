#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

blue "Auth helper: ping endpoint without token returns 401"
req GET "/api/v2/_ping.php"
assert_eq "401" "$HTTP_STATUS" "no token = 401"
assert_contains '"error":"missing_token"' "$RESPONSE_BODY" "error code is missing_token"

blue "Auth helper: ping endpoint with garbage token returns 401"
req GET "/api/v2/_ping.php" "" -H "Authorization: Bearer not-a-real-token"
assert_eq "401" "$HTTP_STATUS" "bad token = 401"
assert_contains '"error":"invalid_token"' "$RESPONSE_BODY" "error code is invalid_token"

# We can't test the success path until token issuance exists (Task 4).
# That test is added in Task 4.

summarize
