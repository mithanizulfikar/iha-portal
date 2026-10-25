# Backup and restore

All student data lives in the PostgreSQL database. Losing it means losing every registration, so backups are not optional.

## What to back up

| What | Where | How |
|---|---|---|
| Database (everything) | PostgreSQL `iha_portal` | `bin/backup.php` (below) |
| `.env` (passwords, `APP_KEY`, SMTP) | project folder | copy it to a safe place **separately** from the database backups |
| Uploaded files | `storage/uploads/` | empty today (photo upload is not built yet); copy the folder once it is used |

Keep `APP_KEY` safe. If it is lost, QR codes already issued still verify, but they can no longer be redrawn on screen and must be replaced from the admin page.

## Taking a backup

```
D:\php\php.exe bin\backup.php
```
It writes `storage\backups\iha-YYYYMMDD-HHMMSS.dump` (compressed PostgreSQL format), checks the file can be read, and deletes copies older than
`BACKUP_KEEP_DAYS` (default 30). If it says `pg_dump` was not found, set `PG_DUMP_PATH` in `.env`, for example
`PG_DUMP_PATH=C:\Program Files\PostgreSQL\16\bin\pg_dump.exe`.

**A backup on the same disk is not a backup.** Copy the `.dump` files to another computer, an external drive or cloud storage every day, and keep at
least one copy off-site. Treat the files as confidential: they contain every student's personal data (store them encrypted or in access-controlled storage).

### Run it every day

Windows (Task Scheduler, 2 a.m. daily):
```
schtasks /create /sc daily /st 02:00 /tn "IHA Backup" /tr "D:\php\php.exe D:\Projects\iha-portal\bin\backup.php"
```
Linux (cron, `crontab -e`):
```
0 2 * * * cd /var/www/iha && php bin/backup.php >> storage/logs/backup.log 2>&1
30 2 * * * cd /var/www/iha && php bin/cleanup.php >> storage/logs/cleanup.log 2>&1
```
Also take one by hand before every upgrade (before `bin\migrate.php`).

## Restoring

Practise this on a spare machine or a scratch database BEFORE you need it.

1. Create an empty database and user (or use a fresh scratch database):
   ```sql
   CREATE DATABASE iha_restore OWNER iha_app;
   ```
2. Restore the backup (adjust the path to `pg_restore`):
   ```
   "C:\Program Files\PostgreSQL\16\bin\pg_restore.exe" --no-owner --role=iha_app --host=127.0.0.1 --username=iha_app --dbname=iha_restore storage\backups\iha-20261024-020000.dump
   ```
3. Point a copy of `.env` at it (`DB_NAME=iha_restore`) and start the site. Check `http://localhost:8080/health` says `ok`, then log in as an
   administrator and open **Applications** and **Reports**: the counts should match what you expect.
4. Run `D:\php\php.exe bin\migrate.php`. It does nothing if the backup was already up to date, and applies any newer migrations otherwise.

To recover the live system, stop the site, restore into the real database name (drop or rename the damaged one first), then start it again.
Anything saved after the backup was taken is lost, which is why daily backups matter.

## Test it

Once a month: restore the latest off-site copy into a scratch database and check the Applications count. A backup you have never restored is only a hope.
