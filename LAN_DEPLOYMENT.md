# TLH v1.2.71 — LAN Deployment Checklist

This build is intended for a trusted local network. Normal HTTP is intentionally allowed and application rate limiting is intentionally not enabled yet.

## Before replacing the website files

1. In phpMyAdmin, export a **fresh backup** of the current `leisure_hub` database and store it somewhere outside the TLH website folder.
2. Keep a copy of the previous working TLH ZIP so the application files can be rolled back if needed.
3. Verify that you know the administrator username/password. Change any account that still uses the old default password.

## Configure the database

1. Copy `config/local.example.php` to `config/local.php`.
2. Edit `config/local.php` and enter the database name, username, and password used by the LAN server.
3. `app_timezone` should remain `Asia/Manila` and `db_timezone` should remain `+08:00`.
4. For better separation, create a MySQL account dedicated to TLH instead of using the MySQL root account. Give it privileges only on the TLH database.

The application also accepts these environment variables when preferred: `TLH_DB_HOST`, `TLH_DB_PORT`, `TLH_DB_NAME`, `TLH_DB_USER`, `TLH_DB_PASS`, `TLH_APP_TIMEZONE`, `TLH_DB_TIMEZONE`, `TLH_APP_ENV`, and `TLH_BACKUP_DIR`.

## Copy the website

Copy the `tlh_website` folder into the Apache document root, for example:

`C:\xampp\htdocs\tlh_website`

This production package intentionally does **not** contain `install.php`, `database.sql`, a real `leisure_hub.sql` dump, or old SQL backups. The existing database must already be present/imported separately.

## LAN access

On the server PC, find the LAN IPv4 address (for example `192.168.1.50`). Other devices on the same trusted network can then open:

`http://192.168.1.50/tlh_website/`

and the admin portal at:

`http://192.168.1.50/tlh_website/admin/login.php`

Allow Apache through the Windows firewall for the **Private** network profile only. Do not add router port forwarding for this LAN deployment.

## Database backups

Admin > Database Backup remains available. By default, generated SQL backups are written to a private `tlh-private/backups` directory outside the Apache document root. If the server cannot write there, set an absolute writable `backup_dir` in `config/local.php`.

Always download important backups to a second computer or secure storage. Do not keep the only backup on the TLH server.

## Final smoke test

From a second LAN device, confirm:

- Home / Availability / Reserve / Track open normally.
- Admin login and Dashboard work.
- Create one unpaid dummy single reservation and one dummy batch.
- Record and verify a test payment/reference only if using a disposable test database; otherwise avoid financial test data in the live database.
- Test a discount, a reschedule, and the Enable Past Date Selection switch.
- Delete unpaid dummy records.
- Export a report and verify the file opens correctly.
- Create and download a database backup.

Once these pass, the LAN deployment is ready for day-to-day use.
