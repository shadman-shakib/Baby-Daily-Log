<?php
// Zara's Daily Log — phone alerts through the ntfy app.
// Run by a cPanel cron job every 5 minutes:
//   /usr/local/bin/php /home/CPANEL_USER/zara.msshakib.com/notify.php
// Send a test notification:   php notify.php test
// Or from a browser:          https://zara.msshakib.com/notify.php?key=YOUR_NOTIFY_KEY&test=1
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$cli = PHP_SAPI === 'cli';

if (!$cli) {
  header('Content-Type: text/plain; charset=utf-8');
  $key = (string)($config['notify_key'] ?? '');
  if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
}
$test = $cli ? in_array('test', $argv ?? [], true) : isset($_GET['test']);

$topic  = (string)($config['ntfy_topic'] ?? '');
$server = rtrim((string)($config['ntfy_server'] ?? 'https://ntfy.sh'), '/');
$site   = (string)($config['site_url'] ?? '');
$diaperMin = (int)($config['diaper_alert_min'] ?? 135);   // 2 h 15 min
$feedMin   = (int)($config['feed_alert_min'] ?? 0);       // 0 = off
$repeatMin = (int)($config['alert_repeat_min'] ?? 30);    // repeat while still overdue
if ($topic === '') exit("Set ntfy_topic in config.php\n");

function send(string $server, string $topic, string $title, string $msg, string $site, string $tags, int $prio = 4): bool {
  $headers = ["Title: $title", "Priority: $prio", "Tags: $tags", 'Content-Type: text/plain; charset=utf-8'];
  if ($site !== '') $headers[] = "Click: $site";
  $ctx = stream_context_create(['http' => [
    'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $msg, 'timeout' => 10, 'ignore_errors' => true,
  ]]);
  $res = @file_get_contents("$server/$topic", false, $ctx);
  $code = 0;
  foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) $code = (int)$m[1];
  return $res !== false && $code >= 200 && $code < 300;
}
function ago(int $ms): string { $m = intdiv($ms, 60000); return $m < 60 ? "{$m} min" : intdiv($m, 60) . ' h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . ' min'; }

if ($test) {
  $ok = send($server, $topic, "Zara's Daily Log", 'Test notification. Alerts are working.', $site, 'baby', 3);
  exit($ok ? "Test sent\n" : "Sending failed. Check ntfy_topic and that the server can reach $server\n");
}

$pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass'],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE IF NOT EXISTS alert_state (name VARCHAR(20) PRIMARY KEY, ref_ms BIGINT NOT NULL, sent_ms BIGINT NOT NULL) ENGINE=InnoDB');

$now = (int)(microtime(true) * 1000);
$checks = [['diaper', $diaperMin, 'Diaper check', 'No diaper change for %s (last at %s).', 'baby,warning']];
if ($feedMin > 0) $checks[] = ['feed', $feedMin, 'Feeding time', 'No feed logged for %s (last at %s).', 'baby_bottle'];

$tz = new DateTimeZone((string)($config['timezone'] ?? 'America/Chicago'));
foreach ($checks as [$type, $limit, $title, $text, $tags]) {
  if ($limit <= 0) continue;
  $baby = (int)($config['baby_id'] ?? 0);
  $from = $baby ? "bl_logs WHERE baby_id = $baby AND" : 'baby_logs WHERE';
  $last = (int)$pdo->query("SELECT COALESCE(MAX(time_ms),0) FROM $from type = " . $pdo->quote($type) . " AND time_ms <= $now")->fetchColumn();
  if (!$last || $now - $last < $limit * 60000) { echo "$type ok\n"; continue; }
  $st = $pdo->prepare('SELECT ref_ms, sent_ms FROM alert_state WHERE name = ?'); $st->execute([$type]);
  $prev = $st->fetch();
  // Already alerted about this same last entry recently? Wait for the repeat window.
  if ($prev && (int)$prev['ref_ms'] === $last && $now - (int)$prev['sent_ms'] < $repeatMin * 60000) { echo "$type overdue, already sent\n"; continue; }
  $at = (new DateTime('@' . intdiv($last, 1000)))->setTimezone($tz)->format('g:i A');
  if (send($server, $topic, $title, sprintf($text, ago($now - $last), $at), $site, $tags)) {
    $pdo->prepare('REPLACE INTO alert_state (name, ref_ms, sent_ms) VALUES (?,?,?)')->execute([$type, $last, $now]);
    echo "$type alert sent\n";
  } else echo "$type alert failed\n";
}

// ---- "Diaper changed" notifications ----
// Sends a short note for each new entry of these types, from either site (shared mode) — at most 5 minutes late.
$logTypes = array_values(array_intersect((array)($config['notify_on_log'] ?? ['diaper']), ['diaper', 'feed', 'pump', 'weight']));
if ($logTypes) {
  $baby = (int)($config['baby_id'] ?? 0);
  $babyName = (string)($config['baby_name'] ?? 'Zara');
  $st = $pdo->prepare('SELECT ref_ms FROM alert_state WHERE name = ?'); $st->execute(['log_seen']);
  $seen = $st->fetchColumn();
  $in = implode(',', array_map([$pdo, 'quote'], $logTypes));
  $maxId = (int)$pdo->query($baby ? "SELECT COALESCE(MAX(id),0) FROM bl_logs WHERE baby_id = $baby" : 'SELECT COALESCE(MAX(id),0) FROM baby_logs')->fetchColumn();
  if ($seen === false) {
    echo "log notifications: starting from entry $maxId\n"; // first run: don't announce old entries
  } else {
    $sql = $baby
      ? "SELECT l.id, l.type, l.kind, l.time_ms, l.amount_ml, u.name AS by_name FROM bl_logs l LEFT JOIN bl_users u ON u.id = l.user_id
         WHERE l.baby_id = $baby AND l.id > " . (int)$seen . " AND l.type IN ($in) ORDER BY l.id LIMIT 10"
      : "SELECT id, type, kind, time_ms, amount_ml, NULL AS by_name FROM baby_logs WHERE id > " . (int)$seen . " AND type IN ($in) ORDER BY id LIMIT 10";
    $kinds = ['wet' => 'Wet', 'dirty' => 'Dirty', 'both' => 'Wet + dirty', 'bottle' => 'Breast milk bottle', 'formula' => 'Formula',
              'breast-l' => 'Breast (L)', 'breast-r' => 'Breast (R)', 'left' => 'Left', 'right' => 'Right', 'weight' => 'Weight'];
    $titles = ['diaper' => 'diaper changed', 'feed' => 'fed', 'pump' => 'pumped', 'weight' => 'weighed'];
    foreach ($pdo->query($sql)->fetchAll() as $r) {
      if ($now - (int)$r['time_ms'] > 3 * 3600000) continue; // skip back-dated entries
      $at = (new DateTime('@' . intdiv((int)$r['time_ms'], 1000)))->setTimezone($tz)->format('g:i A');
      $msg = ($kinds[$r['kind']] ?? $r['kind']) . ($r['amount_ml'] ? ' ' . (0 + $r['amount_ml']) . ' ml' : '') . " · $at" . ($r['by_name'] ? ' · by ' . $r['by_name'] : '');
      $ok = send($server, $topic, "$babyName: " . ($titles[$r['type']] ?? $r['type']), $msg, $site, $r['type'] === 'diaper' ? 'baby,white_check_mark' : 'baby', 3);
      echo "logged {$r['type']} #{$r['id']}: " . ($ok ? 'sent' : 'failed') . "\n";
    }
  }
  $pdo->prepare('REPLACE INTO alert_state (name, ref_ms, sent_ms) VALUES (?,?,?)')->execute(['log_seen', $maxId, $now]);
}
