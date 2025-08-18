<?php
declare(strict_types=1);
/**
 * Backend/Internal Admin Portal (met Pairing)
 * - DB configuratie + schema initialisatie
 * - Admin login
 * - Secrets: internal_secret (Public->Internal)
 * - Public API keys (hash in DB)
 * - Pairing workflow:
 *   * Public Admin POST -> backend_admin.php?pairing_api=request (JSON)
 *   * Admin keurt goed -> Backend genereert plaintext API key, pakt internal_secret
 *   * Backend POST callback (HMAC) -> Public Admin schrijft config
 */

@header('X-Robots-Tag: noindex');
session_start();

$configFile = __DIR__.'/internal_config.php';
$config = file_exists($configFile) ? include $configFile : [
  'db_dsn'=>'','db_user'=>'','db_pass'=>'',
  'internal_secret'=>'',
  'backend_admin_password_hash'=>''
];

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function save_config(array $cfg,string $file): bool {
  $code = "<?php\nreturn ".var_export($cfg,true).";\n";
  return (bool)file_put_contents($file,$code,LOCK_EX);
}
function connect(array $cfg): PDO {
  if (empty($cfg['db_dsn']) || empty($cfg['db_user'])) {
    throw new RuntimeException('DB configuratie ontbreekt.');
  }
  $pdo=new PDO($cfg['db_dsn'],$cfg['db_user'],$cfg['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  return $pdo;
}
function ensure_schema(PDO $pdo): void {
  // UUID via pgcrypto; als je host dit niet toestaat, kun je overschakelen op PHP-UUIDs (extensieloze variant).
  $sqls=[
    "CREATE EXTENSION IF NOT EXISTS pgcrypto;",
    "CREATE TABLE IF NOT EXISTS users(
      id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
      email TEXT UNIQUE NOT NULL,
      password_hash TEXT NOT NULL,
      email_verified_at TIMESTAMPTZ,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    "CREATE TABLE IF NOT EXISTS categories(
      id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
      user_id UUID NOT NULL,
      name TEXT NOT NULL,
      color TEXT,
      min_attention INT NOT NULL DEFAULT 0,
      max_attention INT NOT NULL DEFAULT 100,
      sort_index INT NOT NULL DEFAULT 0,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    "CREATE TABLE IF NOT EXISTS timelogs(
      id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
      user_id UUID NOT NULL,
      category_id UUID NOT NULL,
      start_time TIMESTAMPTZ NOT NULL,
      end_time TIMESTAMPTZ,
      duration INT,
      with_tasks TEXT,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    "CREATE TABLE IF NOT EXISTS email_verification_tokens(
      id SERIAL PRIMARY KEY,
      user_id UUID NOT NULL,
      token_hash TEXT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    "CREATE TABLE IF NOT EXISTS password_reset_tokens(
      id SERIAL PRIMARY KEY,
      user_id UUID NOT NULL,
      token_hash TEXT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    "CREATE TABLE IF NOT EXISTS api_keys(
      id SERIAL PRIMARY KEY,
      key_hash TEXT NOT NULL,
      label TEXT,
      role TEXT NOT NULL,
      active BOOLEAN NOT NULL DEFAULT TRUE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );",
    // Pairing requests
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
    "CREATE INDEX IF NOT EXISTS idx_cat_user_sort ON categories(user_id, sort_index);",
    "CREATE INDEX IF NOT EXISTS idx_logs_user_start ON timelogs(user_id, start_time DESC);",
    "CREATE INDEX IF NOT EXISTS idx_pair_status_created ON bff_pairing_requests(status, created_at DESC);"
  ];
  foreach($sqls as $q){ try{$pdo->exec($q);}catch(Throwable $e){} }
}

/* ==========================================================
   Pairing API endpoint (unauthenticated) — vóór login checks
   ========================================================== */
if (($_GET['pairing_api'] ?? '') === 'request') {
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  // Enkel POST + JSON
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'POST required']); exit;
  }
  if (!is_array($config) || empty($config['db_dsn'])) {
    http_response_code(503);
    echo json_encode(['error'=>'Backend not configured']); exit;
  }
  // Lees payload
  $raw = file_get_contents('php://input');
  $d   = json_decode($raw ?: '', true) ?: [];
  $backend_url    = trim((string)($d['backend_url'] ?? ''));
  $public_api_url = trim((string)($d['public_api_url'] ?? ''));
  $callback_url   = trim((string)($d['callback_url'] ?? ''));
  $callback_secret= (string)($d['callback_secret'] ?? '');
  $request_nonce  = (string)($d['request_nonce'] ?? '');
  $public_host    = trim((string)($d['public_host'] ?? ''));
  $request_ip     = $_SERVER['REMOTE_ADDR'] ?? '';

  // Minimale validatie
  $ok = true;
  if (stripos($backend_url,'http')!==0 || stripos($public_api_url,'http')!==0) $ok=false;
  if (stripos($callback_url,'https://')!==0) $ok=false; // callback moet https zijn
  if (strlen($callback_secret) < 32 || strlen($request_nonce) < 32) $ok=false;
  if (!$ok) { http_response_code(400); echo json_encode(['error'=>'Invalid pairing payload']); exit; }

  try{
    $pdo = connect($config);
    ensure_schema($pdo);
    $stmt = $pdo->prepare("INSERT INTO bff_pairing_requests(backend_url,public_api_url,public_host,callback_url,callback_secret,request_nonce,request_ip,status) VALUES(?,?,?,?,?,?,?,'pending') RETURNING id");
    $stmt->execute([$backend_url,$public_api_url,$public_host,$callback_url,$callback_secret,$request_nonce,$request_ip]);
    $id = (int)$stmt->fetchColumn();
    echo json_encode(['request_id'=>$id,'status'=>'pending']);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'DB error: '.$e->getMessage()]);
  }
  exit;
}

/* ==========================
   Eerste setup (bootstrap)
   ========================== */
if (empty($config['backend_admin_password_hash'])) {
  $err = null;
  if (($_POST['action'] ?? '') === 'bootstrap') {
    $dsn  = trim((string)($_POST['db_dsn'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    $pwd1 = (string)($_POST['admin_password'] ?? '');
    if (!$dsn || !$user || strlen($pwd1)<10) {
      $err='Vul DSN/user in en gebruik min. 10 tekens voor admin wachtwoord.';
    } else {
      $config['db_dsn']=$dsn; $config['db_user']=$user; $config['db_pass']=$pass;
      $config['backend_admin_password_hash']=password_hash($pwd1,PASSWORD_DEFAULT);
      if (empty($config['internal_secret'])) $config['internal_secret']=bin2hex(random_bytes(24));
      if (!save_config($config,$configFile)) {
        $err='Kon config niet schrijven.';
      } else {
        try{
          $pdo=connect($config); ensure_schema($pdo);
          $_SESSION['be_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit;
        }catch(Throwable $e){ $err='DB connect/schema fout: '.$e->getMessage(); }
      }
    }
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backend Admin Setup</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:900px;margin:auto;background:#f8fafc}.card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem}</style>
  <h1>Backend Admin – Eerste setup</h1>
  <?php if(!empty($err)) echo '<p style="color:#b91c1c">'.h($err).'</p>'; ?>
  <form method="post" class="card">
    <input type="hidden" name="action" value="bootstrap">
    <h2>Database</h2>
    <p>DSN (Postgres):<br><input name="db_dsn" placeholder="pgsql:host=/var/run/postgresql;dbname=DB" required style="width:100%"></p>
    <p>DB gebruiker:<br><input name="db_user" required style="width:100%"></p>
    <p>DB wachtwoord:<br><input name="db_pass" type="password" style="width:100%"></p>
    <h2>Admin</h2>
    <p>Nieuw admin wachtwoord:<br><input name="admin_password" type="password" required style="width:100%"></p>
    <p><button>Initialiseren</button></p>
  </form>
  <?php exit;
}

/* =========
   Login
   ========= */
if (empty($_SESSION['be_admin'])) {
  $err=null;
  if (($_POST['action'] ?? '')==='login') {
    if (password_verify((string)($_POST['password'] ?? ''), (string)$config['backend_admin_password_hash'])) {
      $_SESSION['be_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit;
    } else $err='Ongeldig wachtwoord.';
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backend Admin</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:900px;margin:auto;background:#f8fafc}.card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem}</style>
  <h1>Backend Admin</h1>
  <?php if($err) echo '<p style="color:#b91c1c">'.h($err).'</p>'; ?>
  <form method="post" class="card">
    <input type="hidden" name="action" value="login">
    <p>Wachtwoord:<br><input name="password" type="password" required style="width:100%"></p>
    <p><button>Login</button></p>
  </form>
  <?php exit;
}

/* =========================
   Acties na login
   ========================= */
$msg=null; $err=null;

// Probeer verbinding zodat UI keys/pairing kan tonen
$pdoLive=null;
try{ $pdoLive=connect($config); ensure_schema($pdoLive); }catch(Throwable $e){ $err=$e->getMessage(); }

// Save DB connectie
if (($_POST['action'] ?? '')==='save-conn') {
  $config['db_dsn']=trim((string)$_POST['db_dsn']);
  $config['db_user']=trim((string)$_POST['db_user']);
  $config['db_pass']=(string)$_POST['db_pass'];
  $msg = save_config($config,$configFile) ? 'Verbinding opgeslagen.' : 'Kon config niet schrijven.';
}

// Test verbinding
$lastTest=null;
if (($_POST['action'] ?? '')==='test-conn') {
  try{ $pdo=connect($config); $ver = $pdo->query('SELECT version()')->fetchColumn(); $lastTest = '✅ Verbinding OK — '.$ver; }
  catch(Throwable $e){ $lastTest = '❌ Verbinding mislukt: '.$e->getMessage(); }
}

// Schema initialiseren
if (($_POST['action'] ?? '')==='init-schema') {
  try{ $pdo=connect($config); ensure_schema($pdo); $msg='Schema OK.'; }
  catch(Throwable $e){ $err='Schema fout: '.$e->getMessage(); }
}

// Secrets
if (($_POST['action'] ?? '')==='rotate-secret') {
  $config['internal_secret']=bin2hex(random_bytes(24));
  $msg = save_config($config,$configFile) ? 'Internal secret vernieuwd.' : 'Kon config niet schrijven.';
}
if (($_POST['action'] ?? '')==='reveal-secret') {
  $pwd=(string)($_POST['confirm_password'] ?? '');
  if (password_verify($pwd, (string)$config['backend_admin_password_hash'])) {
    $msg='Internal secret: '.$config['internal_secret'];
  } else {
    $err='Onjuist wachtwoord.';
  }
}

// Pairing goed/afwijzen
if (($_POST['action'] ?? '')==='pair-approve' && $pdoLive) {
  try{
    $id=(int)$_POST['id'];
    $pdoLive->beginTransaction();
    $st=$pdoLive->prepare("SELECT * FROM bff_pairing_requests WHERE id=? FOR UPDATE");
    $st->execute([$id]); $row=$st->fetch(PDO::FETCH_ASSOC);
    if(!$row || $row['status']!=='pending') throw new RuntimeException('Verzoek niet gevonden of al verwerkt.');

    // Genereer plaintext Public API key + hash opslaan
    $plain = bin2hex(random_bytes(24));
    $hash  = password_hash($plain, PASSWORD_DEFAULT);
    $st2 = $pdoLive->prepare('INSERT INTO api_keys(key_hash,label,role,active) VALUES(?,?,?,TRUE)');
    $st2->execute([$hash,'pair: '.$row['public_host'],'public_api']);

    // Callback payload
    $payload = [
      'request_id'     => (int)$row['id'],
      'request_nonce'  => $row['request_nonce'],
      'backend_url'    => $row['backend_url'],
      'internal_secret'=> (string)($config['internal_secret'] ?? ''),
      'public_api_key' => $plain
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig  = base64_encode(hash_hmac('sha256', $json, $row['callback_secret'], true));

    // POST callback
    $ch = curl_init($row['callback_url']);
    curl_setopt_array($ch,[
      CURLOPT_POST=>true,
      CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_HTTPHEADER=>[
        'Content-Type: application/json',
        'X-Pair-Signature: '.$sig,
        'User-Agent: Timelog-Backend/1.0'
      ],
      CURLOPT_POSTFIELDS=>$json,
      CURLOPT_TIMEOUT=>20
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = $resp===false? curl_error($ch): null;
    curl_close($ch);
    if ($resp===false || $code>=400) {
      $pdoLive->rollBack();
      throw new RuntimeException('Callback mislukt (HTTP '.$code.'): '.($cerr ?? $resp));
    }

    // Markeer approved
    $pdoLive->prepare("UPDATE bff_pairing_requests SET status='approved', approved_at=NOW() WHERE id=?")->execute([$id]);
    $pdoLive->commit();
    $msg='Koppeling goedgekeurd en secrets verstuurd.';
  }catch(Throwable $e){
    if($pdoLive && $pdoLive->inTransaction()) $pdoLive->rollBack();
    $err='Pairing fout: '.$e->getMessage();
  }
}
if (($_POST['action'] ?? '')==='pair-deny' && $pdoLive) {
  try{
    $id=(int)$_POST['id'];
    $pdoLive->prepare("UPDATE bff_pairing_requests SET status='denied' WHERE id=? AND status='pending'")->execute([$id]);
    $msg='Koppeling geweigerd.';
  }catch(Throwable $e){ $err='Weigeren fout: '.$e->getMessage(); }
}

// Keys lijst
$keys=[];
if ($pdoLive){
  try{ $keys = $pdoLive->query("SELECT id,label,role,active,created_at FROM api_keys ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ $err=$err??$e->getMessage(); }
}

// Pending pairings
$pairings=[];
if ($pdoLive){
  try{ $pairings = $pdoLive->query("SELECT id,backend_url,public_api_url,public_host,callback_url,status,created_at FROM bff_pairing_requests WHERE status='pending' ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC); }
  catch(Throwable $e){ $err=$err??$e->getMessage(); }
}

?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backend Admin</title>
<style>
  body{font:16px system-ui;padding:2rem;max-width:1000px;margin:auto;background:#f8fafc}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;margin-bottom:1rem}
  input{width:100%}
  table{width:100%;border-collapse:collapse}
  th,td{border-bottom:1px solid #e5e7eb;padding:.5rem;text-align:left;vertical-align:top}
  .ok{color:#16a34a}.err{color:#b91c1c}
  code{background:#f1f5f9;padding:0 .25rem;border-radius:.25rem}
  button{cursor:pointer}
</style>
<h1>Backend Admin</h1>
<?php if($msg) echo '<p class="ok">'.h($msg).'</p>'; ?>
<?php if($err) echo '<p class="err">'.h($err).'</p>'; ?>

<div class="card">
  <h2>Database</h2>
  <form method="post">
    <input type="hidden" name="action" value="save-conn">
    <p>DSN:<br><input name="db_dsn" value="<?=h($config['db_dsn'])?>" placeholder="pgsql:host=/var/run/postgresql;dbname=DB"></p>
    <p>DB user:<br><input name="db_user" value="<?=h($config['db_user'])?>"></p>
    <p>DB pass:<br><input name="db_pass" type="password" placeholder="(ongewijzigd)"></p>
    <p><button>Opslaan</button></p>
  </form>
  <form method="post" style="margin-top:.5rem"><input type="hidden" name="action" value="test-conn"><button>Test DB‑verbinding</button></form>
  <?php if(isset($lastTest)) echo '<p>'.h($lastTest).'</p>'; ?>
  <p>Pdo drivers: <code><?=h(implode(', ', PDO::getAvailableDrivers()))?></code></p>
</div>

<div class="card">
  <h2>Schema</h2>
  <form method="post"><input type="hidden" name="action" value="init-schema"><button>Schema initialiseren/valideren</button></form>
</div>

<div class="card">
  <h2>Secrets</h2>
  <p>Internal secret (Public→Internal): <code><?=h(substr((string)($config['internal_secret']??''),0,8))?>…</code></p>
  <form method="post" style="display:inline-block;margin-right:.5rem">
    <input type="hidden" name="action" value="rotate-secret">
    <button style="background:#b91c1c;color:#fff;padding:.4rem .8rem;border-radius:.4rem">Rotate secret</button>
  </form>
  <form method="post" style="display:inline-block">
    <input type="hidden" name="action" value="reveal-secret">
    <input type="password" name="confirm_password" placeholder="Admin wachtwoord" required>
    <button>Toon secret</button>
  </form>
</div>

<div class="card">
  <h2>Public API keys</h2>
  <p>Keys worden automatisch aangemaakt bij pairing. Handmatig genereren kan via DB/CLI.</p>
  <table>
    <thead><tr><th>ID</th><th>Label</th><th>Role</th><th>Actief</th><th>Aangemaakt</th></tr></thead>
    <tbody>
      <?php foreach($keys as $k): ?>
      <tr>
        <td><?=h((string)$k['id'])?></td>
        <td><?=h((string)($k['label']??''))?></td>
        <td><?=h((string)$k['role'])?></td>
        <td><?= $k['active'] ? 'ja' : 'nee' ?></td>
        <td><?=h((string)$k['created_at'])?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2>Koppelingen (Public API Pairing)</h2>
  <?php if(!$pdoLive): ?>
    <p class="err">DB niet verbonden — test de verbinding eerst.</p>
  <?php endif; ?>
  <?php if($pairings): ?>
    <table>
      <thead><tr><th>ID</th><th>Public host</th><th>Public API URL</th><th>Callback</th><th>Ontvangen</th><th>Actie</th></tr></thead>
      <tbody>
      <?php foreach($pairings as $p): ?>
        <tr>
          <td><?=h((string)$p['id'])?></td>
          <td><?=h($p['public_host'])?></td>
          <td><?=h($p['public_api_url'])?></td>
          <td><?=h($p['callback_url'])?></td>
          <td><?=h((string)$p['created_at'])?></td>
          <td>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="pair-approve">
              <input type="hidden" name="id" value="<?=h((string)$p['id'])?>">
              <button>Goedkeuren</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="pair-deny">
              <input type="hidden" name="id" value="<?=h((string)$p['id'])?>">
              <button>Weigeren</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p>Geen openstaande koppelaanvragen.</p>
  <?php endif; ?>
</div>

<form method="post" action="?action=logout"><button>Uitloggen</button></form>
