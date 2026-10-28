<?php
// Zara's Daily Log — JSON API (PHP + MySQL)
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 60,   // stay logged in ~60 days
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }

// Basic CSRF guard: page sends this header on every request
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'zara-log') out(['error' => 'Bad request'], 400);

$action = $_GET['action'] ?? '';
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];

if ($action === 'login') {
  $ok = is_string($body['password'] ?? null) && hash_equals($config['app_password'], $body['password']);
  if (!$ok) { sleep(1); out(['error' => 'Wrong password'], 401); }
  session_regenerate_id(true);
  $_SESSION['auth'] = true;
  out(['ok' => true]);
}
if ($action === 'logout') { $_SESSION = []; session_destroy(); out(['ok' => true]); }
if (empty($_SESSION['auth'])) out(['error' => 'Not logged in'], 401);
if ($action === 'me') out(['ok' => true]);

try {
  $pdo = new PDO(
    "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
    $config['db_user'], $config['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
  );
} catch (Throwable $e) { out(['error' => 'Database connection failed'], 500); }

// Shared mode: read and write Zara's entries in the Baby Daily Log tables (babylog.msshakib.com),
// so both sites always show the same data. Set baby_id and user_id in config.php to turn it on.
$BABY = (int)($config['baby_id'] ?? 0);
$USER = (int)($config['user_id'] ?? 0) ?: null;
$T = $BABY ? 'bl_logs' : 'baby_logs';
$W = $BABY ? 'baby_id = ' . $BABY . ' AND ' : '';

$FEED = ['breast-l', 'breast-r', 'bottle', 'formula'];
$DIAPER = ['wet', 'dirty', 'both'];
$PUMP = ['left', 'right', 'both'];

switch ($action) {
  case 'list':
    $since = (int)(microtime(true) * 1000) - 100 * 86400000; // last ~3 months
    $st = $pdo->prepare("SELECT id, type, kind, time_ms AS time, amount_ml AS amountMl, duration_min AS durationMin, weight_g AS weightG, note
                         FROM $T WHERE $W(time_ms >= ? OR type = ?) ORDER BY time_ms DESC");
    $st->execute([$since, 'weight']);
    $rows = array_map(function ($r) {
      $r['id'] = (int)$r['id']; $r['time'] = (int)$r['time'];
      $r['amountMl'] = $r['amountMl'] !== null ? (float)$r['amountMl'] : null;
      $r['durationMin'] = $r['durationMin'] !== null ? (float)$r['durationMin'] : null;
      $r['weightG'] = $r['weightG'] !== null ? round((float)$r['weightG'], 3) : null;
      return $r;
    }, $st->fetchAll());
    // Most milk in one day since birth (all history, not just the last ~3 months)
    $tz = new DateTimeZone((string)($config['timezone'] ?? 'America/Chicago'));
    $days = [];
    foreach ($pdo->query("SELECT time_ms, amount_ml FROM $T WHERE {$W}type = 'feed' AND amount_ml > 0")->fetchAll() as $f) {
      $k = (new DateTime('@' . intdiv((int)$f['time_ms'], 1000)))->setTimezone($tz)->format('Y-m-d');
      $days[$k] = ($days[$k] ?? 0) + (float)$f['amount_ml'];
    }
    $best = null;
    foreach ($days as $k => $ml) if (!$best || $ml > $best['ml']) $best = ['day' => $k, 'ml' => $ml];
    out(['logs' => $rows, 'milkBest' => $best]);

  case 'add':
  case 'update':
    $type = $body['type'] ?? '';
    $kind = $body['kind'] ?? '';
    $time = $body['time'] ?? null;
    if (!($type === 'feed' && in_array($kind, $FEED, true)) && !($type === 'diaper' && in_array($kind, $DIAPER, true)) && !($type === 'weight' && $kind === 'weight') && !($type === 'pump' && in_array($kind, $PUMP, true)))
      out(['error' => 'Invalid type'], 422);
    if (!is_numeric($time)) out(['error' => 'Invalid time'], 422);
    $amt = isset($body['amountMl']) && is_numeric($body['amountMl']) && $body['amountMl'] > 0 ? (float)$body['amountMl'] : null;
    $dur = isset($body['durationMin']) && is_numeric($body['durationMin']) && $body['durationMin'] > 0 ? (float)$body['durationMin'] : null;
    $wg = isset($body['weightG']) && is_numeric($body['weightG']) ? round((float)$body['weightG'], 3) : null; // grams, up to 3 decimals
    if ($type === 'weight' && ($wg === null || $wg < 500 || $wg > 30000)) out(['error' => 'Weight must be between 0.5 and 30 kg'], 422);
    if ($type !== 'weight') $wg = null;
    $note = mb_substr(trim((string)($body['note'] ?? '')), 0, 200);
    if ($action === 'update') {
      $id = (int)($body['id'] ?? 0);
      $st = $pdo->prepare("UPDATE $T SET type = ?, kind = ?, time_ms = ?, amount_ml = ?, duration_min = ?, weight_g = ?, note = ? WHERE {$W}id = ?");
      $st->execute([$type, $kind, (int)$time, $amt, $dur, $wg, $note, $id]);
      out(['ok' => true, 'id' => $id]);
    }
    if ($BABY) {
      $st = $pdo->prepare('INSERT INTO bl_logs (baby_id, user_id, type, kind, time_ms, amount_ml, duration_min, weight_g, note) VALUES (?,?,?,?,?,?,?,?,?)');
      $st->execute([$BABY, $USER, $type, $kind, (int)$time, $amt, $dur, $wg, $note]);
    } else {
      $st = $pdo->prepare('INSERT INTO baby_logs (type, kind, time_ms, amount_ml, duration_min, weight_g, note) VALUES (?,?,?,?,?,?,?)');
      $st->execute([$type, $kind, (int)$time, $amt, $dur, $wg, $note]);
    }
    out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);

  case 'delete':
    $st = $pdo->prepare("DELETE FROM $T WHERE {$W}id = ?");
    $st->execute([(int)($body['id'] ?? 0)]);
    out(['ok' => true]);

  // ---------- free diaper pickups (shared mode only) ----------
  case 'supplyList':
    if (!$BABY) out(['supplies' => [], 'error' => 'Diaper pickups need shared mode (baby_id in config.php).']);
    $st = $pdo->prepare("SELECT s.id, s.source, DATE_FORMAT(s.got_date, '%Y-%m-%d') AS date, s.qty, s.note, u.name AS byName
                         FROM bl_supplies s LEFT JOIN bl_users u ON u.id = s.user_id WHERE s.baby_id = ? ORDER BY s.got_date DESC, s.id DESC LIMIT 200");
    $st->execute([$BABY]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['qty'] = $r['qty'] !== null ? (int)$r['qty'] : null; }
    out(['supplies' => $rows]);

  case 'supplyAdd':
    if (!$BABY) out(['error' => 'Diaper pickups need shared mode.'], 422);
    $src = mb_substr(trim((string)($body['source'] ?? '')), 0, 40); $date = $body['date'] ?? '';
    if ($src === '') out(['error' => 'Enter where you got them.'], 422);
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) out(['error' => 'Pick a date.'], 422);
    $qty = isset($body['qty']) && is_numeric($body['qty']) && $body['qty'] > 0 && $body['qty'] < 5000 ? (int)$body['qty'] : null;
    $pdo->prepare('INSERT INTO bl_supplies (baby_id, user_id, source, got_date, qty, note) VALUES (?,?,?,?,?,?)')
        ->execute([$BABY, $USER, $src, $date, $qty, mb_substr(trim((string)($body['note'] ?? '')), 0, 120)]);
    out(['ok' => true]);

  case 'supplyDelete':
    if (!$BABY) out(['error' => 'Diaper pickups need shared mode.'], 422);
    $pdo->prepare('DELETE FROM bl_supplies WHERE id = ? AND baby_id = ?')->execute([(int)($body['id'] ?? 0), $BABY]);
    out(['ok' => true]);

  default:
    out(['error' => 'Unknown action'], 404);
}
