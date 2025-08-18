<?php
declare(strict_types=1);
/**
 * Backend Pairing endpoint (standalone, WAF-vriendelijk)
 * Gebruik in Public Admin als "Backend URL": https://<backend>/
 *  -> Public Admin probeert dan automatisch /pairing/request (JSON → FORM → GET)
 * Of direct: https://<backend>/backend_pairing.php?pairing_api=request
 */

@header('X-Robots-Tag: noindex');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$config = include __DIR__.'/internal_config.php';

function connect(array $cfg): PDO {
  if (empty($cfg['db_dsn']) || empty($cfg['db_user'])) {
    throw new RuntimeException('DB config ontbreekt.');
  }
  return new PDO($cfg['db_dsn'],$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
function ensure_schema(PDO $pdo): void {
  $sqls=[
    "CREATE TABLE IF NOT EXISTS bff_pairing_requests(
      id SERIAL PRIMARY KEY,
      backend_url TEXT NOT NULL,
      public_api_url TEXT NOT NULL,
      public_host TEXT NOT NULL,
      callback_url TEXT NOT NULL,
      callback_secret TEXT NOT NULL,
      request_nonce TEXT NOT NULL,
      request_ip TEXT,
      status TEXT NOT NULL DEFAULT 'pending',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      approved_at TIMESTAMPTZ
    );",
    "CREATE TABLE IF NOT EXISTS api_keys(
      id SERIAL PRIMARY KEY,
      key_hash TEXT NOT NULL,
      label TEXT,
      role TEXT NOT NULL,
      active BOOLEAN NOT NULL DEFAULT TRUE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );"
  ];
  foreach($sqls as $q){ try{$pdo->exec($q);}catch(Throwable $e){} }
}

/** Lees pairing-payload uit JSON body, of uit form GET/POST param 'payload' (base64-json) */
function read_pairing_payload(): array {
  $isRequest = (($_GET['pairing_api'] ?? '') === 'request') || preg_match('~/pairing/request$~', ($_SERVER['REQUEST_URI'] ?? ''));
  if (!$isRequest) {
    http_response_code(405);
    echo json_encode(['error'=>'Use /pairing/request or ?pairing_api=request']); exit;
  }

  $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
  $raw = file_get_contents('php://input') ?: '';

  // 1) JSON body
  if (stripos($ctype,'application/json') !== false && $raw !== '') {
    $d = json_decode($raw, true);
    if (is_array($d)) return $d;
  }

  // 2) FORM-encoded (payload=base64(json))
  $payload = $_POST['payload'] ?? $_GET['payload'] ?? null;
  if (is_string($payload) && $payload !== '') {
    $json = base64_decode($payload, true);
    if ($json !== false) {
      $d = json_decode($json, true);
      if (is_array($d)) return $d;
    }
  }

  // 3) JSON zonder juiste content-type (soms WAF removed header)
  if ($raw !== '') {
    $d = json_decode($raw, true);
    if (is_array($d)) return $d;
  }

  http_response_code(400);
  echo json_encode(['error'=>'Invalid or missing payload']); exit;
}

try {
  $d = read_pairing_payload();

  $backend_url    = trim((string)($d['backend_url'] ?? ''));
  $public_api_url = trim((string)($d['public_api_url'] ?? ''));
  $callback_url   = trim((string)($d['callback_url'] ?? ''));
  $callback_secret= (string)($d['callback_secret'] ?? '');
  $request_nonce  = (string)($d['request_nonce'] ?? '');
  $public_host    = trim((string)($d['public_host'] ?? ''));
  $request_ip     = $_SERVER['REMOTE_ADDR'] ?? '';

  $ok=true;
  if (stripos($backend_url,'http')!==0 || stripos($public_api_url,'http')!==0) $ok=false;
  if (stripos($callback_url,'https://')!==0) $ok=false; // callback moet HTTPS
  if (strlen($callback_secret) < 32 || strlen($request_nonce) < 32) $ok=false;
  if (!$ok) { http_response_code(400); echo json_encode(['error'=>'Invalid pairing payload']); exit; }

  $pdo = connect($config);
  ensure_schema($pdo);
  $st = $pdo->prepare("INSERT INTO bff_pairing_requests(backend_url,public_api_url,public_host,callback_url,callback_secret,request_nonce,request_ip,status) VALUES(?,?,?,?,?,?,?,'pending') RETURNING id");
  $st->execute([$backend_url,$public_api_url,$public_host,$callback_url,$callback_secret,$request_nonce,$request_ip]);
  $id = (int)$st->fetchColumn();
  echo json_encode(['request_id'=>$id,'status'=>'pending']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
