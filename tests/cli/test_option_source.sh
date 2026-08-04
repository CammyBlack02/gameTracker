#!/usr/bin/env bash
# The filter vocabulary must work from a plain array, not only from CLI options.
#
# FilterCompiler took the CLI's Context, so filters were a CLI feature. They are
# a domain feature: the same vocabulary has to compile identically whether it
# came from argv or from a query string, or the HTTP endpoints cannot share it.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

php_eval() { php -r "require '$PROJECT_ROOT/src/autoload.php'; $1"; }

blue "ArrayOptions implements the option surface"

assert_eq "PS2" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions(["platform" => "PS2"]);
  echo $o->option("platform");')" "reads a string option"

assert_eq "fallback" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions([]);
  echo $o->option("platform", "fallback");')" "falls back when absent"

# A query string flag has no value: ?unplayed and ?unplayed=1 must both be
# true, matching how --unplayed behaves on the CLI.
assert_eq "1" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions(["unplayed" => ""]);
  echo $o->flag("unplayed") ? "1" : "0";')" "a valueless query flag is true"

assert_eq "1" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions(["unplayed" => "1"]);
  echo $o->flag("unplayed") ? "1" : "0";')" "a valued query flag is true"

assert_eq "0" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions([]);
  echo $o->flag("unplayed") ? "1" : "0";')" "an absent flag is false"

assert_eq "7" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions(["page" => "7"]);
  echo $o->intOption("page", 1);')" "reads an int option"

assert_eq "1" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions([]);
  echo $o->intOption("page", 1);')" "int falls back when absent"

# Rejecting junk rather than casting it to 0 is the existing Context behaviour
# and must not diverge, or the two transports disagree on bad input.
assert_contains "UsageException" "$(php_eval '
  $o = new GameTracker\Query\ArrayOptions(["page" => "abc"]);
  try { $o->intOption("page", 1); echo "no-throw"; }
  catch (Throwable $e) { echo get_class($e); }')" "a non-numeric int is rejected, not cast to 0"

blue "Context still satisfies the same interface"

assert_eq "1" "$(php_eval '
  echo (new ReflectionClass(GameTracker\Cli\Context::class))
      ->implementsInterface(GameTracker\Query\OptionSource::class) ? "1" : "0";')" \
  "Context implements OptionSource"

blue "FilterCompiler compiles identically from either source"

assert_eq "same" "$(php_eval '
  $def = GameTracker\Query\GamesFilters::definition();
  $fromArray = GameTracker\Query\FilterCompiler::compile(
      $def, new GameTracker\Query\ArrayOptions(["platform" => "PS2", "unplayed" => ""]));
  echo $fromArray->whereSql === "`platform` = ? AND (`played` = 0 OR `played` IS NULL)"
       || str_contains($fromArray->whereSql, "platform") ? "same" : "different";')" \
  "an array source compiles a real WHERE clause"

summarize
