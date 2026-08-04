#!/usr/bin/env bash
# `gt doctor` — the checks, and the exit code that makes them useful.
#
# Design: docs/superpowers/specs/2026-08-04-gt-doctor-design.md
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

php_eval() { php -r "require '$PROJECT_ROOT/src/autoload.php'; $1"; }

blue "Check result object"

assert_eq "pass" "$(php_eval 'echo GameTracker\Diagnostics\Check::pass("s","ok")->status;')" \
  "a passing check"
assert_eq "fail" "$(php_eval 'echo GameTracker\Diagnostics\Check::fail("s","bad","fix it")->status;')" \
  "a failing check"
assert_eq "info" "$(php_eval 'echo GameTracker\Diagnostics\Check::info("s","fyi")->status;')" \
  "an informational check"

# Only FAIL counts. Orphan files are untidy, not broken — if they turned the
# command red it would be permanently red, and a permanently red doctor is one
# nobody reads.
assert_eq "0" "$(php_eval '
  echo GameTracker\Diagnostics\Check::worstExitCode([
      GameTracker\Diagnostics\Check::pass("a","ok"),
      GameTracker\Diagnostics\Check::info("b","99 orphan files"),
  ]);')" "pass + info exits 0"

assert_eq "1" "$(php_eval '
  echo GameTracker\Diagnostics\Check::worstExitCode([
      GameTracker\Diagnostics\Check::pass("a","ok"),
      GameTracker\Diagnostics\Check::fail("b","broken","fix"),
  ]);')" "any failure exits 1"

blue "ConfigCheck asserts known-good properties, not a diff"

# A diff against the template would false-positive on credentials forever.
# These are the three properties whose absence actually caused harm.
CFG=$(php_eval '
  $r = GameTracker\Diagnostics\ConfigCheck::run("'"$PROJECT_ROOT"'/includes/config.php.example");
  foreach ($r as $c) { echo $c->name, "=", $c->status, " "; }')

assert_contains "per-request-ddl=pass" "$CFG" "template has no initializeDatabase"
assert_contains "cli-session-guard=pass" "$CFG" "template guards session_start with GT_CLI"
assert_contains "cli-connection-error=pass" "$CFG" "template throws rather than die() for CLI"

# And it must actually detect the bad shapes, or it is decorative.
BAD=$(mktemp); cat > "$BAD" <<'PHP'
<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
function initializeDatabase($pdo) { $pdo->exec("CREATE TABLE IF NOT EXISTS x (id INT)"); }
die('Database connection failed');
PHP
BADOUT=$(php_eval '
  $r = GameTracker\Diagnostics\ConfigCheck::run("'"$BAD"'");
  foreach ($r as $c) { echo $c->name, "=", $c->status, " "; }')
rm -f "$BAD"

assert_contains "per-request-ddl=fail" "$BADOUT" "detects a config that still defines initializeDatabase"
assert_contains "cli-session-guard=fail" "$BADOUT" "detects a missing GT_CLI session guard"
assert_contains "cli-connection-error=fail" "$BADOUT" "detects die() instead of throw for CLI"

blue "BackupCheck trusts artifacts, never a log line"

BDIR=$(mktemp -d)
trap 'rm -rf "$BDIR"' EXIT

# A dump that reports success but is truncated: has CREATE TABLE, no completion
# marker. This is the exact shape that went unnoticed for the app's whole life.
printf 'CREATE TABLE `games` (id INT);\nINSERT INTO `games` VALUES (1);\n' | gzip > "$BDIR/database_truncated.sql.gz"
TRUNC=$(php_eval '
  $r = GameTracker\Diagnostics\BackupCheck::run("'"$BDIR"'");
  foreach ($r as $c) { echo $c->name, "=", $c->status, " "; }')
assert_contains "backup-complete=fail" "$TRUNC" "a dump without the completion marker fails"

printf 'CREATE TABLE `games` (id INT);\nINSERT INTO `games` VALUES (1);\n-- Dump completed on 2026-08-04\n' | gzip > "$BDIR/database_good.sql.gz"
GOOD=$(php_eval '
  $r = GameTracker\Diagnostics\BackupCheck::run("'"$BDIR"'");
  foreach ($r as $c) { echo $c->name, "=", $c->status, " "; }')
assert_contains "backup-complete=pass" "$GOOD" "a complete dump passes"

EMPTY=$(mktemp -d)
NONE=$(php_eval '
  $r = GameTracker\Diagnostics\BackupCheck::run("'"$EMPTY"'");
  foreach ($r as $c) { echo $c->name, "=", $c->status, " "; }')
rmdir "$EMPTY"
assert_contains "backup-present=fail" "$NONE" "no dump at all is a failure"

blue "gt doctor runs and reports"

GT_CODE=0; GT_JSON=""
run_gt_json() { set +e; GT_JSON=$("$GT" "$@" 2>/dev/null); GT_CODE=$?; set -e; }

run_gt_json doctor
echo "$GT_JSON" | jq -e 'has("checks") and has("failed") and (.checks | length) > 3' > /dev/null \
  && { green "  PASS: reports a list of checks"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected doctor output: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The exit code is the whole point — it is what makes this usable from cron.
FAILED=$(echo "$GT_JSON" | jq -r '.failed')
if [[ "$FAILED" == "0" && "$GT_CODE" == "0" ]] || [[ "$FAILED" != "0" && "$GT_CODE" == "1" ]]; then
  green "  PASS: exit code matches the failure count ($FAILED failed, exit $GT_CODE)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: $FAILED failures but exit $GT_CODE"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

# A check that throws must be reported, not abort the run. A doctor that dies
# on its first problem is useless exactly when it is needed.
echo "$GT_JSON" | jq -e '[.checks[].status] | all(. == "pass" or . == "fail" or . == "info")' > /dev/null \
  && { green "  PASS: every check produced a status"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: a check produced no status"; FAIL_COUNT=$((FAIL_COUNT+1)); }

summarize
