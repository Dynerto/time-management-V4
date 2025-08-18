Gebruik (kort)

Plaats bestanden in twee mappen: /backend en /public op je hosting.

Ga naar /backend/backend_admin.php → Eerste setup: DSN, user, pass, admin‑wachtwoord → Schema initialiseren.

Ga naar /public/public_admin.php → Koppelen met backend: vul bij voorkeur https://<backend>/backend_pairing.php → Goedkeuren in Backend Admin → Config wordt automatisch gevuld.

In Public Admin: vul Allowed Origins, App URL, SMTP, en (optioneel) cookie‑instellingen → Opslaan.

Je PWA kan nu spreken tegen https://<public-domein>/api/... met credentials: 'include'.

Als je nog 403’s ziet bij de koppeling, gebruik backend_pairing.php (zoals hierboven) en controleer of Cloudflare/WAF geen POST naar admin‑paden blokkeert.
