<?php
declare(strict_types=1);
/**
 * Public/BFF Admin Portal (met Pairing)
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
  // Pairing pending state
  'pending_pairing'=>null
];

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function save_config(array $cfg,string $file): bool {
  $code = "<?php\nreturn ".var_export($cfg,true).";\n";
  return (bool)file_put_contents($file,$code,LOCK_EX);
}

// Helpers to compute URLs
function url_base(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'];
  return $scheme.'://'.$host;
}
function public_api_url(): string {
  // neem aan dat public_api.php in dezelfde map staat
  return rtrim(url_base(),'/').'/public_api.php';
}
function this_admin_url(): string {
  $path = strtok($_SERVER['REQUEST_URI'],'?');
  return rtrim(url_base(),'/') . $path;
}

// --- Routing
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// --- Bootstrap (eerste setup)
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

// --- Login
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

// --- Save algemene config
if ($action==='save') {
  $config['backend_url']     = trim((string)($_POST['backend_url'] ?? $config['backend_url']));
  $config['internal_secret'] = trim((string)($_POST['internal_secret'] ?? $config['internal_secret']));
  $config['api_key']         = trim((string)($_POST['api_key'] ?? $config['api_key']));
  $config['mail_from']       = trim((string)($_POST['mail_from'] ?? $config['mail_from']));
  $config['app_url']         = trim((string)($_POST['app_url'] ?? $config['app_url']));
  $config['session_cookie_name'] = trim((string)($_POST['session_cookie_name'] ?? $config['session_cookie_name']));
  $config['session_lifetime']    = (int)($_POST['session_lifetime'] ?? $config['session_lifetime']);
  $origins = array_filter(array_map('trim', explode("\n", (string)($_POST['allowed_origins'] ?? implode("\n",$config['allowed_origins'])))));
  $config['allowed_origins'] = array_values(array_unique($origins));
  if (!empty($_POST['fe_admin_password'])) {
    $config['frontend_admin_password_hash'] = password_hash((string)$_POST['fe_admin_password'], PASSWORD_DEFAULT);
  }
  $msg = save_config($config,$configFile) ? 'Opgeslagen.' : 'Kon config niet schrijven.';
}

// --- SMTP test (ongewijzigd, we laten weg voor beknoptheid) ---
// ... (je bestaande SMTP test-code hier laten staan) ...

// --- Pairing start (server->server request naar backend) ---
if ($action==='pair-start') {
  $backend_url = trim((string)($_POST['backend_url'] ?? ''));
  if (!preg_match('~^https?://~i', $backend_url)) $backend_url = 'https://'.$backend_url;
  $backend_url = rtrim($backend_url,'/').'/backend_admin.php'; // admin-root file

  // Bouw payload
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

  // POST naar backend pairing endpoint
  $payload = [
    'backend_url'    => $backend_url,      // echo terug, zodat backend dit kan retourneren
    'public_api_url' => $publicApi,
    'public_host'    => parse_url($publicApi,PHP_URL_HOST),
    'callback_url'   => $callback,
    'callback_secret'=> $secret,
    'request_nonce'  => $nonce
  ];
  $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
  $url  = $backend_url.'?pairing_api=request';

  $ch = curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_POST=>true,
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
    CURLOPT_POSTFIELDS=>$json,
    CURLOPT_TIMEOUT=>15
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
      $msg = 'Koppelaanvraag verstuurd. Ga naar Backend Admin en keur de aanvraag goed.';
    } else {
      $err = 'Onverwacht antwoord van backend.';
    }
  }
}

// --- Pairing callback (backend -> public, server->server) ---
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
  if (rtrim((string)($d['backend_url'] ?? ''),'/') !== rtrim((string)$pend['backend_url'],'/')) { http_response_code(400); echo json_encode(['error'=>'backend_url mismatch']); exit; }

  // Schrijf secrets naar config
  $config['backend_url']    = (string)$d['backend_url'];
  $config['internal_secret']= (string)$d['internal_secret'];
  $config['api_key']        = (string)$d['public_api_key'];
  $config['pending_pairing']= null;

  if (!save_config($config,$configFile)) { http_response_code(500); echo json_encode(['error'=>'Failed to write config']); exit; }
  echo json_encode(['ok'=>true]); exit;
}

// --- View
$paired = (!empty($config['backend_url']) && !empty($config['internal_secret']) && !empty($config['api_key']));
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
<?php if(!empty($msg)) echo '<p class="ok">'.h($msg).'</p>'; ?>
<?php if(!empty($err)) echo '<p class="err">'.h($err).'</p>'; ?>

<div class="card">
  <h2>Status koppeling</h2>
  <?php if($paired): ?>
    <p>✅ Gekoppeld met: <code><?=h($config['backend_url'])?></code></p>
  <?php elseif($pending): ?>
    <p>⏳ Wacht op goedkeuring door Backend Admin.</p>
    <ul>
      <li>Backend: <code><?=h($pending['backend_url'])?></code></li>
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
    <p>Backend Admin URL (map/bestand):<br>
      <input name="backend_url" placeholder="https://backend.domein.tld/backend_admin.php">
    </p>
    <p><button>Koppelen</button></p>
  </form>
  <p style="color:#475569;font-size:14px">
    Na het versturen verschijnt de aanvraag in het Backend Admin portaal. Keur die goed terwijl je daar bent ingelogd.
  </p>
</div>

<div class="card">
  <h2>Algemene instellingen</h2>
  <form method="post">
    <input type="hidden" name="action" value="save">
    <p>Allowed Origins (één per regel):<br><textarea name="allowed_origins" rows="3"><?=h(implode("\n",$config['allowed_origins']))?></textarea></p>
    <p>App URL (voor verify/reset links):<br><input name="app_url" value="<?=h($config['app_url'])?>"></p>
    <p>E-mail “From” (fallback):<br><input name="mail_from" value="<?=h($config['mail_from'])?>"></p>
    <p>Session cookie naam:<br><input name="session_cookie_name" value="<?=h($config['session_cookie_name'])?>"></p>
    <p>Session lifetime (seconden):<br><input name="session_lifetime" value="<?=h((string)$config['session_lifetime'])?>"></p>
    <p>Front-end admin nieuw wachtwoord (optioneel):<br><input type="password" name="fe_admin_password"></p>
    <p><button>Opslaan</button></p>
  </form>
</div>

<form method="post" action="?action=logout"><button>Uitloggen</button></form>
