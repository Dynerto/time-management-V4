Belangrijke aandachtspunten

Vul alle placeholders in (example.com, secrets, DSN/DB‑creds).

Volgorde van installatie (snel):

Backend hosten → /backend_admin.php openen → DB invullen → Schema initialiseren → Genereer Public API key → noteer plaintext key.

Public/BFF hosten → /public_admin.php → vul backend_url, internal_secret (zelfde als backend), api_key (plaintext van vorige stap), allowed_origins (jouw PWA URL), app_url, mail_from.

Frontend hosten → in de PWA Menu ▸ Admin zet je Public API URL.

Cookies werken alleen als allowed_origins exact matcht met de PWA‑origin en je HTTPS gebruikt.

Geen tokens in de frontend: alleen cookie‑sessies (HttpOnly) + CSRF.

Offline: start/stop werkt zonder internet; de SW synchroniseert zodra je online bent.


config die je moet invullen:

public_config.php: backend_url, internal_secret (zelfde als backend), api_key (plaintext key uit backend admin), allowed_origins, app_url, mail_from, optioneel frontend_admin_password_hash.

internal_config.php: db_dsn, db_user, db_pass, en internal_secret.

manifest.json: verwijst naar icons (je eigen PNG’s plaatsen in /icons/).
