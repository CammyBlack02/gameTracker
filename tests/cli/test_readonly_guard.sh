#!/usr/bin/env bash
# The read + filter core is read-only by construction.
#
# Sub-project #1 promises that no CLI command can alter collection data. That
# promise is only worth something if it is mechanical, so this greps the service
# and query layers for write statements instead of trusting review.
#
# When a mutation sub-project lands, it must consciously amend this test rather
# than discover it by accident — that is the point.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Read-only guard"

# Two false-positive classes to avoid: "updated_at"/"created_at" as column
# names, and prose describing the schema — a doc comment saying
# "on update CURRENT_TIMESTAMP" is not write SQL.
#
# So UPDATE must be followed by a table name and then either SET or end of line.
# The end-of-line branch matters: real write SQL is sometimes built across
# concatenated lines, and a line-based grep would otherwise miss it.
WRITE_PATTERN='(INSERT[[:space:]]+INTO|UPDATE[[:space:]]+`?[a-z_]+`?([[:space:]]+SET|[[:space:]]*$)|DELETE[[:space:]]+FROM|REPLACE[[:space:]]+INTO|ALTER[[:space:]]+TABLE|DROP[[:space:]]+(TABLE|DATABASE|TRIGGER)|TRUNCATE[[:space:]])'

# Write SQL is permitted in exactly one place. Sub-project #2 added
# src/Services/Write/, and narrowing the guard rather than deleting it keeps the
# read layer provably read-only.
HITS=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src" \
         --include='*.php' \
         | grep -v '/src/Services/Write/' || true)

if [[ -z "$HITS" ]]; then
  green "  PASS: no write SQL outside src/Services/Write/"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: write SQL outside src/Services/Write/:"
  red "$HITS"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# And the permitted directory must actually contain some, or the guard is
# asserting a property of an empty set.
WRITES=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src/Services/Write" --include='*.php' || true)
if [[ -n "$WRITES" ]]; then
  green "  PASS: src/Services/Write/ contains the write SQL"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: src/Services/Write/ has no write SQL — is the guard still meaningful?"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# Guard the guard: if the pattern stopped matching real write SQL, the checks
# above would pass vacuously and the guarantee would be worthless.
PROBE=$(printf 'INSERT INTO games (title) VALUES ("x");\nUPDATE `games` SET title = "y";\nDELETE FROM games;\n')
PROBE_HITS=$(echo "$PROBE" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "3" "$PROBE_HITS" "the write pattern still matches real write SQL"

# And confirm it does not fire on legitimate column names or on prose about
# the schema. That last line is a real regression: it appears verbatim in a
# GamesWrites doc comment and used to trip the guard.
BENIGN=$(printf 'ORDER BY `updated_at` DESC\nSELECT `created_at` FROM games\n * database (updated_at is `on update CURRENT_TIMESTAMP`, which is what makes\n')
BENIGN_HITS=$(echo "$BENIGN" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "0" "$BENIGN_HITS" "the write pattern ignores column names and schema prose"

# A multi-line UPDATE must still be caught by the end-of-line branch.
MULTILINE=$(printf 'UPDATE games\n  SET title = ?\n')
ML_HITS=$(echo "$MULTILINE" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "1" "$ML_HITS" "the write pattern catches a line-split UPDATE"

# Negative control: the guard must fail when write SQL appears outside the
# permitted directory. Without this the two checks above could both pass
# because the grep silently stopped matching, not because the tree is clean.
PROBE_FILE="$PROJECT_ROOT/src/Query/__guard_probe.php"
cat > "$PROBE_FILE" <<'PHP'
<?php
// Temporary probe written by test_readonly_guard.sh.
$sql = 'DELETE FROM games WHERE id = ?';
PHP

PROBE_LEAK=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/src" \
               --include='*.php' \
               | grep -v '/src/Services/Write/' || true)
rm -f "$PROBE_FILE"

if [[ -n "$PROBE_LEAK" ]]; then
  green "  PASS: the guard catches write SQL planted outside src/Services/Write/"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: the guard did not catch a planted DELETE — it is vacuous"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

# Every writer added by sub-project #2 must be inside the permitted directory.
for writer in GamesWriter ItemsWriter Tombstones; do
  if [[ -f "$PROJECT_ROOT/src/Services/Write/$writer.php" ]]; then
    green "  PASS: $writer lives under src/Services/Write/"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $writer.php is not under src/Services/Write/"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
done

# AssignmentSet builds column lists but must never contain a write statement,
# or the guard would have to permit src/Write/ too and lose its meaning.
if grep -qiE 'INSERT[[:space:]]+INTO|DELETE[[:space:]]+FROM' "$PROJECT_ROOT/src/Write/AssignmentSet.php"; then
  red "  FAIL: AssignmentSet contains write SQL — assemble statements in the writer"
  FAIL_COUNT=$((FAIL_COUNT+1))
else
  green "  PASS: AssignmentSet builds fragments, not statements"
  PASS_COUNT=$((PASS_COUNT+1))
fi

summarize
