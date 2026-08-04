#!/usr/bin/env bash
# Integration tests for bin/gt.
#
# Reuses tests/v2/lib.sh for assertion helpers and counters. These tests do not
# need the PHP dev server — gt talks to the database in-process — but they do
# need the GT_DB_* environment run-all.sh exports, pointing at gameTracker_test.
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
assert_contains "db info" "$GT_OUT" "help lists db info"
# Resource commands are asserted in their own suites, so this file stays
# passable on its own rather than depending on a later task's registry entry.

run_gt
assert_eq "2" "$GT_CODE" "bare gt is a usage error"

# --- Usage errors
blue "Usage errors"

run_gt nosuchcommand
assert_eq "2" "$GT_CODE" "unknown command = 2"
assert_contains "unknown command" "$GT_OUT" "names the problem"

# A known resource with an unknown verb should list the verbs that do exist,
# rather than the generic unknown-command message.
run_gt games nosuchverb
assert_eq "2" "$GT_CODE" "unknown subcommand = 2"
assert_contains "games" "$GT_OUT" "mentions the resource"

run_gt --bogus-flag whoami
assert_eq "2" "$GT_CODE" "unknown option = 2"

# --http serves the read commands that have a v2 endpoint (#6a). Anything
# else is refused rather than quietly run in process — a flag that sometimes
# means what it says is worse than one that refuses.
run_gt --http whoami
assert_eq "2" "$GT_CODE" "--http refuses a command with no v2 endpoint"
assert_contains "read-only" "$GT_OUT" "explains --http is read-only for now"

# --- whoami
blue "whoami"

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

# --- db info
blue "db info"

run_gt_json db info
assert_eq "0" "$GT_CODE" "db info exits 0"

echo "$GT_JSON" | jq -e '.database == "'"${GT_DB_NAME:-gameTracker_test}"'"' > /dev/null \
  && { green "  PASS: reports the database it is pointed at"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: wrong database: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# setup-test-db.sh bootstraps the schema via migrate.php, so unlike production
# the test database genuinely has a populated ledger.
echo "$GT_JSON" | jq -e '.ledger_present == true and .migrations_applied >= 1' > /dev/null \
  && { green "  PASS: migration ledger present and populated"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: ledger state unexpected: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The old colon form must be gone, not silently aliased.
run_gt db:info
assert_eq "2" "$GT_CODE" "db:info is no longer a command"

# --- Output discipline
blue "Output discipline"

# Captured output is not a TTY, so JSON is the auto-detected default. This both
# guards the pipe-safety property and proves TTY detection picks JSON here.
set +e
"$GT" db info 2>/dev/null | jq -e . > /dev/null
JQ_CODE=$?
set -e
assert_eq "0" "$JQ_CODE" "non-TTY output auto-detects JSON and parses"

run_gt db info --table
assert_eq "0" "$GT_CODE" "--table renders without error"
assert_contains "database" "$GT_OUT" "table output includes the database row"

summarize
