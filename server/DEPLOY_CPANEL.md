# Deploying Stock Trading Signals on cPanel

One upload gives you everything on **https://stocksignal.bulksend.top**:

| URL | What it is |
|---|---|
| `/` | Public website: SEO homepage, live track record, pricing, brokers, affiliate program |
| `/app` | Member dashboard: signals, charts, results, watchlist, alerts, settings, Premium, affiliate dashboard |
| `/admin` | Admin panel: users, payments, plans, affiliates, commissions, payouts, brokers, signals, settings, audit log |
| `/api/*` | JSON API used by the Android app |

Behind it run the **scanner** (records every signal and closes it at target/stop), **emails**,
**push notifications** (Firebase), **Paystack** (website payments) and **Google Play Billing** (app).

Requirements: PHP **8.1+** with `pdo_mysql`, `curl`, `openssl`, `mbstring` (standard on cPanel),
MySQL/MariaDB, cron jobs and SSL. Plan on about 45 minutes.

---

## 1. Create the subdomain
cPanel → **Domains** → Create a new domain: `stocksignal.bulksend.top`
- **Document root:** `stocksignal/public` (uncheck "share document root")
- cPanel → **SSL/TLS Status** → run AutoSSL so `https://stocksignal.bulksend.top` works.

## 2. Upload the files
cPanel → **File Manager** → your home folder (`/home/USER/`), not public_html:
1. Create folder `stocksignal`
2. Upload the `server/` contents (zip → upload → Extract) so you have:
```
/home/USER/stocksignal/app/      ← code, config, cron (NOT web-accessible)
/home/USER/stocksignal/public/   ← index.php, .htaccess, assets/ (the subdomain root)
```
3. Make `app/storage` writable (permissions 755).

## 3. Database
cPanel → **MySQL® Databases**: create database `USER_stocksignal`, user `USER_stocksignal` with a strong
password, and add the user to the database with **ALL PRIVILEGES**. Tables are created in step 6.

## 4. Email mailbox
cPanel → **Email Accounts** → create `signals@bulksend.top`. **Connect Devices** shows the SMTP host and
port (usually `mail.bulksend.top`, port **465**, SSL). cPanel → **Email Deliverability**: make sure
**SPF** and **DKIM** are valid so alerts land in the inbox.

## 5. Configuration
Copy `app/config.sample.php` → `app/config.php` and fill in:
| Setting | Value |
|---|---|
| `base_url` | `https://stocksignal.bulksend.top` (already set) |
| `db.*` | database, user and password from step 3 |
| `jwt_secret` | long random string: `php -r "echo bin2hex(random_bytes(32));"` |
| `mail.*` | mailbox from step 4 |
| `google.client_ids` / `google.web_client_id` | Google OAuth client IDs (step 9) |
| `paystack.*` | Paystack keys (step 10) |
| `play.*` | Google Play service account (step 11) |
| `developer.*` | already set to your WhatsApp and Buy Me a Coffee links |

## 6. Install check + make yourself admin
cPanel → **Terminal** (or SSH):
```bash
cd ~/stocksignal
php app/cron/install.php --test-email you@gmail.com
```
It creates/updates all tables (safe to re-run after every update) and checks database, secret,
storage, email, push, Paystack and Play setup.

Then open `https://stocksignal.bulksend.top/signup`, create your own account, and run:
```bash
php app/cron/install.php --make-admin you@gmail.com
```
Sign in and open `/admin`. (`/api/health` should show `"status":"ok"`.)

## 7. Cron jobs
cPanel → **Cron Jobs** (replace `USER`; if `php` isn't found use `/usr/local/bin/php`):
| Schedule | Command | Purpose |
|---|---|---|
| `5,35 * * * *` | `php /home/USER/stocksignal/app/cron/scan.php >/dev/null 2>&1` | scan market, record & close signals, send alerts |
| `*/5 * * * *` | `php /home/USER/stocksignal/app/cron/send_mail.php >/dev/null 2>&1` | send queued emails |
| `30 21 * * 1-5` | `php /home/USER/stocksignal/app/cron/reports.php daily >/dev/null 2>&1` | daily report |
| `0 9 * * 6` | `php /home/USER/stocksignal/app/cron/reports.php weekly >/dev/null 2>&1` | weekly report |
| `0 9 1 * *` | `php /home/USER/stocksignal/app/cron/reports.php monthly >/dev/null 2>&1` | monthly report |
| `15 3 * * *` | `php /home/USER/stocksignal/app/cron/maintenance.php >/dev/null 2>&1` | release affiliate commissions after the hold period, re-check Play subscriptions, clean sessions |

No CLI cron on your plan? Set `cron_key` in config and call
`https://stocksignal.bulksend.top/api/cron/scan?key=YOUR_KEY` (also `mail`, `daily`, `weekly`,
`monthly`, `maintenance`) from a free service like cron-job.org.

## 8. Firebase (push)
1. https://console.firebase.google.com → **Add project** → add an **Android app** with package
   `com.swiftmynd.signal_lab`.
2. In the Flutter project: `dart pub global activate flutterfire_cli` → `flutterfire configure`.
3. Firebase → Project settings → **Service accounts** → *Generate new private key* → upload the JSON
   to `app/firebase-service-account.json` (next to config.php, never in public/).

## 9. Google Sign-In (app + website)
1. Firebase → Authentication → Sign-in method → enable **Google** (creates the OAuth clients). Add your
   app's **SHA-1** (Play Console → App integrity, plus your debug key).
2. Google Cloud console → APIs & Services → Credentials → the **Web client**: add
   `https://stocksignal.bulksend.top` to **Authorized JavaScript origins**.
3. Put that Web client ID in `google.client_ids` **and** `google.web_client_id`, and build the app with
   `--dart-define=GOOGLE_SERVER_CLIENT_ID=<that id>`.

## 10. Paystack (website payments)
1. https://dashboard.paystack.com → Settings → **API Keys & Webhooks**.
2. Copy the **secret** and **public** keys into `config.php → paystack`.
3. Set **Webhook URL** to `https://stocksignal.bulksend.top/paystack/webhook`.
4. Prices are in Admin → **Plans & pricing** (NGN by default; switch to USD in Admin → Settings if your
   Paystack account accepts USD).
Payments are one-time passes (1 or 12 months). Each payment is re-verified with Paystack's API before
Premium is activated, so a fake callback can't unlock anything.

## 11. Google Play subscriptions (Android app)
Play policy requires Google Play Billing for Premium bought inside the app.
1. Play Console → your app → **Monetize → Subscriptions**: create `premium_monthly` and `premium_yearly`
   (same IDs as Admin → Plans), each with an auto-renewing base plan. Publish an app build with the
   billing library first (the console unlocks subscriptions after that).
2. Play Console → **Setup → API access**: link a Google Cloud project, create a **service account**, invite
   it in **Users and permissions** with *View financial data* and *Manage orders and subscriptions*.
3. Download its JSON key to `app/play-service-account.json`.
4. Optional: Monetize → **Real-time developer notifications** isn't required; the daily maintenance cron
   re-checks subscriptions that are about to renew.
5. Add your own Gmail under **License testing** to buy without being charged.

## 12. Affiliates and brokers
- Admin → **Settings**: commission % (default 30), first payment only or every renewal, hold days
  (default 14, matches the refund window), cookie days, minimum payout, payout methods, broker share %.
- Users apply from `/app/affiliate`; you approve in Admin → **Affiliates** (or switch on auto-approve).
- Affiliates share `https://stocksignal.bulksend.top/r/CODE` (any page: `/r/CODE?to=/pricing`) or app users
  type the code at sign-up. The first referrer wins; self-referrals are ignored.
- Commissions are created automatically for every Paystack and Google Play payment by a referred user,
  held for the hold period, then become withdrawable. Refunds (Admin → Payments → Refund) reverse unpaid
  commissions and claw back paid ones.
- Payouts: affiliates request from their dashboard; you pay by bank/USDT/PayPal and click **Mark paid**
  in Admin → **Payouts** with the transfer reference. They get an in-app + email notification.
- Brokers: Admin → **Brokers** → add your broker affiliate link with `{sub_id}` where the broker wants a
  tracking ID (e.g. `https://broker.com/?aff=123&subid={sub_id}`). Every click gets a unique sub-ID.
  When the broker reports a funded account for a sub-ID, use **Record conversion** with the amount you
  earned; the affiliate who brought that user is credited their share.

## 13. Point the app at the server
```bash
flutter build appbundle --release \
  --dart-define=SITE_URL=https://stocksignal.bulksend.top \
  --dart-define=GOOGLE_SERVER_CLIENT_ID=XXXX.apps.googleusercontent.com
```
(The API URL defaults to `SITE_URL/api`.)

## Store-required pages
- Privacy policy: `https://stocksignal.bulksend.top/privacy`
- Terms: `https://stocksignal.bulksend.top/terms`
- Account deletion: `https://stocksignal.bulksend.top/delete-account`

## Maintenance
- Admin → **Overview** warns if the scanner hasn't run, and shows email queue, payouts and applications waiting.
- Admin → **Signals & scanner** has a *Run scan now* button and the scan log.
- Errors go to cPanel → **Errors** / `error_log`. Every admin action is in Admin → **Audit log**.
- Back up the database with cPanel → **Backup**. The `signals` table *is* your track record.

## Testing locally (developers)
```bash
# needs PHP 8.1+, MariaDB/MySQL, Python 3
python3 app/tests/tools/smtp_catcher.py &      # fake mail server on :2525
php app/tests/run.php                           # 138 integration tests
```
