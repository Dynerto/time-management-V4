Gebruik (kort) zie uitleg over dsn / backend login onderaan

Plaats bestanden in twee mappen: /backend en /public op je hosting.

Ga naar /backend/backend_admin.php → Eerste setup: DSN, user, pass, admin‑wachtwoord → Schema initialiseren.

Ga naar /public/public_admin.php → Koppelen met backend: vul bij voorkeur https://<backend>/backend_pairing.php → Goedkeuren in Backend Admin → Config wordt automatisch gevuld.

In Public Admin: vul Allowed Origins, App URL, SMTP, en (optioneel) cookie‑instellingen → Opslaan.

Je PWA kan nu spreken tegen https://<public-domein>/api/... met credentials: 'include'.

Als je nog 403’s ziet bij de koppeling, gebruik backend_pairing.php (zoals hierboven) en controleer of Cloudflare/WAF geen POST naar admin‑paden blokkeert.




**Kort antwoord**

* **DSN = Data Source Name**: de **verbindingstekst** die PDO nodig heeft om met je database te praten (driver + parameters).
* In **jouw** setup (shared hosting met PostgreSQL op dezelfde server) is de **juiste DSN** dit — **deze werkt bij jou al**:

```text
pgsql:host=/var/run/postgresql;dbname=dbi40ckaxdjd13
```

> In de **Backend Admin** vul je daarnaast **DB user** (bijv. `umk1auyij8eii`) en **DB pass** in. Die horen **niet** in de DSN‑string, maar in de aparte velden.

---

## Wat is een DSN precies?

De DSN vertelt PDO:

* **welke driver**: `pgsql` (PostgreSQL),
* **waar de server is**: via **UNIX socket** (`host=/var/run/postgresql`) of **TCP** (`host=naam.of.ip;port=5432`),
* **welke database**: `dbname=...`,
* optioneel extra’s (SSL‑modus, search\_path, etc.).

PDO gebruikt die DSN, samen met de **DB‑gebruiker** en het **wachtwoord**, om een connectie te openen.

---

## Welke DSN moet jij gebruiken?

Gebruik de **socket‑variant** (stabiel, snel, geen SSL‑gedoe) — deze heb je al succesvol getest:

```text
pgsql:host=/var/run/postgresql;dbname=dbi40ckaxdjd13
```

* **DB user**: de gebruiker die je in je hostingpanel aan de database hebt gekoppeld (bijv. `umk1auyij8eii`).
* **DB pass**: wachtwoord van die gebruiker.

Open in **/backend/backend\_admin.php**:

1. vul **DSN** exact zoals hierboven,
2. vul **DB user** en **DB pass**,
3. klik **Test DB‑verbinding** en daarna **Schema initialiseren**.

---

## Alternatieven (alleen gebruiken als het echt moet)

**1) TCP op dezelfde server (niet nodig als socket werkt):**

```text
pgsql:host=127.0.0.1;port=5432;dbname=dbi40ckaxdjd13
```

* Kan weer leiden tot `pg_hba.conf`/SSL‑fouten op shared hosting. Socket is beter.

**2) Externe/Cloud PostgreSQL (andere host):**

```text
pgsql:host=your-db.example.com;port=5432;dbname=dbi40ckaxdjd13;sslmode=require
```

* Sommige providers eisen `sslmode=verify-full` en een `sslrootcert`:

```text
pgsql:host=your-db.example.com;port=5432;dbname=dbi40ckaxdjd13;sslmode=verify-full;sslrootcert=/path/to/ca.pem
```

* Let op: bij veel hosters moet je **IP whitelisten**.

---

## Veelvoorkomende fouten en wat ze betekenen

* **“server does not support SSL, but SSL was required”**
  Je DSN of server dwingt SSL af maar de server kan geen SSL. Oplossing: socket gebruiken (zoals jij al doet) of `sslmode=disable` (alleen intern, niet publiek internet).

* **“no pg\_hba.conf entry …”**
  Je probeert via TCP binnen te komen, maar de server laat dat IP/gebruikerscombinatie niet toe. Op shared hosting kun je dit vaak niet aanpassen → gebruik de socket‑DSN.

* **“permission denied for database … User does not have CONNECT privilege.”**
  De DB‑gebruiker had geen rechten. Jij hebt dit eerder opgelost door in je hostingpanel de gebruiker aan de database te koppelen met de juiste privileges.

---

## Samenvatting voor jouw situatie

* **DSN**: `pgsql:host=/var/run/postgresql;dbname=dbi40ckaxdjd13`
* **DB user**: (die je aan de DB gekoppeld hebt, bijv. `umk1auyij8eii`)
* **DB pass**: (het wachtwoord van die user)

Deze DSN hoort **alleen** in de **Backend Admin**.
De **Public/BFF‑laag** en de **PWA** hebben **géén DSN** nodig — die praten via de pairing/keys met de backend.
