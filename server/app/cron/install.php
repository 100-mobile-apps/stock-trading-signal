<?php
// One-time setup check:  php app/cron/install.php
// Creates tables (safe to re-run) and checks config, email and push settings.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use SignalLab\Config;
use SignalLab\Db;

$ok = fn($m) => print("  ✓ $m\n");
$bad = fn($m) => print("  ✗ $m\n");

echo "Stock Trading Signals setup check\n";
try {
    Db::pdo();
    $ok('Database connection');
    Db::pdo()->exec((string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
    $ok('Tables created / up to date');
} catch (Throwable $e) {
    $bad('Database: ' . $e->getMessage());
    exit(1);
}
$secret = (string) Config::get('jwt_secret');
strlen($secret) >= 32 && !str_contains($secret, 'CHANGE_ME') ? $ok('jwt_secret set') : $bad('Set a long random jwt_secret in config.php');
str_starts_with((string) Config::get('base_url'), 'https://') ? $ok('base_url uses https') : $bad('base_url should start with https://');
is_writable(__DIR__ . '/../storage') ? $ok('storage/ is writable') : $bad('Make app/storage writable (chmod 755)');
is_file((string) Config::get('fcm.service_account_file')) ? $ok('Firebase service account found (push enabled)')
    : $bad('Firebase service account JSON missing: push notifications disabled until you add it');
Config::get('google.client_ids.0') && !str_contains((string) Config::get('google.client_ids.0'), 'XXXX')
    ? $ok('Google client ID configured') : $bad('Add your Google OAuth client ID(s) for Google sign-in');

if (($argv[1] ?? '') === '--test-email' && !empty($argv[2])) {
    SignalLab\Mailer::queue($argv[2], 'Stock Trading Signals test email', SignalLab\Templates::email('It works', 'Email delivery from your server is working.', 'Email delivery works.'));
    [$s, $f] = SignalLab\Mailer::processQueue(5);
    $s ? $ok("Test email sent to {$argv[2]}") : $bad('Test email failed: ' . Db::value('SELECT last_error FROM email_queue ORDER BY id DESC LIMIT 1'));
} else {
    echo "  · Tip: php app/cron/install.php --test-email you@example.com\n";
}
