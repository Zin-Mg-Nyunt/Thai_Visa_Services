# Production deployment

How to deploy Thai Visa Services (PaingThu) with `deploy.sh`, and what to do when something goes wrong.

| Item               | Value                                                                                           |
| ------------------ | ----------------------------------------------------------------------------------------------- |
| Project folder     | `/var/www/html`, branch `master`                                                                |
| Ownership          | project files: `root:root`; `storage/`, `bootstrap/cache/`: `www-data:www-data` (never changed) |
| Database           | MySQL on `127.0.0.1`, database `thai_visa_service`, app user `paingthu_admin`                   |
| Backups            | `/var/backups/paingthu-db/` (`root`, mode `700`; each file mode `600`)                          |
| Backup credentials | `/root/.paingthu-backup.cnf` (`root`, mode `600`, never in Git or `.env`)                       |
| Health check       | `https://myeikthar.com/up` answers HTTP 200                                                     |

## One-time setup

### 1. Backup login

`deploy.sh` stops at the backup step until this file exists. The MySQL user needs `SELECT`, `SHOW VIEW` and `TRIGGER` on `thai_visa_service.*`; `paingthu_admin` has `ALL PRIVILEGES` there, so it can be reused. Check as MySQL root (`sudo mysql`): `SHOW GRANTS FOR 'paingthu_admin'@'localhost';` (or `@'127.0.0.1'`).

As root, with an editor so the password does not go into the shell history:

```bash
umask 077
nano /root/.paingthu-backup.cnf
```

```ini
[client]
user=paingthu_admin
password="<the paingthu_admin password>"
host=127.0.0.1
port=3306
```

```bash
chown root:root /root/.paingthu-backup.cnf && chmod 600 /root/.paingthu-backup.cnf
mysqldump --defaults-extra-file=/root/.paingthu-backup.cnf --single-transaction --no-tablespaces --no-data thai_visa_service > /dev/null && echo "backup login OK"
```

Keep the double quotes around the password. `--defaults-extra-file` must always be the first option. When the `paingthu_admin` password changes, update this file too.

### 2. Memory

The Droplet has 1 GB of RAM; `npm ci` and the build can run out of memory. Check `free -h`. If there is no swap, add one once:

```bash
fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

## Before each deploy: review the migrations

`php artisan migrate --force` runs only migrations that have not run yet, and only their `up()`. A backup does not make a destructive migration safe: restoring it also loses every client record written after it, and damage may be noticed only days later. So check before you push:

1. **Never edit or delete a migration that production has already run.** Always add a new one. (Production never re-runs an old migration, so an edit would never reach it.)
2. See what will run: on the server `runuser -u www-data -- php artisan migrate:status` shows what has run; locally `git diff --stat <production commit>..master -- database/migrations` shows the new files.
3. In each new migration's `up()`, look for `drop`, `dropColumn`, `truncate`, `delete`, `->update(`, `DB::statement`, `->change()`, `rename`. Anything touching `clients`, `client_services`, `payments`, `documents` or `job_order_workers` needs extra care.
4. For a risky one: test it on a copy of a production backup loaded into a scratch database (see "Restore a database backup", step 5), and on the server look at the SQL without running it: `runuser -u www-data -- php artisan migrate --pretend`.
5. Remove data in two deploys: first stop using the column, later drop it.

Never run `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `db:wipe` or `db:seed` on production.

## Deploying

On your computer: test, commit, `git push origin master`. Then:

```bash
ssh root@<server>
tmux new -s deploy            # a dropped SSH connection then does not stop the script (reattach: tmux attach -t deploy)
cd /var/www/html
./deploy.sh
```

Do not `git pull` yourself first: the script pulls after the site is in maintenance mode and the backup is taken. The very first time, `deploy.sh` is not on the server yet; run that one deploy from a copy instead of pulling by hand:

```bash
git fetch origin master && git show origin/master:deploy.sh > /root/deploy.sh && bash /root/deploy.sh && rm /root/deploy.sh
```

If `./deploy.sh` says "Permission denied", run `bash deploy.sh`.

What it does, in order:

1. `php artisan down --retry=60`: visitors get HTTP 503 until step 6 (usually 1 to 3 minutes).
2. Database backup to `/var/backups/paingthu-db/paingthu-<UTC time>-<commit>.sql.gz`. It is written as `<name>.partial`, must end with mysqldump's `-- Dump completed` line, and only then is renamed. The commit in the name is the code that was running when the backup was taken. Backups are never deleted by the script; remove old ones by hand (`du -sh /var/backups/paingthu-db/`), always keeping the latest. Also keep copies off the Droplet (for example DigitalOcean backups).
3. `git pull origin master`, `composer install --no-dev ... --no-scripts` (as root: the project files belong to root).
4. As `www-data`: remove `bootstrap/cache/*.php`, `php artisan optimize:clear`, `php artisan package:discover`. This replaces Composer's skipped scripts and clears the config, routes, events, views and application cache, so no stale cache survives a package, config or schema change.
5. `npm ci --include=dev`, `LOG_CHANNEL=stderr npm run build`, remove `public/hot`.
6. As `www-data`: `php artisan migrate --force`, `php artisan optimize`; then `systemctl reload php8.5-fpm` and `php artisan up`.

It ends with `Health check OK` (or a warning) and `DEPLOYED <commit>. Backup: <file>`. Then check the site in the browser and sign in to the admin area.

## When it fails

The script prints `!! DEPLOY FAILED at line <n>` and stops; the site **stays in maintenance mode**. Nothing is rolled back.

- **Backup failed** (missing `.cnf`, MySQL down, disk full): code and database are unchanged. An unfinished file stays as `<name>.partial`; it is not a valid backup, delete it after looking. Fix the cause and run `./deploy.sh` again, or bring the old site back with `runuser -u www-data -- php artisan up`.
- **Failed after the backup** (pull, composer, npm, build, migrate, optimize): fix the cause, push if needed, run `./deploy.sh` again. Running it again is safe.
- **A migration failed or damaged data:** `runuser -u www-data -- php artisan migrate:status` shows what ran. Either push a fixed migration and deploy again, or restore the backup and return to the previous commit (below).
- **Health check warning:** the site is online. Look at `curl -sS -i https://myeikthar.com/up | head`, `tail -n 50 storage/logs/laravel-$(date -u +%F).log`, `systemctl status php8.5-fpm`. Take the site down while you look if needed: `runuser -u www-data -- php artisan down --retry=60`.

### Return to the previous commit

With the site in maintenance mode, as root in `/var/www/html` (if migrations had run, restore the database first):

```bash
git reset --hard <previous commit>
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
runuser -u www-data -- rm -f bootstrap/cache/*.php
runuser -u www-data -- php artisan optimize:clear
runuser -u www-data -- php artisan package:discover
npm ci --include=dev && LOG_CHANNEL=stderr npm run build
runuser -u www-data -- php artisan optimize
systemctl reload php8.5-fpm
runuser -u www-data -- php artisan up
```

The server's `master` is then behind GitHub, and the next `./deploy.sh` would pull the same commits again: push a fix or a revert first.

### Restore a database backup

This replaces the whole database; everything written after the backup is lost. Only with the site in maintenance mode, as root:

1. Check the backup: `zcat /var/backups/paingthu-db/<file>.sql.gz | tail -n 1` must show `-- Dump completed`.
2. Keep a copy of the current state:
    ```bash
    (umask 077; mysqldump --defaults-extra-file=/root/.paingthu-backup.cnf --single-transaction --no-tablespaces --triggers thai_visa_service | gzip > /var/backups/paingthu-db/before-restore-$(date -u +%Y%m%dT%H%M%SZ).sql.gz)
    ```
3. Empty the database (a table made by a newer migration would otherwise stay behind). Grants on `thai_visa_service.*` survive this:
    ```bash
    sudo mysql -e "DROP DATABASE thai_visa_service; CREATE DATABASE thai_visa_service CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    ```
4. Load the backup as MySQL root: `zcat /var/backups/paingthu-db/<file>.sql.gz | sudo mysql thai_visa_service`
5. Return the code to the commit in the backup's name (above), check `runuser -u www-data -- php artisan migrate:status` shows nothing pending, then `runuser -u www-data -- php artisan up`.

To practise or to test a migration, load a backup into a scratch database instead: `sudo mysql -e "CREATE DATABASE restore_test"`, load it, look, `sudo mysql -e "DROP DATABASE restore_test"`.
