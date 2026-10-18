<?php
// Copy this file to config.php and fill it in. config.php is ignored by git — never commit it.
return [
  // Shared mode (recommended): point at the Baby Daily Log database so both sites show the same data.
  'db_host' => 'localhost',
  'db_name' => 'CPANEL_USER_babylog',
  'db_user' => 'CPANEL_USER_babylog_admin',
  'db_pass' => 'CHANGE_ME',
  'baby_id' => 1,   // the baby's id in bl_babies   (remove both lines to use the standalone baby_logs table)
  'user_id' => 1,   // your id in bl_users, used as "logged by"

  // Password typed on the login screen. Use 10+ characters.
  'app_password' => 'CHANGE_ME_TOO',

  // Phone alerts (ntfy app)
  'ntfy_topic' => 'pick-a-long-random-topic-name',
  'ntfy_server' => 'https://ntfy.sh',
  'site_url' => 'https://zara.example.com',
  'notify_key' => 'random-key-for-the-browser-test-link',
  'diaper_alert_min' => 135,
  'feed_alert_min' => 0,
  'alert_repeat_min' => 30,
  'timezone' => 'America/Chicago',
];
