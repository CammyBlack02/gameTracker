#!/usr/bin/env bash
# Server-side deploy for the GameTracker web app.
#
# Runs on the VM after `git fetch`. Pulls latest main, then rebuilds any
# Vite entry points (currently just spin-wheel — see vite.config.js).
# `npm ci` uses the committed package-lock.json for reproducibility.
#
# Requires: node >= 18, npm >= 9. Install once with your VM's package
# manager if not already present.
#
# Usage: sudo -u www-data ./scripts/deploy.sh
#        (or whichever user owns the git checkout)

set -euo pipefail

# Move to the repo root regardless of where this is invoked from
cd "$(dirname "$0")/.."

echo "==> git pull"
git pull --ff-only

if [ -f package.json ]; then
    echo "==> npm ci (reproducible install)"
    npm ci --omit=optional --no-audit --no-fund

    echo "==> npm run build (Vite bundles for js/dist)"
    npm run build
fi

echo "==> deploy done"
