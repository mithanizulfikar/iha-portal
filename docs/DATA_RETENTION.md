# Data retention and deletion (policy template for IHA to decide)

**This is a template, not legal advice.** The periods below are placeholders in square brackets. IHA (with legal advice) must decide them and
publish the result in the Privacy Policy. The portal does **not** delete any student record automatically.

## What is kept

| Data | Why it is kept | Suggested handling |
|---|---|---|
| Account (name, email, password hash) | to log in and contact the student | keep while the application or admission is active, then [__ months/years] |
| Student profile, academic, parent/guardian, travel details | processing the application | keep for [__ years] after the final decision, then delete or anonymise |
| Eligibility results, overrides, review notes | fairness and appeals | keep with the application for [__ years]; admin-only |
| Audit log | accountability; **cannot be edited** | keep for [__ years]; contains administrator actions and ids, not passwords |
| QR codes and scan log | verification | codes: with the application; scan log: pruned by `bin/cleanup.php` (default 180 days) |
| Technical records (rate limits, used or expired tokens) | security | pruned automatically by `bin/cleanup.php` |
| Backups | disaster recovery | `BACKUP_KEEP_DAYS` (default 30); remember deleted data also remains in older backups until they expire |

## Deletion and correction requests

- Decide who handles requests (a named role) and how long they have to answer.
- Corrections: an administrator returns the application for correction so the student can edit it (this is recorded).
- Deletion: not built as a button, on purpose, because the database protects history (the audit log and evaluation history are append-only).
  A deletion needs a documented procedure run by a database administrator after a backup, and must also cover backups, exported CSV files and printed copies.
  Ask for this to be built as a tested tool before the first real deletion request.

## Things to settle

1. The periods in the table above.
2. Whether rejected or withdrawn applications are kept shorter than approved ones.
3. What happens to exported CSV files and printed records (store them securely, delete them when the task is done).
4. Who may see personal data (`pii.view`): give it only to people who need it. Report viewers see aggregate statistics only.
5. A breach plan: who is told, and how quickly, if data is exposed.
