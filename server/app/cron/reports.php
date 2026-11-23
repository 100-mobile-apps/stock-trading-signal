<?php
// Performance report emails.
//   30 21 * * 1-5   php …/cron/reports.php daily     (after the US close, UTC)
//   0 9 * * 6       php …/cron/reports.php weekly    (Saturday morning)
//   0 9 1 * *       php …/cron/reports.php monthly   (1st of the month)
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$period = $argv[1] ?? '';
if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
    fwrite(STDERR, "Usage: php reports.php daily|weekly|monthly\n");
    exit(1);
}
$n = SignalLab\Reports::send($period);
[$sent, $failed] = SignalLab\Mailer::processQueue(500);
echo "$period reports queued: $n · emails sent: $sent, failed: $failed\n";
