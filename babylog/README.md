# babylog — multi-family version

Families sign up with their email, confirm it with a 6-digit code, and wait for the admin to approve them. Each family adds its baby and invites a partner or grandparents with a one-time invite code.

## Files

| File | Purpose |
|---|---|
| `index.html` | The whole app UI: sign-in screens, dashboard, charts and Family settings |
| `api.php` | JSON API: accounts, approval, babies, invites, log entries, diaper pickups |
| `lib.php` | Database, SMTP email and ntfy helpers |
| `notify.php` | Cron script that sends phone alerts for every baby with alerts turned on |
| `schema.sql` | Creates all `bl_*` tables (new installs) |
| `migrations/` | Upgrades for existing databases: `001_log_notify.sql` (diaper-changed alerts), `002_supplies.sql` (free diaper pickups), `003_weight_decimals.sql` (exact lb + oz weights). Run them in order in phpMyAdmin. |
| `config.example.php` | Template for `config.php` |
| `.htaccess` | Forces HTTPS and hides private files |
| `tools/import_from_zara.sql` | Copies entries from the standalone `zara` table into this app |

## Setup (cPanel)

1. **Subdomain:** go to Domains, create `babylog.yourdomain` with "Share document root" unchecked. Check that SSL shows as Active.
2. **Database:** in MySQL Databases, create a database and a user, and give the user ALL PRIVILEGES. In phpMyAdmin, select the database, open the SQL tab, paste `schema.sql` and click Go.
3. **Email:** in Email Accounts, create `noreply@yourdomain`. In Email Deliverability, SPF and DKIM should be green. Adding a DMARC TXT record (`_dmarc` → `v=DMARC1; p=none;`) also helps keep mail out of spam.
4. **Config:** copy `config.example.php` to `config.php` and fill in the database, SMTP password and your admin email.
5. **Upload:** put all files in the subdomain folder.
6. **First account:** open the site, create an account with your admin email, enter the code, and add your baby.
7. **Phone alerts:** in Cron Jobs, add a job that runs every 5 minutes:
   ```
   /usr/local/bin/php /home/CPANEL_USER/babylog.yourdomain/notify.php >/dev/null 2>&1
   ```
   Each family turns on alerts in Family → Alerts and subscribes to the topic shown there in the ntfy app.

## Approving people

New sign-ups email the admin and, if `admin_ntfy_topic` is set, also ping it on ntfy. To approve or reject them, go to Family → Admin: accounts. Approved people get an email.

## If email doesn't arrive

On Namecheap shared hosting, `smtp_host => 'localhost'`, port 25 and `smtp_secure => 'none'` work reliably. `mail.yourdomain` on port 465 may fail the certificate check. Ask new users to check their spam folder for the first code.
