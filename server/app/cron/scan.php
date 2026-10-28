<?php
// Scans the market, records new signals, closes finished trades and sends alerts.
// cPanel → Cron Jobs:   5,35 * * * *   php /home/USER/signallab/app/cron/scan.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

use SignalLab\Tracker;

$lock = @fopen(__DIR__ . '/../storage/scan.lock', 'c');
if ($lock === false) {
    fwrite(STDERR, "Cannot open app/storage/scan.lock. Check that app/storage exists and is writable.\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another scan is running.\n";
    exit(0);
}
@set_time_limit(900);
$t = microtime(true);
$r = Tracker::run();
printf("Scanned %d series · %d new signals · %d closed · %d errors · %.1fs\n",
    $r['series'], $r['new'], $r['closed'], count($r['errors']), microtime(true) - $t);
foreach ($r['errors'] as $e) echo "  ! $e\n";
// Send the emails this scan queued straight away.
[$sent, $failed] = SignalLab\Mailer::processQueue(200);
echo "Emails sent: $sent, failed: $failed\n";
