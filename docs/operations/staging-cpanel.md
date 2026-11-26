# Online staging — mp.hcsyria-apps.org

Purpose (owner, 24 Nov 2026): put the site online on our own hosting and database so the team can see it, **with synthetic data only**. Real data (M5) comes last. This is M7.1/M7.2 brought forward; the final freeze and cut-over (M7.3) stay at the end.

Decisions of the owner: shared hosting with SSH, PHP, Composer and Node; the site is reachable from the public internet (no VPN).

**State (26 Nov 2026):** online. The host's panel is SureSupport "Control Panel" (`panel.s1893.sureserver.com`), not cPanel. Code is in `~/www/mp` on branch `rebuild`, web PHP 8.3, command-line PHP 8.5, database on MySQL 8.4.

## Rules for a public staging site
- `APP_ENV=staging` (not `production`: the seeders of invented data refuse `production`; HSTS is sent in both). `APP_DEBUG=false`.
- **Never use the local test password.** `RoleAccountsSeeder` refuses to run outside local/testing unless `SEED_ACCOUNT_PASSWORD` holds a strong value.
- **Do not run `SyntheticStaffSeeder`** (it creates desktop users with the password `password`). Seed the pieces one by one, as below.
- Put a second lock in front while it is only a preview: *Security → Protection → Password protection* for `mp`.
- No real data, no real attachments, no real ID cards on this site. `.env`, dumps and backups stay out of git and out of the web root.

## 1. Panel
1. *Software → PHP Settings*: PHP **8.3 or newer** for `mp` (FPM). Extensions: `pdo_mysql`, `mbstring`, `zip` (checks Word files), `fileinfo`, `gd`, `intl`, `openssl`, `curl`, `xml`, `bcmath` (check with `php -m` over SSH).
2. *MySQL Databases → MySQL 8*: database `mp_db` (becomes `hcsyriaapps_mp_db`) and its own user `mp`. Locally it listens on `127.0.0.1:3308`; port 3306 is MySQL 5.7, which this site does not use.
3. *Subdomains*: `mp.hcsyria-apps.org` with document root `www/mp/public`.
4. *SSL/HTTPS*: the wildcard certificate `*.hcsyria-apps.org` covers it; turn **Force HTTPS** on for `mp`.
5. **WebApps: no app may be bound to `mp.hcsyria-apps.org`.** A Node app bound to the domain (even stopped) takes every request and the site answers **503**, static files included, with nothing in the application log. The site needs no Node app.

## 2. Code (SSH or Web SSH Terminal)
```bash
cd ~/www
git clone https://github.com/HopeITs/micro_web_project.git mp
cd mp
git checkout rebuild
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```
Front-end files (`public/build`) are not in git. Node on this host works only inside a project shell (`sureapp project shell node`); see section 5.

Edit `.env` (nano, or *Files → File Editor*), then `chmod 600 .env`:
```
APP_ENV=staging
APP_DEBUG=false
APP_URL=https://mp.hcsyria-apps.org
LOG_LEVEL=warning
DB_HOST=127.0.0.1
DB_PORT=3308
DB_DATABASE=hcsyriaapps_mp_db
DB_USERNAME=mp
DB_PASSWORD=<private>
SESSION_SECURE_COOKIE=true
SEED_ACCOUNT_PASSWORD=<strong password, not written in chat or git>
ID_READER_DRIVER=               # off: no OCR engine on the host yet
MAIL_MAILER=smtp
MAIL_HOST=mail.s1893.sureserver.com
MAIL_PORT=587
MAIL_USERNAME=info@hcsyria.org
MAIL_FROM_ADDRESS=info@hcsyria.org
MAIL_PASSWORD=<private>          # typed by the owner; run `php artisan config:cache` afterwards
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
php artisan storage:link
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
cd ~/www/mp && git pull --ff-only && composer install --no-dev --optimize-autoloader
php artisan optimize:clear && php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan view:cache
```
If styles or scripts changed, also rebuild `public/build` on the server (checked 26 Nov: same file names as a local build):
```bash
sureapp project shell node
cd ~/www/mp && npm run build
exit
```
Run `npm ci` first inside that shell when `package-lock.json` changed.

**Delivery rule (26 Nov 2026):** every change is tested locally, pushed to GitHub, deployed here from `rebuild` and then verified on the live site (see `AGENTS.md`). One session deploys at a time.

## 6. Check
- `https://mp.hcsyria-apps.org/ar/login` opens over HTTPS and `http://` redirects to it; a wrong password is refused; `/.env`, `/.git/config` and `/storage/...` return 403/404.
- Sign in as each role, open a project, the checklist, an official paper, and print one.
- `storage/logs/laravel.log` has no new errors.

Checked 26 Nov without signing in: both login pages 200, `http://` answers 301 to https (Force HTTPS on), protected pages redirect to login, `/.env`, `/.git/config` and the log 403, security headers and secure cookies present, a seventh quick wrong login answers 429. **Open:** password protection of the preview (the owner sets the password); signed-in pages not checked; official papers not imported.


