#!/usr/bin/env bash
# Update the KCK dashboard on the VPS to the latest main branch and rebuild its container.
# Run by .github/workflows/deploy-vps.yml after every push (or by hand: bash deploy/vps/update.sh).
set -euo pipefail
cd "$(dirname "$0")/../.."
git fetch --quiet origin main
git reset --hard --quiet origin/main
cd deploy/vps
docker compose up -d --build kck-web
docker image prune -f >/dev/null
echo "KCK dashboard updated to $(git rev-parse --short HEAD)"
