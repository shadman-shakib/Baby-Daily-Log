<?php
// Shared helpers: database, email (SMTP) and ntfy.
declare(strict_types=1);

function db(array $c): PDO {
  return new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}

function ntfy(array $c, string $topic, string $title, string $msg, string $tags = 'baby', int $prio = 4, string $click = ''): bool {
  if ($topic === '') return false;
  $server = rtrim((string)($c['ntfy_server'] ?? 'https://ntfy.sh'), '/');
  $h = ["Title: $title", "Priority: $prio", "Tags: $tags", 'Content-Type: text/plain; charset=utf-8'];
  if ($click !== '') $h[] = "Click: $click";
  $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $h), 'content' => $msg, 'timeout' => 10, 'ignore_errors' => true]]);
  $res = @file_get_contents("$server/" . rawurlencode($topic), false, $ctx);
  $code = 0;
  foreach ($http_response_header ?? [] as $line) if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) $code = (int)$m[1];
  return $res !== false && $code >= 200 && $code < 300;
}

// Sends a plain-text email. Uses SMTP when smtp_host is set, otherwise PHP mail().
$GLOBALS['MAIL_ERR'] = '';
function send_mail(array $c, string $to, string $subject, string $body): bool {
  $from = (string)$c['mail_from']; $name = (string)($c['mail_from_name'] ?? '');
  $encSub = '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $fromHdr = ($name !== '' ? '=?UTF-8?B?' . base64_encode($name) . '?= ' : '') . "<$from>";
  $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
  $headers = [
    "From: $fromHdr", "To: <$to>", "Subject: $encSub",
    'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
    'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64',
  ];
  $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body));

  if (empty($c['smtp_host'])) {
    $ok = @mail($to, $encSub, chunk_split(base64_encode($body)),
      implode("\r\n", array_filter($headers, fn($h) => !preg_match('/^(To|Subject):/', $h))), "-f$from");
    if (!$ok) $GLOBALS['MAIL_ERR'] = 'mail() returned false';
    return $ok;
  }
  try {
    $secure = $c['smtp_secure'] ?? 'ssl';
    $host = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $c['smtp_host'] . ':' . (int)$c['smtp_port'];
    $verify = $c['smtp_verify'] ?? true;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify, 'SNI_enabled' => true, 'peer_name' => $c['smtp_host']]]);
    $s = @stream_socket_client($host, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException("connect: $errstr");
    stream_set_timeout($s, 15);
    $read = function () use ($s): array {
      $all = ''; $code = 0;
      while (($line = fgets($s, 1024)) !== false) { $all .= $line; $code = (int)substr($line, 0, 3); if (($line[3] ?? ' ') === ' ') break; }
      return [$code, $all];
    };
    $cmd = function (string $line, array $ok) use ($s, $read): string {
      if ($line !== '') fwrite($s, $line . "\r\n");
      [$code, $all] = $read();
      if (!in_array($code, $ok, true)) throw new RuntimeException(trim($all));
      return $all;
    };
    $cmd('', [220]);
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($secure === 'tls') {
      $cmd('STARTTLS', [220]);
      if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('STARTTLS failed');
      $cmd($ehlo, [250]);
    }
    if (!empty($c['smtp_user'])) {
      $cmd('AUTH LOGIN', [334]);
      $cmd(base64_encode((string)$c['smtp_user']), [334]);
      $cmd(base64_encode((string)$c['smtp_pass']), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    $cmd("RCPT TO:<$to>", [250, 251]);
    $cmd('DATA', [354]);
    $cmd(preg_replace('/^\./m', '..', $data) . "\r\n.", [250]);
    @fwrite($s, "QUIT\r\n"); fclose($s);
    return true;
  } catch (Throwable $e) {
    $GLOBALS['MAIL_ERR'] = $e->getMessage();
    error_log('Baby log mail error: ' . $e->getMessage());
    return false;
  }
}
