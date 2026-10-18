<?php
// Copy this file to config.php and fill it in. config.php is ignored by git — never commit it.
return [
  'db_host' => 'localhost',
  'db_name' => 'CPANEL_USER_babylog',
  'db_user' => 'CPANEL_USER_babylog_admin',
  'db_pass' => 'CHANGE_ME',

  'site_name' => 'Baby Daily Log',
  'site_url'  => 'https://babylog.example.com',
  'timezone'  => 'America/Chicago',

  // These emails are approved automatically and see the Admin panel.
  'admin_emails' => ['you@example.com'],
  // ntfy topic that pings you about new sign-ups ('' = off).
  'admin_ntfy_topic' => '',
  'ntfy_server' => 'https://ntfy.sh',

  // Email (create noreply@yourdomain in cPanel → Email Accounts).
  // On cPanel shared hosting, localhost:25 with login works without certificate problems.
  'mail_from'      => 'noreply@example.com',
  'mail_from_name' => 'Baby Daily Log',
  'smtp_host'      => 'localhost',   // '' = use PHP mail()
  'smtp_port'      => 25,
  'smtp_secure'    => 'none',        // 'ssl' (465), 'tls' (587) or 'none'
  'smtp_user'      => 'noreply@example.com',
  'smtp_pass'      => 'CHANGE_ME',
];
