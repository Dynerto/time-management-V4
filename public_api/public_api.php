<?php
/**
 * Public API - Front layer tussen PWA en Backend API
 * - Verzorgt CORS, cookies (HttpOnly), SameSite auto, HMAC forwarding
 * - Routeert alle endpoints door naar Backend API (auth, data, enz.)
 *
 * Vereist: public-config.json (aangemaakt via je Public Admin / pairing)
 *   {
 *     "backend_base": "https://xg3-hlr4be.dynerto.com/backend_api.php",
 *     "public_id": "PUB_...uuid...",
 *     "shared_secret": "...",
 *     "cookie": {
 *       "name": "p_session",
 *       "domain": null,         // null = huidige host
 *       "secure": true,
 *       "samesite": "Auto"      // "Auto" | "None" | "Lax" | "Strict"
 *     },
 *     "allow_origins": ["https://time.dynerto.com","https://app.jouwdomein.nl"] // PWA origins
 *   }
 */

declare(strict_types=1);
mb_internal_encoding('UTF-8');

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Content-Type: application/json; charset=utf-8');

$ROUTE = trim($_GET['route'] ?? (isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : ''), '/');
$METHOD = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ORIGIN = $_SERVER['HTTP_ORIGIN'] ?? null;

/** ---------- Helpers ---------- */
function read_json_config(string $file): array {
  if (!is_file($file)) fail(500, 'config_missing', 'public-config.json ontbreekt of is niet leesbaar.');
  $raw = file_get_contents($file);
  $cfg = json_decode($raw, true);
  if (!is_array($cfg)) fail(500, 'config_invalid', 'public-config.json is ongeldig JSON.');
  return $cfg;
}
function same_host(string $a, string $b): bool {
  $ha = parse_url($a, PHP_URL_HOST) ?: $a;
  $hb = parse_url($b, PHP_URL_HOST) ?: $b;
  return (strcasecmp($ha, $hb) === 0);
}
function allow_cors(array $cfg, ?string $origin): void {
  // Sta alleen expliciet geconfigureerde origins toe (PWA origins)
  $allowed = $cfg['allow_origins'] ?? [];
  if ($origin && in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Public-Id, X-Ts, X-Sign, X-Session, Authorization');
    header('Access-Control-Allow-Methods: GET,POST,PUT,PATCH,DELETE,OPTIONS');
  }
}
function is_preflight(): bool { return ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'; }
function json_body(): array {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
function fail(int $code, string $err, string $msg, array $extra=[]): never {
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$err,'message'=>$msg,'...'=>$extra], JSON_UNESCAPED_SLASHES);
  exit;
}
function ok(array $data=[], int $code=200): never {
  http_response_code($code);
  $data['ok'] = $data['ok'] ?? true;
  echo json_encode($data, JSON_UNESCAPED_SLASHES);
  exit;
}
function cookie_attrs(array $cfg, ?string $origin): array {
  $c = $cfg['cookie'] ?? [];
  $name   = $c['name']   ?? 'p_session';
  $domain = $c['domain'] ?? null; // null => huidige host
  $secure = (bool)($c['secure'] ?? true);
  $ssCfg  = $c['samesite'] ?? 'Auto';

  $host = $_SERVER['HTTP_HOST'] ?? '';
  $cross = $origin ? !same_host($origin, "https://".$host) && !same_host($origin, "http://".$host) : false;

  // SameSite automatique:
  $samesite = 'Lax';
  if ($ssCfg === 'None' || ($ssCfg === 'Auto' && $cross)) $samesite = 'None';
  if ($ssCfg === 'Strict') $samesite = 'Strict';

  return [$name, $domain, $secure, $samesite];
}
function set_session_cookie(array $cfg, string $token, int $ttlSeconds, ?string $origin): void {
  [$name,$domain,$secure,$samesite] = cookie_attrs($cfg, $origin);
  $params = [
    'expires'  => time()+$ttlSeconds,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $samesite,
  ];
  if ($domain) $params['domain'] = $domain;
  setcookie($name, $token, $params);
}
function clear_session_cookie(array $cfg, ?string $origin): void {
  [$name,$domain,$secure,$samesite] = cookie_attrs($cfg, $origin);
  $params = [
    'expires'  => time()-3600,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $samesite,
  ];
  if ($domain) $params['domain'] = $domain;
  setcookie($name, '', $params);
}
function read_session_cookie(array $cfg): ?string {
  $name = $cfg['cookie']['name'] ?? 'p_session';
  return $_COOKIE[$name] ?? null;
}
function backend_forward(array $cfg, string $route, string $method, ?array $json, ?string $session): array {
  $url = rtrim($cfg['backend_base'], '/').'/'.ltrim($route,'/');
  $body = $json ? json_encode($json, JSON_UNESCAPED_SLASHES) : '';
  $ts   = (string)time();
  $toSign = $ts . "\n" . $method . "\n/" . ltrim($route,'/') . "\n" . $body;
  $sign   = hash_hmac('sha256', $toSign, $cfg['shared_secret'] ?? '');

  $ch = curl_init($url);
  $headers = [
    'Content-Type: application/json',
    'X-Public-Id: '.$cfg['public_id'],
    'X-Ts: '.$ts,
    'X-Sign: '.$sign,
  ];
  if ($session) $headers[] = 'X-Session: '.$session;

  curl_setopt_array($ch, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_FOLLOWLOCATION => false,
  ]);
  if ($method !== 'GET' && $method !== 'HEAD') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  }

  $resp = curl_exec($ch);
  if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    fail(502, 'backend_unreachable', 'Backend API niet bereikbaar.', ['cURL'=>$err, 'url'=>$url]);
  }
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 200;
  curl_close($ch);

  $data = json_decode($resp, true);
  if (!is_array($data)) {
    // Als backend HTML of iets anders stuurt – geef door als fout.
    fail($http, 'backend_non_json', 'Backend stuurde geen JSON.', ['raw'=>$resp]);
  }
  // Zet HTTP code door zoals backend hem gaf
  http_response_code($http);
  return $data;
}

/** ---------- Boot ---------- */
$cfg = read_json_config(__DIR__ . '/public-config.json');
allow_cors($cfg, $ORIGIN);

if (is_preflight()) {
  // Preflight leeg 204 met CORS headers
  http_response_code(204);
  exit;
}

// Health zonder verdere eisen
if ($ROUTE === '' || $ROUTE === 'health') {
  ok(['service'=>'public-api','route'=>$ROUTE,'time'=>date('c')]);
}

// Auth endpoints met cookie management hier, alles anders generiek forwarden
$session = read_session_cookie($cfg);
$body    = json_body();

switch ($ROUTE) {
  case 'auth/login': {
    $res = backend_forward($cfg, 'public/auth/login', 'POST', $body, null);
    // Verwacht { ok:true, session_token, ttl, user:{...} }
    if (($res['ok'] ?? false) && isset($res['session_token'])) {
      $ttl = (int)($res['ttl'] ?? 2592000); // 30 dagen default
      set_session_cookie($cfg, $res['session_token'], $ttl, $ORIGIN);
    }
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
  }
  case 'auth/logout': {
    if ($session) backend_forward($cfg, 'public/auth/logout', 'POST', [], $session);
    clear_session_cookie($cfg, $ORIGIN);
    ok();
  }
  case 'auth/session': {
    // Vraag backend om user info; session meegeven
    if (!$session) fail(401, 'not_authenticated', 'Geen geldige sessie.');
    $res = backend_forward($cfg, 'public/auth/session', 'GET', null, $session);
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
  }
  case 'auth/register':
  case 'auth/request-reset':
  case 'auth/reset':
  case 'auth/verify':
  {
    $method = ($ROUTE === 'auth/verify') ? 'GET' : 'POST';
    $res = backend_forward($cfg, 'public/'. $ROUTE, $method, $body, $session);
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
  }
  default: {
    // Alles (bijv. v1/categories, v1/timelogs, settings, etc.) gaat generiek door
    $res = backend_forward($cfg, $ROUTE, $METHOD, ($METHOD==='GET'||$METHOD==='HEAD') ? null : $body, $session);
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
  }
}
