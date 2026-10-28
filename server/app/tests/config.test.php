<?php
// Configuration used by tests/run.php (local MariaDB + local SMTP catcher + fake market data).
return [
    'app_name' => 'Stock Trading Signals',
    'company' => 'Swiftmynd Technologies Ltd',
    'base_url' => 'https://api.test.local',
    'app_url' => 'https://test.local',
    'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => getenv('SL_DB') ?: 'signallab_test', 'user' => 'sl', 'pass' => 'slpass'],
    'jwt_secret' => str_repeat('k', 48),
    'access_ttl' => 3600,
    'refresh_ttl' => 86400 * 30,
    'mail' => ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 2525, 'secure' => 'none', 'user' => 'u', 'pass' => 'p',
        'from' => 'signals@test.local', 'from_name' => 'Stock Trading Signals'],
    'fcm' => ['service_account_file' => ''],
    'google' => ['client_ids' => ['test-client.apps.googleusercontent.com']],
    'universe' => ['default' => ['AAPL', 'NVDA', 'TSLA', 'MSFT'], 'max_symbols' => 80, 'timeframes' => ['1d']],
    'developer' => ['chat_url' => 'https://wa.me/2347011401387', 'coffee_url' => 'https://buymeacoffee.com/swalafblogM',
        'hire_url' => 'https://wa.me/2347011401387?text=Hi%2C%20I%20saw%20Stock%20Trading%20Signals%20and%20I%27d%20like%20you%20to%20build%20an%20app%2Fwebsite%20for%20me.'],
    'market' => ['provider' => 'fake'],
    'cron_key' => 'test-cron-key',
];
