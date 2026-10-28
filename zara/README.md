# zara — single-family version

A one-baby tracker opened with a shared password, for the parents' phones.

## Two ways to run it

- **Shared mode (current setup):** `config.php` points at the babylog database and sets `baby_id` and `user_id`. The site then reads and writes `bl_logs` for that one baby, so it always matches babylog. Entries made here show as logged by `user_id`.
- **Standalone:** leave out `baby_id`. The site uses its own `baby_logs` table, created by `schema.sql` in its own database.

## Files

| File | Purpose |
|---|---|
| `index.html` | The app UI. The baby's birth date and time are set in the `BIRTH` constant near the top of the script, and sex (for growth percentiles) in `SEX`. |
| `api.php` | Password login, log entries, and free diaper pickups (shared mode only, uses babylog's `bl_supplies`) |
| `notify.php` | Cron script for ntfy phone alerts. Run `php notify.php test`, or open `notify.php?key=…&test=1`, to send a test alert. |
| `schema.sql` | Standalone table `baby_logs` |
| `migrations/` | Upgrades for databases created before weight and pumping existed |
| `config.example.php` | Template for `config.php` |
| `.htaccess` | Forces HTTPS and hides private files |

## Setup (cPanel)

1. Create the subdomain and check that SSL is active.
2. Copy `config.example.php` to `config.php` and fill it in. For shared mode, use the babylog database details and set `baby_id` and `user_id`.
3. Upload the files.
4. Add a cron job that runs every 5 minutes:
   ```
   /usr/local/bin/php /home/CPANEL_USER/zara.yourdomain/notify.php >/dev/null 2>&1
   ```
5. In the ntfy app, subscribe to the `ntfy_topic` you set, then open the test link.

Don't also turn on phone alerts for the same baby in babylog, or every alert will arrive twice.
