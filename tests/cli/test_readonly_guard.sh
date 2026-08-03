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

# Word boundaries matter: "updated_at" and "created_at" are legitimate column
# names and must not trip the UPDATE/DELETE patterns.
WRITE_PATTERN='(INSERT[[:space:]]+INTO|UPDATE[[:space:]]+[a-z`]|DELETE[[:space:]]+FROM|REPLACE[[:space:]]+INTO|ALTER[[:space:]]+TABLE|DROP[[:space:]]+(TABLE|DATABASE|TRIGGER)|TRUNCATE[[:space:]])'

for dir in src/Services src/Query; do
  if [[ ! -d "$PROJECT_ROOT/$dir" ]]; then
    red "  FAIL: $dir does not exist"
    FAIL_COUNT=$((FAIL_COUNT+1))
    continue
  fi

  HITS=$(grep -rniE "$WRITE_PATTERN" "$PROJECT_ROOT/$dir" || true)

  if [[ -z "$HITS" ]]; then
    green "  PASS: $dir contains no write statements"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $dir contains write SQL:"
    red "$HITS"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
done

# Guard the guard: if the pattern stopped matching real write SQL, the checks
# above would pass vacuously and the guarantee would be worthless.
PROBE=$(printf 'INSERT INTO games (title) VALUES ("x");\nUPDATE `games` SET title = "y";\nDELETE FROM games;\n')
PROBE_HITS=$(echo "$PROBE" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "3" "$PROBE_HITS" "the write pattern still matches real write SQL"

# And confirm it does not fire on legitimate column names.
BENIGN=$(printf 'ORDER BY `updated_at` DESC\nSELECT `created_at` FROM games\n')
BENIGN_HITS=$(echo "$BENIGN" | grep -ciE "$WRITE_PATTERN" || true)
assert_eq "0" "$BENIGN_HITS" "the write pattern ignores updated_at/created_at"

summarize
