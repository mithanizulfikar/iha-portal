# Deploying the IHA Portal to a live server

These steps have **not** been run on a real server. Follow them on a test server first, and work through `docs/TESTING_CHECKLIST.md` before
real students use it.

## 1. What the server needs

- PHP 8.2 or newer with extensions `pdo_pgsql`, `pgsql`, `mbstring`, `openssl` (plus the usual `ctype`, `filter`, `json`).
- PostgreSQL 14 or newer, reachable from the web server (a managed database service is fine).
- A web server (Apache or nginx with PHP-FPM) serving **only the `public/` folder**. Everything else (`src/`, `templates/`, `database/`,
  `storage/`, `.env`) must be outside the web root. `deploy/apache.conf` and `deploy/nginx.conf.example` are examples.
- A real domain with **HTTPS** (a free certificate from Let's Encrypt is fine) and a way to send email (`docs/EMAIL_SETUP.md`).
- Composer, to install the libraries once.

## 2. About Vercel

Vercel can now run PHP in a container (FrankenPHP). The project includes `Dockerfile.vercel`, `Caddyfile` and `vercel.json` for this, plus database-backed
sessions, SMTP-only email and a cron cleanup endpoint, because a Vercel container's disk is temporary and not shared. Follow `docs/GIT_AND_VERCEL.md`.
It has not yet been run on a real Vercel project, so start with a Preview deployment. A traditional PHP host or VPS (sections 3 to 10 below) remains the simplest and best-understood option.

## 3. Install

```
git clone <your repository> /var/www/iha      # or upload the project folder
cd /var/www/iha
composer install --no-dev --optimize-autoloader
cp .env.example .env
```

## 4. Configure `.env` (production values)

```
APP_ENV=production
APP_URL=https://portal.yourdomain.org
APP_KEY=<64 random characters: php -r "echo bin2hex(random_bytes(32));">
DB_HOST=...  DB_PORT=5432  DB_NAME=iha_portal  DB_USER=...  DB_PASSWORD=...  DB_SSLMODE=require
MAIL_DRIVER=smtp   (and the SMTP_* values; run bin/mail-test.php)
SUPPORT_EMAIL=...  SUPPORT_PHONE=...
```
- `APP_ENV=production` hides PHP error details from visitors; errors go to the server log.
- `APP_URL` must be the exact https address, because it is written into emailed links and QR codes and decides whether cookies are marked "secure".
- `DB_SSLMODE=require` if the database is on another machine.
- Never put `.env` in Git, and make it readable only by the web-server user (`chmod 600 .env`).

## 5. Database

```
php bin/migrate.php
php bin/seed.php
php bin/create-admin.php you@yourdomain.org "Your Name" super_admin
php bin/import-geo.php --file=<verified CSV> --source-name="..." --source-url="..." --coverage="..." --admin-level="district+city" --dataset-date=YYYY-MM-DD
php bin/qr-sync.php          # only needed when upgrading an existing system
```
Create the application's database user with only the rights it needs (not a superuser). For the strongest protection of eligibility data, also
apply `database/hardening/roles.sql` (see its comments; the application would then use two database logins, which is a code change still to do).

## 6. Web server

- Point the document root at `public/`; all requests that are not real files must reach `public/index.php` (`public/.htaccess` does this for Apache; the nginx example does it with `try_files`).
- Make `storage/` writable by the web-server user and keep it outside the document root.
- Redirect HTTP to HTTPS. The application sends HSTS, a Content-Security-Policy and other security headers by itself when `APP_URL` is https.
- PHP sessions are stored as files on the server. That is fine for one application server. With several servers behind a load balancer you need
  sticky sessions or a shared session store (not built yet).

## 7. Scheduled jobs

```
0 2 * * *  cd /var/www/iha && php bin/backup.php
30 2 * * * cd /var/www/iha && php bin/cleanup.php
```
and copy the backup files off the server (`docs/BACKUP_RESTORE.md`).

## 8. Before opening to students

1. `https://your-domain/health` shows `{"status":"ok", ...}`.
2. Run the automated tests against a **copy** of the database (never against live data; they roll back, but do not take the risk):
   `php tests/marks_calc.php` ... `php tests/privacy_scan.php` (the list is in `docs/TESTING_CHECKLIST.md`).
3. Work through the manual checklist (registration, real email, dropdowns, submit, admin review, QR scan from a phone, password reset).
4. Replace the draft Privacy Policy and Terms text with versions IHA has approved, add the real logo (`public/assets/img/logo.svg`), and set `SUPPORT_EMAIL` / `SUPPORT_PHONE`.
5. Decide data-retention periods (`docs/DATA_RETENTION.md`).
6. Do a trial restore from a backup.

## 9. Upgrading later

1. Take a backup. 2. Put the new files in place (keep `.env`). 3. `composer install --no-dev`. 4. `php bin/migrate.php`. 5. Check `/health` and log in.
Migrations are numbered, applied once, and refuse to run again if an applied file was edited.

## 10. Files and folders that must never be public

`.env`, `src/`, `templates/`, `database/`, `storage/` (including `storage/backups/` and `storage/logs/mail.log`), `bin/`, `vendor/`, `composer.*`.
