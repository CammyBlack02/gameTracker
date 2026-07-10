#!/usr/bin/env bash
# v1 endpoints previously returned raw exception messages, filenames, and
# line numbers to clients. Detail should now stay in error_log() only;
# the JSON body gets a generic message.
source "$(dirname "$0")/lib.sh"

COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null

blue "Error responses do not leak file/line/getMessage detail"

# Force an update failure with an obviously-invalid game id — user is authed
# but the ownership check will fail and, depending on trigger, may throw.
req POST "/api/games.php?action=update" \
  "id=999999999&title=X" \
  -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -qE '\.php|Line: [0-9]+|File: [A-Za-z]'; then
  red "  FAIL: games.php?action=update response leaked file/line: $RESPONSE_BODY"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: games.php update response contains no file/line detail"
  PASS_COUNT=$((PASS_COUNT+1))
fi

# Trigger a fatal in listGames by passing an obviously-nonsense per_page.
# (Even if the endpoint accepts it, we just want the shape of the body to
# not contain file/line info.)
req GET "/api/games.php?action=list&per_page=-1" "" -b "$COOKIE"
if echo "$RESPONSE_BODY" | grep -qE 'Line: [0-9]+|File: [A-Za-z]'; then
  red "  FAIL: games.php list response leaked file/line: $RESPONSE_BODY"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: games.php list response contains no file/line detail"
  PASS_COUNT=$((PASS_COUNT+1))
fi

rm -f "$COOKIE"

summarize
