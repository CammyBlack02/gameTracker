#!/usr/bin/env bash
# Top-level test runner. Starts a PHP dev server pointed at the test DB,
# runs every test_*.sh file, then shuts the server down cleanly.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

export GT_DB_NAME="${TEST_DB_NAME:-gameTracker_test}"
export GT_DB_USER="${TEST_DB_USER:-root}"
export GT_DB_PASS="${TEST_DB_PASS:-}"

# Reset DB
"$SCRIPT_DIR/setup-test-db.sh"

# Start dev server in background
cd "$PROJECT_ROOT"
php -S localhost:8000 router.php > /tmp/v2_server.log 2>&1 &
SERVER_PID=$!
trap "kill $SERVER_PID 2>/dev/null || true" EXIT

# Wait for server to be ready (poll, up to 5s).
# Use -s (not -sS) here to suppress connection-refused errors while waiting.
for i in {1..50}; do
  if curl -s http://localhost:8000/ -o /dev/null 2>/dev/null; then
    break
  fi
  sleep 0.1
done

# Explicit liveness check so server failures produce a clear error,
# rather than a cryptic "curl exit 7" inside the first test.
if ! curl -s http://localhost:8000/ -o /dev/null 2>/dev/null; then
  echo "ERROR: PHP dev server did not start on localhost:8000." >&2
  echo "       Check log: /tmp/v2_server.log" >&2
  exit 1
fi

OVERALL_FAIL=0
for test in "$SCRIPT_DIR"/test_*.sh; do
  echo "=== $(basename "$test") ==="
  if ! bash "$test"; then
    OVERALL_FAIL=1
  fi
  echo
done

exit $OVERALL_FAIL
