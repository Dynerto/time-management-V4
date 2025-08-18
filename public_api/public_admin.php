<?php
declare(strict_types=1);
/**
 * Public/BFF Admin Portal
 * - Één-klik koppeling (pairing) met Backend (admin of pairing endpoint)
 * - CORS, cookies/cross-domain instellingen
 * - SMTP config + testmail (PHPMailer)
 * - Inloggen vereist
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
  'rate_limits'=>[],
  'cookie_samesite'      => 'auto',
  'cookie_domain'        => '',
  'force_secure_cookies' => true,
  'pending_pairing'      => null
];

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function save_config(array $cfg,string $file): bool {
  $code = "<?php\nreturn ".var_export($cfg,true).";\n";
  return (bool)file_put_contents($file,$code,LOCK_EX);
}
function url_base(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return $scheme.'://'.$host;
}
function public_api_url(): string { return rtrim(url_base(),'/').'/public_api.php'; }
function this_admin_url(): string { $path = strtok($_SERVER['REQUEST_URI'],'?') ?: '/public_admin.php'; return rtrim(url_base(),'/') . $path; }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$msg = null; $err = null;

/* Bootstrap */
if (empty($config['public_admin_password_hash'])) {
  if ($action==='bootstrap') {
    $tokenProvided = (string)($_POST['setup_token'] ?? '');
    $pwd1 = (string)($_POST['new_password'] ?? '');
    if (strlen($pwd1) < 10) { $err = "Wachtwoord te kort (min 10)."; }
    else {
      if (!empty($config['setup_token']) && !hash_equals((string)$config['setup_token'], $tokenProvided)) {
        $err = "Ongeldige setup token.";
      } else {
        $config['public_admin_password_hash'] = password_hash($pwd1, PASSWORD_DEFAULT);
        $config['setup_token'] = bin2hex(random_bytes(8));
        if (!save_config($config,$configFile)) { $err = "Kon config niet schrijven."; }
        else { $_SESSION['pub_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit; }
      }
    }
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Public Admin Setup</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:720px;margin:auto}</style>
  <h1>Public Admin – Eerste setup</h1>
  <?php if($msg) echo '<p style="color:#16a34a">'.h($msg).'</p>'; ?>
  <?php if($err) echo '<p style="color:#b91c1c">'.h($err).'</p>'; ?>
  <form method="post">
    <input type="hidden" name="action" value="bootstrap">
    <p>Setup token (optioneel als config leeg is):<br><input name="setup_token" style="width:100%"></p>
    <p>Nieuw admin wachtwoord (min 10 tekens):<br><input name="new_password" type="password" required style="width:100%"></p>
    <p><button>Initialiseren</button></p>
  </form>
  <?php exit;
}

/* Login */
if (empty($_SESSION['pub_admin'])) {
  if ($action==='login') {
    if (password_verify((string)($_POST['password'] ?? ''), (string)$config['public_admin_password_hash'])) {
      $_SESSION['pub_admin']=true; header('Location: '.$_SERVER['PHP_SELF']); exit;
    } else $err='Ongeldig wachtwoord.';
  }
  ?>
  <!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Public Admin</title>
  <style>body{font:16px system-ui;padding:2rem;max-width:820px;margin:auto}</style>
  <h1>Public Admin</h1>
  <?php if($err) echo '<p style="color:#b91c1c">'.h($err).'</p>'; ?>
  <form method="post">
    <input type="hidden" name="action" value="login">
    <p>Wachtwoord:<br><input name="password" type="password" required style="width:100%"></p>
    <p><button>Login</button></p>
  </form>
  <?php exit;
}

/* Opslaan algemene config */
if ($action==='save') {
  $config['backend_url']     = trim((string)($_POST['backend_url'] ?? $config['backend_url']));
  $config['internal_secret'] = trim((string)($_POST['internal_secret'] ?? $config['internal_secret']));
  $config['api_key']         = trim((string)($_POST['api_key'] ?? $config['api_key']));
  $config['mail_from']       = trim((string)($_POST['mail_from'] ?? $config['mail_from']));
  $config['app_url']         = trim((string)($_POST['app_url'] ?? $config['app_url']));
  $config['session_cookie_name'] = trim((string)($_POST['session_cookie_name'] ?? $config['session_cookie_name']));
  $config['session_lifetime']    = (int)($_POST['session_lifetime'] ?? $config['session_lifetime']);

  // cookies
  $mode = strtolower(trim((string)($_POST['cookie_samesite'] ?? $config['cookie_samesite'])));
  if (!in_array($mode, ['auto','strict','lax','none'], true)) $mode='auto';
  $config['cookie_samesite']      = $mode;
  $config['cookie_domain']        = trim((string)($_POST['cookie_domain'] ?? $config['cookie_domain']));
  $config['force_secure_cookies'] = !empty($_POST['force_secure_cookies']);

  // origins
  $origins = array_filter(array_map('trim', explode("\n", (string)($_POST['allowed_origins'] ?? implode("\n",$config['allowed_origins'])))));
  $config['allowed_origins'] = array_values(array_unique($origins));

  // FE admin wachtwoord
  if (!empty($_POST['fe_admin_password'])) {
    $config['frontend_admin_password_hash'] = password_hash((string)$_POST['fe_admin_password'], PASSWORD_DEFAULT);
  }

  // SMTP
  $config['smtp']['host']       = trim((string)($_POST['smtp_host'] ?? $config['smtp']['host']));
  $config['smtp']['port']       = (int)($_POST['smtp_port'] ?? $config['smtp']['port']);
  $config['smtp']['secure']     = in_array($_POST['smtp_secure'] ?? $config['smtp']['secure'], ['tls','ssl'], true) ? $_POST['smtp_secure'] : 'tls';
  $config['smtp']['username']   = trim((string)($_POST['smtp_user'] ?? $config['smtp']['username']));
  if (isset($_POST['smtp_pass']) && $_POST['smtp_pass']!=='') $config['smtp']['password'] = (string)$_POST['smtp_pass'];
  $config['smtp']['from_email'] = trim((string)($_POST['smtp_from_email'] ?? $config['smtp']['from_email']));
  $config['smtp']['from_name']  = trim((string)($_POST['smtp_from_name']  ?? $config['smtp']['from_name']));
  $config['smtp']['reply_to']   = trim((string)($_POST['smtp_reply_to']   ?? (string)$config['smtp']['reply_to'])) ?: null;

  $msg = save_config($config,$configFile) ? 'Opgeslagen.' : 'Kon config niet schrijven.';
}

/* SMTP testmail */
if ($action==='test-mail') {
  $to = trim((string)($_POST['to'] ?? ''));
  $ok=false; $eMsg=null;
  try {
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
    if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) throw new RuntimeException('PHPMailer ontbreekt (Composer of lib/phpmailer/src).');

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
    $mail->Subject = 'Testmail Timelog (Public Admin)';
    $mail->Body    = '<p>Dit is een test vanaf de Public/BFF Admin.</p>';
    $mail->send(); $ok=true;
  } catch (Throwable $e) { $eMsg=$e->getMessage(); }
  $msg = $ok ? 'Testmail verzonden.' : ('Mislukt: '.($eMsg ?? 'onbekend'));
}

/* Pairing start */
if ($action==='pair-start') {
  $backend_url = trim((string)($_POST['backend_url'] ?? ''));
  if (!preg_match('~^https?://~i', $backend_url)) $backend_url = 'https://'.$backend_url;

  // Normaliseer: als pad geen .php is, gebruik backend_pairing.php (minder WAF/403 issues)
  $u = parse_url($backend_url); $path = $u['path'] ?? '/';
  if (!preg_match('~\.php$~i', $path)) {
    $backend_url = rtrim($backend_url,'/').'/backend_pairing.php';
  }

  $publicApi = public_api_url();
  $callback  = this_admin_url().'?action=pair-callback';
  $secret    = bin2hex(random_bytes(32));
  $nonce     = bin2hex(random_bytes(16));

  $config['pending_pairing'] = [
    'backend_url'    => $backend_url,
    'public_api_url' => $publicApi,
    'callback_url'   => $callback,
    'callback_secret'=> $secret,
    'request_nonce'  => $nonce,
    'request_id'     => null,
    'created_at'     => time()
  ];
  save_config($config,$configFile);

  $payload = [
    'backend_url'    => $backend_url,
    'public_api_url' => $publicApi,
    'public_host'    => parse_url($publicApi,PHP_URL_HOST),
    'callback_url'   => $callback,
    'callback_secret'=> $secret,
    'request_nonce'  => $nonce
  ];
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $url  = $backend_url.(str_contains($backend_url,'?')?'&':'?').'pairing_api=request';

  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>$json,
    CURLOPT_TIMEOUT=>20
  ]);
  $resp = curl_exec($ch);
  $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
  $cerr = $resp===false ? curl_error($ch) : null;
  curl_close($ch);

  if ($resp===false || $code>=400) {
    $err = 'Koppelaanvraag mislukt: '.($cerr ?? $resp).' (HTTP '.$code.')';
  } else {
    $r = json_decode($resp,true) ?: [];
    if (!empty($r['request_id'])) {
      $config['pending_pairing']['request_id'] = (int)$r['request_id'];
      save_config($config,$configFile);
      $msg = 'Koppelaanvraag verstuurd. Keur de aanvraag goed in Backend Admin.';
    } else {
      $err = 'Onverwacht antwoord van backend.';
    }
  }
}

/* Pairing callback */
if ($action==='pair-callback') {
  header('Content-Type: application/json; charset=UTF-8');
  $raw = file_get_contents('php://input') ?: '';
  $sig = $_SERVER['HTTP_X_PAIR_SIGNATURE'] ?? '';
  $pend = $config['pending_pairing'] ?? null;
  if (!$pend) { http_response_code(400); echo json_encode(['error'=>'No pending pairing']); exit; }

  $expect = base64_encode(hash_hmac('sha256',$raw,(string)$pend['callback_secret'],true));
  if ($sig==='' || !hash_equals($expect,$sig)) { http_response_code(403); echo json_encode(['error'=>'Invalid signature']); exit; }

  $d = json_decode($raw,true) ?: [];
  if (($d['request_id'] ?? null) !== $pend['request_id']) { http_response_code(400); echo json_encode(['error'=>'request_id mismatch']); exit; }
  if (($d['request_nonce'] ?? '') !== $pend['request_nonce']) { http_response_code(400); echo json_encode(['error'=>'nonce mismatch']); exit; }

  // Normaliseer backend_url naar internal_api.php voor gebruik door public_api.php
  $b = (string)($d['backend_url'] ?? '');
  $b = rtrim($b,'/');
  if (preg_match('~/backend_admin\.php$~i',$b) || preg_match('~/backend_pairing\.php$~i',$b)) {
    $b = preg_replace('~/backend_(admin|pairing)\.php$~i','/internal_api.php',$b);
  }
  $config['backend_url']    = $b;
  $config['internal_secret']= (string)$d['internal_secret'];
  $config['api_key']        = (string)$d['public_api_key'];
  $config['pending_pairing']= null;

  if (!save_config($config,$configFile)) { http_response_code(500); echo json_encode(['error'=>'Failed to write config']); exit; }
  echo json_encode(['ok'=>true]); exit;
}

/* View */
$paired  = (!empty($config['backend_url']) && !empty($config['internal_secret']) && !empty($config['api_key']));
$pending = is_array($config['pending_pairing']) ? $config['pending_pairing'] : null;

?>
<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Public Admin</title>
<style>
  body{font:16px system-ui;padding:2rem;max-width:980px;margin:auto;background:#f8fafc}
  .card{background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;padding:1rem;margin-bottom:1rem}
  input,textarea,select{width:100%}
  .ok{color:#16a34a}.err{color:#b91c1c}
  code{background:#f1f5f9;padding:0 .25rem;border-radius:.25rem}
</style>

<h1>Public Admin</h1>
<?php if($msg) echo '<p class="ok">'.h($msg).'</p>'; ?>
<?php if($err) echo '<p class="err">'.h($err).'</p>'; ?>

<div class="card">
  <h2>Status koppeling</h2>
  <?php if($paired): ?>
    <p>✅ Gekoppeld met: <code><?=h($config['backend_url'])?></code></p>
  <?php elseif($pending): ?>
    <p>⏳ Wacht op goedkeuring door Backend Admin.</p>
    <ul>
      <li>Backend aanvraag naar: <code><?=h($pending['backend_url'])?></code></li>
      <li>Public API: <code><?=h($pending['public_api_url'])?></code></li>
      <li>Callback: <code><?=h($pending['callback_url'])?></code></li>
      <li>Request ID: <code><?=h((string)$pending['request_id'])?></code></li>
    </ul>
  <?php else: ?>
    <p>Niet gekoppeld.</p>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Koppelen met Backend</h2>
  <form method="post">
    <input type="hidden" name="action" value="pair-start">
    <p>Backend URL (map of bestand):<br>
      <input name="backend_url" placeholder="https://backend.domein.tld/  of  https://backend.domein.tld/backend_pairing.php" required>
    </p>
    <p><button>Koppelen</button></p>
  </form>
  <p style="color:#475569;font-size:14px">
    Tip: gebruik <code>backend_pairing.php</code> als je host POSTs naar <code>backend_admin.php</code> blokkeert (403/WAF).
  </p>
</div>

<div class="card">
  <h2>Algemene instellingen</h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <p>Allowed Origins (één per regel):<br><textarea name="allowed_origins" rows="3"><?=h(implode("\n",$config['allowed_origins']))?></textarea></p>
    <p>App URL (voor verify/reset links):<br><input name="app_url" value="<?=h($config['app_url'])?>"></p>
    <p>Session cookie naam:<br><input name="session_cookie_name" value="<?=h($config['session_cookie_name'])?>"></p>
    <p>Session lifetime (seconden):<br><input name="session_lifetime" value="<?=h((string)$config['session_lifetime'])?>"></p>
    <p>Front-end admin wachtwoord (optioneel, voor PWA-admin verify):<br><input type="password" name="fe_admin_password"></p>
    <p><button>Opslaan</button></p>
  </form>
</div>

<div class="card">
  <h2>Cookies & Cross‑domain</h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <p>SameSite‑modus:<br>
      <select name="cookie_samesite">
        <option value="auto"  <?=($config['cookie_samesite']==='auto'?'selected':'')?>>Automatisch (aanbevolen)</option>
        <option value="strict"<?=($config['cookie_samesite']==='strict'?'selected':'')?>>Strict</option>
        <option value="lax"   <?=($config['cookie_samesite']==='lax'?'selected':'')?>>Lax</option>
        <option value="none"  <?=($config['cookie_samesite']==='none'?'selected':'')?>>None (cross‑domain, vereist HTTPS)</option>
      </select>
    </p>
    <p>Cookie‑domain (optioneel, bv. <code>.jouwdomein.nl</code>):<br>
      <input name="cookie_domain" value="<?=h($config['cookie_domain'])?>">
    </p>
    <p>
      <label>
        <input type="checkbox" name="force_secure_cookies" value="1" <?=!empty($config['force_secure_cookies'])?'checked':''?>>
        Forceer <code>Secure</code> bij cookies
      </label>
    </p>
    <p><button>Opslaan</button></p>
  </form>
</div>

<div class="card">
  <h2>SMTP (PHPMailer)</h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
      <p>Host<br><input name="smtp_host" value="<?=h($config['smtp']['host'])?>"></p>
      <p>Port<br><input name="smtp_port" value="<?=h((string)$config['smtp']['port'])?>"></p>
      <p>Secure<br>
        <select name="smtp_secure">
          <option value="tls" <?=($config['smtp']['secure']==='tls'?'selected':'')?>>tls</option>
          <option value="ssl" <?=($config['smtp']['secure']==='ssl'?'selected':'')?>>ssl</option>
        </select>
      </p>
      <p>Username<br><input name="smtp_user" value="<?=h($config['smtp']['username'])?>"></p>
      <p>Password (leeg laten = ongewijzigd)<br><input type="password" name="smtp_pass" value=""></p>
      <p>From e‑mail<br><input name="smtp_from_email" value="<?=h($config['smtp']['from_email'])?>"></p>
      <p>From naam<br><input name="smtp_from_name" value="<?=h($config['smtp']['from_name'])?>"></p>
      <p>Reply‑To (optioneel)<br><input name="smtp_reply_to" value="<?=h((string)($config['smtp']['reply_to'] ?? ''))?>"></p>
    </div>
    <p><button>Opslaan</button></p>
  </form>
  <form method="post" style="margin-top:.75rem">
    <input type="hidden" name="action" value="test-mail">
    <p>Testmail naar:<br><input name="to" required></p>
    <p><button>Verstuur testmail</button></p>
  </form>
</div>

<form method="post" action="?action=logout"><button>Uitloggen</button></form>
