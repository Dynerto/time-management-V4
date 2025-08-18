<?php
declare(strict_types=1);

/** Wordt door public_admin.php weggeschreven */
return [
  // === Koppeling met Backend/Internal API ===
  'backend_url' => '',           // wordt via pairing gezet (we normaliseren naar /internal_api.php)
  'internal_secret' => '',
  'api_key' => '',

  // === Front-end & CORS ===
  'allowed_origins' => [
    // 'https://app.jouwdomein.nl'
  ],
  'app_url' => '',

  // === Sessies ===
  'session_cookie_name' => 'tm_sid',
  'session_lifetime'    => 604800, // 7d

  // === Cookies & cross-domain ===
  'cookie_samesite'      => 'auto',  // auto|strict|lax|none
  'cookie_domain'        => '',      // optioneel, bv. .jouwdomein.nl
  'force_secure_cookies' => true,

  // === SMTP (PHPMailer) ===
  'mail_from' => '',
  'smtp' => [
    'host'       => '',
    'port'       => 587,
    'secure'     => 'tls',   // tls|ssl
    'username'   => '',
    'password'   => '',
    'from_email' => '',
    'from_name'  => 'Timelog',
    'reply_to'   => null
  ],

  // === Rate limits (per IP/e-mail/etc) ===
  'rate_limits' => [
    'global_ip'       => [300, 3600],
    'login_ip'        => [60,  3600],
    'login_user'      => [10,  3600],
    'register_ip'     => [20,  3600],
    'register_email'  => [5,   86400],
    'resend_ip'       => [20,  3600],
    'resend_uid'      => [5,   3600],
    'reset_req_ip'    => [30,  3600],
    'reset_req_email' => [5,   3600],
    'verify_ip'       => [60,  3600],
    'reset_confirm_ip'=> [60,  3600],
  ],

  // === Admin portalen ===
  'frontend_admin_password_hash' => '',
  'public_admin_password_hash'   => '',
  'setup_token'                  => '',

  // === Pairing (tijdelijk tijdens koppeling) ===
  'pending_pairing' => null,
];
