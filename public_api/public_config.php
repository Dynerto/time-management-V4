<?php
return [
    // === Koppeling naar Internal API ===
    'backend_url'     => 'https://backend.example.com/internal_api.php', // VUL IN
    'internal_secret' => 'CHANGE-ME-LONG-RANDOM',                         // VUL IN (exact gelijk aan internal_config.php)
    'api_key'         => 'PUBLIC-API-PLAINTEXT-KEY',                      // VUL IN (plaintext; hash staat in DB bij backend)

    // === Sessies & CORS ===
    'allowed_origins' => [
        'https://pwa.example.com' // VUL IN – exacte origin(s) van jouw frontend
    ],
    'session_cookie_name' => 'tm_sid',
    'session_lifetime'    => 604800, // 7 dagen

    // === E-mail (simpel) ===
    'mail_from' => 'Timelog <no-reply@example.com>',
    'app_url'   => 'https://pwa.example.com',

    // === Admin wachtwoorden ===
    // Frontend-admin (wordt gebruikt door /admin/fe/verify endpoint)
    'frontend_admin_password_hash' => '', // zet met password_hash(...)

    // Public-admin portal login (dit bestand beheren)
    'public_admin_password_hash' => '',   // zet met password_hash(...)

    // Init bootstrap token voor eerste setup van public_admin.php
    'setup_token' => 'SET-THIS-ONCE-THEN-DELETE'
];
