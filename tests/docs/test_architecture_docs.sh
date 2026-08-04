#!/usr/bin/env bash
# Structural checks on docs/architecture/.
#
# Documentation rots silently. These make the two failure modes that are
# otherwise invisible into loud ones:
#
#   1. A mermaid block that does not declare a diagram type renders as a raw
#      code fence on GitHub. It looks like a formatting mistake rather than a
#      broken diagram, so nobody reports it.
#   2. A diagram naming a directory that has since been moved or renamed is the
#      first symptom of a stale diagram, and the cheapest one to detect.
#
# Needs no server and no database — it only reads files.
source "$(dirname "$0")/../v2/lib.sh"

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DOCS="$ROOT/docs/architecture"

blue "docs/architecture exists"

if [[ -d "$DOCS" ]]; then
  green "  PASS: docs/architecture/ exists"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: docs/architecture/ is missing"
  FAIL_COUNT=$((FAIL_COUNT+1))
  summarize
fi

assert_eq "yes" "$([[ -s "$DOCS/README.md" ]] && echo yes || echo no)" \
  "README.md index is present and non-empty"

blue "mermaid blocks declare a parsable diagram type"

# Every opening ```mermaid fence must be followed by a line naming a diagram
# type mermaid recognises. This is the cheap half of "does it render" — a full
# parse would need mermaid-cli, which drags in puppeteer and Chromium, and this
# box runs Node 18.
MERMAID_TYPES='^[[:space:]]*(flowchart|graph|sequenceDiagram|erDiagram|classDiagram|stateDiagram(-v2)?|journey|gantt|pie|mindmap|timeline|block-beta)\b'
BLOCKS=0
BAD_TYPE=0
for f in "$DOCS"/*.md; do
  [[ -e "$f" ]] || continue
  while IFS= read -r lineno; do
    BLOCKS=$((BLOCKS+1))
    next=$(sed -n "$((lineno+1))p" "$f")
    if ! echo "$next" | grep -qE "$MERMAID_TYPES"; then
      red "    $(basename "$f"):$lineno — block opens with: ${next:0:60}"
      BAD_TYPE=$((BAD_TYPE+1))
    fi
  done < <(grep -n '^```mermaid$' "$f" | cut -d: -f1)
done

assert_eq "0" "$BAD_TYPE" "every mermaid block declares a known diagram type ($BLOCKS blocks checked)"

# Non-vacuity: the loop above passes trivially if it found nothing.
if [[ "$BLOCKS" -gt 0 ]]; then
  green "  PASS: found $BLOCKS mermaid blocks to check"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: no mermaid blocks found — the type check above was vacuous"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "code fences are balanced"

for f in "$DOCS"/*.md; do
  [[ -e "$f" ]] || continue
  n=$(grep -c '^```' "$f" || true)
  if (( n % 2 == 0 )); then
    green "  PASS: $(basename "$f") has balanced fences ($n)"
    PASS_COUNT=$((PASS_COUNT+1))
  else
    red "  FAIL: $(basename "$f") has an odd number of fences ($n) — one is unclosed"
    FAIL_COUNT=$((FAIL_COUNT+1))
  fi
done

blue "every repo path named in the docs exists"

# The real rot detector. Only repo-relative paths are checked: a token starting
# with ~ or / is external (~/.gt/trash, /etc/nginx/...) and not ours to verify.
MISSING=0
CHECKED=0
for f in "$DOCS"/*.md; do
  [[ -e "$f" ]] || continue
  # Backticked tokens beginning with a real top-level directory of this repo.
  while IFS= read -r p; do
    [[ -z "$p" ]] && continue
    CHECKED=$((CHECKED+1))
    if [[ ! -e "$ROOT/${p%/}" ]]; then
      red "    $(basename "$f"): '$p' does not exist"
      MISSING=$((MISSING+1))
    fi
  done < <(grep -oE '`(api|src|tests|includes|js|css|database|scripts|docs|bin|ios)/[A-Za-z0-9_./*-]+`' "$f" \
             | tr -d '`' | grep -v '[*]' | sort -u)
done

assert_eq "0" "$MISSING" "all $CHECKED repo paths named in the docs exist"

if [[ "$CHECKED" -gt 0 ]]; then
  green "  PASS: found $CHECKED repo paths to check"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: no repo paths found — the existence check above was vacuous"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "every diagram declares what makes it stale"

# Each diagram is an '## <n>. <title>' heading. Diagrams in git that nobody
# updates are worse than none, so each must name the change that invalidates it.
NO_STALE=0
DIAGRAMS=0
for f in "$DOCS"/*.md; do
  [[ -e "$f" ]] || continue
  [[ "$(basename "$f")" == "README.md" ]] && continue
  while IFS= read -r h; do
    DIAGRAMS=$((DIAGRAMS+1))
    lineno="${h%%:*}"
    title="${h#*:}"
    # Range runs to the line BEFORE the next '## ' heading, so a diagram can
    # never satisfy this check using its neighbour's content.
    end=$(awk -v s="$lineno" 'NR>s && /^## /{print NR-1; exit}' "$f")
    [[ -z "$end" ]] && end=$(wc -l < "$f")
    if ! sed -n "${lineno},${end}p" "$f" | grep -q '\*\*Goes stale when:\*\*'; then
      red "    $(basename "$f"): '$title' has no 'Goes stale when:' line"
      NO_STALE=$((NO_STALE+1))
    fi
  done < <(grep -nE '^## [0-9]+\. ' "$f")
done

assert_eq "0" "$NO_STALE" "all $DIAGRAMS diagrams carry a 'Goes stale when' line"

blue "the set is complete"

# Now that all nine exist, assert the count. Until this point the suite was
# deliberately count-agnostic so that every intermediate commit stayed green —
# a suite that is knowingly red for several commits trains people to ignore it.
assert_eq "9" "$DIAGRAMS" "all nine diagrams are present"

for expected in README.md 1-outside.md 2-apis.md 3-refactor.md 4-subsystems.md; do
  assert_eq "yes" "$([[ -s "$DOCS/$expected" ]] && echo yes || echo no)" \
    "$expected is present and non-empty"
done

# Every diagram the index advertises must actually exist as a heading.
# NOTE: adjusted from the brief's `^\| *[0-9]+\. |^\| *\| *[0-9]+\. ` — the
# index's table has a non-empty level-label first cell on a diagram's first
# row (e.g. "| 1 — from outside | 1. System context | ...") and an empty first
# cell on continuation rows (e.g. "| | 2. User journey | ..."). The brief's
# regex only matches the continuation-row shape and undercounts at 5. This
# version matches either shape by treating the first cell as opaque.
INDEX_ROWS=$(grep -cE '^\| *[^|]*\| *[0-9]+\. ' "$DOCS/README.md" || true)
assert_eq "9" "$INDEX_ROWS" "the index lists all nine diagrams"

summarize
