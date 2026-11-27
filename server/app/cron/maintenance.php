<?php
// Daily housekeeping (cPanel cron, e.g. 15 3 * * *):  php /home/USER/stocksignal/app/cron/maintenance.php
// Releases affiliate commissions past the refund window and re-checks Google Play subscriptions.
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';
echo json_encode(SignalLab\Api::maintenance()), "\n";
