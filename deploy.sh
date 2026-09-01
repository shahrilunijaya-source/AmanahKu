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

# The app requires PHP 8.3+. On multi-PHP servers the default `php` may be older.
# A shell function cannot override `#!/usr/bin/env php` in composer, so we prepend
# a directory containing a `php` symlink to PATH instead.
if command -v php8.3 >/dev/null 2>&1 && ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' 2>/dev/null; then
    PHPBIN="$(mktemp -d)"
    ln -s "$(command -v php8.3)" "${PHPBIN}/php"
    export PATH="${PHPBIN}:${PATH}"
    echo "==> Using $(php -v | head -1) (default was too old)"
fi

env_get() {
    grep -E "^$1=" .env 2>/dev/null | cut -d '=' -f2- | tr -d '"' | tr -d "'" | xargs || true
}

# Database credentials come from Laravel, not from grepping .env: a password holding a
# backslash, a quote or a '#' does not survive the env_get pipeline above (xargs eats
# backslashes), and the credentials may not live in .env at all. This asks the app for the
# connection it actually uses.
db_config() {
    php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $connection = config("database.default");
        echo config("database.connections.{$connection}.{$argv[1]}") ?? "";
    ' -- "$1"
}

# Read APP_ENV from .env (default: production if missing)
APP_ENV="$(env_get APP_ENV)"
APP_ENV="${APP_ENV:-production}"
echo "==> Deploying environment: ${APP_ENV}"
if [ "${APP_ENV}" = "local" ]; then
    echo "!!! Refusing to deploy against APP_ENV=local. Fix the host .env." >&2
    exit 1
fi

# Maintenance mode (ignore if not bootable yet). Whatever happens next, the site comes
# back: set -e used to leave the app down when a step failed, which turns a failed deploy
# into an outage.
php artisan down --render="errors::503" --retry=15 || true
trap 'php artisan up || true' EXIT

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
        exit 1
    fi

    DB_HOST_VALUE="$(db_config host)"

    echo "==> Pending migrations found. Dumping to ${BACKUP_FILE}"

    # A "localhost" host means the unix socket to PDO, but mysqldump resolves the name and
    # arrives over TCP as ::1, which is a different grant and gets refused. Say socket.
    MYSQLDUMP_TRANSPORT=(--host="${DB_HOST_VALUE}" --port="$(db_config port)")
    if [ "${DB_HOST_VALUE}" = "localhost" ] || [ -z "${DB_HOST_VALUE}" ]; then
        MYSQLDUMP_TRANSPORT=(--protocol=socket)
    fi

    if ! MYSQL_PWD="$(db_config password)" mysqldump \
        "${MYSQLDUMP_TRANSPORT[@]}" \
        --user="$(db_config username)" --single-transaction --quick \
        "$(db_config database)" > "${BACKUP_FILE}"; then
        rm -f "${BACKUP_FILE}"
        echo "!!! Could not dump the database, so the migration is NOT running." >&2
        echo "!!! Export it from hPanel > Databases, then re-run this script." >&2
        exit 1
    fi

    # Keep the last five. Older ones are a restore nobody will ever choose.
    ls -1t "${BACKUP_DIR}"/pre-migrate-*.sql 2>/dev/null | tail -n +6 | xargs -r rm --
fi

# Database migrations (non-interactive)
php artisan migrate --force

# Storage symlink — Hostinger shared disables PHP exec(), which `artisan storage:link`
# relies on. Create the symlink directly with ln instead.
[ -e public/storage ] || ln -sfn ../storage/app/public public/storage || true

# Committed assets win — no host ever builds over them.
#
# public/build is TRACKED, because Hostinger shared SSH has no Node and could never
# build there. The old rule here was "build if npm exists on this host", which is a
# property of the machine rather than of the release: a host that happens to have npm
# (the DigitalOcean box does) rebuilt on every deploy, overwrote the committed files
# with differently-hashed ones, and left the working tree dirty so the NEXT git pull
# aborted with "local changes would be overwritten by merge".
#
# Building on the host is now only a fallback for the one case it is actually needed:
# no assets came with the release at all. The fix for stale assets is to run
# `bun run build` locally and commit public/build, never to build on the server.
if [ -f public/build/manifest.json ]; then
    echo "==> Using committed public/build (host does not build)"
elif [ -f package.json ] && command -v npm >/dev/null 2>&1; then
    echo "!!! No committed assets in this release — building on the host as a fallback."
    echo "!!! Commit public/build from a local build instead; this host build will dirty the tree."
    npm ci --no-audit --no-fund || npm install --no-audit --no-fund
    npm run build
else
    echo "!!! WARNING: no public/build/manifest.json committed and no npm on host — CSS/JS will 404."
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
