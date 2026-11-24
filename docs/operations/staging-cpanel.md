# Online staging on cPanel — mp.hcsyria-apps.org

Purpose (owner, 24 Nov 2026): put the site online on our own hosting and database so the team can see it, **with synthetic data only**. Real data (M5) comes last. This is M7.1/M7.2 brought forward; the final freeze and cut-over (M7.3) stay at the end.

Decisions of the owner: hosting is cPanel with SSH, PHP, Composer and Node; the site is reachable from the public internet (no VPN).

## Rules for a public staging site
- `APP_ENV=staging` (not `production`: the seeders of invented data refuse `production`; HSTS is sent in both). `APP_DEBUG=false`.
- **Never use the local test password.** `RoleAccountsSeeder` now refuses to run outside local/testing unless `SEED_ACCOUNT_PASSWORD` holds a strong value.
- **Do not run `SyntheticStaffSeeder`** (it creates desktop users with the password `password`). Seed the pieces one by one, as below.
- Put a second lock in front while it is only a preview: cPanel → *Directory Privacy* on the site folder (HTTP password).
- No real data, no real attachments, no real ID cards on this site. `.env`, dumps and backups stay out of git and out of the web root.

## 1. cPanel
1. *MultiPHP Manager*: PHP **8.2 or newer** for the domain. Extensions: `pdo_mysql`, `mbstring`, `zip` (checks Word files), `fileinfo`, `gd`, `intl`, `openssl`, `curl`, `xml`, `bcmath` (check the list with `php -m` over SSH).
2. *MySQL Databases*: one database and one user with all privileges (for now; the narrow runtime user of `runtime-permissions.md` comes with M6.1).
3. *Domains → Subdomain* `mp.hcsyria-apps.org` with **document root = `<app folder>/public`** (for example `mp/public`). The app folder itself stays outside `public_html`.
4. *SSL/TLS Status*: run AutoSSL for the subdomain; then force HTTPS (*Domains → Force HTTPS Redirect*).

## 2. Code (over SSH, in the home folder)
```bash
git clone https://github.com/HopeITs/micro_web_project.git mp
cd mp
git checkout rebuild            # later: the branch the owner accepts
composer install --no-dev --optimize-autoloader
npm ci && npm run build         # or build locally and upload public/build
cp .env.example .env
php artisan key:generate
```
Edit `.env` (nano/vi or cPanel File Manager):
```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://mp.hcsyria-apps.org
DB_HOST=127.0.0.1
DB_DATABASE=<cpanel database>
DB_USERNAME=<cpanel user>
DB_PASSWORD=<private>
SESSION_SECURE_COOKIE=true
SEED_ACCOUNT_PASSWORD=<strong password, not written in chat or git>
MAIL_MAILER=smtp        # the info@hcsyria.org settings, as on the local site
```
## 3. Database and invented data
```bash
php artisan migrate --force
php artisan db:seed --class=CitiesSeeder --force
php artisan db:seed --class=ReferenceListsSeeder --force
php artisan db:seed --class=SyntheticDataSeeder --force
php artisan db:seed --class=RoleAccountsSeeder --force
php artisan db:seed --class=SyntheticSpecialCasesSeeder --force
php artisan db:seed --class=SyntheticKoboSeeder --force
php artisan storage:link   # only if the site shows files through public/storage
chmod -R u+rwX,go-rwx storage bootstrap/cache
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Role accounts: `super.admin`, `judy`, and one `role.<role>` per role; the same strong password for all while it is a preview. Share it with the team privately.

## 4. Optional on the same server
- **Official papers** (82 MB, not in git): upload the owner's folder outside the web root, then `php artisan papers:import <folder>`.
- **ID reading:** needs Tesseract with Arabic data; ask the host first. Without it the "read" button reports that the engine is off.
- **Queue and scheduler:** nothing needs them yet (`QUEUE_CONNECTION=sync`). Kobo scheduling arrives with M7.2.

## 5. Update after each accepted change
```bash
cd ~/mp && git pull --ff-only && composer install --no-dev --optimize-autoloader && npm ci && npm run build
php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 6. Check
- `https://mp.hcsyria-apps.org/ar/login` opens over HTTPS; wrong password is refused; `/.env` and `/storage/...` return 404/403.
- Sign in as each role, open a project, the checklist, an official paper, and print one.
- `storage/logs/laravel.log` has no errors.
