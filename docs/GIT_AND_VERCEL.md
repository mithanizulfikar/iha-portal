# Putting the portal on Git (GitHub) and deploying it to Vercel

Based on Vercel's own guide "Deploy PHP on Vercel with Docker" (container runtime with FrankenPHP). **This project has not yet been
deployed to a real Vercel project**, so go step by step, use a *Preview* deployment first, and check `/health` before anything else.
Check Vercel's pricing/plan page that the container runtime is available on your plan.

Why the code needed changes for Vercel: the container's disk is temporary and not shared, so
- logins (sessions) are kept in PostgreSQL (`SESSION_DRIVER=database`),
- email must go through SMTP (the log-file mailer cannot work),
- errors go to Vercel's logs instead of files,
- scheduled cleanup uses Vercel Cron calling `/cron/cleanup`,
- every visitor appears to come from Vercel's proxy, so the real client address is read from a header (`TRUSTED_IP_HEADER`) for the rate limits.

---

## Part A. Git and GitHub

1. Install **Git for Windows** from git-scm.com (accept the defaults). Open a new terminal and check: `git --version`.
2. Tell Git who you are (once):
   ```
   git config --global user.name "Your Name"
   git config --global user.email "you@example.com"
   ```
3. In the project folder:
   ```
   cd /d D:\Projects\iha-portal
   git init
   git add .
   git status
   ```
   **Read the list carefully.** `.env`, `vendor/`, `composer.phar`, `storage/logs/…` and any `.zip` must NOT be listed (the `.gitignore` excludes them).
   Also run `git check-ignore -v .env`: it should print a line naming the `.gitignore` rule. If `.env` is listed, stop and tell me.
4. Commit:
   ```
   git commit -m "IHA portal"
   git branch -M main
   ```
5. On github.com click **New repository**, name it `iha-portal`, choose **Private**, do not add a README, press Create.
6. Connect and push (use the address GitHub shows you):
   ```
   git remote add origin https://github.com/YOUR-USER/iha-portal.git
   git push -u origin main
   ```
   A browser window asks you to sign in to GitHub the first time.
7. Open the repository on GitHub and confirm `.env` is **not** there. If a password or key ever reaches Git by mistake, treat it as leaked: change it (database
   password, Gmail app password, `APP_KEY`) even after deleting the file, because Git keeps history.

Keep the repository private: it contains your whole application.

## Part B. A cloud PostgreSQL database

Vercel does not host your database. Create a managed PostgreSQL (the Vercel Marketplace has Postgres integrations; Neon and Supabase are common; any managed PostgreSQL works).

1. Create a database in a region close to your Vercel region. Note the host, port, database name, user and password. If the provider offers a **pooled** connection address, use it (each page request opens a connection).
2. Create a **separate** database for testing/previews and one for production so test data never mixes with real students.
3. Prepare the empty database from your PC. In a new terminal use `set "NAME=value"` (the quotes keep special characters safe); these values exist only in that window:
   ```
   cd /d D:\Projects\iha-portal
   set "DB_HOST=your-host"
   set "DB_PORT=5432"
   set "DB_NAME=your-db"
   set "DB_USER=your-user"
   set "DB_PASSWORD=your-password"
   set "DB_SSLMODE=require"
   D:\php\php.exe bin\migrate.php
   D:\php\php.exe bin\seed.php
   D:\php\php.exe bin\create-admin.php you@yourdomain.org "Your Name" super_admin
   ```
   Later, with your verified CSV: `D:\php\php.exe bin\import-geo.php --file=... --source-name=... ` as in `README.md`. Close the window afterwards.
   Do **not** copy your local test data to the cloud.
4. Whenever a new version contains a new numbered file in `database/migrations/`, run `bin\migrate.php` against the cloud database **before** deploying that version.

## Part C. Vercel

1. Sign up at vercel.com (signing in with GitHub is easiest).
2. **Add New → Project → Import** your `iha-portal` repository. Framework preset: **Other**. Leave the root directory as it is; the repository already contains
   `vercel.json`, `Dockerfile.vercel` and `Caddyfile`.
3. Before pressing Deploy, open **Environment Variables** and add these (Project Settings → Environment Variables; choose the environments they apply to; use the
   test database for Preview and the production database for Production):

   | Name | Value |
   |---|---|
   | `APP_ENV` | `production` |
   | `APP_URL` | `https://YOUR-PROJECT.vercel.app` (set after the first deploy, then redeploy; later your own domain) |
   | `APP_KEY` | 64 random characters: `D:\php\php.exe -r "echo bin2hex(random_bytes(32));"` (a different one for production) |
   | `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | from your database provider |
   | `DB_SSLMODE` | `require` |
   | `SESSION_DRIVER` | `database` |
   | `MAIL_DRIVER` | `smtp` |
   | `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_ENCRYPTION` | your mail provider (`docs/EMAIL_SETUP.md`) |
   | `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | sender address and name |
   | `SUPPORT_EMAIL`, `SUPPORT_PHONE` | shown on the home page |
   | `CRON_SECRET` | 16+ random characters (protects `/cron/cleanup`) |
   | `QR_PUBLIC_SHOW_NAME`, `QR_PUBLIC_SHOW_SCHOOL` | `1` or `0` |

   Add `TRUSTED_IP_HEADER` = `X-Forwarded-For` only after reading "Client address" below.
   Type secrets only in Vercel's dashboard; never in Git, chat or email.
4. Press **Deploy**. The first build takes a few minutes. When it finishes, open the address Vercel gives you plus `/health`:
   `https://YOUR-PROJECT.vercel.app/health` must show `{"status":"ok","migrations_applied":N}`.
   - `database_unavailable`: wrong `DB_*` values or `DB_SSLMODE`, or the database does not accept connections from Vercel.
   - 502 error: see the troubleshooting list in Vercel's guide (the `Caddyfile` must listen on `$PORT`, which it does).
5. Set `APP_URL` to the real address (https), then redeploy (Deployments → ⋯ → Redeploy). Links in emails and QR codes use `APP_URL`.
6. Walk through `docs/TESTING_CHECKLIST.md` on the **Preview** deployment: register with a real email, verify, log in, fill the form, submit, admin review, QR scan from a phone, password reset.
7. Keep Vercel's **Deployment Protection** enabled for previews so test deployments are not public.
8. Custom domain: Project → Settings → Domains, then update `APP_URL`.

## Client address (rate limits behind Vercel's proxy)

Login, registration and reset limits are per client address. Behind Vercel the server sees the proxy's address, so without a fix everyone shares one limit.
Set `TRUSTED_IP_HEADER=X-Forwarded-For` **only if** your platform sets that header to the real visitor address and replaces any value a visitor sends.
Check Vercel's current documentation on request headers. Then test: make 5 wrong logins from one device; a second device on another network must still be able to log in.
If a visitor could forge the header, they could dodge the rate limits, so do not guess.

## Cleanup job

`vercel.json` asks Vercel Cron to call `/cron/cleanup` daily. The page only works when `CRON_SECRET` is set and the request carries
`Authorization: Bearer <CRON_SECRET>`. Check in Vercel's "Managing Cron Jobs" documentation that Vercel sends that header when a `CRON_SECRET` variable exists.
If it does not, the call returns 401 (visible in the logs) and nothing is harmed; you can run `D:\php\php.exe bin\cleanup.php` with the cloud `DB_*` values set instead. The portal also
removes expired rate-limit rows and sessions as it runs.

## Backups

Your provider's database backups are the main protection; check how long they keep them and practise a restore. You can also run `bin\backup.php` from your PC with the cloud `DB_*` values set (needs `pg_dump`).
See `docs/BACKUP_RESTORE.md`.

## Things to decide before real students use it

- **Where the data lives.** Vercel and most cloud databases are outside Pakistan. Student personal data (CNIC, addresses, family details) stored abroad may be restricted
  by IHA's rules or the law; get advice, and choose the database region deliberately.
- **Cost and limits** of your Vercel and database plans (including cold starts, connection limits and email sending limits).
- **A second pair of eyes** on security before launch: the code was written and tested only by the automated scripts in `tests/`.

## Updating the live site

Push to the `main` branch on GitHub and Vercel redeploys. For a risky change, push to another branch first: Vercel builds a Preview you can test.
```
git add .
git commit -m "describe the change"
git push
```
New numbered migration? Run `bin\migrate.php` against the right database first (Part B step 3).
