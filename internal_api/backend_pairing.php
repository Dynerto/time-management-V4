<?php
declare(strict_types=1);
/**
 * Backend Pairing endpoint (standalone)
 * Gebruik als Public Admin "backend URL": https://<backend>/backend_pairing.php
 * Public Admin zal POSTen naar ?pairing_api=request
 */
@header('X-Robots-Tag: noindex');
header('Content-Type: application/json; charset=UTF-8');

$config = include __DIR__.'/internal_config.php';
function connect(array $cfg): PDO {
  $pdo=new PDO($cfg['db_dsn'],$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  return $pdo;
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

if (($_GET['pairing_api'] ?? '') !== 'request') {
  http_response_code(405); echo json_encode(['error'=>'Use ?pairing_api=request']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo json_encode(['error'=>'POST required']); exit;
}

try {
  $raw = file_get_contents('php://input') ?: '';
  $d = json_decode($raw,true) ?: [];
  $backend_url    = trim((string)($d['backend_url'] ?? ''));
  $public_api_url = trim((string)($d['public_api_url'] ?? ''));
  $callback_url   = trim((string)($d['callback_url'] ?? ''));
  $callback_secret= (string)($d['callback_secret'] ?? '');
  $request_nonce  = (string)($d['request_nonce'] ?? '');
  $public_host    = trim((string)($d['public_host'] ?? ''));
  $request_ip     = $_SERVER['REMOTE_ADDR'] ?? '';

  $ok=true;
  if (stripos($backend_url,'http')!==0 || stripos($public_api_url,'http')!==0) $ok=false;
  if (stripos($callback_url,'https://')!==0) $ok=false;
  if (strlen($callback_secret) < 32 || strlen($request_nonce) < 32) $ok=false;
  if (!$ok) { http_response_code(400); echo json_encode(['error'=>'Invalid pairing payload']); exit; }

  $pdo = connect($config);
  ensure_schema($pdo);
  $st = $pdo->prepare("INSERT INTO bff_pairing_requests(backend_url,public_api_url,public_host,callback_url,callback_secret,request_nonce,request_ip,status) VALUES(?,?,?,?,?,?,?,'pending') RETURNING id");
  $st->execute([$backend_url,$public_api_url,$public_host,$callback_url,$callback_secret,$request_nonce,$request_ip]);
  $id = (int)$st->fetchColumn();
  echo json_encode(['request_id'=>$id,'status'=>'pending']);
} catch(Throwable $e){
  http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
