# Deploying the Stock Trading Signals backend on cPanel

What runs on your hosting: the **API** (logins, settings, signals, results), the **scanner**
(records every signal and closes it at target/stop), **emails** (alerts, verification, reports) and
**push notifications** (through Firebase Cloud Messaging, which is free).

Requirements: PHP **8.1+** with `pdo_mysql`, `curl` and `openssl` (standard on cPanel), MySQL/MariaDB,
cron jobs and SSL (AutoSSL / Let's Encrypt). Plan on about 30 minutes.

---

## 1. Create the subdomain
cPanel → **Domains** → Create a new domain: `api.yourdomain.com`
- **Document root:** `signallab/public` (uncheck "share document root")
- cPanel → **SSL/TLS Status** → run AutoSSL so `https://api.yourdomain.com` works.

## 2. Upload the files
cPanel → **File Manager** → your home folder (`/home/USER/`), not public_html:
1. Create folder `signallab`
2. Upload the `server/` contents so you have:
```
/home/USER/signallab/app/      ← code, config, cron (NOT web-accessible)
/home/USER/signallab/public/   ← index.php + .htaccess (the subdomain root)
```
3. Make `app/storage` writable (permissions 755).

### Fix a missing `../app/bootstrap.php` on `signalab.bulksend.top`

The live error from `/home/globacom/signalab.bulksend.top/index.php` means the
domain's current document root is `/home/globacom/signalab.bulksend.top/`.
From there, `__DIR__ . '/../app'` resolves to `/home/globacom/app`, **not**
`/home/globacom/signalab.bulksend.top/app`. Do not put `app/` inside the web
document root: it contains database credentials and other private files.

**Preferred fix:** In cPanel File Manager, upload `server/app/` to
`/home/globacom/signallab/app/` and `server/public/` (including its hidden
`.htaccess`) to `/home/globacom/signallab/public/`. In cPanel > Domains >
Manage `signalab.bulksend.top`, change its document root to
`/home/globacom/signallab/public`. The existing `public/index.php` then finds
`../app/bootstrap.php` without changing any PHP code. An old `index.php` in
`/home/globacom/signalab.bulksend.top/` is no longer served.

**If the document root cannot be changed:** Keep only the contents of
`server/public/` in `/home/globacom/signalab.bulksend.top/`, place the private
`app/` at `/home/globacom/signallab/app/`, and edit line 6 of the **live**
`/home/globacom/signalab.bulksend.top/index.php` to:

```php
$appDir = getenv('SIGNALLAB_APP_DIR') ?: '/home/globacom/signallab/app';
```

The next line remains `require $appDir . '/bootstrap.php';`. Reapply that
site-specific line if you later replace the live `index.php` from this repo.
In either layout, confirm the private files exist in cPanel Terminal:

```bash
test -f /home/globacom/signallab/app/bootstrap.php && echo bootstrap-ok
test -f /home/globacom/signallab/app/config.php && echo config-ok
```

If `config.php` is missing, copy `config.sample.php` to `config.php` in the
private `app/` folder and fill it in as described in step 5. Never put it in
the public directory. Check `https://signalab.bulksend.top/` first; a JSON
response confirms bootstrapping. Then check `/health`, which also requires
the configured database and installed tables from step 6.

## 3. Database
cPanel → **MySQL® Databases**
1. Create database `USER_signallab`
2. Create user `USER_signallab` with a strong password
3. Add the user to the database with **ALL PRIVILEGES**

(Tables are created in step 6.)

## 4. Email mailbox
cPanel → **Email Accounts** → create `signals@yourdomain.com`.
Then **Connect Devices** shows the SMTP host and port (usually `mail.yourdomain.com`, port **465**, SSL).
For better inbox delivery: cPanel → **Email Deliverability** → make sure **SPF** and **DKIM** are valid.

## 5. Configuration
Copy `app/config.sample.php` → `app/config.php` and fill in:
| Setting | Value |
|---|---|
| `base_url` | `https://api.yourdomain.com` |
| `db.*` | database, user and password from step 3 |
| `jwt_secret` | a long random string (cPanel → Terminal: `php -r "echo bin2hex(random_bytes(32));"`) |
| `mail.*` | mailbox from step 4 |
| `google.client_ids` | your Google OAuth client IDs (step 8) |
| `developer.*` | already set to your WhatsApp and Buy Me a Coffee links |

## 6. Install check
cPanel → **Terminal** (or SSH):
```bash
cd ~/signallab
php app/cron/install.php --test-email you@gmail.com
```
It creates the tables and checks the database, secret, storage, email and push setup.
Open `https://api.yourdomain.com/health` → should show `"status":"ok"`.

## 7. Cron jobs
cPanel → **Cron Jobs** (replace `USER`; if `php` isn't found use `/usr/local/bin/php`):
| Schedule | Command | Purpose |
|---|---|---|
| `5,35 * * * *` | `php /home/USER/signallab/app/cron/scan.php >/dev/null 2>&1` | scan market, record & close signals, send alerts |
| `*/5 * * * *` | `php /home/USER/signallab/app/cron/send_mail.php >/dev/null 2>&1` | send queued emails |
| `30 21 * * 1-5` | `php /home/USER/signallab/app/cron/reports.php daily >/dev/null 2>&1` | daily report (after US close) |
| `0 9 * * 6` | `php /home/USER/signallab/app/cron/reports.php weekly >/dev/null 2>&1` | weekly report |
| `0 9 1 * *` | `php /home/USER/signallab/app/cron/reports.php monthly >/dev/null 2>&1` | monthly report |

Cron times use the server's clock. Check the timezone at the top of cPanel's Cron page.
If your host has no CLI cron, set `cron_key` in config and call
`https://api.yourdomain.com/cron/scan?key=YOUR_KEY` from a free service like cron-job.org.

### If `/health` says `"last_scan":null`

The server has **no scan log row yet**. Do not wait for a trading signal to
diagnose this: first confirm that the scan script runs. The sample cron command
above discards all output, including PHP errors. In cPanel Terminal, use the
**actual** private `app/` location (shown here as `/home/globacom/signallab/app`):

```bash
command -v php
php -v
test -f /home/globacom/signallab/app/cron/scan.php && echo scan-script-found
test -w /home/globacom/signallab/app/storage && echo storage-writable
php /home/globacom/signallab/app/cron/scan.php
```

The CLI PHP must be version 8.1+; cPanel's web PHP version may differ from
the `php` found by cron. If the script prints a PHP error, fix that first. If
`scan-script-found` is missing, correct the cron path to the actual deployed
directory. If `storage-writable` is missing, fix the private storage folder's
ownership/permissions so the cron user can create `scan.lock`. A successful
run prints the number of series and any Yahoo Finance errors and creates a
`last_scan` entry on `/health` even when it finds **zero** new signals.

For initial troubleshooting, send the scanner cron output to a **private** log
outside the web document root instead of `/dev/null` (use the PHP executable
and app path you verified above):

```text
5,35 * * * * /usr/local/bin/php /home/globacom/signallab/app/cron/scan.php >> /home/globacom/scan-cron.log 2>&1
```

Check `/home/globacom/scan-cron.log` after the next scheduled run. If manual
scanning works but cron does not, the scheduled command, PHP binary, or cron
environment differs. Also ensure the web API and CLI are using the **same**
`app/config.php` and database. A successful first scan does not backfill old
signals; it records new qualifying signals on subsequent closed market bars.

## 8. Firebase (push) and Google Sign-In
1. https://console.firebase.google.com → **Add project** → add an **Android app** with package
   `com.swiftmynd.signal_lab` (and an iOS app if you publish there).
2. In the Flutter project: `dart pub global activate flutterfire_cli` → `flutterfire configure`
   (adds `google-services.json` and the Gradle plugin).
3. Firebase → Project settings → **Service accounts** → *Generate new private key* → upload the JSON
   to `app/firebase-service-account.json` (next to config.php, never in public/).
4. Google Sign-In: Firebase → Authentication → Sign-in method → enable **Google** (this creates the
   OAuth clients). Add your app's **SHA-1** (Play Console → App integrity, and your debug key).
   Copy the **Web client ID** into `config.php → google.client_ids` and build the app with
   `--dart-define=GOOGLE_SERVER_CLIENT_ID=<that id>`.

## 9. Point the app at your API
```bash
flutter build appbundle --release \
  --dart-define=API_BASE_URL=https://api.yourdomain.com \
  --dart-define=GOOGLE_SERVER_CLIENT_ID=XXXX.apps.googleusercontent.com
```

## Public pages your server provides
- `https://api.yourdomain.com/record`: public live track record (share it to build trust)
- `https://api.yourdomain.com/privacy`: privacy policy (use as the Play Store privacy URL)
- `https://api.yourdomain.com/delete-account`: account deletion page (required by Google Play)

## Maintenance
- `https://api.yourdomain.com/health` shows the last scan time.
- Errors go to cPanel → **Errors** / `error_log`. The `scan_log` table records every scan.
- Back up the database with cPanel → **Backup**. The signals table *is* your track record.

## Testing locally (optional, for developers)
```bash
# needs PHP 8.1+, MariaDB/MySQL, Python 3
python3 app/tests/tools/smtp_catcher.py &      # fake mail server on :2525
php app/tests/run.php                           # 78 integration tests
```
