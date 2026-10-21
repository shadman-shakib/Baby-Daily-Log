<?php
// Baby Daily Log — phone alerts for every baby that has phone alerts turned on.
// cPanel cron job, every 5 minutes:
//   /usr/local/bin/php /home/CPANEL_USER/babylog.msshakib.com/notify.php >/dev/null 2>&1
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Forbidden\n"); }

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
$tz = new DateTimeZone((string)($config['timezone'] ?? 'America/Chicago'));
$repeatMin = 30;
$pdo = db($config);
$now = (int)(microtime(true) * 1000);

function ago(int $ms): string { $m = intdiv($ms, 60000); return $m < 60 ? "{$m} min" : intdiv($m, 60) . ' h ' . str_pad((string)($m % 60), 2, '0', STR_PAD_LEFT) . ' min'; }

$babies = $pdo->query("SELECT id, name, ntfy_topic, diaper_alert_min, feed_alert_min FROM bl_babies WHERE ntfy_topic IS NOT NULL AND ntfy_topic <> ''")->fetchAll();
$last = $pdo->prepare('SELECT COALESCE(MAX(time_ms),0) FROM bl_logs WHERE baby_id = ? AND type = ? AND time_ms <= ?');
$state = $pdo->prepare('SELECT ref_ms, sent_ms FROM bl_alert_state WHERE baby_id = ? AND name = ?');
$save = $pdo->prepare('REPLACE INTO bl_alert_state (baby_id, name, ref_ms, sent_ms) VALUES (?,?,?,?)');

foreach ($babies as $b) {
  $checks = [
    ['diaper', (int)$b['diaper_alert_min'], 'Diaper check', "No diaper change for %s (last at %s).", 'baby,warning'],
    ['feed', (int)$b['feed_alert_min'], 'Feeding time', "No feed logged for %s (last at %s).", 'baby_bottle'],
  ];
  foreach ($checks as [$type, $limit, $title, $text, $tags]) {
    if ($limit <= 0) continue;
    $last->execute([$b['id'], $type, $now]); $t = (int)$last->fetchColumn();
    if (!$t || $now - $t < $limit * 60000) continue;
    $state->execute([$b['id'], $type]); $prev = $state->fetch();
    if ($prev && (int)$prev['ref_ms'] === $t && $now - (int)$prev['sent_ms'] < $repeatMin * 60000) continue;
    $at = (new DateTime('@' . intdiv($t, 1000)))->setTimezone($tz)->format('g:i A');
    if (ntfy($config, $b['ntfy_topic'], "{$b['name']}: $title", sprintf($text, ago($now - $t), $at), $tags, 4, $config['site_url'] ?? '')) {
      $save->execute([$b['id'], $type, $t, $now]);
      echo "baby {$b['id']} $type alert sent\n";
    }
  }
}

// ---- "Diaper changed" notes: one per new diaper entry, for babies with phone alerts and log_notify on ----
$kinds = ['wet' => 'Wet', 'dirty' => 'Dirty', 'both' => 'Wet + dirty'];
$maxQ = $pdo->prepare('SELECT COALESCE(MAX(id),0) FROM bl_logs WHERE baby_id = ?');
$newQ = $pdo->prepare("SELECT l.id, l.kind, l.time_ms, u.name AS by_name FROM bl_logs l LEFT JOIN bl_users u ON u.id = l.user_id
                       WHERE l.baby_id = ? AND l.id > ? AND l.type = 'diaper' ORDER BY l.id LIMIT 10");
foreach ($pdo->query("SELECT id, name, ntfy_topic FROM bl_babies WHERE ntfy_topic IS NOT NULL AND ntfy_topic <> '' AND log_notify = 1")->fetchAll() as $b) {
  $maxQ->execute([$b['id']]); $maxId = (int)$maxQ->fetchColumn();
  $state->execute([$b['id'], 'log_seen']); $prev = $state->fetch();
  if ($prev) {
    $newQ->execute([$b['id'], (int)$prev['ref_ms']]);
    foreach ($newQ->fetchAll() as $r) {
      if ($now - (int)$r['time_ms'] > 3 * 3600000) continue; // skip back-dated entries
      $at = (new DateTime('@' . intdiv((int)$r['time_ms'], 1000)))->setTimezone($tz)->format('g:i A');
      $msg = ($kinds[$r['kind']] ?? $r['kind']) . " · $at" . ($r['by_name'] ? ' · by ' . $r['by_name'] : '');
      ntfy($config, $b['ntfy_topic'], "{$b['name']}: diaper changed", $msg, 'baby,white_check_mark', 3, $config['site_url'] ?? '');
      echo "baby {$b['id']} diaper #{$r['id']} sent\n";
    }
  }
  $save->execute([$b['id'], 'log_seen', $maxId, $now]);
}
