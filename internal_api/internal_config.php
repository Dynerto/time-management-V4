<?php
declare(strict_types=1);
return [
  // Vul je DB in via backend_admin.php (Eerste setup)
  'db_dsn'  => 'pgsql:host=/var/run/postgresql;dbname=YOUR_DB', // bv. pgsql:host=/var/run/postgresql;dbname=dbi40ckaxdjd13
  'db_user' => 'YOUR_DB_USER',
  'db_pass' => 'YOUR_DB_PASSWORD',

  // Deze waarden worden door backend_admin.php gezet
  'internal_secret' => '',
  'backend_admin_password_hash' => '',
];
