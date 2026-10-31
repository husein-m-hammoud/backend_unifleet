#!/usr/bin/env bash
#
# UNIFLEET — backend deploy. Run ON the server, as the service account.
#
#   cd /var/www/unifleet/backend && ./deploy/deploy.sh
#
# Idempotent: safe to re-run. Does only the backend; the frontend is built on a
# workstation and pushed with deploy/push-frontend.sh, so no Node toolchain
# exists on this box.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/unifleet/backend}"
BRANCH="${BRANCH:-main}"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"

cd "$APP_DIR"

if [[ "$(id -u)" -eq 0 ]]; then
    echo "Refusing to run as root: it would leave root-owned files in storage/ and"
    echo "bootstrap/cache/ that php-fpm then cannot write, which fails as a 500"
    echo "well after this script reports success. Run as the service account."
    exit 1
fi

echo "==> Deploying $BRANCH to $APP_DIR"

# Maintenance mode, so a request cannot hit half-migrated schema with cached
# routes from the previous release. The trap matters: without it, a failure
# between here and the end leaves the site down with no indication why.
#
# Note: while down, every route returns 503 - INCLUDING /api/health. The uptime
# monitor will alert. That is expected during a deploy, not an incident. Keep
# the window short and tell whoever is on call.
php artisan down --retry=60 || true
trap 'php artisan up || true' EXIT

echo "==> Fetching code"
git fetch --quiet origin "$BRANCH"
# --ff-only so a dirty or diverged server checkout fails loudly here rather than
# producing a silent merge commit nobody made.
git merge --ff-only "origin/$BRANCH"

echo "==> Installing dependencies"
# --no-dev omits phpunit and friends. Rubix ML is a production dep and a stale
# vendor/ breaks the AI Insights page, so this is not skippable on a code change.
composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

echo "==> Migrating"
php artisan migrate --force

echo "==> Rebuilding caches"
# Clear before caching: a stale config cache silently wins over a corrected .env,
# which is a genuinely hard failure to diagnose.
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Reloading PHP-FPM"
# Without this, opcache keeps serving the previous release's compiled PHP.
sudo systemctl reload "$PHP_FPM"

php artisan up
trap - EXIT

echo "==> Smoke test"
# doctor exits non-zero on any failing check, so a bad deploy fails the script.
# Expect 'Ops alerting: none' and a Mail warning until SMTP is configured; those
# are warnings, not failures, and do not affect the exit code.
php artisan unifleet:doctor

echo "==> Done. Deployed $(git rev-parse --short HEAD)"
