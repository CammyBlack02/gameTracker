#!/usr/bin/env bash
# RomM shim shape mapping — runs the PHP unit checks in test_romm_shape.php.
#
# Split into a .php file rather than the `php -r` per assertion style used by
# test_title_key.sh: this suite pins ~40 keys of a wire contract, and one PHP
# process per key would be both slow and unreadable. The PHP script owns the
# assertions and its exit code is the result.
#
# Needs no database and no web server, unlike tests/v2 — api/romm/_shape.php is
# deliberately free of config.php so the contract can be checked anywhere.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "RomM shim shape mapping"

if php "$PROJECT_ROOT/tests/cli/test_romm_shape.php"; then
  green "✓ shape contract holds"
else
  red "✗ shape contract broken — see the failures above"
  exit 1
fi
