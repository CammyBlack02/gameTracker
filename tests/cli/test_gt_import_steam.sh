#!/usr/bin/env bash
# `gt import steam` — driven through an injected transport.
#
# CI must never call the live Steam API: it would need a real key, it would be
# slow, and the result would change under us. SteamSource takes an HttpTransport
# so the tests can feed it a recorded payload instead.
source "$(dirname "$0")/../v2/lib.sh"
source "$(dirname "$0")/fixtures.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
GT="$PROJECT_ROOT/bin/gt"
FIXTURE="$PROJECT_ROOT/tests/cli/fixtures/steam-owned-games.json"

seed_games
FIXTURE_UID=$(fixture_user_id "$FIXTURE_USER")

GT_CODE=0; GT_OUT=""
run_gt() { set +e; GT_OUT=$("$GT" "$@" 2>&1); GT_CODE=$?; set -e; }

blue "Missing credentials"

fixture_mysql -e "
  DELETE FROM settings
  WHERE user_id = $FIXTURE_UID AND setting_key IN ('steam_api_key','steam_user_id')
"

run_gt import steam "--user=$FIXTURE_USER" --yes
assert_eq "1" "$GT_CODE" "absent Steam credentials are a domain error"
assert_contains "steam_api_key" "$GT_OUT" "names the missing setting"

blue "SteamSource against a recorded payload"

ROWS=$(php -r '
  require $argv[1] . "/src/autoload.php";
  $stub = new class($argv[2]) implements GameTracker\Import\HttpTransport {
      public function __construct(private string $file) {}
      public function get(string $url): ?string {
          // appdetails deliberately unavailable: a detail outage must degrade,
          // not abort.
          return str_contains($url, "GetOwnedGames")
              ? file_get_contents($this->file)
              : null;
      }
  };
  $src = new GameTracker\Import\SteamSource($stub, "k", "1");
  $n = 0; $platform = ""; $store = "";
  foreach ($src->rows() as $row) {
      $n++;
      $platform = $row->columns["platform"];
      $store = $row->columns["digital_store"];
  }
  echo $n, " ", $platform, " ", $store;
' -- "$PROJECT_ROOT" "$FIXTURE")

assert_eq "3 PC Steam" "$ROWS" "parses 3 games as PC/Steam even with appdetails down"

blue "A cover URL is offered for each game"

COVERS=$(php -r '
  require $argv[1] . "/src/autoload.php";
  $stub = new class($argv[2]) implements GameTracker\Import\HttpTransport {
      public function __construct(private string $file) {}
      public function get(string $url): ?string {
          return str_contains($url, "GetOwnedGames") ? file_get_contents($this->file) : null;
      }
  };
  $src = new GameTracker\Import\SteamSource($stub, "k", "1");
  $withCover = 0;
  foreach ($src->rows() as $row) { if ($row->coverUrl !== null) { $withCover++; } }
  echo $withCover;
' -- "$PROJECT_ROOT" "$FIXTURE")

assert_eq "3" "$COVERS" "every game carries a cover URL for the post-commit fetch"

blue "An unreachable Steam API is a domain error, not a crash"

UNREACHABLE=$(php -r '
  require $argv[1] . "/src/autoload.php";
  $dead = new class implements GameTracker\Import\HttpTransport {
      public function get(string $url): ?string { return null; }
  };
  $src = new GameTracker\Import\SteamSource($dead, "k", "1");
  try { foreach ($src->rows() as $row) {} echo "no-throw"; }
  catch (Throwable $e) { echo get_class($e); }
' -- "$PROJECT_ROOT")

assert_contains "BadRequestException" "$UNREACHABLE" "an unreachable API throws a domain error"

summarize
