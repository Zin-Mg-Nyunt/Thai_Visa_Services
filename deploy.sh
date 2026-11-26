#!/usr/bin/env bash
#
# deploy.sh: deploy the latest master to production. Run as root, inside tmux:
#   cd /var/www/html && ./deploy.sh
# Setup, recovery and the migration review before each deploy: docs/DEPLOYMENT.md
#
# On any error the script stops and the site stays in maintenance mode. Fix the cause and run it again.

set -euo pipefail
umask 022
trap 'echo "!! DEPLOY FAILED at line $LINENO. The site is still in maintenance mode. See docs/DEPLOYMENT.md." >&2' ERR
cd /var/www/html
www() { runuser -u www-data -- "$@"; }   # every Artisan command runs as www-data (it owns storage/ and bootstrap/cache/)

# 1. Maintenance mode
www php artisan down --retry=60

# 2. Database backup: written as .partial and renamed only when complete; the name has date and time, nothing is overwritten
mkdir -p -m 700 /var/backups/paingthu-db
BACKUP="/var/backups/paingthu-db/paingthu-$(date -u +%Y%m%dT%H%M%SZ)-$(git rev-parse --short HEAD).sql.gz"
(
    umask 077
    set -o noclobber
    mysqldump --defaults-extra-file=/root/.paingthu-backup.cnf --single-transaction --quick --triggers \
        --hex-blob --no-tablespaces --default-character-set=utf8mb4 thai_visa_service | gzip >"$BACKUP.partial"
)
zcat "$BACKUP.partial" | tail -n 1 | grep -q '^-- Dump completed'
mv "$BACKUP.partial" "$BACKUP"
echo "Backup: $BACKUP"

# 3. Code and PHP dependencies (--no-scripts: Composer's scripts would run Artisan as root)
git pull origin master
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# 4. Remove every compiled cache (a stale one can name a removed package and stop Artisan), then rediscover packages
www rm -f bootstrap/cache/*.php
www php artisan optimize:clear
www php artisan package:discover

# 5. Frontend (the build tools are dev dependencies; LOG_CHANNEL=stderr keeps the build from creating a root-owned log file)
npm ci --include=dev
LOG_CHANNEL=stderr npm run build
rm -f public/hot

# 6. Database, caches, PHP-FPM, back online
www php artisan migrate --force
www php artisan optimize
systemctl reload php8.5-fpm
www php artisan up

curl -fsS -o /dev/null https://myeikthar.com/up && echo "Health check OK" || echo "WARNING: https://myeikthar.com/up did not answer 200"
echo "DEPLOYED $(git rev-parse --short HEAD). Backup: $BACKUP"
