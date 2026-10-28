<?php
// Sends queued emails (verification, password reset, alerts, reports).
// cPanel → Cron Jobs:   */5 * * * *   php /home/USER/signallab/app/cron/send_mail.php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

[$sent, $failed] = SignalLab\Mailer::processQueue(100);
echo "Emails sent: $sent, failed: $failed\n";
