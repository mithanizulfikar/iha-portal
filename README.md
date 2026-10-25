# IHA Student Registration & Eligibility Portal

Stages 1-9 of the build: foundation, schema, landing page, registration, email verification, login, the five-section student form,
review and submit, the eligibility engine, the admin applications list with review and override, and QR codes with public verification, admin dashboard, reports, CSV export, audit log, rule settings and printing.
Still to come: see "Not done yet" at the end of this file.

**Status of testing:** Stage 1-2 was run and verified on a real PostgreSQL (39 database checks pass). Stages 3 onward were written without PHP available, so run every test script after updating:
`php tests/marks_calc.php`, `php tests/eligibility_engine.php`, `php tests/db_smoke.php`, `php tests/auth_flow.php`,
`php tests/student_flow.php`, `php tests/submission_flow.php`, `php tests/qr_flow.php`, `php tests/reports_flow.php`, `php tests/password_flow.php`, `php tests/admin_users_flow.php`, `php tests/session_handler.php` and `php tests/privacy_scan.php`. The full list, with how each acceptance test is covered, is in `docs/TESTING_CHECKLIST.md`.

## Local setup (XAMPP + VS Code on Windows)

1. **PostgreSQL 15+**: install separately (XAMPP does not include it). Note the `postgres` password.
2. **Enable the PostgreSQL driver in XAMPP PHP**: in `xampp/php/php.ini` uncomment
   `extension=pdo_pgsql` and `extension=pgsql`, then restart Apache. Confirm with `php -m | findstr pgsql`.
   PHP must be 8.2 or newer (`php -v`).
3. **Create the database and an application user** (psql or pgAdmin):
   ```sql
   CREATE ROLE iha_app LOGIN PASSWORD 'choose-a-strong-password';
   CREATE DATABASE iha_portal OWNER iha_app;
   ```
4. **Configure**: copy `.env.example` to `.env` and fill in the `DB_*` values (and SMTP later).
5. **Install dependencies**: `composer install`. (Stages 1-2 also run without Composer; the QR and mail libraries are needed from Stage 3/5.)
6. **Migrate and seed**:
   ```
   php bin/migrate.php
   php bin/seed.php
   ```
   The seed inserts the 7 provinces/territories, admin permissions, and eligibility rule v1
   (DOB 2009-02-02 to 2012-02-02 inclusive, minimum 60%).
7. **Create the first Super Admin**: `php bin/create-admin.php you@example.com "Your Name" super_admin`
8. **Run**: `php -S localhost:8080 -t public`, then open `http://localhost:8080/health`; it should report the migrations applied.
9. **Check constraints**: `php tests/db_smoke.php` (runs in a rolled-back transaction).

## Stage 3: what was added

Pages: `/` landing, `/register`, `/verify-email`, `/login`, `/admin/login`, `/student`, `/admin`, `/privacy`, `/terms`.

To upgrade an existing install: copy the new files over the project folder (your `.env` is not touched), then add the new
lines from `.env.example` (`MAIL_DRIVER`, `SUPPORT_EMAIL`, `SUPPORT_PHONE`) to your `.env`. No new migration is needed.

**Testing registration locally:** with `MAIL_DRIVER=log` no email is sent; open `storage/logs/mail.log` and copy the
verification link from the newest entry into your browser.

Behaviour worth knowing:
- Verification links work once, expire after 24 hours, and a new link replaces older ones. Only the token hash is stored.
- The link opens a confirm page; the button (POST) does the verification, so email scanners that pre-fetch links can't use up the token.
- After verifying, the student logs in with their own password (no automatic login from an emailed link). This blocks "pre-hijacking", where someone registers your email with their own password.
- Registering an email that already exists shows the same "check your email" page and never overwrites the existing password.
- The permanent Student ID is created when the email is verified, so unverified sign-ups never use up IDs.
- Rate limits: login 5 failures per 15 min per email+IP (30 per IP); registration 10 per hour per IP; resend 3 per hour per address.
- Sessions: HttpOnly, SameSite=Lax, ID regenerated at login, 30-minute idle timeout. All POST forms need a CSRF token.
- The privacy and terms pages are draft text for IHA to review.
- Behind a reverse proxy, make sure the web server sets the real client IP in `REMOTE_ADDR`; forwarded headers are deliberately not trusted.

## Stage 4: the five-section student form

Pages (verified students only): `/student/profile`, `/student/academic`, `/student/marks`, `/student/guardian`, `/student/travel`
(`/student/application` opens the first). JSON lookups for the dropdowns: `/api/geo/districts`, `/api/geo/cities` (login required).

No new migration. Copy the new files over the project; nothing in `.env` changes.

- **Drafts:** every section saves independently ("Save Draft" or "Save & Continue"). Anything you fill in must be valid
  (formats, dates, province/district/city match); empty fields are fine in a draft. If any field is invalid, nothing from that section is saved.
- **Completion:** each section counts as complete when its required (*) fields are filled; the dashboard shows 0-100%.
  This is about form-filling only.
- **Dropdowns:** Province -> District -> City, each enabled only after the one above it; changing a parent clears the children.
  "Other" appears only in City lists and reveals "Enter City/Locality Name" (mandatory while selected, saved in `city_other`, never added to `cities`).
  The server rejects any combination that doesn't match the database.
- **Marks:** percentages recalculate live in the browser and again on the server (integer maths, exact 60% comparison available via `MarksCalculator::meetsMinimum`).
  Bad rows (obtained above total, zero total, negative, text) block the save; unfinished rows are kept but never counted.
- **Travel:** if all four answers are No the trip fields are hidden and any stored trips are removed.
- **Privacy:** none of these pages reads the eligibility tables, and saving the form creates no eligibility records.

Not in this stage (still to do): student photograph upload, multiple earlier academic-history records
(the form captures the single most recent examination that drives eligibility), the review/submit page, and printable reports.

**Testing the dropdowns before you have a real dataset:** `tests/fixtures/geo_TEST_ONLY.csv` has a handful of well-known districts
so the dropdowns have something to show. It is NOT a complete or verified list. Use it only on a development database:
```
php bin/import-geo.php --file=tests/fixtures/geo_TEST_ONLY.csv --source-name="TEST FIXTURE (not verified, dev only)" --coverage="12 sample districts" --admin-level="district+city" --dataset-date=2026-10-18
```

## Stage 5a: submit, eligibility, admin review

No new migration. Copy the files over; nothing in `.env` changes.

**Student side:** `/student/review` shows everything entered (with a declaration checkbox and **Submit application**), `/student/submitted` is the
confirmation page. Submitting requires all five sections complete, a verified email and the declaration. Double clicks and retries are safe
(the application row is locked, the second request finds it already submitted). After submission the student sees only the workflow status
(SUBMITTED, UNDER REVIEW, ...) and neutral notifications; **no page, email, notification or student-facing query reads or shows eligibility**.

**Eligibility** (`src/Eligibility/`): rule v1 comes from the `eligibility_rules` table: DOB 2009-02-02 to 2012-02-02 (inclusive) and at least 60%.
The percentage is recalculated on the server from the stored subjects and compared exactly (59.995% displays as 60.00 but fails).
Outcomes: ELIGIBLE (both pass), INELIGIBLE (any completed criterion fails, even if the other is still pending), PENDING REVIEW (nothing failed but something can't be decided).
Every evaluation is kept as history; the current effective status is stored separately.

**Admin side** (`/admin/applications`, needs the permission shown):
- List (`applications.view`): search, filters (status, eligibility, province/district/city, school, percentage, dates, QR), 25 per page, flags for eligibility and each criterion, OVERRIDDEN marker.
  Columns and filters that expose personal data need `pii.view`; eligibility columns need `eligibility.view`.
- Detail: calculated result with criteria and reason, history, student data, review history, internal notes, audit trail (`audit.view`).
- Review (`applications.review`): start review, approve, reject, return for correction (reason required; the optional message to the student is the only text the student sees).
  **Approval is blocked unless the effective eligibility is ELIGIBLE.** Recalculate eligibility is also here.
- Override (`eligibility.override`, Super Admin by default): resolves an INELIGIBLE or PENDING result with a documented reason (10+ characters).
  The calculated result is never edited; the override is a separate audited record and stops applying if a recalculation changes the calculated outcome.

Not yet in this part: QR code issuance/revocation (the hook points are marked in `EligibilityService`), admin dashboard statistics, reports, CSV export, printing.

## Stage 5b: QR codes and public verification

**One-time setup (needed before codes can be issued):**
1. Install the dependencies. Enable `extension=zip` in `D:\php\php.ini` (remove the `;`), install Composer from getcomposer.org
   (its installer asks for the PHP path: `D:\php\php.exe`), then in the project folder run `composer install --no-dev`.
2. Set a real secret in `.env`: generate one with `D:\php\php.exe -r "echo bin2hex(random_bytes(32));"` and paste it as `APP_KEY=...`.
   The placeholder from `.env.example` is refused. Keep this key safe and stable.
3. Apply the new migration: `php bin\migrate.php` (adds `token_salt`).
4. Issue codes for applications that were submitted before this stage: `php bin\qr-sync.php`.

**When an application holds an ACTIVE QR code** (re-checked automatically after submission, recalculation, override, review, and admin actions):
effective eligibility is ELIGIBLE, status is SUBMITTED / UNDER REVIEW / APPROVED (not a draft, not returned for correction, not rejected),
all five sections are complete, and the account is active with a verified email. If any of these stops being true the code is revoked;
if they hold again a new code is issued. An unchanged eligible student keeps the same code.

**How tokens are stored:** the QR holds a random-looking token that is never saved. It is `HMAC-SHA256(APP_KEY, salt)`; the database keeps only the salt
and the token's SHA-256 hash, so a stolen database cannot reveal valid tokens. Changing `APP_KEY` later does not break codes already printed or
scanned (verification only needs the hash), but those codes can no longer be redrawn on screen: replace them from the admin page.

**Public page** `/verify?token=...` shows VALID or INVALID. For VALID it shows the Student ID, and the student's name and school (switch these
off with `QR_PUBLIC_SHOW_NAME=0` / `QR_PUBLIC_SHOW_SCHOOL=0`). Unknown, malformed and revoked codes all give the same bare INVALID. Scans are logged
(hashed IP, no token) and rate-limited.

**Students** see the code on their dashboard (download as SVG, or a printable ID card at `/student/id-card`) with no eligibility wording. When
there is no code they see only "No verification QR Code is currently available for this account." with no reason.
**Admins with `qr.manage`** (Super Admin by default) can view, download, revoke (reason required) and replace codes from the application page.

## Stage 6: dashboard, reports, exports, audit log, rules, printing

No new migration. Copy the files over; nothing in `.env` changes.

- **Admin dashboard** (`/admin`): live counts from the database (students, verified/unverified, applications, drafts, submitted, under review, approved,
  rejected; and, for roles with eligibility access, eligible / ineligible / pending and active / revoked QR codes). The "How these are counted" note on the page
  defines each figure (a student with two applications counts twice under applications; "submitted" means submitted at least once; drafts are never counted as ineligible).
- **Reports** (`/admin/reports`): Eligibility (eligibility roles only), Academic, Registration. Filters: date range, province, district, city, school.
  Charts use Chart.js from jsDelivr (pinned version). Tables are paginated where long (schools). Print button on every report.
- **CSV export** (`applications.export`): the applications list (with the current search/filters) and each report table. Personal-data and eligibility columns are
  included only for roles allowed to see them; spreadsheet formulas in cells are neutralised; every export is audit-logged.
- **Audit log** (`/admin/audit`, `audit.view`, Super Admin by default): filter by action, role, entity and dates; entries cannot be edited or deleted.
- **Eligibility rules** (`/admin/rules`, `eligibility.rules`, Super Admin only): publish a new version of the date-of-birth range and minimum percentage. Old versions
  stay in the history. Optionally re-evaluate waiting (submitted / under review) applications right away; their QR codes follow the new result.
- **Printing:** students get `/student/academic-report` (details and marks, no eligibility) and a Print button on their application; admins get Print on the
  application page and on reports. Print styles hide navigation, buttons and forms.

## Stage 7a: Forgot password and Change password

Apply the new migration first: `php bin\migrate.php` (adds `password_reset_tokens` and `users.password_changed_at`).

- **Forgot password** (`/forgot-password`, linked from the student login page): the student enters their email; if it belongs to an active student account a
  one-time link is emailed (expires in 60 minutes; asking again replaces the older link). The page gives the same answer for registered and unregistered
  addresses, and requests are rate-limited (3 per hour per address, 10 per hour per network). Only a hash of the token is stored.
  A weak password does not use up the link. Administrator accounts are not reset by email.
- **Change password** (`/account/password`, link in the top bar when signed in; students and administrators): needs the current password
  (5 wrong attempts lock it for 15 minutes), a different new password that follows the same rules (10+ characters, a letter and a number).
- **Other devices are signed out** whenever a password changes (reset, change, or `bin\set-password.php`); the device that made the change stays signed in.
- A "your password was changed" email (no password, no link) goes to the account holder, and every change is audit-logged.
- **Command line:** `D:\php\php.exe bin\set-password.php user@example.com` sets a new password for any account (for example a forgotten administrator password).
  Administrator passwords need 12+ characters.

## Stage 7c: Administrator accounts

Apply the new migration: `php bin\migrate.php` (adds `users.must_change_password`). Super Admin only (`users.manage`): **Admins** in the top bar, `/admin/users`.

- **Add an administrator** (name, email, role). A 16-character temporary password is generated and shown to you **once**; it is stored only as a hash. The new
  administrator is sent to "Change password" at first login and cannot use the portal until they choose their own.
- **Change role**, **suspend / reactivate** (a suspended administrator is signed out at once) and **reset password** (a new temporary password, shown once; their other sessions end).
- Safety rules: you cannot change, suspend or reset yourself here, and at least one active Super Admin must always remain.
- Every action is audit-logged (the audit log never contains passwords). Student accounts are not managed on this page.

## Operations documents (stage 8)

- `docs/DEPLOYMENT.md`: requirements, installing on a real server (Apache/nginx or `deploy/Dockerfile`), production `.env`, going-live checklist, upgrades, and why Vercel is not a fit.
- `docs/BACKUP_RESTORE.md` and `bin/backup.php`: daily database backups (verified and pruned), off-site copies, scheduling, and how to restore and test a restore.
- `bin/cleanup.php`: daily housekeeping for expired tokens, rate-limit rows and old QR scan logs (never student records).
- `docs/DATA_RETENTION.md`: a policy template for IHA to complete.
- `docs/TESTING_CHECKLIST.md`: automated scripts, acceptance-test coverage and the manual browser walk-through.
- `tests/privacy_scan.php`: static scan proving student-facing pages, emails and scripts contain no eligibility wording.
- HSTS is sent when the site is served over https. The deployment files in `deploy/` are untested starting points.

## Stage 9: Git and Vercel

Apply the new migration first: `php bin\migrate.php` (adds the optional `sessions` table). Everything is described step by step in `docs/GIT_AND_VERCEL.md`.

- New: `Dockerfile.vercel`, `Caddyfile`, `vercel.json` (FrankenPHP container, cron), database sessions (`SESSION_DRIVER=database`, off by default so local use is unchanged),
  `TRUSTED_IP_HEADER` for the real client address behind a proxy, `/cron/cleanup` (needs `CRON_SECRET`), and `src/Core/Housekeeping.php` (shared by `bin/cleanup.php` and the cron endpoint).
- `.gitignore` now also excludes `composer.phar`, `.env.*`, `.vercel` and `*.zip`.
- Not yet verified on a real Vercel project.

## Email (SMTP)

See `docs/EMAIL_SETUP.md`. In short: set `MAIL_DRIVER=smtp` and the `SMTP_*` values in `.env`, then run `php bin\mail-test.php you@example.com`.
SMTP connections time out after 15 seconds and a failed send never blocks registration, reset requests or submission.

## Not done yet (from the original specification)

Student photograph upload; multiple earlier academic-history records; real email delivery in production (set up with `docs/EMAIL_SETUP.md`; until then mail goes to `storage/logs/mail.log`);
the verified Pakistan districts and cities dataset; reviewed Privacy Policy and Terms text; the IHA logo file; applying the database privacy roles in `database/hardening/roles.sql`
(and switching the app to two database connections); 

## Geography data (districts and cities)

No district or city list is bundled, because the spec requires a *verified* dataset and I can't vouch for one I haven't seen.
Provide a CSV with the header `province,district,city` (city may be blank) from an official or well-documented source, then:

```
php bin/import-geo.php --file=data/pk_geo.csv --source-name="<dataset name>" --source-url="<url>" \
    --coverage="<what it covers>" --admin-level="district+city" --dataset-date=YYYY-MM-DD
```

Provenance is mandatory and recorded in `geo_dataset_sources`. The import is transactional and re-runnable.
Province names must match the seeded ones (common aliases such as KPK/ICT/AJK are handled).

## Design decisions worth knowing

- **Student ID** comes from the `student_id_seq` sequence as a column default, so concurrent registrations can't collide; a trigger makes it immutable.
- **Geography integrity is enforced by the database**, not just the app: composite foreign keys force district into province and city into district; `city_id` and `city_other` can never both be set; "Other" names live only in `city_other` columns, never in `cities`.
- **Eligibility is split three ways**: immutable calculated history (`eligibility_results`), immutable manual overrides (`eligibility_overrides`), and a small `application_eligibility` table holding the current effective status. A CHECK constraint rejects a status that contradicts its criteria. Rule versions are frozen once created.
- **One ACTIVE QR per application** is a partial unique index; tokens are stored only as SHA-256 hashes.
- **Audit, evaluation and review history are append-only** via triggers.
- **Privacy at database level (optional, recommended):** `database/hardening/roles.sql` creates a student role with no access to eligibility, review-note, audit or scan-log tables. Use it in production so a bug in a student page cannot leak eligibility data.
- **Drafts** may hold incomplete subject rows; `academic_subjects.is_valid` marks rows that count, and the PHP service recomputes everything server-side.

## Hosting note

Vercel does not run PHP natively. Use a PHP host with PostgreSQL access (VPS, or a container platform with an Apache/nginx + PHP-FPM image), and point the web root at `public/`. If Vercel is mandatory, host the PHP backend separately and document that split.

## Layout

```
bin/        CLI: migrate, seed, import-geo, create-admin
config/     (reserved for later stages)
database/   migrations/, seeds/, hardening/
public/     web root (front controller, .htaccess)
src/        Core/ now; Auth, Student, Academic, Eligibility, QR, Admin, Reports in later stages
storage/    protected uploads and generated files (outside the web root)
tests/      db_smoke.php, auth_flow.php, password_flow.php, admin_users_flow.php, session_handler.php, student_flow.php, submission_flow.php, qr_flow.php, reports_flow.php, marks_calc.php, eligibility_engine.php
```

## Next stages

3. (done) Registration, email verification, login, CSRF, rate limiting, landing page; password reset still to add
4. (done) Five-section student portal with dependent dropdowns and marks table
5. (done) Submission, eligibility engine, admin review, QR module and public verification
6. Admin portal, reports, CSV export, printing
