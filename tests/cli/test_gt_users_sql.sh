#!/usr/bin/env bash
# `gt users list` and `gt sql` — sub-project #5's tail.
#
# The sql half is mostly a SECURITY suite, and it is written to prove the
# refusals are real rather than cosmetic. Every write attempt is checked twice:
# that the command refused, AND that the thing it tried to do did not happen. An
# error message with the write applied behind it is the failure mode that matters.
#
# gt sql enforces read-only in three independent layers, each covering a gap in
# the others. All three are exercised here:
#
#   1. native prepare      -> blocks a second statement; PDO's default
#                             ATTR_EMULATE_PREPARES=true lets query() run
#                             "SELECT 1; DROP TABLE x", so this is not theoretical
#   2. keyword allowlist   -> blocks DDL, which layer 3 CANNOT: DDL forces an
#                             implicit commit that ends the read-only transaction
#   3. READ ONLY txn       -> blocks DML the allowlist let through, e.g. a CTE
#                             ("WITH x AS (...) DELETE ...") whose leading keyword
#                             is WITH
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"

seed_games
USER_FLAG="--user=$FIXTURE_USER"

GT_CODE=0
GT_OUT=""
run_gt() {
  set +e
  GT_OUT=$("$GT" "$@" 2>&1)
  GT_CODE=$?
  set -e
}

sql_probe_exists() {
  fixture_mysql -N -e "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'gt_sql_probe'"
}

blue "gt users list"

run_gt users list --json
assert_eq "0" "$GT_CODE" "users list exits 0"

echo "$GT_OUT" | jq -e 'type == "array" and length > 0' > /dev/null \
  && { green "  PASS: returns a non-empty array"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected shape: $(echo "$GT_OUT" | head -c 160)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# The fixture user owns a known number of seeded games, so the count column is
# checked against something real rather than merely being present.
FIX_ID=$(fixture_user_id "$FIXTURE_USER")
EXPECTED_GAMES=$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE user_id = $FIX_ID")
ACTUAL_GAMES=$(echo "$GT_OUT" | jq -r --argjson id "$FIX_ID" '.[] | select(.id == $id) | .games')
assert_eq "$EXPECTED_GAMES" "$ACTUAL_GAMES" "the games count matches the database"

echo "$GT_OUT" | jq -e '.[0] | has("username") and has("role") and has("items") and has("completions")' > /dev/null \
  && { green "  PASS: carries username, role and the ownership counts"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: missing expected columns"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# A password hash in scrollback or a piped JSON log is an offline cracking target
# for no operational benefit, so it must never be selected at all.
assert_eq "0" "$(echo "$GT_OUT" | grep -ci 'password\|hash' || true)" \
  "no password hash is ever printed"

run_gt users list unexpected-arg
assert_eq "2" "$GT_CODE" "an unexpected argument is a usage error"

blue "gt sql: legitimate reads"

run_gt sql "SELECT COUNT(*) AS c FROM games" --json
assert_eq "0" "$GT_CODE" "a SELECT exits 0"
echo "$GT_OUT" | jq -e '.[0].c != null' > /dev/null \
  && { green "  PASS: SELECT returns its rows"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: no rows back: $(echo "$GT_OUT" | head -c 160)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt sql "SHOW TABLES" --json
assert_eq "0" "$GT_CODE" "SHOW is allowed"

run_gt sql "EXPLAIN SELECT * FROM games WHERE id = 1" --json
assert_eq "0" "$GT_CODE" "EXPLAIN is allowed"

run_gt sql "SELECT 1;" --json
assert_eq "0" "$GT_CODE" "a single trailing semicolon is tolerated"

blue "gt sql: the output cap is stated, not silent"

# Captured separately, because run_gt folds stderr into stdout and the whole
# point of the split is that it does not have to. The warning belongs on stderr
# so a piped --json run stays machine-readable; if that ever regresses, the JSON
# parse below is what catches it.
CAP_OUT=$("$GT" sql "SELECT id FROM games" --limit=2 --json 2>/dev/null)
CAP_ERR=$("$GT" sql "SELECT id FROM games" --limit=2 --json 2>&1 >/dev/null)

run_gt sql "SELECT id FROM games" --limit=2 --json
assert_eq "0" "$GT_CODE" "a capped query still exits 0"

assert_eq "2" "$(echo "$CAP_OUT" | jq -r 'length')" "--limit caps the row count"
echo "$CAP_OUT" | jq -e . > /dev/null \
  && { green "  PASS: stdout is still valid JSON when a warning fires"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: the warning contaminated stdout"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Silently truncating is how someone concludes a table has 2 rows in it.
echo "$CAP_ERR" | grep -qi "capped" \
  && { green "  PASS: truncation is reported, on stderr"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: truncation was silent: $(echo "$CAP_ERR" | head -c 100)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

run_gt sql "SELECT 1" --limit=0
assert_eq "2" "$GT_CODE" "--limit=0 is a usage error"

run_gt sql
assert_eq "2" "$GT_CODE" "no query is a usage error"

blue "gt sql: writes are refused AND do not happen"

fixture_mysql -N -e "CREATE TABLE IF NOT EXISTS gt_sql_probe (id INT)" > /dev/null
fixture_mysql -N -e "DELETE FROM games WHERE title = 'GT_SQL_PWNED'" > /dev/null

# Layer 2 — the allowlist. These never reach the database.
for stmt in "DROP TABLE gt_sql_probe" \
            "TRUNCATE TABLE gt_sql_probe" \
            "ALTER TABLE gt_sql_probe ADD COLUMN x INT" \
            "CREATE TABLE gt_sql_probe2 (id INT)" \
            "UPDATE games SET title = 'GT_SQL_PWNED'" \
            "DELETE FROM games" \
            "INSERT INTO games (user_id, title, platform) VALUES (1, 'GT_SQL_PWNED', 'x')" \
            "REPLACE INTO games (user_id, title, platform) VALUES (1, 'GT_SQL_PWNED', 'x')" \
            "GRANT ALL ON *.* TO 'x'@'localhost'"; do
  run_gt sql "$stmt"
  assert_eq "2" "$GT_CODE" "refused: ${stmt:0:38}"
done

# Layer 2, defeated by a comment prefix if the keyword scan were naive.
run_gt sql "/* just looking */ DROP TABLE gt_sql_probe"
assert_eq "2" "$GT_CODE" "a comment-prefixed DROP is still refused"

# Layer 1 — native prepare. The allowlist passes this (it starts with SELECT), so
# only the prepare stops it. Exits 1 because the server rejects it, not 2.
run_gt sql "SELECT 1; DROP TABLE gt_sql_probe"
assert_eq "1" "$GT_CODE" "a second statement is rejected by the prepare"

# Layer 3 — the read-only transaction. WITH is on the allowlist because CTEs are
# legitimate reads, so this one gets past layer 2 and the server refuses it.
run_gt sql "WITH x AS (SELECT 1) DELETE FROM games"
assert_eq "1" "$GT_CODE" "a CTE write is refused by the read-only transaction"
echo "$GT_OUT" | grep -qi "read only\|25006" \
  && { green "  PASS: refused by the server, not by parsing"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected a read-only transaction error, got: $(echo "$GT_OUT" | head -c 120)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# THE NON-VACUITY CHECK. Everything above asserted an exit code; this asserts
# nothing actually happened. A refusal message with the write applied behind it
# is the only failure that would matter.
assert_eq "1" "$(sql_probe_exists)" "the probe table survived every attempt"
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM games WHERE title = 'GT_SQL_PWNED'")" \
  "no row was inserted or renamed by any attempt"
assert_eq "0" "$(fixture_mysql -N -e "SELECT COUNT(*) FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = 'gt_sql_probe2'")" \
  "no table was created by any attempt"

blue "gt sql: a failing read is a domain error, not a crash"

run_gt sql "SELECT * FROM gt_definitely_not_a_table"
assert_eq "1" "$GT_CODE" "an invalid query exits 1"
echo "$GT_OUT" | grep -qi "doesn't exist\|does not exist\|1146" \
  && { green "  PASS: the server's own error is shown, not swallowed"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unhelpful error: $(echo "$GT_OUT" | head -c 120)"; FAIL_COUNT=$((FAIL_COUNT+1)); }

fixture_mysql -N -e "DROP TABLE IF EXISTS gt_sql_probe" > /dev/null
fixture_mysql -N -e "DROP TABLE IF EXISTS gt_sql_probe2" > /dev/null

summarize
