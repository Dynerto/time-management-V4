<?php
declare(strict_types=1);
/**
 * Public/BFF Admin Portal
 * - Beheer public_config.php via web
 * - Inloggen vereist (server-side wachtwoord)
 */
session_start();
$configFile = __DIR__.'/public_config.php';
$config = file_exists($configFile) ? include $configFile : [
  'backend_url'=>'','internal_secret'=>'','api_key'=>'',
  'allowed_origins'=>[],'session_cookie_name'=>'tm_sid','session_lifetime'=>604800,
  'mail_from'=>'','app_url'=>'',
  'frontend_admin_password_hash'=>'',
  'public_admin_password_hash'=>'',
  'setup_token'=>''
];

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function save_config(array $cfg,string $file): bool {
  $code = "<?php\nreturn ".var_export($cfg,true).";\n";
  return (bool)file_put_contents($file,$code,LOCK_EX);
}

// --- Routing ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- First time bootstrap ---
if (empty($config['public_admin_password_hash'])) {
  // Eerste setup verifiëren met setup_token
  if ($action==='bootstrap') {
    if (!hash_equals((string)$config['setup_token'], (string)($_POST['setup_token'] ?? ''))) {
      $msg = "Ongeldige setup token."; include __DIR__.'/_public_admin_view.php'; exit;
    }
    $pwd1 = (string)($_POST['new_password'] ?? '');
    if (strlen($pwd1) < 10) { $msg = "Wachtwoord te kort (min 10)."; include __DIR__.'/_public_admin_view.php'; exit; }
    $config['public_admin_password_hash'] = password_hash($pwd1, PASSWORD_DEFAULT);
    $config['setup_token'] = bin2hex(random_bytes(8)); // burn
    save_config($config,$configFile);
    $_SESSION['pub_admin']=true;
    header('Location: '.$_SERVER['PHP_SELF']); exit;
  }
  // Toon bootstrap view
  $msg = $msg ?? null;
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Public Admin Setup</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:720px;margin:auto}</style>
  <h1>Public Admin – Eerste setup</h1>
  <?php if(!empty($msg)) echo '<p style="color:#b91c1c">'.h($msg).'</p>'; ?>
  <form method="post">
    <input type="hidden" name="action" value="bootstrap">
    <p>Setup token:<br><input name="setup_token" required style="width:100%"></p>
    <p>Nieuw admin wachtwoord:<br><input name="new_password" type="password" required style="width:100%"></p>
    <p><button>Initialiseren</button></p>
  </form>
  <?php exit;
}

// --- Login ---
if (empty($_SESSION['pub_admin'])) {
  if ($action==='login') {
    if (password_verify((string)($_POST['password'] ?? ''), (string)$config['public_admin_password_hash'])) {
      $_SESSION['pub_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit;
    } else $msg='Ongeldig wachtwoord.';
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Public Admin</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:820px;margin:auto}</style>
  <h1>Public Admin</h1>
  <?php if(!empty($msg)) echo '<p style="color:#b91c1c">'.h($msg).'</p>'; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <p>Wachtwoord:<br><input name="password" type="password" required style="width:100%"></p>
    <p><button>Login</button></p>
  </form>
  <?php exit;
}

// --- Save config ---
if ($action==='save') {
  $config['backend_url']     = trim((string)($_POST['backend_url'] ?? ''));
  $config['internal_secret'] = trim((string)($_POST['internal_secret'] ?? ''));
  $config['api_key']         = trim((string)($_POST['api_key'] ?? ''));
  $config['mail_from']       = trim((string)($_POST['mail_from'] ?? ''));
  $config['app_url']         = trim((string)($_POST['app_url'] ?? ''));
  $config['session_cookie_name'] = trim((string)($_POST['session_cookie_name'] ?? 'tm_sid'));
  $config['session_lifetime']    = (int)($_POST['session_lifetime'] ?? 604800);
  $origins = array_filter(array_map('trim', explode("\n", (string)($_POST['allowed_origins'] ?? ''))));
  $config['allowed_origins'] = array_values(array_unique($origins));
  // Optioneel FE admin wachtwoord aanpassen
  if (!empty($_POST['fe_admin_password'])) {
    $config['frontend_admin_password_hash'] = password_hash((string)$_POST['fe_admin_password'], PASSWORD_DEFAULT);
  }
  save_config($config,$configFile);
  $msg='Opgeslagen.';
}

// --- View ---
?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Admin</title>
<style>
  body{font:16px system-ui;padding:2rem;max-width:980px;margin:auto;background:#f8fafc}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;margin-bottom:1rem}
  input,textarea{width:100%}
</style>
<h1>Public Admin</h1>
<?php if(!empty($msg)) echo '<p style="color:#16a34a">'.h($msg).'</p>'; ?>
<form method="post" class="card">
  <input type="hidden" name="action" value="save">
  <h2>Verbinding met Backend</h2>
  <p>Backend URL (internal_api.php):<br><input name="backend_url" value="<?=h($config['backend_url'])?>"></p>
  <p>Internal Secret:<br><input name="internal_secret" value="<?=h($config['internal_secret'])?>"></p>
  <p>Public API key (plaintext):<br><input name="api_key" value="<?=h($config['api_key'])?>"></p>
  <h2>Front-end instellingen</h2>
  <p>Allowed Origins (één per regel):<br><textarea name="allowed_origins" rows="4"><?=h(implode("\n",$config['allowed_origins']))?></textarea></p>
  <p>App URL (voor verify/reset links):<br><input name="app_url" value="<?=h($config['app_url'])?>"></p>
  <p>E-mail “From” adres:<br><input name="mail_from" value="<?=h($config['mail_from'])?>"></p>
  <p>Session cookie naam:<br><input name="session_cookie_name" value="<?=h($config['session_cookie_name'])?>"></p>
  <p>Session lifetime (seconden):<br><input name="session_lifetime" value="<?=h((string)$config['session_lifetime'])?>"></p>
  <h2>Front-end admin (PWA) wachtwoord</h2>
  <p>Nieuw FE-admin wachtwoord (optioneel):<br><input name="fe_admin_password" type="password" placeholder="Laat leeg om niet te wijzigen"></p>
  <p><button>Opslaan</button></p>
</form>
<form method="post" action="?action=logout"><button>Uitloggen</button></form>
