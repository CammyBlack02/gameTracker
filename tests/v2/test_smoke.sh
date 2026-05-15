#!/usr/bin/env bash
# Verifies the test harness itself works by hitting the existing /api/auth.php?action=check
source "$(dirname "$0")/lib.sh"

blue "Smoke: hitting existing /api/auth.php?action=check"
req GET "/api/auth.php?action=check"
assert_eq "200" "$HTTP_STATUS" "endpoint returns 200"
assert_contains '"success":true' "$RESPONSE_BODY" "response is success:true"
assert_contains '"authenticated":false' "$RESPONSE_BODY" "anonymous user is not authenticated"

summarize
