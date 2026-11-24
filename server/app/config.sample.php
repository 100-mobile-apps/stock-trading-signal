<?php
// Copy to config.php and fill in. Never commit config.php.
return [
    'app_name' => 'Stock Trading Signals',
    'company'  => 'Swiftmynd Technologies Ltd',
    // Website root (no trailing slash). The app's API is served at {base_url}/api
    'base_url' => 'https://stocksignal.bulksend.top',
    // Where "Open app" buttons in emails point.
    'app_url'  => 'https://stocksignal.bulksend.top/app',
    // Your Play Store listing (shown on the website).
    'play_url' => 'https://play.google.com/store/apps/details?id=com.swiftmynd.signal_lab',
    'timezone' => 'UTC',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_signallab',
        'user' => 'cpaneluser_signallab',
        'pass' => 'CHANGE_ME',
    ],

    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    'jwt_secret'  => 'CHANGE_ME_TO_A_LONG_RANDOM_STRING',
    'access_ttl'  => 3600,              // 1 hour
    'refresh_ttl' => 60 * 60 * 24 * 60, // 60 days

    // Email. Use the mailbox you create in cPanel → Email Accounts.
    'mail' => [
        'driver'    => 'smtp',          // 'smtp' (recommended) or 'mail' (PHP mail())
        'host'      => 'mail.yourdomain.com',
        'port'      => 465,
        'secure'    => 'ssl',           // 'ssl' (465), 'tls' (587) or 'none'
        'user'      => 'signals@yourdomain.com',
        'pass'      => 'CHANGE_ME',
        'from'      => 'signals@yourdomain.com',
        'from_name' => 'Stock Trading Signals',
    ],

    // Firebase Cloud Messaging (push). Firebase console → Project settings →
    // Service accounts → Generate new private key. Upload the JSON next to this file.
    'fcm' => [
        'service_account_file' => __DIR__ . '/firebase-service-account.json',
    ],

    // Google Sign-In: OAuth client IDs whose ID tokens are accepted
    // (the Web client ID used as serverClientId in the app, plus Android/iOS IDs).
    'google' => [
        'client_ids' => [
            'XXXXXXXX-xxxxxxxx.apps.googleusercontent.com',
        ],
        // Web client ID for the "Continue with Google" button on the website (same Web client is fine;
        // add https://stocksignal.bulksend.top to its Authorized JavaScript origins). Empty = hide button.
        'web_client_id' => 'XXXXXXXX-xxxxxxxx.apps.googleusercontent.com',
    ],

    // Paystack (website payments). Dashboard → Settings → API Keys & Webhooks.
    // Webhook URL: https://stocksignal.bulksend.top/paystack/webhook
    'paystack' => [
        'secret_key' => '',   // sk_live_...
        'public_key' => '',   // pk_live_...
    ],

    // Google Play in-app subscriptions (Android). Create a service account with access to
    // Play Console (Users & permissions → "View financial data" + "Manage orders"), upload its JSON here.
    'play' => [
        'package_name'         => 'com.swiftmynd.signal_lab',
        'service_account_file' => __DIR__ . '/play-service-account.json',
    ],

    // Stocks the scanner always follows, plus every symbol on any user's watchlist.
    'universe' => [
        'default'     => ['AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'AMD', 'SPY', 'QQQ'],
        'max_symbols' => 80,
        'timeframes'  => ['1d', '1h'],
    ],

    // Developer links shown in email footers and public pages.
    'developer' => [
        'chat_url'   => 'https://wa.me/2347011401387',
        'coffee_url' => 'https://buymeacoffee.com/swalafblogM',
        'hire_url'   => 'https://wa.me/2347011401387?text=Hi%2C%20I%20saw%20Stock%20Trading%20Signals%20and%20I%27d%20like%20you%20to%20build%20an%20app%2Fwebsite%20for%20me.',
    ],

    // 'yahoo' in production. 'fake' is only for automated tests.
    'market' => ['provider' => 'yahoo'],

    // Optional: lets you trigger cron scripts over HTTP (…/api/cron/scan?key=…) if your
    // host has no CLI cron. Leave empty to disable.
    'cron_key' => '',
];
