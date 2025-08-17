<?php
declare(strict_types=1);
/**
 * Public/BFF Admin Portal
 * - Beheer public_config.php via web
 * - Inloggen vereist (server-side wachtwoord)
 * - SMTP velden + testmail
 */
session_start();
$configFile = __DIR__.'/public_config.php';
$config = file_exists($configFile) ? include $configFile : [
  'backend_url'=>'','internal_secret'=>'','api_key'=>'',
  'allowed_origins'=>[],'session_cookie_name'=>'tm_sid','session_lifetime'=>604800,
  'mail_from'=>'','app_url'=>'',
  'frontend_admin_password_hash'=>'',
  'public_admin_password_hash'=>'',
  'setup_token'=>'',
  'smtp'=>[
    'host'=>'','port'=>587,'secure'=>'tls','username'=>'','password'=>'',
    'from_email'=>'','from_name'=>'Timelog','reply_to'=>null
  ],
  'rate_limits'=>[]
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
  $msg = null;
  if ($action==='bootstrap') {
    if (!hash_equals((string)$config['setup_token'], (string)($_POST['setup_token'] ?? ''))) {
      $msg = "Ongeldige setup token.";
    } else {
      $pwd1 = (string)($_POST['new_password'] ?? '');
      if (strlen($pwd1) < 10)       $msg = "Wachtwoord te kort (min 10).";
      else {
        $config['public_admin_password_hash'] = password_hash($pwd1, PASSWORD_DEFAULT);
        $config['setup_token'] = bin2hex(random_bytes(8));
        if (!save_config($config,$configFile)) $msg = "Kon config niet schrijven.";
        else { $_SESSION['pub_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit; }
      }
    }
  }
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
  $msg = null;
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
  // FE admin wachtwoord (optioneel)
  if (!empty($_POST['fe_admin_password'])) {
    $config['frontend_admin_password_hash'] = password_hash((string)$_POST['fe_admin_password'], PASSWORD_DEFAULT);
  }
  // SMTP
  $config['smtp']['host']       = trim((string)($_POST['smtp_host'] ?? ''));
  $config['smtp']['port']       = (int)($_POST['smtp_port'] ?? 587);
  $config['smtp']['secure']     = in_array($_POST['smtp_secure'] ?? 'tls', ['tls','ssl'], true) ? $_POST['smtp_secure'] : 'tls';
  $config['smtp']['username']   = trim((string)($_POST['smtp_user'] ?? ''));
  $config['smtp']['password']   = (string)($_POST['smtp_pass'] ?? '');
  $config['smtp']['from_email'] = trim((string)($_POST['smtp_from_email'] ?? ''));
  $config['smtp']['from_name']  = trim((string)($_POST['smtp_from_name']  ?? 'Timelog'));
  $config['smtp']['reply_to']   = trim((string)($_POST['smtp_reply_to']   ?? '')) ?: null;

  $msg = save_config($config,$configFile) ? 'Opgeslagen.' : 'Kon config niet schrijven.';
}

// Test e‑mail
if ($action==='test-mail') {
  $to = trim((string)($_POST['to'] ?? ''));
  $ok = false; $err = null;
  try {
    // Mini PHPMailer boot (zelfde als in public_api.php)
    $autoload1 = __DIR__.'/vendor/autoload.php';
    $autoload2 = __DIR__.'/../vendor/autoload.php';
    if (is_readable($autoload1)) require_once $autoload1;
    elseif (is_readable($autoload2)) require_once $autoload2;
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
      $base = __DIR__.'/lib/phpmailer/src';
      if (is_readable($base.'/PHPMailer.php')) {
        require_once $base.'/Exception.php'; require_once $base.'/PHPMailer.php'; require_once $base.'/SMTP.php';
      }
    }
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) throw new RuntimeException('PHPMailer ontbreekt');

    $cfg = $config['smtp'] ?? [];
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = (string)($cfg['host'] ?? '');
    $mail->Port = (int)($cfg['port'] ?? 587);
    $sec = (string)($cfg['secure'] ?? 'tls');
    if ($sec === 'ssl') $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    else $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->SMTPAuth = true;
    $mail->Username = (string)($cfg['username'] ?? '');
    $mail->Password = (string)($cfg['password'] ?? '');
    $mail->setFrom((string)($cfg['from_email'] ?? ''), (string)($cfg['from_name'] ?? 'Timelog'));
    if (!empty($cfg['reply_to'])) $mail->addReplyTo((string)$cfg['reply_to']);
    $mail->isHTML(true); $mail->CharSet='UTF-8';

    $mail->addAddress($to);
    $mail->Subject = 'Testmail Timelog';
    $mail->Body    = '<p>Dit is een test vanuit Public Admin.</p>';
    $mail->send(); $ok=true;
  } catch (Throwable $e) { $err = $e->getMessage(); }
  $msg = $ok ? 'Testmail verzonden.' : ('Mislukt: '.($err ?? 'onbekend'));
}

// --- View ---
?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Admin</title>
<style>
  body{font:16px system-ui;padding:2rem;max-width:980px;margin:auto;background:#f8fafc}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;margin-bottom:1rem}
  input,textarea,select{width:100%}
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
  <p>E-mail “From” (fallback, niet gebruikt bij SMTP):<br><input name="mail_from" value="<?=h($config['mail_from'])?>"></p>
  <p>Session cookie naam:<br><input name="session_cookie_name" value="<?=h($config['session_cookie_name'])?>"></p>
  <p>Session lifetime (seconden):<br><input name="session_lifetime" value="<?=h((string)$config['session_lifetime'])?>"></p>

  <h2>Front-end admin (PWA) wachtwoord</h2>
  <p>Nieuw FE-admin wachtwoord (optioneel):<br><input name="fe_admin_password" type="password" placeholder="Laat leeg om niet te wijzigen"></p>

  <h2>SMTP (PHPMailer)</h2>
  <div class="grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
    <p>Host<br><input name="smtp_host" value="<?=h($config['smtp']['host'])?>"></p>
    <p>Port<br><input name="smtp_port" value="<?=h((string)$config['smtp']['port'])?>"></p>
    <p>Secure<br>
      <select name="smtp_secure">
        <option value="tls" <?=($config['smtp']['secure']==='tls'?'selected':'')?>>tls</option>
        <option value="ssl" <?=($config['smtp']['secure']==='ssl'?'selected':'')?>>ssl</option>
      </select>
    </p>
    <p>Username<br><input name="smtp_user" value="<?=h($config['smtp']['username'])?>"></p>
    <p>Password<br><input type="password" name="smtp_pass" value="<?=h($config['smtp']['password'])?>"></p>
    <p>From e‑mail<br><input name="smtp_from_email" value="<?=h($config['smtp']['from_email'])?>"></p>
    <p>From naam<br><input name="smtp_from_name" value="<?=h($config['smtp']['from_name'])?>"></p>
    <p>Reply‑To (optioneel)<br><input name="smtp_reply_to" value="<?=h((string)($config['smtp']['reply_to'] ?? ''))?>"></p>
  </div>
  <p><button>Opslaan</button></p>
</form>

<form method="post" class="card">
  <input type="hidden" name="action" value="test-mail">
  <h2>Verstuur test e‑mail</h2>
  <p>Naar (e‑mail):<br><input name="to" required></p>
  <p><button>Verstuur</button></p>
</form>

<form method="post" action="?action=logout"><button>Uitloggen</button></form>
