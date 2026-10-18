# Baby Daily Log

A small, self-hosted web app for tracking a newborn's day: feeds, diaper changes, pumping and weight, with charts and phone alerts. It runs on ordinary cPanel shared hosting with PHP and MySQL/MariaDB. There's no build step and no framework.

This repository holds two sites that share one database:

| Folder | Live at | What it is |
|---|---|---|
| [`babylog/`](babylog/) | babylog.msshakib.com | Multi-family version. People sign up with email and a 6-digit code, the admin approves them, and families share a baby through invite codes. |
| [`zara/`](zara/) | zara.msshakib.com | Single-family version for one baby, opened with one shared password. In shared mode it reads and writes the same tables as `babylog`, so both sites always show the same entries. |

## Features

- **Logging:** feeds (breast L/R, breast-milk bottle, formula), diapers (wet / dirty / both), pumping (time and ml), weight (kg or lb, stored in grams). Every entry can be edited or deleted.
- **Today at a glance:** time since the last feed, feeds and ml today, wet and dirty counts, and time since the last wet or dirty diaper.
- **Charts:** last 7 days, day-to-day change, milk intake, diaper changes, pumping and weight. Ranges run from Today, Yesterday, 3 days, 1 week, 1 month and 3 months to custom dates.
- **Diaper frequency:** how often any, wet and dirty diapers are changed.
- **Age and birthday:** shown in the header, for example "1 week, 5 days old · day 13".
- **Alerts:** an in-page banner when a diaper is overdue (2 h 15 min by default), plus phone notifications through the free [ntfy](https://ntfy.sh) app, run by a cron job.
- **Design:** a nursery theme with light and dark modes, works on phones, and uses only original SVG illustrations.
- **babylog only:**
  - email verification, admin approval and password reset by email
  - invite codes, several babies per account, and who logged each entry
  - CSV export, plus an option to delete your account

## Tech

- Frontend: a single `index.html` with plain HTML, CSS and JavaScript. Charts are hand-drawn SVG. Fonts are Quicksand and Nunito from Google Fonts.
- Backend: `api.php`, a JSON API using PDO prepared statements, sessions and bcrypt password hashes.
- Email: a small built-in SMTP client in `babylog/lib.php`, no Composer needed.
- Alerts: `notify.php`, run by cron every 5 minutes, posting to ntfy.

## Quick start

See each folder's README:

- [babylog/README.md](babylog/README.md)
- [zara/README.md](zara/README.md)

In short:

1. Create a subdomain in cPanel and make sure SSL is active.
2. Create a MySQL database and user, then run `schema.sql` in phpMyAdmin.
3. Copy `config.example.php` to `config.php` and fill it in.
4. Upload the folder's files, including the hidden `.htaccess`.
5. Add the cron job for `notify.php`.

## Security notes

- `config.php` holds real passwords and is excluded by `.gitignore`. Never commit it. If one was ever pushed, change those passwords.
- `.htaccess` forces HTTPS and blocks web access to `config.php`, `.sql` and `.md` files and to `.git`.
- The API checks on every request that the logged-in user belongs to the baby being read or changed.
- Logins, code checks and emails are rate-limited.
- Keep this repository **private**. It contains a child's name and birth date.
