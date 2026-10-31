#!/usr/bin/env bash
#
# UNIFLEET — frontend deploy. Run on a WORKSTATION, not the server.
#
#   ./deploy/push-frontend.sh
#
# Builds the SPA locally and rsyncs the static output. The server deliberately
# has no Node toolchain: it is build-time-only software, and leaving it off an
# internet-facing box removes it as an attack surface and keeps it out of the
# change set IT has to approve.
set -euo pipefail

FRONTEND_DIR="${FRONTEND_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../unifleet" && pwd)}"
SERVER="${SERVER:-8.213.39.81}"
SSH_USER="${SSH_USER:-ecs-user}"
REMOTE_DIR="${REMOTE_DIR:-/var/www/unifleet/frontend}"

cd "$FRONTEND_DIR"
echo "==> Building in $FRONTEND_DIR"

# The default node on the dev Mac is v14 under Rosetta and cannot run Vite 5.
# .nvmrc pins 20; load it if nvm is available.
if [[ -s "$HOME/.nvm/nvm.sh" ]]; then
    # shellcheck disable=SC1091
    source "$HOME/.nvm/nvm.sh"
    nvm use >/dev/null
fi
node -v

if [[ ! -f .env ]]; then
    echo "No .env here. Vite INLINES env values at build time, so building"
    echo "without VITE_GOOGLE_MAPS_API_KEY produces a bundle whose maps fail"
    echo "silently in the browser with no server-side error. Copy .env.example"
    echo "and fill it in before deploying."
    exit 1
fi

# typecheck first: `vite build` strips types WITHOUT checking them, so the build
# passes with type errors. This is the only real gate.
npm run typecheck
npm run build

# Guard against the regression this whole arrangement exists to prevent.
if grep -rq "localhost:8000" dist/; then
    echo "Built bundle contains localhost:8000 - it would call the operator's"
    echo "own machine instead of the server. Check API_BASE in src/lib/api.ts."
    exit 1
fi

echo "==> Uploading to $SSH_USER@$SERVER:$REMOTE_DIR"
# --delete removes assets from previous builds; filenames are content-hashed, so
# without it the directory grows forever. index.html is copied last by rsync's
# ordering in practice, but the no-store header on it (see nginx.conf) is what
# actually prevents a client caching an index that points at deleted assets.
rsync -az --delete --human-readable \
    dist/ "$SSH_USER@$SERVER:$REMOTE_DIR/"

echo "==> Done."
