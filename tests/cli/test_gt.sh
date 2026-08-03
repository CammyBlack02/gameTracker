#!/usr/bin/env bash
# Integration tests for bin/gt (Phase A).
#
# Reuses tests/v2/lib.sh for the assertion helpers and counters. Unlike the v2
# suites these tests do not need the PHP dev server — gt talks to the database
# in-process — but they do need the GT_DB_* environment that run-all.sh exports,
# which points at gameTracker_test.
#
# Design: docs/superpowers/specs/2026-08-03-gt-cli-design.md
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

# lib.sh runs under `set -e`, so a non-zero exit from gt would abort the script
# before we could assert on it. Capture both streams and the code deliberately.
GT_OUT=""
GT_CODE=0
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

# Stdout only. Used for every jq assertion: combining streams would let a
# STDERR warning corrupt otherwise-valid JSON and produce a confusing failure.
GT_JSON=""
run_gt_json() {
  set +e
  GT_JSON=$("$GT" "$@" 2>/dev/null)
  GT_CODE=$?
  set -e
}

# --- Invocations that must work with no database at all
blue "No-database invocations"

run_gt --version
assert_eq "0" "$GT_CODE" "--version exits 0"
assert_contains "^gt [0-9]" "$GT_OUT" "--version prints a version"

run_gt help
assert_eq "0" "$GT_CODE" "help exits 0"
assert_contains "whoami" "$GT_OUT" "help lists whoami"
assert_contains "db:info" "$GT_OUT" "help lists db:info"

run_gt
assert_eq "2" "$GT_CODE" "bare gt is a usage error"

# --- Usage errors
blue "Usage errors"

run_gt nosuchcommand
assert_eq "2" "$GT_CODE" "unknown command = 2"
assert_contains "unknown command" "$GT_OUT" "names the problem"

run_gt --bogus-flag whoami
assert_eq "2" "$GT_CODE" "unknown option = 2"

# --http is declared but unimplemented until Phase D. It must fail loudly
# rather than silently running in-process, which would report results the
# HTTP layer never produced.
run_gt --http whoami
assert_eq "2" "$GT_CODE" "--http refuses until Phase D"
assert_contains "not implemented" "$GT_OUT" "explains why --http failed"

# --- whoami
blue "whoami"

# The test database has two users (admin from 000_baseline, plus testuser),
# so an unqualified whoami must refuse to guess rather than pick one.
run_gt whoami
assert_eq "1" "$GT_CODE" "ambiguous user = 1"
assert_contains "multiple users" "$GT_OUT" "refuses to guess between users"

run_gt_json whoami --user="$TEST_USER"
assert_eq "0" "$GT_CODE" "explicit --user resolves"
echo "$GT_JSON" | jq -e '.username == "'"$TEST_USER"'"' > /dev/null \
  && { green "  PASS: reports the requested username"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: username mismatch: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

echo "$GT_JSON" | jq -e '.games | type == "number"' > /dev/null \
  && { green "  PASS: games count is numeric"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: games not numeric: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt whoami --user=ghost_user_that_does_not_exist
assert_eq "1" "$GT_CODE" "unknown user = 1"
assert_contains "no such user" "$GT_OUT" "names the missing user"

# --- db:info
blue "db:info"

run_gt_json db:info
assert_eq "0" "$GT_CODE" "db:info exits 0"

echo "$GT_JSON" | jq -e '.database == "'"${GT_DB_NAME:-gameTracker_test}"'"' > /dev/null \
  && { green "  PASS: reports the database it is pointed at"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: wrong database: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# setup-test-db.sh bootstraps the schema via migrate.php, so unlike production
# the test database genuinely has a populated ledger.
echo "$GT_JSON" | jq -e '.ledger_present == true and .migrations_applied >= 1' > /dev/null \
  && { green "  PASS: migration ledger present and populated"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: ledger state unexpected: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# --- STDOUT must stay machine-parseable
blue "Output discipline"

# Warnings and errors go to STDERR precisely so JSON on STDOUT survives a pipe
# into jq. Regression guard: a stray echo in a command would break every caller.
set +e
"$GT" db:info 2>/dev/null | jq -e . > /dev/null
JQ_CODE=$?
set -e
assert_eq "0" "$JQ_CODE" "db:info STDOUT is valid JSON with STDERR discarded"

run_gt db:info --table
assert_eq "0" "$GT_CODE" "--table renders without error"
assert_contains "database" "$GT_OUT" "table output includes the database row"

summarize
