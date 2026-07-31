#!/usr/bin/env bash
#
# Post-pull deploy script for Hostinger — Amanahku.
# Mirrors the DevStage01 convention. Run via SSH after Hostinger Git auto-deploy pulls:
#   bash deploy.sh
#
# Auto-detects the environment from APP_ENV in .env:
#   - production  -> optimized install (no dev deps), event cache
#   - staging     -> dev deps kept, easier debugging
#
set -euo pipefail
cd "$(dirname "$0")"

env_get() {
    grep -E "^$1=" .env 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" | xargs || true
}

# Read APP_ENV from .env (default: production if missing)
APP_ENV="$(env_get APP_ENV)"
APP_ENV="${APP_ENV:-production}"
echo "==> Deploying environment: ${APP_ENV}"
if [ "${APP_ENV}" = "local" ]; then
    echo "!!! Refusing to deploy against APP_ENV=local. Fix the host .env." >&2
    exit 1
fi

# Maintenance mode (ignore if not bootable yet)
php artisan down --render="errors::503" --retry=15 || true

if [ "${APP_ENV}" = "production" ]; then
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    composer install --optimize-autoloader --no-interaction --prefer-dist
fi

# A release that migrates dumps the database first. A migration that drops a column or a
# table has no undo, and "take a dump before a migrating deploy" is the one manual step
# everybody skips at 6pm. Nothing to migrate means nothing to back up, so an ordinary
# code-only deploy stays as fast as it was.
# Match a migration filename, not the word "pending": the nothing-to-do message is
# "No pending migrations." and would match a naive grep for it.
if php artisan migrate:status --pending 2>/dev/null | grep -qE '[0-9]{4}_[0-9]{2}_[0-9]{2}_'; then
    BACKUP_DIR="storage/app/backups"
    BACKUP_FILE="${BACKUP_DIR}/pre-migrate-$(date +%Y%m%d-%H%M%S).sql"
    mkdir -p "${BACKUP_DIR}"

    if ! command -v mysqldump >/dev/null 2>&1; then
        echo "!!! Pending migrations but no mysqldump on this host. Dump the database by hand" >&2
        echo "!!! (hPanel > Databases > Export), then re-run this script." >&2
        php artisan up || true
        exit 1
    fi

    echo "==> Pending migrations found. Dumping to ${BACKUP_FILE}"
    MYSQL_PWD="$(env_get DB_PASSWORD)" mysqldump \
        --host="$(env_get DB_HOST)" --port="$(env_get DB_PORT)" \
        --user="$(env_get DB_USERNAME)" --single-transaction --quick \
        "$(env_get DB_DATABASE)" > "${BACKUP_FILE}"

    # Keep the last five. Older ones are a restore nobody will ever choose.
    ls -1t "${BACKUP_DIR}"/pre-migrate-*.sql 2>/dev/null | tail -n +6 | xargs -r rm --
fi

# Database migrations (non-interactive)
php artisan migrate --force

# Storage symlink — Hostinger shared disables PHP exec(), which `artisan storage:link`
# relies on. Create the symlink directly with ln instead.
[ -e public/storage ] || ln -sfn ../storage/app/public public/storage || true

# Build front-end assets ONLY if Node is on this host.
# Hostinger shared SSH has no Node — assets are built locally and the compiled
# public/build directory is committed, so the server skips this step.
if [ -f package.json ] && command -v npm >/dev/null 2>&1; then
    npm ci --no-audit --no-fund || npm install --no-audit --no-fund
    npm run build
else
    echo "==> Skipping asset build (no npm on host; using committed public/build)"
    test -f public/build/manifest.json || echo "!!! WARNING: no public/build/manifest.json committed — CSS/JS will 404."
fi

# Cache framework config for speed
php artisan config:cache
php artisan route:cache
php artisan view:cache
[ "${APP_ENV}" = "production" ] && php artisan event:cache || true

# Restart queue workers so they pick up new code (invites/digests are queued)
php artisan queue:restart || true

# Warm any app-level caches
php artisan optimize

# Bring app back up
php artisan up
echo "==> Deploy complete (${APP_ENV})"
