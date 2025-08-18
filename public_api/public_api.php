<?php
declare(strict_types=1);
/**
 * Public (BFF) API
 * - Sessies + CSRF (auto SameSite + Secure)
 * - CORS met credentials (allowed_origins)
 * - PHPMailer via SMTP (verify/reset e-mails)
 * - SQLite rate-limiting
 * - Proxy naar Internal API (HMAC-achtig via headers: X-Internal-Secret + X-Api-Key)
 */

function cfg(){ static $c=null; if($c===null){ $c=include __DIR__.'/public_config.php'; } return $c; }
function j($d,$c=200){ http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
function bad($m,$c=400){ j(['error'=>$m],$c); }
function body(): array { $raw=file_get_contents('php://input'); $d=json_decode($raw?:'',true); return is_array($d)?$d:[]; }
function client_ip(): string { $h=$_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; if(strpos($h,',')!==false)$h=trim(explode(',',$h)[0]); return trim($h); }

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? null;
$allowed = cfg()['allowed_origins'] ?? [];
if ($origin && in_array($origin,$allowed,true)) {
  header('Access-Control-Allow-Origin: '.$origin);
  header('Vary: Origin');
  header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD']==='OPTIONS'){ http_response_code(204); exit; }

// ---------- Sessies + cookies (auto SameSite) ----------
function compute_cookie_params(): array {
  $cfg = cfg();
  $lifetime = (int)($cfg['session_lifetime'] ?? 604800);
  $cookieDomain = (string)($cfg['cookie_domain'] ?? '');
  $forceSecure  = !empty($cfg['force_secure_cookies']);
  $mode         = strtolower((string)($cfg['cookie_samesite'] ?? 'auto'));
  $isHttps      = !empty($_SERVER['HTTPS']);

  $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
  $oHost   = $origin ? (parse_url($origin, PHP_URL_HOST) ?: '') : '';
  $sHost   = $_SERVER['HTTP_HOST'] ?? '';

  $cross = false;
  if ($oHost && $sHost) {
    $cross = (strtolower($oHost) !== strtolower($sHost));
    $cd = ltrim(strtolower($cookieDomain), '.');
    if ($cd && str_ends_with(strtolower($oHost), $cd) && str_ends_with(strtolower($sHost), $cd)) {
      $cross = false;
    }
  }

  if (!in_array($mode, ['auto','strict','lax','none'], true)) $mode = 'auto';
  $same = 'Strict';
  if ($mode === 'auto')       $same = $cross ? 'None' : 'Strict';
  elseif ($mode === 'strict') $same = 'Strict';
  elseif ($mode === 'lax')    $same = 'Lax';
  elseif ($mode === 'none')   $same = 'None';

  $secure = $isHttps || $forceSecure;
  if ($same === 'None') $secure = true;

  return [
    'lifetime' => $lifetime,
    'path'     => '/',
    'domain'   => $cookieDomain ?: '',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $same
  ];
}
if (!function_exists('str_ends_with')) {
  function str_ends_with(string $haystack, string $needle): bool {
    return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
  }
}

$cookieName = cfg()['session_cookie_name'] ?? 'tm_sid';
$params = compute_cookie_params();

session_name($cookieName);
session_set_cookie_params([
  'lifetime'=>$params['lifetime'],
  'path'=>$params['path'],
  'domain'=>$params['domain'],
  'secure'=>$params['secure'],
  'httponly'=>$params['httponly'],
  'samesite'=>$params['samesite']
]);
session_start();
if (!isset($_SESSION['created_at'])) $_SESSION['created_at']=time();
$_SESSION['last_seen']=time();
if (time() - ($_SESSION['last_rot'] ?? 0) > 600) { session_regenerate_id(true); $_SESSION['last_rot']=time(); }

// CSRF
if (empty($_COOKIE['tm_csrf'])) {
  $token = bin2hex(random_bytes(16));
  setcookie('tm_csrf', $token, [
    'expires'=> time() + $params['lifetime'],
    'path'   => $params['path'],
    'domain' => $params['domain'],
    'secure' => $params['secure'],
    'httponly'=> false,
    'samesite'=> $params['samesite']
  ]);
}
function require_csrf_for_state_change() {
  if (in_array($_SERVER['REQUEST_METHOD'],['POST','PUT','PATCH','DELETE'],true)) {
    $h=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($h==='' || !hash_equals($_COOKIE['tm_csrf'] ?? '', $h)) bad('CSRF check failed',403);
  }
}
require_csrf_for_state_change();

// ---------- SQLite Rate Limiter ----------
function rl_db_path(): string { $path = __DIR__.'/rate_limit.sqlite'; if (!is_writable(__DIR__)) { $path = sys_get_temp_dir().'/rate_limit.sqlite'; } return $path; }
function rl_db(): PDO { static $pdo=null; if ($pdo) return $pdo; $pdo = new PDO('sqlite:'.rl_db_path(), null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $pdo->exec("PRAGMA journal_mode=WAL;"); $pdo->exec("CREATE TABLE IF NOT EXISTS buckets(key TEXT,window_start INTEGER,count INTEGER,PRIMARY KEY(key,window_start));"); return $pdo; }
function rate_limit(string $name, int $max, int $window, array $parts=[]): array {
  $k = $name.':'.implode('|', $parts); $now = time(); $win = (int)floor($now / $window) * $window;
  $pdo = rl_db(); $pdo->beginTransaction();
  try { $stmt = $pdo->prepare("SELECT count FROM buckets WHERE key=? AND window_start=?"); $stmt->execute([$k,$win]); $row=$stmt->fetch(PDO::FETCH_ASSOC); $count=$row?(int)$row['count']:0;
    if ($count >= $max) { $pdo->commit(); return ['ok'=>false,'retry_after'=>$win+$window-$now]; }
    if ($row) { $pdo->prepare("UPDATE buckets SET count=count+1 WHERE key=? AND window_start=?")->execute([$k,$win]); }
    else { $pdo->prepare("INSERT INTO buckets(key,window_start,count) VALUES(?,?,1)")->execute([$k,$win]); }
    $pdo->commit(); return ['ok'=>true];
  } catch(Throwable $e){ $pdo->rollBack(); return ['ok'=>true]; }
}
function enforce_rl(string $key, array $keyParts=[], ?int $max=null, ?int $win=null): void {
  $cfg = cfg()['rate_limits'][$key] ?? null; if (!$cfg && $max===null) return; [$m,$w] = $cfg ?: [$max,$win]; $res = rate_limit($key, (int)$m, (int)$w, $keyParts);
  if (!$res['ok']) { header('Retry-After: '.(int)$res['retry_after']); bad('Too many requests',429); }
}
enforce_rl('global_ip', [client_ip()]);

// ---------- PHPMailer (SMTP) ----------
function mailer_or_throw(): \PHPMailer\PHPMailer\PHPMailer {
  $autoload1 = __DIR__.'/vendor/autoload.php'; $autoload2 = __DIR__.'/../vendor/autoload.php';
  if (is_readable($autoload1)) require_once $autoload1; elseif (is_readable($autoload2)) require_once $autoload2;
  if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
    $base = __DIR__.'/lib/phpmailer/src';
    $ph = $base.'/PHPMailer.php'; $sm = $base.'/SMTP.php'; $ex = $base.'/Exception.php';
    if (is_readable($ph) && is_readable($sm) && is_readable($ex)) { require_once $ex; require_once $ph; require_once $sm; }
  }
  if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) throw new RuntimeException('PHPMailer not installed. Use Composer or lib/phpmailer/src/');
  $cfg = cfg()['smtp'] ?? [];
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
  $fromEmail = (string)($cfg['from_email'] ?? 'no-reply@example.com');
  $fromName  = (string)($cfg['from_name']  ?? 'Timelog');
  $mail->setFrom($fromEmail, $fromName);
  if (!empty($cfg['reply_to'])) $mail->addReplyTo((string)$cfg['reply_to']);
  $mail->isHTML(true); $mail->CharSet='UTF-8';
  return $mail;
}
function send_mail(string $toEmail, string $subject, string $html): void {
  try{ $mail = mailer_or_throw(); $mail->addAddress($toEmail); $mail->Subject = $subject; $mail->Body = $html; $mail->AltBody = strip_tags($html); $mail->send(); }
  catch(Throwable $e){ error_log('send_mail: '.$e->getMessage()); }
}

// ---------- Proxy helper ----------
function call_internal(string $path,string $method,?array $payload,?string $userId){
  $cfg=cfg(); $base=rtrim((string)$cfg['backend_url'],'/');
  // Normaliseer: soms staat hier backend_admin.php of backend_pairing.php
  if (preg_match('~/backend_(admin|pairing)\.php$~i',$base)) $base = preg_replace('~/backend_(admin|pairing)\.php$~i','/internal_api.php',$base);
  if (!preg_match('~/internal_api\.php$~i',$base)) $base .= '/internal_api.php';
  $url=$base.$path;
  $ch=curl_init($url);
  $headers=['Content-Type: application/json','X-Internal-Secret: '.$cfg['internal_secret'],'X-Api-Key: '.$cfg['api_key']];
  if($userId) $headers[]='X-User-Id: '.$userId;
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>20]);
  if($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
  $resp=curl_exec($ch); if($resp===false){ $err = curl_error($ch); curl_close($ch); bad('Internal unreachable: '.$err,502); }
  $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  $json=json_decode($resp,true);
  if($status>=400) bad(($json['error']??'Internal error').' ('.$status.')',$status);
  return $json;
}

// ---------- Router ----------
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$path = str_starts_with($uri,'/api') ? substr($uri,4) : $uri;
$seg=array_values(array_filter(explode('/',$path)));
$method=$_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['uid'] ?? null;

// ---------- AUTH ----------
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='register') {
    enforce_rl('register_ip',  [client_ip()]);
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    enforce_rl('register_email', [$email]);
    $r=call_internal('/internal/register','POST',['email'=>$email,'password'=>$pw],null);
    $vt=call_internal('/internal/users/verification','POST',['user_id'=>$r['id']],null);
    $verifyLink = rtrim((string)cfg()['app_url'],'/').'/index.html?verify='.urlencode($vt['token']);
    send_mail($r['email'],'Bevestig je e-mailadres', '<p>Bevestig je e-mailadres voor Timelog:</p><p><a href="'.$verifyLink.'">E-mailadres bevestigen</a></p>');
    $_SESSION['uid']=$r['id']; $_SESSION['email']=$r['email'];
    j(['ok'=>true,'user'=>['id'=>$r['id'],'email'=>$r['email'],'verified'=>false]],201);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='login') {
    enforce_rl('login_ip', [client_ip()]);
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    enforce_rl('login_user', [$email]);
    $r=call_internal('/internal/login','POST',['email'=>$email,'password'=>$pw],null);
    $_SESSION['uid']=$r['id']; $_SESSION['email']=$email;
    j(['ok'=>true,'user'=>['id'=>$r['id'],'email'=>$email,'verified'=>!empty($r['verified'])]]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='logout') { session_destroy(); j(['ok'=>true]); }
if (($seg[0]??'')==='auth' && $method==='GET'  && ($seg[1]??'')==='me') {
    if(!$userId) bad('Unauthorized',401);
    $u=call_internal('/internal/user','GET',null,$userId); j(['user'=>$u]);
}
if (($seg[0]??'')==='auth' && $method==='GET'  && ($seg[1]??'')==='verify' && isset($_GET['token'])){
    enforce_rl('verify_ip', [client_ip()]);
    call_internal('/internal/users/verify','POST',['token'=>$_GET['token']],null);
    header('Content-Type: text/html; charset=UTF-8'); echo '<meta name=viewport content="width=device-width, initial-scale=1"><p>E-mailadres geverifieerd. Je kunt het venster sluiten of de app vernieuwen.</p>'; exit;
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='resend-verify'){
    if(!$userId) bad('Unauthorized',401);
    enforce_rl('resend_ip', [client_ip()]); enforce_rl('resend_uid', [$userId]);
    $vt=call_internal('/internal/users/verification','POST',['user_id'=>$userId],null);
    $verifyLink = rtrim((string)cfg()['app_url'],'/').'/index.html?verify='.urlencode($vt['token']);
    send_mail($_SESSION['email'] ?? '','Bevestig je e-mailadres', '<p>Bevestig je e-mailadres:</p><p><a href="'.$verifyLink.'">Bevestigen</a></p>');
    j(['ok'=>true]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='request-reset'){
    enforce_rl('reset_req_ip', [client_ip()]);
    $d=body(); $email=trim(strtolower($d['email']??''));
    enforce_rl('reset_req_email', [$email]);
    $res=call_internal('/internal/users/password-reset','POST',['email'=>$email],null);
    if(!empty($res['token'])){
        $link = rtrim((string)cfg()['app_url'],'/').'/index.html?reset='.urlencode($res['token']);
        send_mail($email,'Wachtwoord resetten','<p>Klik om je wachtwoord te resetten:</p><p><a href="'.$link.'">Reset wachtwoord</a></p>');
    }
    j(['ok'=>true]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='reset'){
    enforce_rl('reset_confirm_ip', [client_ip()]);
    $d=body(); $token=$d['token']??''; $pw=$d['new_password']??'';
    call_internal('/internal/users/password-reset/confirm','POST',['token'=>$token,'new_password'=>$pw],null);
    j(['ok'=>true]);
}

// ---------- Categories ----------
function need_login(){ global $userId; if(!$userId) bad('Unauthorized',401); }
if (($seg[0]??'')==='categories'){
  if($method==='GET' && count($seg)===1){ need_login(); j(call_internal('/internal/categories','GET',null,$userId)); }
  if($method==='POST'&& count($seg)===1){ need_login(); $d=body(); $p=['user_id'=>$userId,'name'=>trim($d['name']??''),'color'=>$d['color']??null,'min_attention'=>(int)($d['min_attention']??0),'max_attention'=>(int)($d['max_attention']??100)];
    if($p['name']==='') bad('Name required'); j(call_internal('/internal/categories','POST',$p,$userId),201);
  }
  if(count($seg)===2){
    $id=$seg[1]; need_login();
    if($method==='GET'){ j(call_internal('/internal/categories/'.$id,'GET',null,$userId)); }
    if($method==='PUT'||$method==='PATCH'){ $d=body();
      call_internal('/internal/categories/'.$id,$method,['name'=>$d['name']??'','color'=>$d['color']??null,'min_attention'=>(int)($d['min_attention']??0),'max_attention'=>(int)($d['max_attention']??100)],$userId);
      j(['ok'=>true]);
    }
    if($method==='DELETE'){ call_internal('/internal/categories/'.$id,'DELETE',null,$userId); j(['ok'=>true]); }
  }
  if(count($seg)===2 && $seg[1]==='reorder' && ($method==='PUT'||$method==='POST')){ need_login(); $d=body(); $order=$d['order']??null; if(!is_array($order)) bad('Invalid order');
    call_internal('/internal/categories/reorder','PUT',['user_id'=>$userId,'order'=>$order],$userId); j(['ok'=>true]);
  }
}

// ---------- Timelogs ----------
if (($seg[0]??'')==='timelogs'){
  if($method==='GET' && count($seg)===1){ need_login(); $qs=''; if($_GET) $qs='?'.http_build_query($_GET); j(call_internal('/internal/timelogs'.$qs,'GET',null,$userId)); }
  if($method==='POST'&& count($seg)===1){ need_login(); $d=body(); if(!($d['category_id']??false)) bad('category_id required');
    $p=['user_id'=>$userId,'category_id'=>$d['category_id'],'start_time'=>$d['start_time']??'','end_time'=>$d['end_time']??null,'duration'=>$d['duration']??null,'with_tasks'=>$d['with_tasks']??null];
    j(call_internal('/internal/timelogs','POST',$p,$userId),201);
  }
  if(count($seg)===2){ need_login(); $id=$seg[1];
    if($method==='GET'){ j(call_internal('/internal/timelogs/'.$id,'GET',null,$userId)); }
    if($method==='PUT'||$method==='PATCH'){ $d=body();
      call_internal('/internal/timelogs/'.$id,$method,['category_id'=>$d['category_id']??'','start_time'=>$d['start_time']??'','end_time'=>$d['end_time']??null,'duration'=>$d['duration']??null,'with_tasks'=>$d['with_tasks']??null],$userId);
      j(['ok'=>true]);
    }
    if($method==='DELETE'){ call_internal('/internal/timelogs/'.$id,'DELETE',null,$userId); j(['ok'=>true]); }
  }
}

// ---------- Front-end admin verify (optioneel, voor PWA admin panel) ----------
if (($seg[0]??'')==='admin' && ($seg[1]??'')==='fe' && ($seg[2]??'')==='verify' && $method==='POST') {
    $d = body(); $pwd = $d['password'] ?? '';
    $hash = (cfg()['frontend_admin_password_hash'] ?? '');
    if ($hash && password_verify($pwd, $hash)) { j(['ok'=>true]); }
    bad('Unauthorized', 401);
}

bad('Not found',404);
