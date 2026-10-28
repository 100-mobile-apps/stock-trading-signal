<?php
// Copy to config.php and fill in. Never commit config.php.
return [
    'app_name' => 'Stock Trading Signals',
    'company'  => 'Swiftmynd Technologies Ltd',
    // Public URL of the /public folder (no trailing slash), e.g. https://api.yourdomain.com
    'base_url' => 'https://api.yourdomain.com',
    // Where "Open app" buttons in emails point (website or store link).
    'app_url'  => 'https://yourdomain.com',
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
            '222013884908-n9pn4sgjipl20s3ilk5ttcl4fmkri7mm.apps.googleusercontent.com',
        ],
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

    // Optional: lets you trigger cron scripts over HTTP (…/cron/scan?key=…) if your
    // host has no CLI cron. Leave empty to disable.
    'cron_key' => '',
];
