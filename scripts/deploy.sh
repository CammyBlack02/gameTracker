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

# Preflight: Node/npm on the VM. First deploy after phase 4e needs these.
if [ -f package.json ]; then
    for cmd in node npm; do
        if ! command -v "$cmd" >/dev/null 2>&1; then
            cat <<EOF >&2
==> $cmd not installed. Install with:

  Debian/Ubuntu:  curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash - \\
                  && sudo apt-get install -y nodejs
  RHEL/Alma:      curl -fsSL https://rpm.nodesource.com/setup_20.x | sudo -E bash - \\
                  && sudo dnf install -y nodejs

Node >= 18 required. Rerun this script when ready.
EOF
            exit 1
        fi
    done

    node_major=$(node -p 'process.versions.node.split(".")[0]')
    if [ "$node_major" -lt 18 ]; then
        echo "==> Node $node_major installed but >= 18 required. Upgrade before continuing." >&2
        exit 1
    fi
fi

echo "==> git pull"
git pull --ff-only

if [ -f package.json ]; then
    echo "==> npm ci (reproducible install)"
    npm ci --omit=optional --no-audit --no-fund

    echo "==> npm run build (Vite bundles for js/dist)"
    npm run build
fi

echo "==> deploy done"
