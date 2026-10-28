# Stock Trading Signals: stock signals with a verified track record

By **Swiftmynd Technologies Ltd** · Flutter app (Android and iOS) + PHP/MySQL backend for cPanel.

Stock Trading Signals scans US stocks every hour. It issues BUY signals with entry, stop-loss and two targets,
plus bearish warnings. **The server records every signal the moment it is issued and closes it
automatically** at the target, the stop or after 20 bars. That record powers the app's results
(daily, weekly and monthly), push and email alerts, email reports and a public track-record page.
Nothing is edited and losses are included.

> No signal service can be 100% profitable. Stock Trading Signals earns trust with transparency instead.
> Educational use only, not financial advice.

## What's in the box
```
lib/                 Flutter app
server/              Backend for cPanel (PHP 8.1+, MySQL). See server/DEPLOY_CPANEL.md
store/               Google Play listing: title, descriptions, screenshots, feature graphic, ASO plan
tool/setup.dart      One-time Android setup (permissions, desugaring, link queries)
assets/icon/         App icon
test/                Flutter tests (engine, sizing, API models from real server responses, widgets)
```

## App flow
Splash → 3 intro slides → **Create account / Sign in** (email + password or **Google**, forgot
password) → setup (**risk profile** → **alerts & reports** → risk acknowledgement) → app:

- **Home:** greeting, email-verification banner, market pulse, live-signal summary, latest signals,
  results today / this week / this month + all-time record, risk plan, **WhatsApp chat button**.
- **Signals:** *Live* (new, running, bearish) and *History* (every closed signal, won or lost) → tap
  for the **signal timeline** (issued → entered → Target 1 → closed) with levels, reasons and position size.
- **Watchlist:** synced to your account, with search, sparklines and status badges.
- **Results:** verified record with Today / Week / Month / All time, **daily / weekly / monthly**
  period tables, equity curve, how trades ended, results by stock, public-record link, and the backtest
  (clearly labelled "simulated").
- **Stock page:** candlestick chart with crosshair, current signal, technical snapshot, key stats,
  **live signals on this stock**, backtest and position-size calculator.
- **Alerts inbox:** everything the server sent you, with push and email delivery icons.
- **Settings:** account (verify email, change password, sign out, **delete account**), risk profile,
  push, email and report toggles, test notification, **Support the developer** (rate, Buy Me a Coffee,
  WhatsApp chat, hire Swiftmynd Technologies), methodology, disclaimer, privacy.
- **Support pop-up:** shows after the 3rd app open (at most every 5 days) with rate / coffee / chat / hire.

## Notifications
| Event | Push | Email |
|---|---|---|
| New BUY signal / bearish warning | ✅ | ✅ (with levels & reasons) |
| Target 1 reached, target hit, stop-loss hit, time exit | ✅ | ✅ (with result in % and R) |
| Daily / weekly / monthly performance report | inbox | ✅ |
Every toggle is per user. Emails go only to verified addresses.

## Run the app
Flutter **3.32+**.
```bash
cd signal_lab
flutter create --org com.swiftmynd --project-name signal_lab --platforms android,ios .
dart run tool/setup.dart                 # Android permissions, desugaring, link queries
dart pub global activate flutterfire_cli && flutterfire configure   # Firebase push (see server guide §8)
flutter pub get
dart run flutter_launcher_icons
flutter test
flutter run --dart-define=API_BASE_URL=https://api.yourdomain.com \
            --dart-define=GOOGLE_SERVER_CLIENT_ID=XXXX.apps.googleusercontent.com
```
Release for Google Play: `flutter build appbundle --release --dart-define=...` (same defines).

Links (WhatsApp, Buy Me a Coffee, hire) live in `lib/config.dart`.

## Deploy the backend
Follow **server/DEPLOY_CPANEL.md**: subdomain, upload, database, mailbox, `config.php`,
`install.php`, cron jobs, Firebase key.

## Verified here
- **Backend:** 78 integration tests on real PHP 8.4 + MariaDB with a local mail server: sign-up,
  login, refresh rotation, email verification, password reset, throttling, Google sign-in checks,
  settings, watchlist, devices, the scanner run bar-by-bar over 560 simulated days (the live record
  matches the app's backtest trade for trade), push payloads, 90+ alert emails delivered, reports sent
  once per period, performance maths, public pages and account deletion.
- **Engine parity:** PHP server and app engine produce identical signals.
- **Flutter:** written against current stable APIs but **not compiled here** (no Flutter SDK in this
  environment). Run `flutter analyze` and `flutter test`, and send any errors over to be fixed.
