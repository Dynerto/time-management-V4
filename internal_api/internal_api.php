<?php
declare(strict_types=1);

header('Content-Type: application/json');

$configPath = __DIR__ . '/internal_config.php';
$config = file_exists($configPath) ? include $configPath : null;
if (!is_array($config) || !isset($config['db_dsn'],$config['db_user'],$config['db_pass'],$config['internal_secret'])) {
    http_response_code(503);
    echo json_encode(['error' => 'Internal API not configured; run backend_admin.php first.']);
    exit;
}

$providedSecret = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
if (!hash_equals($config['internal_secret'], $providedSecret)) {
    http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit;
}
function bad($m,$c=400){ http_response_code($c); echo json_encode(['error'=>$m]); exit; }
function ok($d=[],$c=200){ http_response_code($c); echo json_encode($d); exit; }
function body(): array { $raw=file_get_contents('php://input'); $d=json_decode($raw?:'',true); return is_array($d)?$d:[]; }

try {
    $pdo=new PDO($config['db_dsn'],$config['db_user'],$config['db_pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
} catch(Throwable $e){ bad('DB connect failed: '.$e->getMessage(),500); }

function initialize_schema(PDO $pdo): void {
    $stmts = [
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
        "CREATE INDEX IF NOT EXISTS idx_logs_user_start ON timelogs(user_id, start_time DESC);",
        "ALTER TABLE timelogs DROP CONSTRAINT IF EXISTS fk_user;
         ALTER TABLE timelogs ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;",
        "ALTER TABLE timelogs DROP CONSTRAINT IF EXISTS fk_cat;
         ALTER TABLE timelogs ADD CONSTRAINT fk_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE;"
    ];
    foreach($stmts as $sql){ try{ $pdo->exec($sql); }catch(Throwable $e){} }
}
initialize_schema($pdo);

function verify_api_key(PDO $pdo): bool {
    $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($provided==='') return false;
    $stmt=$pdo->prepare('SELECT key_hash FROM api_keys WHERE active=TRUE AND role=?');
    try { $stmt->execute(['public_api']); } catch(Throwable $e){ return false; }
    foreach($stmt->fetchAll(PDO::FETCH_COLUMN,0) as $hash){
        if (password_verify($provided,$hash)) return true;
    }
    return false;
}
if (!verify_api_key($pdo)) { bad('Invalid API key',403); }

$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:''; $m=$_SERVER['REQUEST_METHOD'];
$parts=array_values(array_filter(explode('/',$uri)));
$userId=$_SERVER['HTTP_X_USER_ID'] ?? null;

// AUTH
if (count($parts)>=2 && $parts[0]==='internal' && $parts[1]==='register' && $m==='POST'){
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) bad('Invalid email');
    if (strlen($pw)<8) bad('Password too short');
    $hash=password_hash($pw,PASSWORD_ARGON2ID);
    try{
        $stmt=$pdo->prepare('INSERT INTO users(email,password_hash,email_verified_at) VALUES(?,?,NULL) RETURNING id');
        $stmt->execute([$email,$hash]); $id=$stmt->fetchColumn();
    }catch(Throwable $e){ bad('Email already exists'); }
    ok(['id'=>$id,'email'=>$email],201);
}
if (count($parts)>=2 && $parts[0]==='internal' && $parts[1]==='login' && $m==='POST'){
    $d=body(); $email=trim(strtolower($d['email']??'')); $pw=$d['password']??'';
    $stmt=$pdo->prepare('SELECT id,password_hash,email_verified_at FROM users WHERE email=?'); $stmt->execute([$email]);
    $row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row || !password_verify($pw,$row['password_hash'])) bad('Invalid credentials',401);
    ok(['id'=>$row['id'],'email'=>$email,'verified'=>!empty($row['email_verified_at'])]);
}
if (count($parts)>=2 && $parts[0]==='internal' && $parts[1]==='user' && $m==='GET'){
    if(!$userId) bad('Unauthorized',401);
    $stmt=$pdo->prepare('SELECT id,email,created_at,(email_verified_at IS NOT NULL) AS verified FROM users WHERE id=?'); $stmt->execute([$userId]);
    $u=$stmt->fetch(PDO::FETCH_ASSOC); if(!$u) bad('User not found',404); ok($u);
}

// E-mail verify
if (count($parts)>=3 && $parts[0]==='internal' && $parts[1]==='users' && $parts[2]==='verification' && $m==='POST'){
    $d=body(); $uid=$d['user_id']??''; if(!$uid) bad('user_id required');
    $token=bin2hex(random_bytes(24)); $hash=password_hash($token,PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO email_verification_tokens(user_id,token_hash) VALUES(?,?)')->execute([$uid,$hash]);
    ok(['token'=>$token]);
}
if (count($parts)>=3 && $parts[0]==='internal' && $parts[1]==='users' && $parts[2]==='verify' && $m==='POST'){
    $d=body(); $token=$d['token']??''; if(!$token) bad('token required');
    $stmt=$pdo->query('SELECT id,user_id,token_hash FROM email_verification_tokens ORDER BY id DESC');
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        if(password_verify($token,$row['token_hash'])){
            $pdo->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$row['user_id']]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE user_id=?')->execute([$row['user_id']]);
            ok(['ok'=>true]);
        }
    }
    bad('Invalid token',400);
}

// Password reset
if (count($parts)>=3 && $parts[0]==='internal' && $parts[1]==='users' && $parts[2]==='password-reset' && $m==='POST'){
    $d=body(); $email=trim(strtolower($d['email']??'')); if(!$email) ok(['ok'=>true]);
    $stmt=$pdo->prepare('SELECT id FROM users WHERE email=?'); $stmt->execute([$email]);
    $uid=$stmt->fetchColumn(); if(!$uid) ok(['ok'=>true]);
    $token=bin2hex(random_bytes(24)); $hash=password_hash($token,PASSWORD_DEFAULT);
    $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash) VALUES(?,?)')->execute([$uid,$hash]);
    ok(['ok'=>true,'token'=>$token,'user_id'=>$uid]);
}
if (count($parts)>=4 && $parts[0]==='internal' && $parts[1]==='users' && $parts[2]==='password-reset' && $parts[3]==='confirm' && $m==='POST'){
    $d=body(); $token=$d['token']??''; $pw=$d['new_password']??'';
    if(strlen($pw)<8) bad('Password too short');
    $stmt=$pdo->query('SELECT id,user_id,token_hash FROM password_reset_tokens ORDER BY id DESC');
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
        if(password_verify($token,$row['token_hash'])){
            $pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pw,PASSWORD_ARGON2ID),$row['user_id']]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id=?')->execute([$row['user_id']]);
            ok(['ok'=>true]);
        }
    }
    bad('Invalid token',400);
}

// CATEGORIES
if (count($parts)===2 && $parts[0]==='internal' && $parts[1]==='categories' && $m==='GET'){
    if(!$userId) bad('Unauthorized',401);
    $stmt=$pdo->prepare('SELECT * FROM categories WHERE user_id=? ORDER BY sort_index ASC, created_at ASC');
    $stmt->execute([$userId]); ok($stmt->fetchAll(PDO::FETCH_ASSOC));
}
if (count($parts)===2 && $parts[0]==='internal' && $parts[1]==='categories' && $m==='POST'){
    $d=body(); $uid=$d['user_id']??''; if(!$uid) bad('User ID missing');
    $name=trim($d['name']??''); if($name==='') bad('Name required');
    $color=$d['color']??null; $min=(int)($d['min_attention']??0); $max=(int)($d['max_attention']??100);
    if ($min<0||$min>100||$max<0||$max>100||$min>$max) bad('Invalid attention range');
    $stmt=$pdo->prepare('SELECT COALESCE(MAX(sort_index),0) FROM categories WHERE user_id=?'); $stmt->execute([$uid]);
    $sort=((int)$stmt->fetchColumn())+1;
    $stmt=$pdo->prepare('INSERT INTO categories(user_id,name,color,min_attention,max_attention,sort_index) VALUES(?,?,?,?,?,?) RETURNING id');
    $stmt->execute([$uid,$name,$color,$min,$max,$sort]); $id=$stmt->fetchColumn();
    ok(['id'=>$id],201);
}
if (count($parts)>=3 && $parts[0]==='internal' && $parts[1]==='categories'){
    $cid=$parts[2]; if(!$userId) bad('Unauthorized',401);
    if ($m==='GET'){
        $stmt=$pdo->prepare('SELECT * FROM categories WHERE id=? AND user_id=?'); $stmt->execute([$cid,$userId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC); if(!$row) bad('Category not found',404); ok($row);
    }
    if ($m==='PUT'||$m==='PATCH'){
        $d=body(); $name=trim($d['name']??''); $color=$d['color']??null;
        $min=(int)($d['min_attention']??0); $max=(int)($d['max_attention']??100);
        if ($min<0||$min>100||$max<0||$max>100||$min>$max) bad('Invalid attention range');
        $stmt=$pdo->prepare('UPDATE categories SET name=?, color=?, min_attention=?, max_attention=? WHERE id=? AND user_id=?');
        $stmt->execute([$name,$color,$min,$max,$cid,$userId]); ok(['ok'=>true]);
    }
    if ($m==='DELETE'){
        $stmt=$pdo->prepare('DELETE FROM categories WHERE id=? AND user_id=?'); $stmt->execute([$cid,$userId]); ok(['ok'=>true]);
    }
}
if (count($parts)===3 && $parts[0]==='internal' && $parts[1]==='categories' && $parts[2]==='reorder' && ($m==='PUT'||$m==='POST')){
    $d=body(); $order=$d['order']??null; $uid=$d['user_id']??$userId; if(!$uid) bad('User ID required',401);
    if(!is_array($order)) bad('Invalid order payload');
    $stmt=$pdo->prepare('UPDATE categories SET sort_index=? WHERE id=? AND user_id=?');
    foreach($order as $it){ $id=$it['id']??null; $ix=isset($it['sort_index'])?(int)$it['sort_index']:null;
        if($id!==null && $ix!==null){ try{$stmt->execute([$ix,$id,$uid]);}catch(Throwable $e){} }
    }
    ok(['ok'=>true]);
}

// TIMELOGS
if (count($parts)===2 && $parts[0]==='internal' && $parts[1]==='timelogs' && $m==='GET'){
    if(!$userId) bad('Unauthorized',401);
    $from=$_GET['from']??null; $to=$_GET['to']??null;
    $sql='SELECT * FROM timelogs WHERE user_id=?'; $vals=[$userId];
    if($from){ $sql.=' AND start_time >= ?'; $vals[]=$from; }
    if($to){ $sql.=' AND start_time < ?'; $vals[]=$to; }
    $sql.=' ORDER BY start_time DESC';
    $stmt=$pdo->prepare($sql); $stmt->execute($vals); ok($stmt->fetchAll(PDO::FETCH_ASSOC));
}
if (count($parts)===2 && $parts[0]==='internal' && $parts[1]==='timelogs' && $m==='POST'){
    $d=body(); $uid=$d['user_id']??''; if(!$uid) bad('User ID missing');
    $cat=$d['category_id']??''; $start=$d['start_time']??''; if(!$start) bad('start_time is required');
    $end=$d['end_time']??null; $dur=$d['duration']??null; $with=$d['with_tasks']??null;
    $stmt=$pdo->prepare('INSERT INTO timelogs(user_id,category_id,start_time,end_time,duration,with_tasks) VALUES(?,?,?,?,?,?) RETURNING id');
    $stmt->execute([$uid,$cat,$start,$end,$dur,$with]); $id=$stmt->fetchColumn(); ok(['id'=>$id],201);
}
if (count($parts)>=3 && $parts[0]==='internal' && $parts[1]==='timelogs'){
    $id=$parts[2]; if(!$userId) bad('Unauthorized',401);
    if ($m==='GET'){
        $stmt=$pdo->prepare('SELECT * FROM timelogs WHERE id=? AND user_id=?'); $stmt->execute([$id,$userId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC); if(!$row) bad('Timelog not found',404); ok($row);
    }
    if ($m==='PUT'||$m==='PATCH'){
        $d=body();
        $stmt=$pdo->prepare('UPDATE timelogs SET category_id=?, start_time=?, end_time=?, duration=?, with_tasks=? WHERE id=? AND user_id=?');
        $stmt->execute([$d['category_id']??'',$d['start_time']??'',$d['end_time']??null,$d['duration']??null,$d['with_tasks']??null,$id,$userId]);
        ok(['ok'=>true]);
    }
    if ($m==='DELETE'){
        $stmt=$pdo->prepare('DELETE FROM timelogs WHERE id=? AND user_id=?'); $stmt->execute([$id,$userId]); ok(['ok'=>true]);
    }
}

bad('Not found',404);
