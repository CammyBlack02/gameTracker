#!/usr/bin/env bash
# Title normalisation used for import matching.
#
# Matching correctness rests entirely on this function: too aggressive and two
# different games collide and one is silently never imported; too timid and a
# Steam rename creates a duplicate of a game already owned.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

norm() {
  php -r '
    require $argv[1] . "/src/autoload.php";
    echo GameTracker\Import\TitleKey::normalise($argv[2]);
  ' -- "$PROJECT_ROOT" "$1"
}

blue "Normalisation"

assert_eq "half-life" "$(norm 'Half-Life')"            "lowercases"
assert_eq "half-life" "$(norm 'Half-Life™')"           "strips trademark"
assert_eq "half-life" "$(norm 'Half-Life®')"           "strips registered"
assert_eq "half-life" "$(norm 'Half-Life©')"           "strips copyright"
assert_eq "half-life" "$(norm '  Half-Life  ')"        "trims"
assert_eq "half life 2" "$(norm 'Half  Life   2')"     "collapses inner whitespace"
assert_eq "half-life: source" "$(norm 'Half-Life: Source')" "keeps punctuation that distinguishes titles"

blue "Distinct titles must not collide"

A=$(norm 'Half-Life 2')
B=$(norm 'Half-Life 2: Deathmatch')
if [[ "$A" != "$B" ]]; then
  green "  PASS: sequel and spin-off stay distinct"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: '$A' collided with '$B'"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

C=$(norm 'Portal')
D=$(norm 'Portal 2')
if [[ "$C" != "$D" ]]; then
  green "  PASS: numbered sequels stay distinct"; PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: '$C' collided with '$D'"; FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "Unicode is handled without mangling"

assert_eq "pokémon red" "$(norm 'Pokémon Red')" "accented characters survive lowercasing"

summarize
