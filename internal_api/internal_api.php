<?php
declare(strict_types=1);
/**
 * Internal API
 * Auth via headers:
 *  - X-Internal-Secret  (exact match met backend internal_config.php)
 *  - X-Api-Key          (plaintext; wordt geverifieerd tegen gehashte api_keys.active=TRUE role='public_api')
 *
 * Paden (via .htaccess): /internal/...
 *  - POST   /internal/register
 *  - POST   /internal/login
 *  - GET    /internal/user               (requires X-User-Id)
 *  - POST   /internal/users/verification (user_id)
 *  - POST   /internal/users/verify       (token)
 *  - POST   /internal/users/password-reset            (email)
 *  - POST   /internal/users/password-reset/confirm   (token, new_password)
 *
 *  - GET    /internal/categories
 *  - POST   /internal/categories
 *  - GET    /internal/categories/{id}
 *  - PUT    /internal/categories/{id}
 *  - PATCH  /internal/categories/{id}
 *  - DELETE /internal/categories/{id}
 *  - PUT    /internal/categories/reorder            (order: array of ids)
 *
 *  - GET    /internal/timelogs[?from=ISO&to=ISO]
 *  - POST   /internal/timelogs
 *  - GET    /internal/timelogs/{id}
 *  - PUT    /internal/timelogs/{id}
 *  - PATCH  /internal/timelogs/{id}
 *  - DELETE /internal/timelogs/{id}
 */
@header('X-Robots-Tag: noindex');

function cfg(){ static $c=null; if($c===null){ $c=include __DIR__.'/internal_config.php'; } return $c; }
function pdo(): PDO { static $pdo=null; if($pdo===null){ $c=cfg(); $pdo=new PDO($c['db_dsn'],$c['db_user'],$c['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } return $pdo; }
function ok($d,$s=200){ http_response_code($s); header('Content-Type: application/json; charset=UTF-8'); echo json_encode($d); exit; }
function bad($m,$s=400){ ok(['error'=>$m],$s); }
function body(): array { $raw=file_get_contents('php://input'); $d=json_decode($raw?:'',true); return is_array($d)?$d:[]; }
function uuidv4(): string { $d=random_bytes(16); $d[6]=chr((ord($d[6])&0x0f)|0x40); $d[8]=chr((ord($d[8])&0x3f)|0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d),4)); }
function now(): string { return gmdate('Y-m-d\TH:i:s\Z'); }

function verify_api_key(PDO $db, string $plain): bool {
  $st=$db->query("SELECT key_hash, role, active FROM api_keys WHERE active=TRUE");
  while($r=$st->fetch(PDO::FETCH_ASSOC)){
    if ($r['role']==='public_api' && password_verify($plain,(string)$r['key_hash'])) return true;
  }
  return false;
}

function require_internal_auth(): void {
  $sec = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
  $api = $_SERVER['HTTP_X_API_KEY'] ?? '';
  if ($sec==='' || $api==='') bad('Unauthorized',401);
  $cfg = cfg();
  if (!hash_equals((string)($cfg['internal_secret']??''), $sec)) bad('Unauthorized',401);
  if (!verify_api_key(pdo(), $api)) bad('Unauthorized',401);
}
require_internal_auth();

// Router helpers
$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo==='') {
  $uri = parse_url($_SERVER['REQUEST_URI']??'/', PHP_URL_PATH) ?: '/';
  $pathInfo = preg_replace('~^/internal_api\.php~','',$uri) ?: '/';
}
$seg = array_values(array_filter(explode('/', $pathInfo)));
if ($seg && $seg[0]==='internal') array_shift($seg);
$method = $_SERVER['REQUEST_METHOD'];

// Utils
function valid_uuid(string $s): bool { return (bool)preg_match('/^[0-9a-fA-F-]{36}$/',$s); }
function user_exists(string $uid): bool {
  $st=pdo()->prepare("SELECT 1 FROM users WHERE id=?"); $st->execute([$uid]); return (bool)$st->fetchColumn();
}
function require_user_id(): string {
  $uid = $_SERVER['HTTP_X_USER_ID'] ?? '';
  if ($uid==='' || !valid_uuid($uid) || !user_exists($uid)) bad('Unauthorized',401);
  return $uid;
}

// ===== Users/Auth =====
if (($seg[0]??'')==='register' && $method==='POST'){
  $d=body(); $email=trim(strtolower($d['email']??'')); $pw=(string)($d['password']??'');
  if ($email===''||$pw==='') bad('Email/password required',422);
  $id=uuidv4(); $hash=password_hash($pw,PASSWORD_DEFAULT);
  try{
    $st=pdo()->prepare("INSERT INTO users(id,email,password_hash,email_verified_at) VALUES(?,?,?,NULL)");
    $st->execute([$id,$email,$hash]);
    ok(['id'=>$id,'email'=>$email,'verified'=>false],201);
  }catch(Throwable $e){
    if (strpos($e->getMessage(),'duplicate')!==false) bad('Email already exists',409);
    bad('DB error: '.$e->getMessage(),500);
  }
}
if (($seg[0]??'')==='login' && $method==='POST'){
  $d=body(); $email=trim(strtolower($d['email']??'')); $pw=(string)($d['password']??'');
  $st=pdo()->prepare("SELECT id,password_hash,email_verified_at FROM users WHERE email=?"); $st->execute([$email]); $u=$st->fetch(PDO::FETCH_ASSOC);
  if(!$u || !password_verify($pw,(string)$u['password_hash'])) bad('Invalid credentials',401);
  ok(['id'=>$u['id'],'verified'=>!empty($u['email_verified_at'])]);
}
if (($seg[0]??'')==='user' && $method==='GET'){
  $uid=require_user_id();
  $st=pdo()->prepare("SELECT id,email,email_verified_at FROM users WHERE id=?"); $st->execute([$uid]); $u=$st->fetch(PDO::FETCH_ASSOC);
  if(!$u) bad('Not found',404);
  ok(['id'=>$u['id'],'email'=>$u['email'],'verified'=>!empty($u['email_verified_at'])]);
}

// email verification: create token
if (($seg[0]??'')==='users' && ($seg[1]??'')==='verification' && $method==='POST'){
  $d=body(); $uid=(string)($d['user_id']??''); if(!valid_uuid($uid)) bad('user_id required',422);
  $token=bin2hex(random_bytes(24));
  $hash=hash('sha256',$token);
  $st=pdo()->prepare("INSERT INTO email_verification_tokens(user_id,token_hash) VALUES(?,?)");
  $st->execute([$uid,$hash]);
  ok(['token'=>$token],201);
}
// email verification: consume token
if (($seg[0]??'')==='users' && ($seg[1]??'')==='verify' && $method==='POST'){
  $d=body(); $token=(string)($d['token']??''); if(strlen($token)<10) bad('token required',422);
  $hash=hash('sha256',$token);
  $st=pdo()->prepare("SELECT user_id FROM email_verification_tokens WHERE token_hash=? ORDER BY created_at DESC LIMIT 1");
  $st->execute([$hash]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row) bad('Invalid token',400);
  $uid=$row['user_id'];
  pdo()->prepare("UPDATE users SET email_verified_at=NOW() WHERE id=?")->execute([$uid]);
  pdo()->prepare("DELETE FROM email_verification_tokens WHERE user_id=?")->execute([$uid]);
  ok(['ok'=>true]);
}

// password reset request
if (($seg[0]??'')==='users' && ($seg[1]??'')==='password-reset' && count($seg)===2 && $method==='POST'){
  $d=body(); $email=trim(strtolower($d['email']??'')); if($email==='') bad('email required',422);
  $st=pdo()->prepare("SELECT id FROM users WHERE email=?"); $st->execute([$email]); $u=$st->fetch(PDO::FETCH_ASSOC);
  if(!$u){ ok(['ok'=>true]); } // geen account-informatie lekken
  $uid=$u['id'];
  $token=bin2hex(random_bytes(24)); $hash=hash('sha256',$token);
  pdo()->prepare("INSERT INTO password_reset_tokens(user_id,token_hash) VALUES(?,?)")->execute([$uid,$hash]);
  ok(['token'=>$token],201);
}
// password reset confirm
if (($seg[0]??'')==='users' && ($seg[1]??'')==='password-reset' && ($seg[2]??'')==='confirm' && $method==='POST'){
  $d=body(); $token=(string)($d['token']??''); $pw=(string)($d['new_password']??'');
  if(strlen($token)<10||strlen($pw)<6) bad('token/password required',422);
  $hash=hash('sha256',$token);
  $st=pdo()->prepare("SELECT user_id FROM password_reset_tokens WHERE token_hash=? ORDER BY created_at DESC LIMIT 1");
  $st->execute([$hash]); $row=$st->fetch(PDO::FETCH_ASSOC);
  if(!$row) bad('Invalid token',400);
  $uid=$row['user_id'];
  pdo()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($pw,PASSWORD_DEFAULT),$uid]);
  pdo()->prepare("DELETE FROM password_reset_tokens WHERE user_id=?")->execute([$uid]);
  ok(['ok'=>true]);
}

// ===== Categories =====
if (($seg[0]??'')==='categories'){
  $uid = require_user_id();

  if ($method==='GET' && count($seg)===1){
    $st=pdo()->prepare("SELECT id,name,color,min_attention,max_attention,sort_index,created_at FROM categories WHERE user_id=? ORDER BY sort_index ASC, created_at ASC");
    $st->execute([$uid]); ok($st->fetchAll(PDO::FETCH_ASSOC));
  }
  if ($method==='POST' && count($seg)===1){
    $d=body(); $name=trim((string)($d['name']??'')); if($name==='') bad('Name required',422);
    $color=($d['color']??null); $min=(int)($d['min_attention']??0); $max=(int)($d['max_attention']??100);
    $st=pdo()->prepare("SELECT COALESCE(MAX(sort_index)+1,0) FROM categories WHERE user_id=?"); $st->execute([$uid]); $sort=(int)$st->fetchColumn();
    $id=uuidv4();
    $st=pdo()->prepare("INSERT INTO categories(id,user_id,name,color,min_attention,max_attention,sort_index) VALUES(?,?,?,?,?,?,?)");
    $st->execute([$id,$uid,$name,$color,$min,$max,$sort]);
    ok(['id'=>$id],201);
  }
  if (count($seg)===2 && $seg[1]==='reorder' && ($method==='PUT'||$method==='POST')){
    $d=body(); $order=$d['order']??null; if(!is_array($order)) bad('Invalid order',422);
    $i=0; $up=pdo()->prepare("UPDATE categories SET sort_index=? WHERE id=? AND user_id=?");
    foreach($order as $cid){ $up->execute([$i++,$cid,$uid]); }
    ok(['ok'=>true]);
  }
  if (count($seg)===2 && valid_uuid($seg[1])){
    $cid=$seg[1];
    if ($method==='GET'){
      $st=pdo()->prepare("SELECT id,name,color,min_attention,max_attention,sort_index,created_at FROM categories WHERE id=? AND user_id=?");
      $st->execute([$cid,$uid]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) bad('Not found',404); ok($c);
    }
    if ($method==='PUT'||$method==='PATCH'){
      $d=body();
      $st=pdo()->prepare("UPDATE categories SET name=?,color=?,min_attention=?,max_attention=? WHERE id=? AND user_id=?");
      $st->execute([$d['name']??'', $d['color']??null, (int)($d['min_attention']??0), (int)($d['max_attention']??100), $cid, $uid]);
      ok(['ok'=>true]);
    }
    if ($method==='DELETE'){
      pdo()->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$cid,$uid]); ok(['ok'=>true]);
    }
  }
}

// ===== Timelogs =====
if (($seg[0]??'')==='timelogs'){
  $uid = require_user_id();

  if ($method==='GET' && count($seg)===1){
    $from=$_GET['from']??null; $to=$_GET['to']??null;
    $sql="SELECT id,category_id,start_time,end_time,duration,with_tasks,created_at FROM timelogs WHERE user_id=?";
    $args=[$uid];
    if($from){ $sql.=" AND start_time >= ?"; $args[]=$from; }
    if($to){ $sql.=" AND start_time <= ?"; $args[]=$to; }
    $sql.=" ORDER BY start_time DESC";
    $st=pdo()->prepare($sql); $st->execute($args); ok($st->fetchAll(PDO::FETCH_ASSOC));
  }
  if ($method==='POST' && count($seg)===1){
    $d=body(); $cat=(string)($d['category_id']??''); if(!valid_uuid($cat)) bad('category_id required',422);
    // beveilig: category moet van deze user zijn
    $chk=pdo()->prepare("SELECT 1 FROM categories WHERE id=? AND user_id=?"); $chk->execute([$cat,$uid]); if(!$chk->fetchColumn()) bad('Forbidden category',403);
    $start=(string)($d['start_time']??''); if($start==='') bad('start_time required',422);
    $end=$d['end_time']??null; $dur=$d['duration']??null; $with=$d['with_tasks']??null;
    if (is_array($with)) $with=json_encode($with);
    if ($dur===null && $end) { $dur=(int)max(0, (strtotime($end)-strtotime($start))); }
    $id=uuidv4();
    $st=pdo()->prepare("INSERT INTO timelogs(id,user_id,category_id,start_time,end_time,duration,with_tasks) VALUES(?,?,?,?,?,?,?)");
    $st->execute([$id,$uid,$cat,$start,$end,$dur,$with]);
    ok(['id'=>$id],201);
  }
  if (count($seg)===2 && valid_uuid($seg[1])){
    $tid=$seg[1];
    if ($method==='GET'){
      $st=pdo()->prepare("SELECT id,category_id,start_time,end_time,duration,with_tasks,created_at FROM timelogs WHERE id=? AND user_id=?");
      $st->execute([$tid,$uid]); $t=$st->fetch(PDO::FETCH_ASSOC); if(!$t) bad('Not found',404); ok($t);
    }
    if ($method==='PUT'||$method==='PATCH'){
      $d=body();
      $cat=(string)($d['category_id']??''); if($cat && !valid_uuid($cat)) bad('category_id invalid',422);
      if($cat){
        $chk=pdo()->prepare("SELECT 1 FROM categories WHERE id=? AND user_id=?"); $chk->execute([$cat,$uid]); if(!$chk->fetchColumn()) bad('Forbidden category',403);
      }
      $start=$d['start_time']??''; $end=$d['end_time']??null; $dur=$d['duration']??null; $with=$d['with_tasks']??null;
      if (is_array($with)) $with=json_encode($with);
      if ($dur===null && $end && $start) { $dur=(int)max(0, (strtotime((string)$end)-strtotime((string)$start))); }
      $st=pdo()->prepare("UPDATE timelogs SET category_id=COALESCE(?,category_id), start_time=COALESCE(?,start_time), end_time=?, duration=?, with_tasks=? WHERE id=? AND user_id=?");
      $st->execute([$cat?:null, $start?:null, $end, $dur, $with, $tid, $uid]);
      ok(['ok'=>true]);
    }
    if ($method==='DELETE'){
      pdo()->prepare("DELETE FROM timelogs WHERE id=? AND user_id=?")->execute([$tid,$uid]); ok(['ok'=>true]);
    }
  }
}

bad('Not found',404);
