<?php
declare(strict_types=1);

/**
 * Public (BFF) API met:
 * - Cookie-sessies (HttpOnly, Secure, SameSite=Strict)
 * - CSRF check voor mutaties
 * - E-mail verify & password reset flows
 * - Proxy naar Internal API (HMAC via X-Internal-Secret + API Key)
 */

function cfg(){ static $c=null; if($c===null){ $c=include __DIR__.'/public_config.php'; } return $c; }
function j($d,$c=200){ http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); exit; }
function bad($m,$c=400){ j(['error'=>$m],$c); }
function body(): array { $raw=file_get_contents('php://input'); $d=json_decode($raw?:'',true); return is_array($d)?$d:[]; }

// ---------- CORS (met credentials) ----------
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

// ---------- Sessies ----------
$cookieName = cfg()['session_cookie_name'] ?? 'tm_sid';
$lifetime   = cfg()['session_lifetime'] ?? 604800;
session_name($cookieName);
session_set_cookie_params([
  'lifetime'=>$lifetime,'path'=>'/','domain'=>'',
  'secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict'
]);
session_start();
if (!isset($_SESSION['created_at'])) $_SESSION['created_at']=time();
$_SESSION['last_seen']=time();
if (time() - ($_SESSION['last_rot'] ?? 0) > 600) { session_regenerate_id(true); $_SESSION['last_rot']=time(); }

// CSRF – niet-HttpOnly cookie + header check
if (empty($_COOKIE['tm_csrf'])) {
  $token = bin2hex(random_bytes(16));
  setcookie('tm_csrf', $token, [
    'expires'=>time()+$lifetime, 'path'=>'/', 'secure'=>!empty($_SERVER['HTTPS']),
    'httponly'=>false, 'samesite'=>'Strict'
  ]);
}
function require_csrf_for_state_change() {
  if (in_array($_SERVER['REQUEST_METHOD'],['POST','PUT','PATCH','DELETE'],true)) {
    $h=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($h==='' || !hash_equals($_COOKIE['tm_csrf'] ?? '', $h)) bad('CSRF check failed',403);
  }
}
require_csrf_for_state_change();

// ---------- Proxy helper ----------
function call_internal(string $path,string $method,?array $payload,?string $userId){
    $cfg=cfg();
    $url=rtrim($cfg['backend_url'],'/').$path;
    $ch=curl_init($url);
    $headers=['Content-Type: application/json',
        'X-Internal-Secret: '.$cfg['internal_secret'],
        'X-Api-Key: '.$cfg['api_key']];
    if($userId) $headers[]='X-User-Id: '.$userId;
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers]);
    if($payload!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload));
    $resp=curl_exec($ch); if($resp===false){ curl_close($ch); bad('Internal unreachable',502); }
    $status=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $json=json_decode($resp,true);
    if($status>=400) bad(($json['error']??'Internal error').' ('.$status.')',$status);
    return $json;
}

// ---------- Mail helper ----------
function send_mail(string $to,string $subject,string $html): void {
    $headers = "MIME-Version: 1.0\r\n".
               "Content-Type: text/html; charset=UTF-8\r\n".
               "From: ".(cfg()['mail_from'] ?? 'no-reply@example.com')."\r\n";
    @mail($to,$subject,$html,$headers);
}

// ---------- Routing ----------
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/';
$path = str_starts_with($uri,'/api') ? substr($uri,4) : $uri;
$seg=array_values(array_filter(explode('/',$path)));
$method=$_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['uid'] ?? null;

// ---------- AUTH ----------
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='register') {
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    $r=call_internal('/internal/register','POST',['email'=>$email,'password'=>$pw],null);
    $vt=call_internal('/internal/users/verification','POST',['user_id'=>$r['id']],null);
    $verifyLink = rtrim(cfg()['app_url'],'/').'/index.html?verify='.urlencode($vt['token']);
    send_mail($r['email'],'Bevestig je e-mailadres', '<p>Bevestig je e-mailadres voor Timelog:</p><p><a href="'.$verifyLink.'">E-mailadres bevestigen</a></p>');
    $_SESSION['uid']=$r['id']; $_SESSION['email']=$r['email'];
    j(['ok'=>true,'user'=>['id'=>$r['id'],'email'=>$r['email'],'verified'=>false]],201);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='login') {
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    $r=call_internal('/internal/login','POST',['email'=>$email,'password'=>$pw],null);
    $_SESSION['uid']=$r['id']; $_SESSION['email']=$email;
    j(['ok'=>true,'user'=>['id'=>$r['id'],'email'=>$email,'verified'=>!empty($r['verified'])]]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='logout') {
    session_destroy(); j(['ok'=>true]);
}
if (($seg[0]??'')==='auth' && $method==='GET' && ($seg[1]??'')==='me') {
    if(!$userId) bad('Unauthorized',401);
    $u=call_internal('/internal/user','GET',null,$userId);
    j(['user'=>$u]);
}
if (($seg[0]??'')==='auth' && $method==='GET' && ($seg[1]??'')==='verify' && isset($_GET['token'])){
    $token=$_GET['token'];
    call_internal('/internal/users/verify','POST',['token'=>$token],null);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<meta name=viewport content="width=device-width, initial-scale=1"><p>E-mailadres geverifieerd. Je kunt het venster sluiten of de app vernieuwen.</p>';
    exit;
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='resend-verify'){
    if(!$userId) bad('Unauthorized',401);
    $vt=call_internal('/internal/users/verification','POST',['user_id'=>$userId],null);
    $verifyLink = rtrim(cfg()['app_url'],'/').'/index.html?verify='.urlencode($vt['token']);
    send_mail($_SESSION['email'] ?? '','Bevestig je e-mailadres', '<p>Bevestig je e-mailadres:</p><p><a href="'.$verifyLink.'">Bevestigen</a></p>');
    j(['ok'=>true]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='request-reset'){
    $d=body(); $email=trim(strtolower($d['email']??''));
    $res=call_internal('/internal/users/password-reset','POST',['email'=>$email],null);
    if(!empty($res['token'])){
        $link = rtrim(cfg()['app_url'],'/').'/index.html?reset='.urlencode($res['token']);
        send_mail($email,'Wachtwoord resetten','<p>Klik om je wachtwoord te resetten:</p><p><a href="'.$link.'">Reset wachtwoord</a></p>');
    }
    j(['ok'=>true]);
}
if (($seg[0]??'')==='auth' && $method==='POST' && ($seg[1]??'')==='reset'){
    $d=body(); $token=$d['token']??''; $pw=$d['new_password']??'';
    call_internal('/internal/users/password-reset/confirm','POST',['token'=>$token,'new_password'=>$pw],null);
    j(['ok'=>true]);
}

// ---------- Categories ----------
function need_login(){ global $userId; if(!$userId) bad('Unauthorized',401); }
if (($seg[0]??'')==='categories'){
  if($method==='GET' && count($seg)===1){ need_login(); j(call_internal('/internal/categories','GET',null,$userId)); }
  if($method==='POST' && count($seg)===1){ need_login(); $d=body(); $p=['user_id'=>$userId,'name'=>trim($d['name']??''),'color'=>$d['color']??null,'min_attention'=>(int)($d['min_attention']??0),'max_attention'=>(int)($d['max_attention']??100)];
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
  if($method==='POST' && count($seg)===1){ need_login(); $d=body(); if(!($d['category_id']??false)) bad('category_id required');
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

// ---------- Export ----------
if (($seg[0]??'')==='export' && ($seg[1]??'')==='csv' && $method==='GET'){
  need_login();
  $cats=call_internal('/internal/categories','GET',null,$userId);
  $qs=''; if($_GET) $qs='?'.http_build_query($_GET);
  $logs=call_internal('/internal/timelogs'.$qs,'GET',null,$userId);
  $map=[]; foreach($cats as $c){ $map[$c['id']]=$c['name']; }
  header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="export.csv"');
  $f = fopen('php://output','w');
  fputcsv($f,['Taak','Start','Einde','Duur (s)','Met']);
  foreach($logs as $log){
    $with=[];
    if(!empty($log['with_tasks'])){
      $ids=json_decode($log['with_tasks'],true);
      if(is_array($ids)) foreach($ids as $id){ if(isset($map[$id]) && $id!==$log['category_id']) $with[]=$map[$id]; }
    }
    fputcsv($f,[$map[$log['category_id']]??'Onbekend',$log['start_time'],$log['end_time']??'',$log['duration']??'',implode(';',$with)]);
  }
  exit;
}

// ---------- Front-end admin verify (PWA admin) ----------
if (($seg[0]??'')==='admin' && ($seg[1]??'')==='fe' && ($seg[2]??'')==='verify' && $method==='POST') {
    $d = body(); $pwd = $d['password'] ?? '';
    $hash = (cfg()['frontend_admin_password_hash'] ?? '');
    if ($hash && password_verify($pwd, $hash)) { j(['ok'=>true]); }
    bad('Unauthorized', 401);
}

bad('Not found',404);
