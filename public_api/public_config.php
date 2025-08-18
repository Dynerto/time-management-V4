<?php
declare(strict_types=1);

/**
 * Public/BFF configuratie.
 * Vul waarden via public_admin.php; dit bestand wordt door de admin UI weggeschreven.
 */
return [
    // === Koppeling met Backend/Internal API ===
    'backend_url' => '',           // b.v. https://backend.domein.tld/internal_api.php of backend_admin.php (zie pairing)
    'internal_secret' => '',       // geheime sleutel voor Public -> Internal (wordt door pairing gevuld)
    'api_key' => '',               // Public API plaintext key (door pairing gevuld)

    // === Front-end & CORS ===
    'allowed_origins' => [         // Exacte origins die requests met credentials mogen doen
        // 'https://app.jouwdomein.nl'
    ],
    'app_url' => '',               // Basis URL van je PWA (voor verify/reset links), b.v. https://app.jouwdomein.nl

    // === Sessie ===
    'session_cookie_name' => 'tm_sid',
    'session_lifetime'    => 604800, // 7 dagen, in seconden

    // === Cookies & cross-domain gedrag ===
    // 'auto'  => automatisch Strict of None afhankelijk van cross-site,
    // 'strict'=> force Strict, 'lax' => force Lax, 'none' => force None (vereist HTTPS)
    'cookie_samesite'      => 'auto',
    // Optioneel gedeeld cookie-domein voor subdomeinen (bv. '.jouwdomein.nl'); laat leeg voor default
    'cookie_domain'        => '',
    // Dwing 'Secure' af op cookies (aan te raden; bij SameSite=None is Secure verplicht)
    'force_secure_cookies' => true,

    // === E-mail (PHPMailer/SMTP) ===
    'mail_from' => '', // fallback / legacy; feitelijk gebruiken we SMTP hieronder
    'smtp' => [
        'host'       => '',      // SMTP host
        'port'       => 587,     // 587 (TLS) of 465 (SSL)
        'secure'     => 'tls',   // 'tls' of 'ssl'
        'username'   => '',      // SMTP user
        'password'   => '',      // SMTP pass
        'from_email' => '',      // afzender e-mailadres
        'from_name'  => 'Timelog',
        'reply_to'   => null     // optioneel reply-to
    ],

    // === Rate limiting (Public API) ===
    // max requests per window (in sec) per key (IP/e-mail/user-id afhankelijk van endpoint)
    'rate_limits' => [
        'global_ip'      => [300, 3600],  // alle endpoints opgeteld per IP
        'login_ip'       => [60,  3600],
        'login_user'     => [10,  3600],
        'register_ip'    => [20,  3600],
        'register_email' => [5,   86400],
        'resend_ip'      => [20,  3600],
        'resend_uid'     => [5,   3600],
        'reset_req_ip'   => [30,  3600],
        'reset_req_email'=> [5,   3600],
        'verify_ip'      => [60,  3600],
        'reset_confirm_ip'=>[60,  3600]
    ],

    // === Admin portalen ===
    'frontend_admin_password_hash' => '', // wachtwoordhash voor PWA-Admin (optioneel, gezet via Public Admin)
    'public_admin_password_hash'   => '', // server-side wachtwoord voor dit Public Admin
    'setup_token'                  => '', // init token voor eerste setup (optioneel; kan leeg zijn)

    // === Pairing status (wordt tijdelijk gevuld tijdens koppeling) ===
    'pending_pairing' => null,
];
