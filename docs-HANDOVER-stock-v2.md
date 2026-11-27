# Stock Trading Signals — full handover for the next Claude Code session

Written 2026-11-24. This explains everything built so far, how it is wired, what is verified, what is not,
and exactly what to do next. Read it fully before changing anything.

---

## 0. TL;DR

- **Product:** "Stock Trading Signals", a free US-stock signal app (Flutter, Android first) with a PHP/MySQL
  backend on cPanel. v2 adds a **website**, **member dashboard**, **admin panel**, **Premium** (paid
  unlock of live trade levels) and an **affiliate + broker-referral system**.
- **Owner:** Abdulfattah Abdullahi. Developer/brand name in all apps: **Swiftmynd Technologies Ltd**.
  - WhatsApp chat and hire-me: **+2347011401387** (`https://wa.me/2347011401387`)
  - Buy Me a Coffee: **buymeacoffee.com/swalafblogM**
  - GitHub: **adeluxs**. Sibling apps use the org **100-mobile-apps** (for example `100-mobile-apps/dayfx-signal`).
- **Domain:** `https://stocksignal.bulksend.top` (a cPanel subdomain of bulksend.top, the owner's cPanel host).
  - `/` website · `/app` member dashboard · `/admin` admin panel · `/api/*` JSON API for the app.
- **Android package:** `com.swiftmynd.signal_lab` (kept from the original "SignalLab" name; do not change it).
  - Display name everywhere: exactly **"Stock Trading Signals"**.
- **Status:**
  - Backend, website and admin: **138/138 integration tests pass** on PHP 8.3 + MariaDB, and a Playwright
    click-through of every page was checked visually.
  - Flutter v2 changes: **written but never compiled**. The sandbox has no Flutter SDK, and the Flutter
    engine download from storage.googleapis.com is blocked by the proxy.
  - **Not on GitHub yet.** The session had no repo access. A local commit exists (see §10).
- **Deliverable on disk:** `/mnt/user-data/outputs/stock_trading_signals.zip` (whole project). Web screenshots
  are in `/mnt/user-data/outputs/web_admin_screenshots/`.

---

## 1. History of requests (why things are the way they are)

1. Asked for a free Flutter app with "up to 100% profitable" signals. We told the user no app can be 100%
   profitable, and built an honest app with a **public, fully tracked record (losses included)**. Keep this
   stance in all copy: no "guaranteed", "100%" or "#1" claims (Play metadata policy).
2. Market chosen: **US stocks**, using free Yahoo Finance chart data.
3. The user found early versions "basic and non-trusted" and wanted a complete standard flow: splash,
   onboarding, auth, signals feed and performance, pro stock detail, price and signal alerts.
4. Required real functionality, not just UI:
   - tracked results daily/weekly/monthly
   - push AND email for new signals and for wins/losses
   - auth with email/password + Google
   - backend on the user's cPanel (PHP + MySQL)
5. ASO: keyword-based title. "Free" is not allowed in the title, so it went into the short description.
6. Developer promo pop-up like the user's Ruqya app: rate app, WhatsApp click-to-chat, Buy Me a Coffee and
   Hire me (same WhatsApp). Company name Swiftmynd Technologies Ltd.
7. Renamed to exactly "Stock Trading Signals" everywhere.
8. Generated store screenshots (8) and the Play icon. The user supplied their own icon, which we resized to
   512×512 with the artwork at about 84% of the canvas (the first attempt left too much empty border).
9. **v2 request: "build the web version of this app and the admin panel with affiliate system".** Answers:
   - Web = **server-rendered PHP web dashboard** (SEO, desktop-optimized). Not Flutter web.
   - Affiliates earn from **Paid Premium plan AND Broker referral links**.
   - Domain **stocksignal.bulksend.top**.
10. "Are you done … push to GitHub". Push failed: no repo access in the session (§10).

---

## 2. Repository layout

```
signal_lab/
  lib/                         Flutter app
    config.dart                AppConfig: SITE_URL, API_BASE_URL (= SITE_URL/api), premiumProductIds, dev links
    main.dart
    core/                      format.dart (fmtPrice, fmtDate, fmtPct, fmtR…), theme.dart (AppColors, Gap, numStyle)
    engine/                    on-device indicators/strategy/backtest (mirror of server Engine.php)
    models/                    candle.dart, profile.dart (AppUser, PremiumStatus, PremiumPlan, Broker,
                               AppNotification, RiskProfile, NotificationPrefs), tracked.dart (TrackedSignal + locked)
    services/                  api_client.dart (JWT + refresh), auth_service.dart (register/login/google + referralCode),
                               billing_service.dart (NEW, in_app_purchase), push_service.dart, notification_service.dart,
                               market_data.dart, prefs_store.dart
    state/app_state.dart       ChangeNotifier; v2 adds premium, plans, billing, brokers(), affiliateSummary()
    screens/                   splash, onboarding, auth (referral code field NEW), shell, dashboard, signals (lock card NEW),
                               tracked_signal (locked levels NEW), stock_detail, watchlist, results, performance,
                               alerts, settings (Premium/Brokers/Affiliate entries NEW), info_screens,
                               premium_screen.dart (NEW paywall + PremiumLockCard), brokers_screen.dart (NEW + AffiliateCard)
    widgets/                   common, charts, signal_widgets, tracked_widgets (locked NEW), risk_form, support (promo pop-up)
  test/                        Flutter tests (engine, sizing, models, widgets): not run in v2
  tool/setup.dart              One-time Android setup (permissions, desugaring, link queries)
  assets/icon/                 App icon
  store/                       Play listing: STORE_LISTING.md, fastlane/metadata/android/en-US/*, screenshots, feature graphic,
                               playstore_icon_512.png
  server/                      Everything for cPanel
    public/                    WEB ROOT: index.php (front controller), .htaccess, assets/{site.css, site.js, icon.svg}
    app/                       NOT web-accessible
      bootstrap.php            PSR-ish autoloader (SignalLab\X → src/X.php), require_once src/Http.php, loads config
      config.sample.php        Copy to config.php (already pre-filled for stocksignal.bulksend.top)
      sql/schema.sql           All tables (CREATE TABLE IF NOT EXISTS)
      src/                     All PHP classes (see §4)
      cron/                    install.php, scan.php, send_mail.php, reports.php, maintenance.php
      storage/                 writable: cache/ (chart JSON), fake/ (test candles), gtoken_*.json (Google OAuth token cache)
      tests/                   run.php (138 tests), config.test.php, fixtures/ (expected.json, play-sa.json test key),
                               tools/smtp_catcher.py
    DEPLOY_CPANEL.md           Step-by-step deployment (keep in sync)
  README.md, HANDOVER.md (this file), .gitignore
```

---

## 3. Architecture

### Front controller (`server/public/index.php`)
- Path starting with `/api` → strip `/api` → `Api::router()` → JSON responses (CORS headers).
- Everything else → `Web::handle($req)` → HTML website, `/app`, `/admin` (cookie session, CSRF).
- `.htaccess`:
  - forces HTTPS
  - passes the Authorization header through
  - sends non-files to index.php (static `/assets/*` are served directly)

### HTTP layer (`src/Http.php`, one file holding several classes)
- `ApiError(status, codeName, message)`.
- `Response(status, body, contentType, headers[])` with `json()`, `html()`, `error()`, `redirect()`,
  `text()`, and `with(header, value)` (Set-Cookie can repeat).
- `Request(method, path, query, body, headers, ip, cookies)`:
  - cookies are parsed from the Cookie header
  - helpers: `withPath()`, `cookie()`, `str()`, `int()`, `bearer()`
- `Router`: `add()`, `dispatch()`, and an optional `$onError` callable (the website renders HTML errors
  and redirects 401 to `/login?next=`). Also `send()` / `sendWith($res, $api)`.

### Core classes (`server/app/src/`)
| Class | Role |
|---|---|
| Config, Db (PDO helpers: all/one/value/exec/insert/update/now), Clock (freezable, `Clock::freeze(?DateTimeImmutable)`) | infrastructure |
| Jwt, Auth | HS256 access tokens + rotating refresh tokens. `createUser()`, `checkPassword()` and `googleUser()` return ids (used by the web); `register()`, `login()` and `google()` return tokens (app). They accept `$referral` and `$source` ('app'/'web'). Suspended users get 403. `userPayload()` includes `is_admin`, `referral_code` and `premium`. Email links go to the **web** `/verify?token=` and `/reset?token=`. |
| Engine | EMA50/200 trend, MACD cross, RSI filter, ATR stops (1.5 ATR stop, T1 1.5 ATR, T2 3.0 ATR), max hold 20 bars, 0.1% cost. Identical to the app engine (parity test). |
| Market | Yahoo chart API (closed bars only); `provider=fake` reads `storage/fake/*.json` in tests. |
| Tracker | Live recording: one open trade per stock/tf, sells are bearish warnings, no backfill. |
| Performance | Daily/weekly/monthly buckets, R-multiples, equity, by-symbol. |
| Notifier | `signalEvent()` fans out to inbox + push + email. **Free users get `lockedText()`** for new_buy (no levels). `system($uid, $title, $body)` sends account notices. |
| Push | FCM HTTP v1 through GoogleAuth. `Push::$transport` is mockable. |
| GoogleAuth | Service-account JWT → OAuth token, cached in memory + `storage/gtoken_*.json`. |
| Mailer, Templates | SMTP queue. `signalEmail(..., $locked)` shows "🔒 Premium" plus a "See Premium plans" button to `/pricing`. |
| Reports | Daily/weekly/monthly report emails. |
| HttpClient | curl wrapper. `HttpClient::$mock = fn($method, $url, $body, $headers) => ?['status', 'body']` for tests. |
| **Settings** | Key/value table with defaults (§6). |
| **Migrator** | Runs schema.sql, adds the v2 `users` columns if missing, adds indexes, seeds plans. Run by install.php. |
| **Plans** | Seeds monthly ($1.50 / ₦2,400, `premium_monthly`) and yearly ($12 / ₦19,200, `premium_yearly`, badge "Save 33%"). |
| **Premium** | `isActive()` (true for everyone if `premium_enabled=0`), `status()`, `extend(months)`, `setUntil()`. |
| **Billing** | See the Billing notes below. |
| **Affiliates** | See the Affiliates notes below. |
| **Brokers** | See the Brokers notes below. |
| **Urls** | `site($path)` and `api($path)` from `base_url`. |
| **Session** | DB-backed web session (`web_sessions`, cookie `sts_sid`, only the sha256 hash is stored). Holds the CSRF token (`_csrf` field or `X-CSRF-Token` header), flash messages, and a rotated id on login. |
| **View** | Layouts `publicPage` / `appPage` / `adminPage` / `errorPage`, plus components: table, tile, pill, statusPill, tabs, field, select, checkbox, copyBox, postButton, devButtons, pager. |
| **Web** | Router, guards (`uid()`, `admin()`), auth pages, `/r`, `/go`, Paystack callback/webhook, sitemap/robots. |
| **WebPages** | Public pages: home (FAQPage JSON-LD), pricing, record, brokers, affiliate program, auth forms, privacy, terms, delete account. |
| **WebApp** | Member pages under `/app` (including `chart.json`). |
| **Admin** | Admin routes + `Admin::log()` audit trail. |
| Api | App JSON API (§5). `LOCKED_FIELDS`, `isLive()`, `signalPayload($row, $premium)`, `premiumFeatures()`, `brokerDisclosure()`, `maintenance()`. |

**Billing** (Paystack and Google Play):
- `recordSuccess()` is idempotent on `provider_ref` (INSERT IGNORE).
- `refund()` marks the payment refunded, subtracts the plan months from `premium_until` (never before now)
  and calls `Affiliates::reverseForPayment`.
- Paystack:
  - `paystackInit()` creates a pending payment with ref `STS-{uid}-{hex}` and calls
    `/transaction/initialize` (amount in kobo/cents, `callback_url` = `/billing/callback`).
  - `paystackVerify()` re-verifies with the Paystack API (status, amount ≥ expected, currency) and
    **atomically claims pending→success**, so callback + webhook can't double credit. Then it extends
    Premium, creates the commission and sends a system notification.
  - `paystackWebhook()` checks the HMAC-SHA512 signature, then calls `paystackVerify()`.
- Google Play:
  - `googleVerify($uid, $productId, $token)` rejects a token owned by another user (409) and checks
    `obfuscatedExternalAccountId == obfuscatedId($uid)`.
  - `obfuscatedId()` = sha256('sts-user-'.uid.'-'.jwt_secret)[0..40]. It is sent to the app as
    `billing_account_id` and used as `applicationUserName` in the purchase.
  - `googleFetch()` calls androidpublisher v3 **subscriptionsv2**.
  - `googleSync()` upserts `google_subscriptions` and sets `premium_until` to the later expiry. It records
    a payment per new `latestOrderId` (skipping `testPurchase`). The commission base is
    `price_usd × (1 − google_fee_pct)`.
  - `googleRecheckDue()` runs daily from maintenance.

**Affiliates:**
- 7-character referral code per user (alphabet without 0/O/1/I).
- `attribute()`: first referrer wins and self-referral is ignored.
- `logClick()`, `apply()` (pending, or approved when auto-approve is on), `setStatus()`, `savePayout()`
  (bank / usdt / paypal with required fields).
- `rateFor()`: per-affiliate override, else the setting.
- `onPayment()` is idempotent (UNIQUE `payment_id` + `affiliate_user_id`) and respects
  `commission_recurring`, `approved`-only affiliates and the hold days.
- `reverseForPayment()`: unpaid → reversed; already paid → negative "Clawback" adjustment.
- `adjust()`, `approveDue()`, `summary()`.
- `requestPayout()` runs in a transaction with FOR UPDATE, enforces the minimum and only one open request.
- `markPayout(paid|rejected)` notifies the affiliate.

**Brokers:**
- `active()`, `payload()` (app links carry `?u=<signed user token>&src=app`), `userToken()` /
  `userFromToken()` (HMAC).
- `click()`:
  - credits the affiliate from `?ref=` first, else the user's referrer
  - records `broker_clicks` with a unique `sub_id` `st{12 hex}`
  - substitutes `{sub_id}` / `{subid}` / `{click_id}` into `url_template`
- `recordConversion(subId, amountUsd, note)` gives the affiliate `broker_share_pct`, approved
  immediately, and rejects duplicates.

### Premium lock rule
- A signal is "live" when `kind='trade'` and its status is pending or open.
- For non-Premium users, the API sends `locked:true` and nulls: `signal_price`, `stop_loss`, `target1`,
  `target2`, `entry_price`, `live_stop`, `live_target1`, `live_target2`.
- Closed results, bearish warnings, strength, reasons and `last_price` stay visible.
- The same rule applies on the web (WebApp), in push, in email and on the chart levels (`chart.json`
  returns no levels when locked).

---

## 4. Database (server/app/sql/schema.sql)

- **Original tables:** users, user_settings, watchlist, refresh_tokens, email_tokens, devices,
  login_attempts, tracked_series, signals, notifications (kind adds 'system'; UNIQUE
  user_id + signal_id + kind), email_queue, report_log, scan_log.
- **v2 tables:**
  - `settings(k, v)`
  - `plans(code, name, interval_months, price_usd, price_ngn, google_product_id, badge, active, sort)`
  - `payments(user_id, provider paystack/google/manual, provider_ref UNIQUE, plan_id, amount, currency,
    amount_usd, status pending/success/failed/refunded, period_end, meta)`
  - `google_subscriptions(user_id, token_hash UNIQUE, purchase_token, product_id, latest_order_id,
    state, expiry_time, auto_renewing, last_checked_at)`
  - `affiliates(user_id PK, status pending/approved/rejected/suspended, commission_rate NULL,
    payout_method, payout_details JSON, promo_plan, admin_note)`
  - `referral_clicks(affiliate_user_id, ip_hash, landing)`
  - `payouts(affiliate_user_id, amount_usd, method, details, status requested/paid/rejected, txn_ref,
    admin_note)`
  - `brokers(slug UNIQUE, name, logo_url, tagline, description, url_template, min_deposit, regions,
    active, sort)`
  - `broker_clicks(broker_id, user_id, affiliate_user_id, sub_id UNIQUE, source web/app/email, ip_hash)`
  - `commissions(affiliate_user_id, referred_user_id, source premium/broker/adjustment, payment_id,
    broker_id, broker_click_id, base_usd, rate, amount_usd, status
    pending/approved/paid/reversed/rejected, available_at, payout_id, note, UNIQUE(payment_id,
    affiliate_user_id))`
  - `admin_log(admin_id, action, target, details, ip)`
  - `web_sessions(id_hash PK, user_id, csrf, data, ip, user_agent, created_at, last_seen, expires_at)`
- **Columns Migrator adds to `users`:**
  - `role` ENUM user/admin
  - `status` ENUM active/suspended
  - `referral_code` (UNIQUE)
  - `referred_by` (indexed)
  - `referred_at`
  - `premium_until`
  - `signup_source` app/web

---

## 5. Routes

### App API (prefix `/api`)
- **Health:** `GET /`, `GET /health`.
- **Auth:**
  - `POST /auth/register` with optional `referral_code`
  - `POST /auth/login`
  - `POST /auth/google` with `id_token` and optional `referral_code`
  - `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/forgot`
  - `GET|POST /auth/reset`, `GET /auth/verify` (kept for old links)
  - `POST /auth/resend-verification`, `POST /auth/change-password`
- **Account and settings:**
  - `GET /me` returns user (incl. premium, referral_code, is_admin), settings, watchlist and
    `billing_account_id`
  - `PATCH /me`, `DELETE /me`, `GET|PUT /me/settings`, `GET|PUT /watchlist`
  - `POST|DELETE /devices`, `POST /devices/test`
- **Signals and results:**
  - `GET /signals` (tf, status live/closed/all, scope watchlist/all, symbol, before_id, limit)
  - `GET /signals/{id}`, `GET /performance`
  - `GET /notifications`, `POST /notifications/read`
- **Premium, brokers, affiliate:**
  - `GET /plans` returns enabled, plans and features
  - `GET /billing/status`, `POST /billing/google/verify` (product_id, purchase_token)
  - `GET /brokers` returns disclosure and items
  - `GET /affiliate` returns the summary and `program_url`
- **Public:** `GET /record`, `/record.json`, `/privacy`, `/delete-account` (legacy HTML;
  the website versions are preferred).
- **Web cron:** `GET /cron/{scan|mail|daily|weekly|monthly|maintenance}?key=CRON_KEY`.

### Website (no prefix)
- **Public:** `/`, `/pricing`, `/record`, `/brokers`, `/affiliate`, `/terms`, `/privacy`, `/robots.txt`,
  `/sitemap.xml`.
- **Referrals and broker links:**
  - `/r/{code}` sets cookie `sts_ref`, logs the click, redirects to `?to=` (relative paths only)
  - `/go/{slug}` takes `?ref=CODE` or `?u=token&src=app`
- **Auth:** `/login` (GET, POST), `/login/google` (POST; Google Identity Services credential with
  `g_csrf_token` double submit), `/signup`, `/logout` (POST), `/forgot`, `/reset?token`,
  `/verify?token`, `/delete-account`.
- **Payments:** `/billing/callback?reference=`, `POST /paystack/webhook`.

### Member pages (`/app`, login required)
- **Pages:** `/app`, `/app/signals`, `/app/signals/{id}`, `/app/results?p=daily|weekly|monthly&tf=`,
  `/app/chart.json?symbol&tf&signal`, `/app/stock/{symbol}`, `/app/watchlist`, `/app/alerts`,
  `/app/settings`, `/app/billing`, `/app/brokers`, `/app/affiliate`.
- **Form posts:**
  - `/app/watchlist/add|remove`, `/app/alerts/read`
  - `/app/settings/profile|trading|password|verify|delete`
  - `/app/billing/checkout`
  - `/app/affiliate/apply|payout-details|payout`
- **Chart data:** cached in `storage/cache` for 5 min (1h) or 15 min (1d). Returns 160 candles, centred
  on the signal when `signal` is given, plus EMA50/200, markers and levels.

### Admin (`/admin`, role=admin, every POST CSRF-checked and audit-logged)
- **Overview:** warnings, KPIs, a 30-day sign-up sparkline, recent payments and users.
- **Users:** `/admin/users` (search `q`, `filter=premium|admin|suspended|affiliates`) and
  `/admin/users/{id}` with these POST actions:
  - `premium` (days / until / revoke)
  - `status` (active / suspended)
  - `role` (an admin can't demote themselves)
  - `verify`, `delete`, `notify`
- **Payments:** `/admin/payments` with `{id}/refund` and `{id}/verify` (re-check a pending Paystack payment).
- **Plans:** `/admin/plans` and `/admin/plans/{id}` (prices, Google product id, badge, active).
- **Affiliates and commissions:**
  - `/admin/affiliates` and `/admin/affiliates/{id}` (status, rate override, note)
  - `/admin/commissions` with `adjust`, `{id}/reject` and `release` (approve due now)
- **Payouts:** `/admin/payouts` and `/admin/payouts/{id}` (paid + txn_ref, or rejected).
- **Brokers:** `/admin/brokers` (`?edit=id`), `brokers/save`, `brokers/conversion` (sub_id + amount).
- **Signals:** `/admin/signals` with `signals/scan` (run Tracker now) and `signals/mail` (process the queue).
- **Other:** `/admin/settings`, `/admin/log`.

---

## 6. Settings (Admin → Settings; defaults in Settings.php)

| Key | Default | Meaning |
|---|---|---|
| `premium_enabled` | 1 | When 0, nobody is locked (everything free) |
| `checkout_currency` | NGN | Paystack currency, NGN or USD |
| `usd_ngn_rate` | 1600 | Converts NGN payments to USD for commissions |
| `google_fee_pct` | 15 | Play fee removed from the commission base |
| `affiliate_enabled` | 1 | Affiliate program open |
| `affiliate_auto_approve` | 0 | Approve applications automatically |
| `commission_rate` | 30 | % of each payment |
| `commission_recurring` | 1 | Commission on renewals too (0 = first payment only) |
| `commission_hold_days` | 14 | Days before a commission becomes withdrawable |
| `cookie_days` | 30 | Referral cookie lifetime |
| `min_payout_usd` | 10 | Minimum payout request |
| `payout_methods` | bank,usdt,paypal | Allowed payout methods |
| `broker_share_pct` | 50 | Affiliate's share of broker revenue |
| `site_announcement` | (empty) | Top bar on the public site |
| `support_email` | (empty) | Support contact |

---

## 7. Config (`server/app/config.php`; copy from config.sample.php, never commit it)

- **Site:** `base_url` `https://stocksignal.bulksend.top`, `app_url` `…/app`, `play_url` (Play listing),
  `app_name`, `company`.
- **Database:** `db.*` (cPanel MySQL `USER_stocksignal`).
- **Secrets:** `jwt_secret` (≥32 random characters). It also keys the IP hashes, broker tokens and
  obfuscatedId, so **never change it after launch**.
- **Mail:** SMTP via `mail.bulksend.top:465 ssl`, user `signals@bulksend.top`.
- **Push:** `fcm.service_account_file` → `app/firebase-service-account.json`.
- **Google sign-in:**
  - `google.client_ids[]`: the Web client ID used as serverClientId in the app (+ Android IDs)
  - `google.web_client_id`: the Google button on the website. Empty hides it. Its JS origin must include the domain.
- **Paystack:** `paystack.secret_key` / `public_key`. Webhook: `https://stocksignal.bulksend.top/paystack/webhook`.
- **Google Play:** `play.package_name` `com.swiftmynd.signal_lab` and `play.service_account_file` →
  `app/play-service-account.json`. That service account needs Play Console *View financial data* and
  *Manage orders and subscriptions*.
- **Other:**
  - `universe.default` (AAPL, MSFT, NVDA, AMZN, GOOGL, META, TSLA, AMD, SPY, QQQ), `max_symbols` 80,
    `timeframes` 1d/1h
  - `developer.chat_url` / `coffee_url` / `hire_url`
  - `market.provider` yahoo
  - `cron_key`

---

## 8. Flutter v2 changes (UNCOMPILED — verify first)

- **Dependencies:** `pubspec.yaml` adds `in_app_purchase: ^3.2.0`.
- **`config.dart`:**
  - `siteUrl` (`SITE_URL`, default `https://stocksignal.bulksend.top`)
  - `apiBaseUrl` default `'$siteUrl/api'`
  - `premiumProductIds = {'premium_monthly', 'premium_yearly'}`
  - `publicRecordUrl`, `affiliateUrl`, `termsUrl` and `privacyUrl` now point to the site
- **Models:**
  - `TrackedSignal.locked`; `openPct` and `openR` return null when locked (avoids a divide by zero on
    null levels, since the levels parse as 0)
  - `AppUser` gains `isAdmin`, `referralCode` and `premium`
  - New `PremiumStatus`, `PremiumPlan` and `Broker`
- **`services/billing_service.dart`:**
  - listens to `purchaseStream`
  - `buy()` uses `buyNonConsumable` with `applicationUserName = billing_account_id`
  - verifies each purchase via `POST /billing/google/verify`, then `completePurchase`
  - also has `restore()`
- **`state/app_state.dart`:**
  - new fields: `billing`, `premium`, `plans`, `premiumFeatures`, `purchaseBusy`
  - `takePurchaseError()`, `loadPaywall()`, `buyPremium()`, `restorePurchases()`, `brokers()`,
    `affiliateSummary()`, static `brokerDisclosure`
  - `_applyMe()` reads `premium` and `billing_account_id`
- **Screens:**
  - `premium_screen.dart`: `openPremium()`, `PremiumLockCard` (compact variant) and `PremiumScreen`
    (plan tiles with Play prices, Continue with Google Play, Restore, Terms, Manage subscriptions link,
    renewal disclosure)
  - `brokers_screen.dart`: broker cards with the disclosure, and `AffiliateCard` (shows the code and
    opens the web affiliate dashboard)
  - `auth_screen.dart`: optional "Referral code" field on sign-up, also passed to Google sign-in
  - `tracked_widgets.dart`, `tracked_signal_screen.dart` and `signals_screen.dart`: show the lock
    instead of levels; the position-size button is hidden when locked
  - `settings_screen.dart`: new Premium, Brokers and Affiliate entries
- **Play policy:** the app must **not** link to web checkout or mention web prices. It doesn't. The
  affiliate dashboard link is fine (it's not a purchase).
- **Likely compile risks:**
  - `switch` statements in billing_service (Dart 3 syntax)
  - `withValues(alpha:)` needs Flutter ≥3.27 (already used in the app)
  - `takePurchaseError()` mutating state inside build (harmless)
  - unused imports
  Run `flutter analyze` and fix.
- **Android:** the in_app_purchase plugin adds the BILLING permission. Subscriptions can only be created
  in Play Console after an AAB containing billing has been uploaded to a test track.

---

## 9. Testing (how it was verified)

**Local stack:**
```bash
apt-get install -y mariadb-server php8.3-cli php8.3-mysql php8.3-curl php8.3-mbstring   # if missing
mysqld_safe --user=root --datadir=/var/lib/mysql &
mysql -uroot -e "CREATE DATABASE signallab_test; CREATE USER 'sl'@'127.0.0.1' IDENTIFIED BY 'slpass'; GRANT ALL ON signallab_test.* TO 'sl'@'127.0.0.1';"
mkdir -p /tmp/signallab-mails
MAILDIR=/tmp/signallab-mails python3 server/app/tests/tools/smtp_catcher.py &
cd server && MAILDIR=/tmp/signallab-mails php app/tests/run.php     # expect "138 passed, 0 failed"
```

**Test sections:**
1. engine parity with the app's expected.json
2. auth
3. settings/watchlist/devices
4. tracker over 560 simulated bars
5. performance/reports
6. deletion
7. store pages
8. Premium locking (API, push, email)
9. website: CSRF, sign-up, member pages, chart JSON, watchlist validation, settings, affiliate apply,
   admin approve, `/r` cookie + attribution, Paystack checkout/callback/webhook (bad signature 401,
   no double credit), 30% commission, 14-day hold, payout minimum, single open request, admin mark
   paid, refund clawback
10. Google Play verify (mocked androidpublisher + oauth) incl. wrong account / token reuse
11. broker clicks + conversions
12. every admin page plus admin actions

**Test helpers:** `call()` (API), `web()` (website, with a cookie jar `$JAR`; query strings in the path
are parsed), `csrf()`, `webLogin()`, `flashOf()`.

**Browser check:**
- Run `php -S 127.0.0.1:8099` with a router that serves `public/` and sets `SCRIPT_NAME=/index.php`,
  using the env `SIGNALLAB_CONFIG=server/app/tests/config.test.php`.
- Drive it with Playwright (Chromium is preinstalled in the sandbox).
- Test accounts after a run (in the test DB only):

  | Account | Password | Role |
  |---|---|---|
  | ada@example.com | newpass456 | member (made Premium by hand for screenshots) |
  | amina@example.com | amina1234 | affiliate |
  | boss@example.com | boss12345 | admin |

**Visual fixes already made:**
- compact dashboard tables
- `.grid>.card+.card` margin bug (pricing cards were misaligned)
- logo icon squish
- nowrap dates
- zero-filled 30-day sign-up sparkline
- chart window centred on old signals

**Not run:** the Flutter tests, and anything against real Paystack, Google Play, FCM or SMTP.

---

## 10. GitHub status

- The local repo is `/home/claude/signal_lab`: branch `main`, one commit `3042843`
  ("Stock Trading Signals v2: …"). It may not exist in your environment; use the zip instead.
- `.gitignore` excludes config.php, `*service-account*.json`, cache/gtoken files and keystores.
- The push failed because `gh` is authenticated as **adeluxs** but this session is "bound to configured
  repositories" and no repo was enabled. None of these were reachable:
  - `100-mobile-apps/stock-trading-signals`
  - `adeluxs/stock-trading-signals`
- **Next session:**
  1. Create (or get access to) a repo; the suggestion is `100-mobile-apps/stock-trading-signals`,
     private, which matches the sibling apps.
  2. Push `main`.
  3. Consider a GitHub Actions workflow like the Faraid/Zakat repos: `flutter create` → setup →
     analyze → test → build AAB to an `out-aab` branch → emulator screenshots to `out-screenshots`.
     That is the practical way to get the Flutter code compiled.

---

## 11. Deployment (summary; full steps in server/DEPLOY_CPANEL.md)

1. **Upload:** cPanel subdomain `stocksignal.bulksend.top` with doc root `~/stocksignal/public`, then
   AutoSSL. Upload `server/` to `~/stocksignal/` and chmod 755 `app/storage`.
2. **Database:** create MySQL DB + user; `cp app/config.sample.php app/config.php` and fill it in.
3. **Install:** `php app/cron/install.php --test-email you@…` (creates/migrates tables, idempotent).
4. **Admin:** sign up on the site, then `php app/cron/install.php --make-admin you@…`.
5. **Cron jobs:**
   | Schedule | Script |
   |---|---|
   | `5,35 * * * *` | scan.php |
   | `*/5 * * * *` | send_mail.php |
   | `30 21 * * 1-5` | reports.php daily |
   | `0 9 * * 6` | reports.php weekly |
   | `0 9 1 * *` | reports.php monthly |
   | `15 3 * * *` | maintenance.php |

   No CLI cron? Use `/api/cron/{job}?key=` from cron-job.org.
6. **Firebase:** add the service-account JSON; enable Google sign-in; add the SHA-1s; add the JS origin
   for the website button.
7. **Paystack:** keys + webhook.
8. **Play Console:** subscriptions `premium_monthly` / `premium_yearly` with auto-renew base plans;
   API access service account; license testers.
9. **Brokers:** Admin → Brokers: add real broker affiliate URLs with `{sub_id}`. **Don't use broker
   logos/names in a way that implies partnership unless the user has an actual affiliate agreement.**
10. **Build the app:**
    `flutter build appbundle --release --dart-define=SITE_URL=https://stocksignal.bulksend.top --dart-define=GOOGLE_SERVER_CLIENT_ID=…`

Note: the project memory says some sibling apps (DayFX, Crypto) moved from cPanel/bulksend.top to the
owner's **Vultr** server. For this app the user said cPanel `stocksignal.bulksend.top`. **Ask before
assuming Vultr.** The code runs on either (needs PHP 8.1+, MySQL/MariaDB, cron, writable `app/storage`;
on nginx route all non-files to `public/index.php`).

---

## 12. Store listing state (store/)

- **Title:** "Stock Trading Signals".
- **Short description** (changed in v2 because levels are now Premium): "Free stock trading signals, buy
  alerts & a real tracked win/loss record".
- **Full description:** levels marked "(Premium)"; mentions optional Premium via Google Play and the
  website.
- **STORE_LISTING.md:**
  - privacy/deletion URLs on `stocksignal.bulksend.top`
  - in-app purchases declared
  - data safety adds *Purchase history*
  - broker affiliate disclosure noted
- **Assets:** 8 screenshots (`store/screenshots/1home…8history.png`), feature graphic,
  `playstore_icon_512.png`.
- **Watch the financial features declaration:** the app shows information only (no brokerage). Broker
  links are affiliate links; keep the disclosure visible.

---

## 13. Known gaps / honest caveats

1. **Flutter not compiled.** Highest priority.
2. **Payments never run live.** Paystack and Play were only mocked. Test with Paystack test keys and Play
   license testers before launch.
3. **Data source:** Yahoo's chart endpoint is unofficial and free. It can rate-limit or change. The chart
   cache helps on the web; the scanner fetches per symbol per run.
4. **Performance:** `Performance::report()` loads all closed signals into PHP. Fine for thousands of rows;
   add caching if the record grows very large. The homepage and dashboard call it on each request.
5. **Track record is empty at launch.** The record only fills as the live scanner runs (no backfill, by
   design for honesty). Test data in screenshots is synthetic.
6. **Web sessions** are only cleaned by the maintenance cron.
7. **No rate limit on `/r` and `/go` clicks** (counts could be inflated). Clicks don't pay anything,
   only real payments/conversions do.
8. **Broker conversions are manual** (admin enters them from the broker's report). There are no
   automatic postbacks.
9. **USD Paystack** only works if the merchant account has USD enabled; the default is NGN.
10. **Legal pages:** terms/privacy text was written by Claude. The user should review it (not legal advice).
11. **The Play "spam/repetitive apps" risk:** the owner has many similar signal apps (gold, FX, crypto,
    indices, DayFX, V75…). Keep each distinct (see `claude/app-launch-checklist.md` in the project).

---

## 14. Owner preferences to keep (from this and sibling projects)

- Flutter, Android first; PHP/MySQL backend; the owner knows cPanel well and has no devops budget or staff.
- Always include the developer promo:
  - rate (official in-app review API)
  - WhatsApp chat and hire-me (+2347011401387)
  - Buy Me a Coffee (buymeacoffee.com/swalafblogM)
  - "Swiftmynd Technologies Ltd"
  - Never shown during core tasks; capped; can be turned off.
- Sign-up + Google sign-in, no guest mode; in-app deletion plus a web deletion page.
- Honest marketing: no "100% profitable"/"guaranteed"; keyword-driven but not stuffed; "Free" goes in the
  short description, not the title.
- Communicate plainly and concisely; the owner prefers things working end to end over UI-only demos.
