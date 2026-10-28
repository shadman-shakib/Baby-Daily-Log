<?php
// Baby Daily Log (multi-family) — JSON API
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib.php';
date_default_timezone_set((string)($config['timezone'] ?? 'America/Chicago'));

session_set_cookie_params([
  'lifetime' => 60 * 60 * 24 * 60,
  'path' => '/',
  'secure' => !empty($_SERVER['HTTPS']),
  'httponly' => true,
  'samesite' => 'Strict',
]);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data); exit; }
function fail(string $msg, int $code = 400, array $extra = []): void { out(['error' => $msg] + $extra, $code); }

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'baby-log') fail('Bad request');

$action = $_GET['action'] ?? '';
$b = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($b)) $b = [];

try { $pdo = db($config); } catch (Throwable $e) { fail('Database connection failed', 500); }

function q(string $sql, array $args = []): PDOStatement { global $pdo; $st = $pdo->prepare($sql); $st->execute($args); return $st; }
function str($v, int $max): string { return mb_substr(trim(is_string($v) ? $v : ''), 0, $max); }
function cleanEmail($v): string { return strtolower(str($v, 190)); }
function isAdminEmail(string $email): bool {
  global $config;
  return in_array(strtolower($email), array_map('strtolower', $config['admin_emails'] ?? []), true);
}
// The logged-in, approved user's id. Stops with 401 otherwise.
function uid(): int {
  $u = (int)($_SESSION['uid'] ?? 0);
  if (!$u) fail('Not logged in', 401);
  $st = q('SELECT status FROM bl_users WHERE id = ?', [$u])->fetchColumn();
  if ($st !== 'active') { $_SESSION = []; fail('Not logged in', 401); }
  return $u;
}
function isAdmin(int $uid): bool {
  $e = q('SELECT email FROM bl_users WHERE id = ?', [$uid])->fetchColumn();
  return $e && isAdminEmail($e);
}
function member(int $uid, $babyId): string {
  $role = q('SELECT role FROM bl_members WHERE baby_id = ? AND user_id = ?', [(int)$babyId, $uid])->fetchColumn();
  if (!$role) fail('No access to this baby', 403);
  return $role;
}
function ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0', 0, 45); }
function throttled(string $key, int $max = 8): bool {
  $since = time() - 15 * 60;
  $byIp = (int)q('SELECT COUNT(*) FROM bl_attempts WHERE ip = ? AND ts > ?', [ip(), $since])->fetchColumn();
  $byKey = (int)q('SELECT COUNT(*) FROM bl_attempts WHERE email = ? AND ts > ?', [$key, $since])->fetchColumn();
  return $byIp >= 30 || $byKey >= $max;
}
function attempt(string $key, bool $slow = true): void {
  q('INSERT INTO bl_attempts (ip, email, ts) VALUES (?,?,?)', [ip(), $key, time()]);
  q('DELETE FROM bl_attempts WHERE ts < ?', [time() - 86400]);
  if ($slow) sleep(1);
}
function login(int $id): void { session_regenerate_id(true); $_SESSION['uid'] = $id; }
function validPassword($p): bool { return is_string($p) && mb_strlen($p) >= 8 && mb_strlen($p) <= 200; }
function randCode(int $len, string $abc): string { $c = ''; for ($i = 0; $i < $len; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)]; return $c; }

// Creates and emails a 6-digit code. Returns false if one was sent less than 60 s ago or sending failed.
function sendCode(array $user, string $purpose): bool {
  global $config;
  $prev = q('SELECT sent_at FROM bl_codes WHERE user_id = ? AND purpose = ?', [$user['id'], $purpose])->fetchColumn();
  if ($prev && time() - (int)$prev < 60) return true; // already sent moments ago
  $code = randCode(6, '0123456789');
  q('REPLACE INTO bl_codes (user_id, purpose, code_hash, expires_at, tries, sent_at) VALUES (?,?,?,?,0,?)',
    [$user['id'], $purpose, password_hash($code, PASSWORD_DEFAULT), time() + 15 * 60, time()]);
  $site = $config['site_name'] ?? 'Baby Daily Log';
  $subject = $purpose === 'verify' ? "$code is your $site code" : "$code is your $site password reset code";
  $why = $purpose === 'verify' ? 'to confirm your email' : 'to reset your password';
  $body = "Hi {$user['name']},\n\nYour code $why is:\n\n    $code\n\nIt works for 15 minutes. If you didn't ask for this, you can ignore this email.\n\n— $site\n{$config['site_url']}\n";
  return send_mail($config, $user['email'], $subject, $body);
}
// Checks a code. Returns true once, then deletes it. Five wrong tries cancel it.
function checkCode(int $userId, string $purpose, $code): bool {
  $row = q('SELECT code_hash, expires_at, tries FROM bl_codes WHERE user_id = ? AND purpose = ?', [$userId, $purpose])->fetch();
  if (!$row || time() > (int)$row['expires_at'] || (int)$row['tries'] >= 5) return false;
  if (!password_verify(preg_replace('/\D/', '', (string)$code), $row['code_hash'])) {
    q('UPDATE bl_codes SET tries = tries + 1 WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
    return false;
  }
  q('DELETE FROM bl_codes WHERE user_id = ? AND purpose = ?', [$userId, $purpose]);
  return true;
}
function userByEmail(string $email) { return q('SELECT id, name, email, pass_hash, email_verified, status FROM bl_users WHERE email = ?', [$email])->fetch(); }
function babyRow(int $bid) { return q('SELECT id, name, birth_date, birth_time, sex, ntfy_topic, diaper_alert_min, feed_alert_min FROM bl_babies WHERE id = ?', [$bid])->fetch(); }

$FEED = ['breast-l', 'breast-r', 'bottle', 'formula'];
$DIAPER = ['wet', 'dirty', 'both'];
$PUMP = ['left', 'right', 'both'];

switch ($action) {

  // ---------- sign up & sign in ----------
  case 'register': {
    $name = str($b['name'] ?? '', 60);
    $email = cleanEmail($b['email'] ?? '');
    $pass = $b['password'] ?? '';
    if (throttled('reg:' . ip(), 6)) fail('Too many sign-ups from here. Try again in 15 minutes.', 429);
    if ($name === '') fail('Enter your name.', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Enter a valid email.', 422);
    if (!validPassword($pass)) fail('Password needs at least 8 characters.', 422);
    $u = userByEmail($email);
    if ($u && $u['email_verified']) fail('An account with this email already exists. Log in instead.', 409);
    attempt('reg:' . ip(), false);
    if ($u) { // unverified leftover: let them start again
      q('UPDATE bl_users SET name = ?, pass_hash = ? WHERE id = ?', [$name, password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
      $id = (int)$u['id'];
    } else {
      q('INSERT INTO bl_users (name, email, pass_hash) VALUES (?,?,?)', [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
      $id = (int)$pdo->lastInsertId();
    }
    if (!sendCode(['id' => $id, 'name' => $name, 'email' => $email], 'verify')) fail("We couldn't send the email. Check the address and try again.", 502);
    out(['ok' => true, 'next' => 'verify']);
  }

  case 'resendCode': {
    $email = cleanEmail($b['email'] ?? '');
    $purpose = ($b['purpose'] ?? '') === 'reset' ? 'reset' : 'verify';
    if (throttled('mail:' . $email, 5)) fail('Too many emails. Try again in 15 minutes.', 429);
    attempt('mail:' . $email, false);
    $u = userByEmail($email);
    if ($u && ($purpose === 'reset' ? $u['email_verified'] : !$u['email_verified'])) {
      q('UPDATE bl_codes SET sent_at = 0 WHERE user_id = ? AND purpose = ?', [$u['id'], $purpose]);
      sendCode($u, $purpose);
    }
    out(['ok' => true]); // same answer either way, so nobody can probe for accounts
  }

  case 'verify': {
    $email = cleanEmail($b['email'] ?? '');
    if (throttled('code:' . $email)) fail('Too many attempts. Try again in 15 minutes.', 429);
    $u = userByEmail($email);
    if (!$u || $u['email_verified'] || !checkCode((int)$u['id'], 'verify', $b['code'] ?? '')) {
      attempt('code:' . $email); fail('That code is wrong or expired. Check the latest email, or send a new code.', 400);
    }
    $admin = isAdminEmail($email);
    q('UPDATE bl_users SET email_verified = 1, status = ? WHERE id = ?', [$admin ? 'active' : 'pending', $u['id']]);
    if ($admin) { login((int)$u['id']); out(['ok' => true, 'next' => 'app']); }
    $topic = (string)($config['admin_ntfy_topic'] ?? '');
    if ($topic !== '') ntfy($config, $topic, 'New sign-up', "{$u['name']} ({$email}) is waiting for approval.", 'bust_in_silhouette', 4, $config['site_url'] ?? '');
    foreach ($config['admin_emails'] ?? [] as $ae)
      send_mail($config, $ae, 'New sign-up waiting: ' . $u['name'], "{$u['name']} ({$email}) signed up and is waiting for your approval.\n\nOpen {$config['site_url']} → Family → Admin to approve.\n");
    out(['ok' => true, 'next' => 'pending']);
  }

  case 'login': {
    $email = cleanEmail($b['email'] ?? '');
    if (throttled('login:' . $email)) fail('Too many attempts. Try again in 15 minutes.', 429);
    $u = userByEmail($email);
    if (!$u || !password_verify((string)($b['password'] ?? ''), $u['pass_hash'])) { attempt('login:' . $email); fail('Wrong email or password.', 401); }
    if (!$u['email_verified']) { sendCode($u, 'verify'); fail('Confirm your email first. We sent you a new code.', 403, ['next' => 'verify']); }
    if ($u['status'] === 'pending') fail('Your account is waiting for approval. You can log in once it is approved.', 403, ['next' => 'pending']);
    if ($u['status'] !== 'active') fail('This account is turned off. Ask the admin.', 403);
    if (password_needs_rehash($u['pass_hash'], PASSWORD_DEFAULT))
      q('UPDATE bl_users SET pass_hash = ? WHERE id = ?', [password_hash((string)$b['password'], PASSWORD_DEFAULT), $u['id']]);
    login((int)$u['id']);
    out(['ok' => true]);
  }

  case 'forgot': {
    $email = cleanEmail($b['email'] ?? '');
    if (throttled('mail:' . $email, 5)) fail('Too many emails. Try again in 15 minutes.', 429);
    attempt('mail:' . $email, false);
    $u = userByEmail($email);
    if ($u && $u['email_verified']) sendCode($u, 'reset');
    out(['ok' => true]);
  }

  case 'resetPassword': {
    $email = cleanEmail($b['email'] ?? '');
    if (throttled('code:' . $email)) fail('Too many attempts. Try again in 15 minutes.', 429);
    if (!validPassword($b['password'] ?? null)) fail('New password needs at least 8 characters.', 422);
    $u = userByEmail($email);
    if (!$u || !checkCode((int)$u['id'], 'reset', $b['code'] ?? '')) { attempt('code:' . $email); fail('That code is wrong or expired. Send a new code.', 400); }
    q('UPDATE bl_users SET pass_hash = ? WHERE id = ?', [password_hash($b['password'], PASSWORD_DEFAULT), $u['id']]);
    q('DELETE FROM bl_attempts WHERE email = ?', ['login:' . $email]);
    if ($u['status'] === 'active') { login((int)$u['id']); out(['ok' => true, 'next' => 'app']); }
    out(['ok' => true, 'next' => $u['status'] === 'pending' ? 'pending' : 'login']);
  }

  case 'logout':
    $_SESSION = []; session_destroy(); out(['ok' => true]);

  case 'me': {
    $u = uid();
    $user = q('SELECT id, name, email FROM bl_users WHERE id = ?', [$u])->fetch();
    $babies = q('SELECT b.id, b.name, b.birth_date AS birthDate, b.birth_time AS birthTime, b.sex, b.diaper_alert_min AS diaperAlertMin,
                        b.feed_alert_min AS feedAlertMin, b.ntfy_topic AS ntfyTopic, b.log_notify AS logNotify, m.role
                 FROM bl_babies b JOIN bl_members m ON m.baby_id = b.id WHERE m.user_id = ? ORDER BY b.created_at', [$u])->fetchAll();
    foreach ($babies as &$x) {
      $x['id'] = (int)$x['id']; $x['diaperAlertMin'] = (int)$x['diaperAlertMin']; $x['feedAlertMin'] = (int)$x['feedAlertMin']; $x['logNotify'] = (bool)$x['logNotify'];
      if ($x['birthTime']) $x['birthTime'] = substr($x['birthTime'], 0, 5);
    }
    $user['id'] = (int)$user['id'];
    out(['user' => $user, 'babies' => $babies, 'isAdmin' => isAdmin($u)]);
  }

  case 'changePassword': {
    $u = uid();
    $hash = q('SELECT pass_hash FROM bl_users WHERE id = ?', [$u])->fetchColumn();
    if (!password_verify((string)($b['current'] ?? ''), (string)$hash)) fail('Current password is not right.', 403);
    if (!validPassword($b['new'] ?? null)) fail('New password needs at least 8 characters.', 422);
    q('UPDATE bl_users SET pass_hash = ? WHERE id = ?', [password_hash($b['new'], PASSWORD_DEFAULT), $u]);
    out(['ok' => true]);
  }

  case 'deleteAccount': {
    $u = uid();
    $hash = q('SELECT pass_hash FROM bl_users WHERE id = ?', [$u])->fetchColumn();
    if (!password_verify((string)($b['password'] ?? ''), (string)$hash)) fail('Password is not right.', 403);
    $pdo->beginTransaction();
    $ids = q('SELECT baby_id FROM bl_members WHERE user_id = ?', [$u])->fetchAll(PDO::FETCH_COLUMN);
    q('DELETE FROM bl_users WHERE id = ?', [$u]);
    foreach ($ids as $bid) {
      if ((int)q('SELECT COUNT(*) FROM bl_members WHERE baby_id = ?', [$bid])->fetchColumn() === 0) q('DELETE FROM bl_babies WHERE id = ?', [$bid]);
      elseif (!q("SELECT 1 FROM bl_members WHERE baby_id = ? AND role = 'owner'", [$bid])->fetchColumn())
        q("UPDATE bl_members SET role = 'owner' WHERE baby_id = ? ORDER BY joined_at LIMIT 1", [$bid]);
    }
    $pdo->commit();
    $_SESSION = []; session_destroy();
    out(['ok' => true]);
  }

  // ---------- admin ----------
  case 'adminUsers': {
    $u = uid(); if (!isAdmin($u)) fail('Admins only', 403);
    $rows = q("SELECT id, name, email, status, UNIX_TIMESTAMP(created_at) AS created FROM bl_users
               WHERE email_verified = 1 ORDER BY status = 'pending' DESC, created_at DESC LIMIT 200")->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['you'] = $r['id'] === $u; $r['created'] = (int)$r['created'] * 1000; }
    out(['users' => $rows]);
  }

  case 'adminSetStatus': {
    $u = uid(); if (!isAdmin($u)) fail('Admins only', 403);
    $id = (int)($b['userId'] ?? 0); $status = $b['status'] ?? '';
    if ($id === $u) fail("You can't change your own account here.", 422);
    if ($status === 'delete') {
      q('DELETE FROM bl_users WHERE id = ? AND status <> ?', [$id, 'active']);
      out(['ok' => true]);
    }
    if (!in_array($status, ['active', 'disabled'], true)) fail('Invalid status', 422);
    $target = q('SELECT name, email, status FROM bl_users WHERE id = ? AND email_verified = 1', [$id])->fetch();
    if (!$target) fail('No such user', 404);
    q('UPDATE bl_users SET status = ? WHERE id = ?', [$status, $id]);
    if ($status === 'active' && $target['status'] === 'pending')
      send_mail($config, $target['email'], 'You are approved — ' . ($config['site_name'] ?? 'Baby Daily Log'),
        "Hi {$target['name']},\n\nYour account is approved. Log in at {$config['site_url']}\n");
    out(['ok' => true]);
  }

  // ---------- babies & family ----------
  case 'createBaby': {
    $u = uid();
    $name = str($b['name'] ?? '', 60);
    if ($name === '') fail("Enter the baby's name.", 422);
    $bd = (is_string($b['birthDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['birthDate'])) ? $b['birthDate'] : null;
    $bt = (is_string($b['birthTime'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $b['birthTime'])) ? $b['birthTime'] : null;
    $sex = in_array($b['sex'] ?? null, ['F', 'M'], true) ? $b['sex'] : null;
    if ((int)q('SELECT COUNT(*) FROM bl_members WHERE user_id = ?', [$u])->fetchColumn() >= 10) fail('You can follow up to 10 babies.', 422);
    $pdo->beginTransaction();
    q('INSERT INTO bl_babies (name, birth_date, birth_time, sex) VALUES (?,?,?,?)', [$name, $bd, $bt, $sex]);
    $bid = (int)$pdo->lastInsertId();
    q("INSERT INTO bl_members (baby_id, user_id, role) VALUES (?,?,'owner')", [$bid, $u]);
    $pdo->commit();
    out(['ok' => true, 'babyId' => $bid]);
  }

  case 'updateBaby': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $name = str($b['name'] ?? '', 60);
    if ($name === '') fail("Enter the baby's name.", 422);
    $bd = (is_string($b['birthDate'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $b['birthDate'])) ? $b['birthDate'] : null;
    $bt = (is_string($b['birthTime'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $b['birthTime'])) ? $b['birthTime'] : null;
    $sex = in_array($b['sex'] ?? null, ['F', 'M'], true) ? $b['sex'] : null;
    q('UPDATE bl_babies SET name = ?, birth_date = ?, birth_time = ?, sex = ? WHERE id = ?', [$name, $bd, $bt, $sex, $bid]);
    out(['ok' => true]);
  }

  case 'alertSettings': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $d = max(0, min(1440, (int)($b['diaperAlertMin'] ?? 135)));
    $f = max(0, min(1440, (int)($b['feedAlertMin'] ?? 0)));
    $topic = babyRow($bid)['ntfy_topic'];
    if (!empty($b['phoneAlerts']) && !$topic) $topic = 'babylog-' . randCode(14, 'abcdefghijkmnpqrstuvwxyz23456789');
    if (array_key_exists('phoneAlerts', $b) && !$b['phoneAlerts']) $topic = null;
    $ln = array_key_exists('logNotify', $b) ? (empty($b['logNotify']) ? 0 : 1) : 1;
    q('UPDATE bl_babies SET diaper_alert_min = ?, feed_alert_min = ?, ntfy_topic = ?, log_notify = ? WHERE id = ?', [$d, $f, $topic, $ln, $bid]);
    out(['ok' => true, 'ntfyTopic' => $topic]);
  }

  case 'testAlert': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    if (throttled('test:' . $u, 5)) fail('Too many tests. Try again in a few minutes.', 429);
    attempt('test:' . $u, false);
    $baby = babyRow($bid);
    if (!$baby['ntfy_topic']) fail('Turn on phone alerts first.', 422);
    $ok = ntfy($config, $baby['ntfy_topic'], $baby['name'] . "'s Daily Log", 'Test notification. Alerts are working.', 'baby', 3, $config['site_url'] ?? '');
    $ok ? out(['ok' => true]) : fail("Couldn't reach the notification service.", 502);
  }

  case 'createInvite': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    q('DELETE FROM bl_invites WHERE expires_at < NOW()');
    $code = randCode(8, 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');
    q('INSERT INTO bl_invites (code, baby_id, created_by, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))', [$code, $bid, $u]);
    out(['code' => $code]);
  }

  case 'join': {
    $u = uid();
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($b['code'] ?? '')));
    if (throttled('join:' . $u)) fail('Too many attempts. Try again in 15 minutes.', 429);
    $inv = q('SELECT baby_id FROM bl_invites WHERE code = ? AND expires_at > NOW()', [$code])->fetch();
    if (!$inv) { attempt('join:' . $u); fail('That invite code is wrong or expired. Ask for a new one.', 404); }
    q("INSERT IGNORE INTO bl_members (baby_id, user_id, role) VALUES (?,?,'member')", [$inv['baby_id'], $u]);
    q('DELETE FROM bl_invites WHERE code = ?', [$code]);
    out(['ok' => true, 'babyId' => (int)$inv['baby_id']]);
  }

  case 'members': {
    $u = uid(); member($u, $b['babyId'] ?? 0);
    $rows = q('SELECT u.id, u.name, u.email, m.role FROM bl_members m JOIN bl_users u ON u.id = m.user_id
               WHERE m.baby_id = ? ORDER BY m.joined_at', [(int)$b['babyId']])->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['you'] = $r['id'] === $u; }
    out(['members' => $rows]);
  }

  case 'removeMember': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0);
    $role = member($u, $bid);
    $target = (int)($b['userId'] ?? 0);
    if ($target !== $u && $role !== 'owner') fail('Only the owner can remove family members.', 403);
    if ($target === $u && $role === 'owner')
      q("UPDATE bl_members SET role = 'owner' WHERE baby_id = ? AND user_id <> ? ORDER BY joined_at LIMIT 1", [$bid, $u]);
    q('DELETE FROM bl_members WHERE baby_id = ? AND user_id = ?', [$bid, $target]);
    if ((int)q('SELECT COUNT(*) FROM bl_members WHERE baby_id = ?', [$bid])->fetchColumn() === 0) q('DELETE FROM bl_babies WHERE id = ?', [$bid]);
    out(['ok' => true]);
  }

  // ---------- logs ----------
  case 'list': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $since = (int)(microtime(true) * 1000) - 100 * 86400000;
    $rows = q('SELECT l.id, l.type, l.kind, l.time_ms AS time, l.amount_ml AS amountMl, l.duration_min AS durationMin,
                      l.weight_g AS weightG, l.length_cm AS lengthCm, l.note, u.name AS byName
               FROM bl_logs l LEFT JOIN bl_users u ON u.id = l.user_id
               WHERE l.baby_id = ? AND (? = 1 OR l.time_ms >= ? OR l.type IN (?, ?)) ORDER BY l.time_ms DESC',
               [$bid, empty($b['all']) ? 0 : 1, $since, 'weight', 'length'])->fetchAll();
    foreach ($rows as &$r) {
      $r['id'] = (int)$r['id']; $r['time'] = (int)$r['time'];
      $r['amountMl'] = $r['amountMl'] !== null ? (float)$r['amountMl'] : null;
      $r['durationMin'] = $r['durationMin'] !== null ? (float)$r['durationMin'] : null;
      $r['weightG'] = $r['weightG'] !== null ? round((float)$r['weightG'], 3) : null;
      $r['lengthCm'] = $r['lengthCm'] !== null ? round((float)$r['lengthCm'], 3) : null;
    }
    unset($r);
    // Most milk in one day since birth (all history)
    $days = [];
    foreach (q("SELECT time_ms, amount_ml FROM bl_logs WHERE baby_id = ? AND type = 'feed' AND amount_ml > 0", [$bid])->fetchAll() as $f) {
      $k = date('Y-m-d', intdiv((int)$f['time_ms'], 1000));
      $days[$k] = ($days[$k] ?? 0) + (float)$f['amount_ml'];
    }
    $best = null;
    foreach ($days as $k => $ml) if (!$best || $ml > $best['ml']) $best = ['day' => $k, 'ml' => $ml];
    out(['logs' => $rows, 'milkBest' => $best]);
  }

  case 'add':
  case 'update': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $type = $b['type'] ?? ''; $kind = $b['kind'] ?? ''; $time = $b['time'] ?? null;
    $ok = ($type === 'feed' && in_array($kind, $FEED, true)) || ($type === 'diaper' && in_array($kind, $DIAPER, true))
       || ($type === 'weight' && $kind === 'weight') || ($type === 'length' && $kind === 'length') || ($type === 'pump' && in_array($kind, $PUMP, true));
    if (!$ok) fail('Invalid type', 422);
    if (!is_numeric($time)) fail('Invalid time', 422);
    $amt = isset($b['amountMl']) && is_numeric($b['amountMl']) && $b['amountMl'] > 0 && $b['amountMl'] < 5000 ? (float)$b['amountMl'] : null;
    $dur = isset($b['durationMin']) && is_numeric($b['durationMin']) && $b['durationMin'] > 0 && $b['durationMin'] < 1000 ? (float)$b['durationMin'] : null;
    $wg = isset($b['weightG']) && is_numeric($b['weightG']) ? round((float)$b['weightG'], 3) : null; // grams, up to 3 decimals
    if ($type === 'weight' && ($wg === null || $wg < 500 || $wg > 30000)) fail('Weight must be between 0.5 and 30 kg', 422);
    if ($type !== 'weight') $wg = null;
    $lc = isset($b['lengthCm']) && is_numeric($b['lengthCm']) ? round((float)$b['lengthCm'], 3) : null; // cm, up to 3 decimals
    if ($type === 'length' && ($lc === null || $lc < 30 || $lc > 130)) fail('Length must be between 30 and 130 cm', 422);
    if ($type !== 'length') $lc = null;
    $note = str($b['note'] ?? '', 200);
    if ($action === 'update') {
      q('UPDATE bl_logs SET type = ?, kind = ?, time_ms = ?, amount_ml = ?, duration_min = ?, weight_g = ?, length_cm = ?, note = ? WHERE id = ? AND baby_id = ?',
        [$type, $kind, (int)$time, $amt, $dur, $wg, $lc, $note, (int)($b['id'] ?? 0), $bid]);
      out(['ok' => true]);
    }
    q('INSERT INTO bl_logs (baby_id, user_id, type, kind, time_ms, amount_ml, duration_min, weight_g, length_cm, note) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$bid, $u, $type, $kind, (int)$time, $amt, $dur, $wg, $lc, $note]);
    out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
  }

  case 'delete': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    q('DELETE FROM bl_logs WHERE id = ? AND baby_id = ?', [(int)($b['id'] ?? 0), $bid]);
    out(['ok' => true]);
  }

  // ---------- free diaper pickups ----------
  case 'supplyList': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $rows = q("SELECT s.id, s.source, DATE_FORMAT(s.got_date, '%Y-%m-%d') AS date, s.qty, s.note, u.name AS byName
               FROM bl_supplies s LEFT JOIN bl_users u ON u.id = s.user_id WHERE s.baby_id = ? ORDER BY s.got_date DESC, s.id DESC LIMIT 200", [$bid])->fetchAll();
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['qty'] = $r['qty'] !== null ? (int)$r['qty'] : null; }
    out(['supplies' => $rows]);
  }

  case 'supplyAdd': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    $src = str($b['source'] ?? '', 40); $date = $b['date'] ?? '';
    if ($src === '') fail('Enter where you got them.', 422);
    if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Pick a date.', 422);
    $qty = isset($b['qty']) && is_numeric($b['qty']) && $b['qty'] > 0 && $b['qty'] < 5000 ? (int)$b['qty'] : null;
    q('INSERT INTO bl_supplies (baby_id, user_id, source, got_date, qty, note) VALUES (?,?,?,?,?,?)', [$bid, $u, $src, $date, $qty, str($b['note'] ?? '', 120)]);
    out(['ok' => true]);
  }

  case 'supplyDelete': {
    $u = uid(); $bid = (int)($b['babyId'] ?? 0); member($u, $bid);
    q('DELETE FROM bl_supplies WHERE id = ? AND baby_id = ?', [(int)($b['id'] ?? 0), $bid]);
    out(['ok' => true]);
  }

  default:
    fail('Unknown action', 404);
}
