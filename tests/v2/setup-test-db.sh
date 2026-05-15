#!/usr/bin/env bash
# Creates an isolated MySQL test database with two seed users.
# Idempotent: drops and recreates the DB on every run.

set -euo pipefail

DB_NAME="${TEST_DB_NAME:-gameTracker_test}"
DB_USER="${TEST_DB_USER:-root}"

echo "Recreating database $DB_NAME..."
mysql -u"$DB_USER" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Bootstrap schema by running the project's normal init code against the test DB.
# We do this by setting env vars that an overridable config can read.
PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
export GT_DB_NAME="$DB_NAME"
export GT_DB_USER="$DB_USER"
export GT_DB_PASS="${TEST_DB_PASS:-}"

# Trigger schema creation by hitting any PHP file that requires config.php
php -d display_errors=1 -r "
\$_SERVER['HTTP_HOST'] = 'localhost';
require '$PROJECT_ROOT/includes/config.php';
echo 'Schema initialized\n';
"

# Seed: create test user with known password (test_password)
PASSWORD_HASH=$(php -r 'echo password_hash("test_password", PASSWORD_DEFAULT);')
mysql -u"$DB_USER" "$DB_NAME" -e "
  INSERT INTO users (username, password_hash, role) VALUES ('testuser', '$PASSWORD_HASH', 'user');
"

echo "Test DB ready. User: testuser / test_password"
