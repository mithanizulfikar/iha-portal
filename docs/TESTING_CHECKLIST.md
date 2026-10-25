# Testing checklist

Two kinds of checks: **automated** scripts (run them from the project folder) and a **manual** walk-through in the browser. The automated scripts talk
to the real database but roll everything back; run them on a development or copy database, not on live data.

## 1. Automated scripts (all should end with `0 failed`)

```
php tests/marks_calc.php          marks arithmetic, exact 60% comparison
php tests/eligibility_engine.php  the eligibility rules and the specification's boundary table
php tests/privacy_scan.php        student-facing pages/emails/scripts contain no eligibility wording
php tests/db_smoke.php            database rules (Student ID, geography, history tables, QR uniqueness)
php tests/auth_flow.php           registration, verification, login, rate limits
php tests/password_flow.php       forgot / change password
php tests/student_flow.php        the five form sections, geography validation, completion
php tests/submission_flow.php     submit, eligibility recorded, privacy of notifications/emails, admin review and override
php tests/qr_flow.php             QR issue / revoke / replace, public verification
php tests/reports_flow.php        dashboard counts, reports, CSV rules, rule versions, audit log
php tests/admin_users_flow.php    administrator accounts
```
Note: `qr_flow.php` skips the image check if the QR library is not installed (`composer install --no-dev`).

## 2. How the specification's acceptance tests (section 17) are covered

"Auto" = an automated script above; "Manual" = do it in the browser (section 3 below).

| Test | Coverage |
|---|---|
| A. A new student can register | Auto (`auth_flow`) + Manual 1 |
| A. Verification email delivered | Auto (message created) + Manual 2 for real delivery |
| A. Unverified accounts cannot use the submission workflow | Auto (`submission_flow`) + Manual 3 |
| A. Duplicate email handled | Auto (`auth_flow`, `db_smoke`) |
| A. Unique permanent Student ID | Auto (`db_smoke`, `auth_flow`) |
| A. Simultaneous registrations cannot give duplicate IDs | **Manual 4** (guaranteed by a database sequence plus a UNIQUE constraint; try two sign-ups at the same moment) |
| A. Students cannot edit their Student ID | Auto (`db_smoke` trigger) + Manual 5 (no such field on any form) |
| B. Provinces/territories appear; districts load by province; cities by district | Manual 6 |
| B. Changing province clears district and city; changing district clears city | Manual 6 |
| B. "Other" only in city lists; text box only when Other is chosen | Manual 6 |
| B. Student and school locations save and reload | Auto (`student_flow`) + Manual 6 |
| B. Invalid province/district/city combinations rejected | Auto (`student_flow`, `db_smoke`) |
| B. Custom city names never added to the official data | Auto (`student_flow`) |
| C. Subject and overall percentages; invalid marks rejected; server recalculates | Auto (`marks_calc`, `student_flow`, `submission_flow`) |
| C. Missing marks never give a wrong final result | Auto (`eligibility_engine`) |
| D. The six boundary cases and 59.99% / 60% | Auto (`eligibility_engine`, `submission_flow`) |
| D. Incomplete applications, missing/invalid academic data, invalid DOB, failed criterion with other data missing | Auto (`eligibility_engine`) |
| D. Recalculation after a correction; rule changes and version history | Auto (`submission_flow`, `reports_flow`) |
| E. Dashboard/pages/scripts/emails/notifications never show an eligibility result | Auto (`privacy_scan`, `submission_flow`) + Manual 7 |
| E. Students cannot reach results by direct URL or API | **Manual 8** |
| E. Authorised admin roles see results by permission; others do not | Auto (permission checks in `submission_flow`, `reports_flow`) + Manual 9 |
| E. Public QR page shows no marks, criteria or private data | Auto (`qr_flow`) + Manual 10 |
| E. Application status stays separate from eligibility | Auto (`submission_flow`) |
| F. QR only for eligible, complete, verified applications; revoked on change; replace | Auto (`qr_flow`) + Manual 10 |
| G. Search by Student ID; filters; dashboard counts; eligibility report categories | Auto (`submission_flow`, `reports_flow`) |
| G. CSV contents; unauthorised users blocked; read-only cannot change; overrides need a reason; audit log | Auto (`reports_flow`, `submission_flow`, `admin_users_flow`) + Manual 9, 11 |

## 3. Manual walk-through

Use a test server or the local one. Keep `storage/logs/mail.log` open if email is not set up yet.

1. **Register** with a new email; you land on "check your email". Open the link from the email (or the log), press the confirm button, then log in. You should see a Student ID like `IHA-PK-00000012`.
2. **Real email:** with SMTP set up (`docs/EMAIL_SETUP.md`), the verification and password-reset emails arrive in an inbox (check spam).
3. **Unverified:** register a second account but do not verify it. Log in: the dashboard asks you to verify, and `/student/profile` sends you back to the dashboard.
4. **Duplicate IDs:** register two accounts at the same moment (two browsers, press the button together). Both get different IDs.
5. **Student ID:** open every section; the ID is shown but cannot be typed into.
6. **Dropdowns:** choose a province (districts appear), a district (cities appear plus "Other"); change the province (district and city are cleared); choose Other (a name box appears and is required); choose a normal city again (the box disappears and its text is cleared). Save, reload: values are kept. Repeat for permanent address, school and guardian.
7. **Student privacy:** as a student, open the dashboard, review page, academic report, ID card, and the emails/notifications. Use "View page source" and the browser's Network tab. Search for `eligible`, `ineligible`, `pending`, `criteria`: none may appear (the public home page's general criteria are fine).
8. **Direct URLs:** while logged in as a student, open `/admin`, `/admin/applications`, `/admin/reports`, `/admin/audit`, `/admin/rules`, `/admin/users`: each must refuse (403) or send you to the login page.
9. **Roles:** log in as a Report Viewer (no applications, no eligibility, no personal data), as an Application Reviewer (reviews and sees eligibility, cannot override, change rules, manage QR or users), and as a Super Admin. Confirm each can do only what is described in `README.md`.
10. **QR:** submit an eligible application: the dashboard shows a QR code; scan it with a phone: the page says VALID with the Student ID (and name/school if enabled) and nothing else. In admin, revoke the code: scanning the same code now says INVALID. Submit an ineligible application: no QR.
11. **Admin flow:** open Applications, filter by eligibility, open a record, return it for correction, resubmit as the student, approve it; export CSV and open it in a spreadsheet (check personal columns are absent for roles without access); look at the audit log.
12. **Passwords:** "Forgot your password?" end to end; change password while logged in on two browsers (the other one must be signed out).
13. **Phone and tablet:** open the home page, the registration form and the marks table on a phone: nothing should be cut off.
14. **Print:** print the review page, academic report, ID card and an admin application record.

## 4. Not built yet (cannot be tested)

Student photograph upload; several earlier academic-history records; an automated browser test suite; multi-server session storage.
