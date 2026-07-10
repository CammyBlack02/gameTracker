#!/usr/bin/env bash
# Creates an isolated MySQL test database with two seed users.
# Idempotent: drops and recreates the DB on every run.

set -euo pipefail

DB_NAME="${TEST_DB_NAME:-gameTracker_test}"
DB_USER="${TEST_DB_USER:-root}"
# TEST_DB_HOST is optional. When set (e.g. CI's 127.0.0.1) we force TCP
# so the mysql client doesn't try /var/run/mysqld/mysqld.sock, which
# doesn't exist on runners that use a Dockerised MySQL service.
DB_HOST_FLAG=""
if [[ -n "${TEST_DB_HOST:-}" ]]; then
  DB_HOST_FLAG="-h${TEST_DB_HOST}"
fi

echo "Recreating database $DB_NAME..."
mysql $DB_HOST_FLAG -u"$DB_USER" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Bootstrap schema via the migration runner (Phase 2b). This creates
# every table from 000_baseline (users/games/items/etc.) through 004
# (schema_migrations ledger). No more per-request DDL — the runner is
# the sole schema authority.
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export GT_DB_NAME="$DB_NAME"
export GT_DB_USER="$DB_USER"
# When TEST_DB_PASS is set, use it. Otherwise inherit whatever
# GT_DB_PASS the caller already exported (CI sets it; local dev may too)
# — the previous default of empty string clobbered a real password and
# made migrate.php fail with "Access denied ... using password: NO".
export GT_DB_PASS="${TEST_DB_PASS:-${GT_DB_PASS:-}}"
# Same story for the host, so a caller with prod-shaped env doesn't
# accidentally point migrate.php at the wrong server.
export GT_DB_HOST="${TEST_DB_HOST:-${GT_DB_HOST:-localhost}}"

php -d display_errors=1 "$PROJECT_ROOT/database/migrate.php"

# Seed: create test user with known password (test_password). The
# baseline migration also seeds an admin/admin user (users table was
# empty until this INSERT).
PASSWORD_HASH=$(php -r 'echo password_hash("test_password", PASSWORD_DEFAULT);')
mysql $DB_HOST_FLAG -u"$DB_USER" "$DB_NAME" -e "
  INSERT INTO users (username, password_hash, role) VALUES ('testuser', '$PASSWORD_HASH', 'user');
"

echo "Test DB ready. User: testuser / test_password"
