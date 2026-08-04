#!/usr/bin/env bash
# The same command must give the same answer in-process and over HTTP.
#
# This is the point of sub-project #6: the CLI and the v2 endpoints share one
# service layer, and these assertions are what prove it. They are also what
# makes 6b safe — converting a v1 endpoint to the services is only defensible
# if something checks the behaviour did not change.
#
# Parity is asserted on the WHOLE payload, never a row count. Two
# implementations agreeing on "3 rows" while disagreeing on which three is
# exactly the failure this exists to catch.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
seed_items
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

# The v2 endpoints authenticate by bearer token, so mint one for the fixture
# user directly — the CLI has no login command and should not grow one.
RAW_TOKEN=$(php -r 'echo bin2hex(random_bytes(32));')
HASHED=$(php -r "echo hash('sha256', '$RAW_TOKEN');")
fixture_mysql -e "
  DELETE FROM api_tokens WHERE user_id = $FIXTURE_UID;
  INSERT INTO api_tokens (user_id, token_hash, device_name, created_at)
  VALUES ($FIXTURE_UID, '$HASHED', 'parity-test', NOW());
" 2>/dev/null || {
  red "  SETUP: could not mint a token — is api_tokens shaped as expected?"
  fixture_mysql -e "DESCRIBE api_tokens" || true
  FAIL_COUNT=$((FAIL_COUNT+1)); summarize
}

export GT_TOKEN="$RAW_TOKEN"
export GT_BASE_URL="${BASE_URL:-http://localhost:8000}"

# Compare the two transports on one command.
assert_parity() {
  local label="$1"; shift
  local direct http

  # set +e: lib.sh runs under set -e, and a non-zero command substitution in
  # an assignment aborts the whole script. A failing transport is a result to
  # report, not a reason to stop the suite.
  set +e
  direct=$("$GT" "$@" --json --user="$FIXTURE_USER" 2>/dev/null)
  http=$("$GT" --http "$@" --json --user="$FIXTURE_USER" 2>/dev/null)
  set -e

  if [[ -z "$http" ]]; then
    red "  FAIL: $label — the HTTP transport returned nothing"
    FAIL_COUNT=$((FAIL_COUNT+1))
    return
  fi

  # Compare canonicalised JSON so key order cannot create a false mismatch.
  local a b
  a=$(echo "$direct" | jq -S . 2>/dev/null)
  b=$(echo "$http" | jq -S . 2>/dev/null)

  if [[ "$a" == "$b" ]]; then
    green "  PASS: $label agrees over both transports"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $label differs"
    diff <(echo "$a") <(echo "$b") | head -12
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
}

blue "Read parity: games"

assert_parity "unfiltered list"      games list
assert_parity "exact filter"         games list --platform=PS2
assert_parity "boolean filter"       games list --unplayed
assert_parity "--missing"            games list --missing=description
assert_parity "sorting"              games list --sort=title
assert_parity "paging"               games list --page=2 --limit=2
assert_parity "single get"           games get "$(fixture_id games 'FIXTURE Halo 3' mine)"

blue "Read parity: items"

assert_parity "items list"           items list
assert_parity "items category"       items list --category=Controller
assert_parity "items get"            items get "$(fixture_id items 'FIXTURE Xbox Pad' mine)"

blue "Parity on the empty and error cases too"

# A comparison that only ever runs on populated results proves less than it
# looks: both sides returning nothing must also agree.
assert_parity "filter matching nothing" games list --platform=NoSuchPlatform

blue "The HTTP transport is genuinely doing HTTP"

# Guard against the whole suite passing because --http silently fell back to
# in-process, which would make every assertion above vacuous.
GT_CODE=0
set +e
BAD=$(GT_TOKEN=deadbeef "$GT" --http games list --json --user="$FIXTURE_USER" 2>&1)
GT_CODE=$?
set -e
assert_eq "1" "$GT_CODE" "a rejected token fails, so --http really goes over the wire"

set +e
NOTOKEN=$(GT_TOKEN= "$GT" --http games list --json --user="$FIXTURE_USER" 2>&1)
NOTOKEN_CODE=$?
set -e
assert_eq "1" "$NOTOKEN_CODE" "a missing token is a domain error"
assert_contains "GT_TOKEN" "$NOTOKEN" "the error names the variable to set"

blue "--http refuses writes rather than silently going in-process"

set +e
WRITE=$("$GT" --http games set 1 --set-genre=X --user="$FIXTURE_USER" 2>&1)
WRITE_CODE=$?
set -e
assert_eq "2" "$WRITE_CODE" "a write with --http is a usage error"
assert_contains "read" "$WRITE" "explains --http is read-only for now"

summarize
