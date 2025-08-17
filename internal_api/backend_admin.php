<?php
declare(strict_types=1);
/**
 * Backend/Internal Admin Portal (verbeterd)
 * - Init DB credentials + internal secret
 * - Test DB-verbinding expliciet
 * - Schema initialiseren alleen als verbinding OK is
 * - API keys genereren/revoken
 * - Inloggen vereist
 */
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
  // Let op: SSL vereiste? Gebruik ...;sslmode=require in DSN
  $pdo=new PDO(
    $cfg['db_dsn'],
    $cfg['db_user'],
    $cfg['db_pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
  );
  return $pdo;
}

function init_schema(PDO $pdo): void {
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
    "CREATE INDEX IF NOT EXISTS idx_cat_user_sort ON categories(user_id, sort_index);",
    "CREATE INDEX IF NOT EXISTS idx_logs_user_start ON timelogs(user_id, start_time DESC);"
  ];
  foreach($sqls as $q){ try{$pdo->exec($q);}catch(Throwable $e){} }
}

// --- Bootstrap (eerste setup) ---
if (empty($config['backend_admin_password_hash'])) {
  $msg = null; $err = null;
  if (($_POST['action'] ?? '') === 'bootstrap') {
    $dsn  = trim((string)($_POST['db_dsn'] ?? ''));
    $user = trim((string)($_POST['db_user'] ?? ''));
    $pass = (string)($_POST['db_pass'] ?? '');
    $pwd1 = (string)($_POST['admin_password'] ?? '');
    if (!$dsn || !$user || strlen($pwd1)<10) $err='Vul DSN/user in en gebruik min. 10 tekens voor admin wachtwoord.';
    else {
      $config['db_dsn']=$dsn; $config['db_user']=$user; $config['db_pass']=$pass;
      $config['backend_admin_password_hash']=password_hash($pwd1,PASSWORD_DEFAULT);
      $config['internal_secret']=bin2hex(random_bytes(24));
      if (!save_config($config,$configFile)) $err='Kon config niet schrijven.';
      else {
        try{
          $pdo=connect($config);
          init_schema($pdo);
          $_SESSION['be_admin']=true;
          header('Location: '.$_SERVER['PHP_SELF']); exit;
        }catch(Throwable $e){ $err='DB connect/schema fout: '.$e->getMessage(); }
      }
    }
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Backend Admin Setup</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:900px;margin:auto;background:#f8fafc}.card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem}</style>
  <h1>Backend Admin – Eerste setup</h1>
  <?php if(!empty($msg)) echo '<p class="ok">'.h($msg).'</p>'; ?>
  <?php if(!empty($err)) echo '<p style="color:#b91c1c">'.h($err).'</p>'; ?>
  <form method="post" class="card">
    <input type="hidden" name="action" value="bootstrap">
    <h2>Database</h2>
    <p>DSN (Postgres):<br>
      <input name="db_dsn" placeholder="pgsql:host=HOST;port=5432;dbname=DB;sslmode=require" required style="width:100%">
    </p>
    <p>DB gebruiker:<br><input name="db_user" required style="width:100%"></p>
    <p>DB wachtwoord:<br><input name="db_pass" type="password" style="width:100%"></p>
    <h2>Admin</h2>
    <p>Nieuw admin wachtwoord:<br><input name="admin_password" type="password" required style="width:100%"></p>
    <p><button>Initialiseren</button></p>
  </form>
  <?php exit;
}

// --- Login ---
if (empty($_SESSION['be_admin'])) {
  $msg=null; $err=null;
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

// --- Acties (na login) ---
$msg=null; $err=null;

// Save DB connectiegegevens
if (($_POST['action'] ?? '')==='save-conn') {
  $config['db_dsn']=trim((string)$_POST['db_dsn']);
  $config['db_user']=trim((string)$_POST['db_user']);
  $config['db_pass']=(string)$_POST['db_pass'];
  $msg = save_config($config,$configFile) ? 'Verbinding opgeslagen.' : 'Kon config niet schrijven.';
}

// Test verbinding
$drivers = PDO::getAvailableDrivers();
$lastTest=null;
if (($_POST['action'] ?? '')==='test-conn') {
  try{
    $pdo=connect($config);
    $ver = $pdo->query('SELECT version()')->fetchColumn();
    $lastTest = '✅ Verbinding OK — '.$ver;
  }catch(Throwable $e){
    $lastTest = '❌ Verbinding mislukt: '.$e->getMessage();
  }
}

// Schema initialiseren
if (($_POST['action'] ?? '')==='init-schema') {
  try{
    $pdo=connect($config); // expliciet verbinden; als dit faalt, gaat het naar catch
    init_schema($pdo);
    $msg='Schema OK.';
  }catch(Throwable $e){
    $err='Schema fout: '.$e->getMessage();
  }
}

// Secrets / keys
if (($_POST['action'] ?? '')==='rotate-secret') {
  $config['internal_secret']=bin2hex(random_bytes(24));
  $msg = save_config($config,$configFile) ? 'Internal secret vernieuwd.' : 'Kon config niet schrijven.';
}
function connect_or_null(array $cfg): ?PDO {
  try{ return connect($cfg); }catch(Throwable $e){ return null; }
}
$pdoLive = connect_or_null($config);

if (($_POST['action'] ?? '')==='gen-key' && $pdoLive) {
  try{
    $label = trim((string)($_POST['label'] ?? 'Public API'));
    $plain = bin2hex(random_bytes(24));
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $st = $pdoLive->prepare('INSERT INTO api_keys(key_hash,label,role,active) VALUES(?,?,?,TRUE)');
    $st->execute([$hash,$label,'public_api']);
    $msg = 'Nieuwe Public API key (bewaar nu!): '.$plain;
  }catch(Throwable $e){ $err='Key fout: '.$e->getMessage(); }
} elseif (($_POST['action'] ?? '')==='gen-key' && !$pdoLive) {
  $err='Genereer keys: DB niet verbonden. Test de verbinding eerst.';
}

if (($_POST['action'] ?? '')==='revoke' && $pdoLive) {
  try{
    $id=(int)$_POST['id']; $pdoLive->prepare('UPDATE api_keys SET active=FALSE WHERE id=?')->execute([$id]);
    $msg='Key revoked.';
  }catch(Throwable $e){ $err='Revocation fout: '.$e->getMessage(); }
} elseif (($_POST['action'] ?? '')==='revoke' && !$pdoLive) {
  $err='Revocation: DB niet verbonden.';
}

// Keys ophalen (als DB werkt)
$keys=[];
if ($pdoLive){
  try{ $keys = $pdoLive->query("SELECT id,label,role,active,created_at FROM api_keys ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC); }
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
  th,td{border-bottom:1px solid #e5e7eb;padding:.5rem;text-align:left}
  .ok{color:#16a34a}.err{color:#b91c1c}
  code{background:#f1f5f9;padding:0 .25rem;border-radius:.25rem}
</style>
<h1>Backend Admin</h1>
<?php if($msg) echo '<p class="ok">'.h($msg).'</p>'; ?>
<?php if($err) echo '<p class="err">'.h($err).'</p>'; ?>

<div class="card">
  <h2>Database</h2>
  <form method="post">
    <input type="hidden" name="action" value="save-conn">
    <p>DSN:<br>
      <input name="db_dsn" value="<?=h($config['db_dsn'])?>" placeholder="pgsql:host=HOST;port=5432;dbname=DB;sslmode=require">
    </p>
    <p>DB user:<br><input name="db_user" value="<?=h($config['db_user'])?>"></p>
    <p>DB pass:<br><input name="db_pass" type="password" placeholder="(ongewijzigd)"></p>
    <p><button>Opslaan</button></p>
  </form>

  <form method="post" style="margin-top:.5rem">
    <input type="hidden" name="action" value="test-conn">
    <button>Test DB‑verbinding</button>
  </form>
  <?php if($lastTest !== null): ?>
    <p><?=h($lastTest)?></p>
  <?php endif; ?>

  <p>Pdo drivers: <code><?=h(implode(', ', $drivers))?></code></p>
</div>

<div class="card">
  <h2>Schema</h2>
  <form method="post">
    <input type="hidden" name="action" value="init-schema">
    <button>Schema initialiseren/valideren</button>
  </form>
  <p style="color:#475569;font-size:14px">
    Tip: bij cloud Postgres is vaak SSL verplicht. Gebruik in DSN <code>sslmode=require</code> of stricter (verify‑ca/verify‑full met <code>sslrootcert</code>).
    Sommige providers vragen ook om je webserver‑IP te whitelisten in hun dashboard.
  </p>
</div>

<div class="card">
  <h2>Secrets</h2>
  <p>Internal secret (Public→Internal): <code><?=h(substr((string)($config['internal_secret']??''),0,8))?>…</code></p>
  <form method="post">
    <input type="hidden" name="action" value="rotate-secret">
    <button style="background:#b91c1c;color:#fff;padding:.4rem .8rem;border-radius:.4rem">Rotate secret</button>
  </form>
</div>

<div class="card">
  <h2>Public API keys</h2>
  <form method="post" style="margin-bottom:.75rem">
    <input type="hidden" name="action" value="gen-key">
    <p>Label (optioneel):<br><input name="label" placeholder="Public API key label"></p>
    <p><button>Genereer nieuwe key</button></p>
  </form>
  <?php if(!$pdoLive): ?>
    <p class="err">DB niet verbonden — test de verbinding eerst om keys te beheren.</p>
  <?php endif; ?>
  <table>
    <thead><tr><th>ID</th><th>Label</th><th>Role</th><th>Actief</th><th>Aangemaakt</th><th>Actie</th></tr></thead>
    <tbody>
      <?php foreach($keys as $k): ?>
      <tr>
        <td><?=h((string)$k['id'])?></td>
        <td><?=h((string)($k['label']??''))?></td>
        <td><?=h((string)$k['role'])?></td>
        <td><?= $k['active'] ? 'ja' : 'nee' ?></td>
        <td><?=h((string)$k['created_at'])?></td>
        <td>
          <?php if($k['active']): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="action" value="revoke">
            <input type="hidden" name="id" value="<?=h((string)$k['id'])?>">
            <button>Revoke</button>
          </form>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<form method="post" action="?action=logout"><button>Uitloggen</button></form>
