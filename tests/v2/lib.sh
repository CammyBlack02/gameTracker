#!/usr/bin/env bash
# Shared helpers for v2 integration tests.
# Source this from individual test scripts.

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8000}"
TEST_USER="${TEST_USER:-testuser}"
TEST_PASS="${TEST_PASS:-test_password}"

# Coloured output
red()   { printf "\033[31m%s\033[0m\n" "$*"; }
green() { printf "\033[32m%s\033[0m\n" "$*"; }
blue()  { printf "\033[34m%s\033[0m\n" "$*"; }

PASS_COUNT=0
FAIL_COUNT=0

# assert_eq <expected> <actual> <message>
assert_eq() {
  if [[ "$1" == "$2" ]]; then
    green "  PASS: $3"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red   "  FAIL: $3"
    red   "    expected: $1"
    red   "    actual:   $2"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
}

# assert_contains <needle> <haystack> <message>
assert_contains() {
  if echo "$2" | grep -q "$1"; then
    green "  PASS: $3"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red   "  FAIL: $3 — did not contain '$1'"
    red   "    haystack: $2"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
}

# req <METHOD> <path> [data] [extra-curl-args...]
# Sets globals: HTTP_STATUS, RESPONSE_BODY
req() {
  local method="$1"
  local path="$2"
  local data="${3:-}"
  local extra=("${@:4}")
  local response
  if [[ -n "$data" ]]; then
    response=$(curl -sS -o /tmp/v2_body -w "%{http_code}" \
      -X "$method" "$BASE_URL$path" \
      -H "Content-Type: application/x-www-form-urlencoded" \
      -d "$data" "${extra[@]+"${extra[@]}"}")
  else
    response=$(curl -sS -o /tmp/v2_body -w "%{http_code}" \
      -X "$method" "$BASE_URL$path" "${extra[@]+"${extra[@]}"}")
  fi
  HTTP_STATUS="$response"
  RESPONSE_BODY="$(cat /tmp/v2_body)"
}

summarize() {
  echo
  if [[ $FAIL_COUNT -eq 0 ]]; then
    green "==> $PASS_COUNT passed, 0 failed"
    exit 0
  else
    red "==> $PASS_COUNT passed, $FAIL_COUNT failed"
    exit 1
  fi
}
