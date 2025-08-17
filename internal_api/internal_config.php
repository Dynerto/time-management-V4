<?php
// Vul de DSN en credentials van jouw PostgreSQL in.
return [
    'db_dsn'  => 'pgsql:host=127.0.0.1;port=5432;dbname=timelog', // VUL IN
    'db_user' => 'DB_USER',                                       // VUL IN
    'db_pass' => 'DB_PASS',                                       // VUL IN

    // Shared secret voor Public -> Internal
    'internal_secret' => 'CHANGE-ME-LONG-RANDOM',                 // VUL IN (match met public_config.php)

    // Backend Admin portal login
    'backend_admin_password_hash' => '', // zet via backend_admin.php bootstrap

    // (optie) andere flags/instellingen...
];
