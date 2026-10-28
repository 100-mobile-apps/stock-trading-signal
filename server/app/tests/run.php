<?php
// Integration tests:  SIGNALLAB_CONFIG=app/tests/config.test.php php app/tests/run.php
// Needs: MariaDB/MySQL test DB, SMTP catcher on 127.0.0.1:2525 writing to $MAILDIR, fake data in storage/fake.
declare(strict_types=1);
putenv('SIGNALLAB_CONFIG=' . (getenv('SIGNALLAB_CONFIG') ?: __DIR__ . '/config.test.php'));
require __DIR__ . '/../bootstrap.php';

use SignalLab\{Api, Auth, Clock, Db, Engine, Mailer, Market, Performance, Push, Reports, Request, Tracker, ApiError};

$MAILDIR = getenv('MAILDIR') ?: '/tmp/signallab-mails';
$EXPECTED = json_decode((string) file_get_contents(getenv('EXPECTED') ?: __DIR__ . '/fixtures/expected.json'), true);
$pass = 0;
$fail = 0;
function check(bool $cond, string $name, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ $name\n"; } else { $fail++; echo "  ✗ $name" . ($extra ? " — $extra" : '') . "\n"; }
}
function call(string $method, string $path, array $body = [], ?string $token = null, array $query = []): array
{
    $h = ['content-type' => 'application/json'];
    if ($token) $h['authorization'] = "Bearer $token";
    $res = Api::router()->dispatch(new Request($method, $path, $query, $body, $h, '10.0.0.' . random_int(1, 250)));
    $j = json_decode($res->body, true);
    return ['status' => $res->status, 'json' => $j, 'raw' => $res->body];
}
function mails(): array
{
    global $MAILDIR;
    $files = glob("$MAILDIR/*.eml");
    sort($files);
    return array_map(function ($f) {
        $raw = (string) file_get_contents($f);
        preg_match('/^To: <([^>]+)>/m', $raw, $to);
        preg_match('/^Subject: (.+)$/m', $raw, $sub);
        $subject = $sub[1] ?? '';
        if (preg_match('/=\?UTF-8\?B\?(.+)\?=/', $subject, $m)) $subject = base64_decode($m[1]);
        // decode base64 parts
        $body = '';
        foreach (preg_split('/--b_[0-9a-f]+/', $raw) as $part) {
            if (preg_match("/Content-Transfer-Encoding: base64\s*\n\s*\n(.+)/s", $part, $pm)) $body .= base64_decode(preg_replace('/\s+/', '', $pm[1])) . "\n";
        }
        return ['to' => $to[1] ?? '', 'subject' => $subject, 'body' => $body];
    }, $files);
}
function clearMails(): void { global $MAILDIR; array_map('unlink', glob("$MAILDIR/*.eml")); }

// -------------------------------------------------------------- reset DB
Db::pdo()->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (Db::all('SHOW TABLES') as $t) Db::pdo()->exec('DROP TABLE `' . array_values($t)[0] . '`');
Db::pdo()->exec('SET FOREIGN_KEY_CHECKS=1');
Db::pdo()->exec((string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
clearMails();
$pushed = [];
Push::$transport = function (array $msg) use (&$pushed) { $pushed[] = $msg['message']; return 'ok'; };

// -------------------------------------------------------------- 1. engine parity with the app
echo "\n1. Engine parity (PHP server vs app engine)\n";
foreach ($EXPECTED as $sym => $exp) {
    $C = json_decode((string) file_get_contents(__DIR__ . "/../storage/fake/{$sym}_1d.json"), true);
    $sigs = Engine::signals($C);
    $same = count($sigs) === count($exp['signals']);
    foreach ($sigs as $k => $s) {
        $e = $exp['signals'][$k] ?? null;
        if (!$e || $e['i'] !== $s['i'] || $e['buy'] !== $s['buy'] || abs($e['price'] - $s['price']) > 1e-6
            || abs($e['sl'] - $s['sl']) > 1e-6 || $e['strength'] !== $s['strength']) { $same = false; break; }
    }
    check($same, "$sym: identical signals (" . count($sigs) . ')');
}

// -------------------------------------------------------------- 2. auth
echo "\n2. Accounts & authentication\n";
Clock::freeze(null);
$r = call('POST', '/auth/register', ['email' => 'Ada@Example.com', 'password' => 'secret123', 'name' => 'Ada']);
check($r['status'] === 201 && isset($r['json']['data']['access_token'], $r['json']['data']['refresh_token']), 'register returns tokens');
check(($r['json']['data']['user']['email'] ?? '') === 'ada@example.com', 'email normalised to lowercase');
check(($r['json']['data']['user']['email_verified'] ?? true) === false, 'new account starts unverified');
$access = $r['json']['data']['access_token'];
$refresh = $r['json']['data']['refresh_token'];
check(call('POST', '/auth/register', ['email' => 'ada@example.com', 'password' => 'secret123'])['status'] === 409, 'duplicate email rejected (409)');
check(call('POST', '/auth/register', ['email' => 'bob@example.com', 'password' => 'short'])['status'] === 422, 'weak password rejected (422)');
check(call('POST', '/auth/register', ['email' => 'not-an-email', 'password' => 'secret123'])['status'] === 422, 'invalid email rejected');
check(call('GET', '/me')['status'] === 401, '/me without token → 401');
check(call('GET', '/me', [], 'abc.def.ghi')['status'] === 401, 'forged token → 401');
$me = call('GET', '/me', [], $access);
check($me['status'] === 200 && count($me['json']['data']['watchlist']) === 4, '/me returns user, settings and default watchlist');
check(call('POST', '/auth/login', ['email' => 'ada@example.com', 'password' => 'wrongpass1'])['status'] === 401, 'wrong password → 401');
$login = call('POST', '/auth/login', ['email' => 'ADA@example.com', 'password' => 'secret123']);
check($login['status'] === 200, 'login works (case-insensitive email)');
$ref = call('POST', '/auth/refresh', ['refresh_token' => $refresh]);
check($ref['status'] === 200 && $ref['json']['data']['refresh_token'] !== $refresh, 'refresh rotates tokens');
check(call('POST', '/auth/refresh', ['refresh_token' => $refresh])['status'] === 401, 'old refresh token cannot be reused');
$access = $ref['json']['data']['access_token'];

// Email verification
Mailer::processQueue();
$m = mails();
check(count($m) === 1 && $m[0]['to'] === 'ada@example.com' && str_contains($m[0]['subject'], 'Confirm'), 'verification email delivered via SMTP');
preg_match('#/auth/verify\?token=([0-9a-f]+)#', $m[0]['body'] ?? '', $tm);
$v = call('GET', '/auth/verify', [], null, ['token' => $tm[1] ?? '']);
check($v['status'] === 200 && str_contains($v['raw'], 'Email confirmed'), 'verification link confirms email');
check(call('GET', '/me', [], $access)['json']['data']['user']['email_verified'] === true, 'account now verified');
check(call('GET', '/auth/verify', [], null, ['token' => $tm[1] ?? ''])['status'] === 400, 'verification link is single-use');
clearMails();

// Password reset
call('POST', '/auth/forgot', ['email' => 'ada@example.com']);
check(call('POST', '/auth/forgot', ['email' => 'nobody@example.com'])['status'] === 200, 'forgot password does not reveal unknown emails');
Mailer::processQueue();
$m = mails();
preg_match('#/auth/reset\?token=([0-9a-f]+)#', $m[0]['body'] ?? '', $rm);
check(count($m) === 1 && isset($rm[1]), 'reset email delivered');
check(str_contains(call('GET', '/auth/reset', [], null, ['token' => $rm[1] ?? ''])['raw'], 'new password'), 'reset page renders form');
$rs = call('POST', '/auth/reset', ['token' => $rm[1] ?? '', 'password' => 'newpass456']);
check($rs['status'] === 200, 'password reset succeeds');
check(call('POST', '/auth/login', ['email' => 'ada@example.com', 'password' => 'secret123'])['status'] === 401, 'old password no longer works');
$login = call('POST', '/auth/login', ['email' => 'ada@example.com', 'password' => 'newpass456']);
check($login['status'] === 200, 'new password works');
$access = $login['json']['data']['access_token'];
clearMails();

// Throttling
$codes = [];
for ($i = 0; $i < 11; $i++) {
    $res = Api::router()->dispatch(new Request('POST', '/auth/login', [], ['email' => 'eve@example.com', 'password' => 'x1234567'], [], '9.9.9.9'));
    $codes[] = $res->status;
}
check(end($codes) === 429, 'brute force is throttled (429 after 10 failures)');

// Google sign-in (token verification stubbed; real calls use Google's tokeninfo endpoint)
$exp = Clock::ts() + 600;
$good = fn() => ['aud' => 'test-client.apps.googleusercontent.com', 'iss' => 'https://accounts.google.com', 'exp' => $exp,
    'sub' => 'g-123', 'email' => 'grace@gmail.com', 'email_verified' => 'true', 'name' => 'Grace'];
$g = Auth::google('x', $good);
check(isset($g['access_token']) && $g['user']['email_verified'] && $g['user']['google_linked'], 'Google sign-in creates a verified account');
$g2 = Auth::google('x', $good);
check($g2['user']['id'] === $g['user']['id'], 'second Google sign-in reuses the same account');
try {
    Auth::google('x', fn() => ['aud' => 'someone-else', 'iss' => 'accounts.google.com', 'exp' => $exp, 'sub' => 's', 'email' => 'x@y.z', 'email_verified' => true]);
    check(false, 'Google token for another app is rejected');
} catch (ApiError $e) {
    check($e->status === 401, 'Google token for another app is rejected');
}
$gAccess = $g['access_token'];

// -------------------------------------------------------------- 3. settings, watchlist, devices
echo "\n3. Settings, watchlist, devices\n";
$s = call('PUT', '/me/settings', ['risk_pct' => 2, 'report_daily' => true, 'style' => '1d', 'account_size' => 5000], $access);
check($s['json']['data']['risk_pct'] == 2.0 && $s['json']['data']['report_daily'] === true, 'settings update');
$w = call('PUT', '/watchlist', ['symbols' => ['aapl', 'NVDA', 'nvda', 'TSLA', 'bad symbol!']], $access);
check($w['json']['data'] === ['AAPL', 'NVDA', 'TSLA'], 'watchlist normalised, deduped and validated');
check(call('POST', '/devices', ['token' => str_repeat('a', 40), 'platform' => 'android'], $access)['status'] === 200, 'device registered for push');
call('PUT', '/me/settings', ['email_signals' => false, 'email_results' => false, 'report_weekly' => false, 'report_monthly' => false], $gAccess);
call('PUT', '/watchlist', ['symbols' => ['MSFT']], $gAccess);
check(call('POST', '/devices', ['token' => str_repeat('b', 40)], $gAccess)['status'] === 200, 'second user device registered');

// -------------------------------------------------------------- 4. live tracker over simulated time
echo "\n4. Live signal tracking over simulated time (bar by bar)\n";
$data = json_decode((string) file_get_contents(__DIR__ . '/../storage/fake/AAPL_1d.json'), true);
$startIdx = 700;
$runs = 0;
for ($i = $startIdx; $i < count($data); $i++) {
    // "now" = just after bar i closed
    Clock::freeze((new DateTimeImmutable('@' . ($data[$i]['t'] + 86400 + 60)))->setTimezone(new DateTimeZone('UTC')));
    Tracker::run(['AAPL', 'NVDA', 'TSLA', 'MSFT'], ['1d']);
    $runs++;
}
echo "  · ran the scanner $runs times\n";
$firstScanT = gmdate('Y-m-d H:i:s', $data[$startIdx]['t']);
foreach ($EXPECTED as $sym => $exp) {
    $closed = Db::all("SELECT * FROM signals WHERE symbol = ? AND kind='trade' AND status IN ('won','lost') ORDER BY signal_time", [$sym]);
    // Compare against app backtest trades that started after the first backtest trade the server could also see.
    $serverFirst = $closed ? strtotime($closed[0]['signal_time'] . ' UTC') : PHP_INT_MAX;
    $expTrades = array_values(array_filter($exp['trades'], fn($t) => $t['sig'] >= $serverFirst));
    // Drop trades still open at the end on either side.
    $n = min(count($closed), count($expTrades));
    $match = $n > 0 && abs(count($closed) - count($expTrades)) <= 1;
    for ($k = 0; $k < $n && $match; $k++) {
        $c = $closed[$k];
        $e = $expTrades[$k];
        $match = strtotime($c['signal_time'] . ' UTC') === $e['sig'] && abs((float) $c['entry_price'] - $e['entry']) < 1e-4
            && abs((float) $c['exit_price'] - $e['exit']) < 1e-4 && abs((float) $c['r_multiple'] - $e['R']) < 1e-3;
        if (!$match) echo "    mismatch at #$k: server {$c['signal_time']} {$c['exit_price']} vs app " . gmdate('Y-m-d', $e['sig']) . " {$e['exit']}\n";
    }
    check($match, "$sym: live record matches the app's backtest trade-for-trade ($n trades)");
}
$warnings = (int) Db::value("SELECT COUNT(*) FROM signals WHERE kind='warning'");
check($warnings > 0, "SELL signals recorded as bearish warnings ($warnings)");
check((int) Db::value("SELECT COUNT(*) FROM signals WHERE signal_time < ?", [$firstScanT]) === 0, 'no signals back-filled from before tracking started');
check((int) Db::value("SELECT COUNT(*) FROM (SELECT symbol FROM signals WHERE status IN ('pending','open') AND kind='trade' GROUP BY symbol, tf HAVING COUNT(*) > 1) x") === 0,
    'never more than one open trade per stock');

// Notifications
$adaId = (int) Db::value("SELECT id FROM users WHERE email = 'ada@example.com'");
$graceId = (int) Db::value("SELECT id FROM users WHERE email = 'grace@gmail.com'");
$adaKinds = array_column(Db::all('SELECT kind, COUNT(*) n FROM notifications WHERE user_id = ? GROUP BY kind', [$adaId]), 'n', 'kind');
echo '  · Ada inbox: ' . json_encode($adaKinds) . "\n";
check(($adaKinds['new_buy'] ?? 0) > 0 && (($adaKinds['target'] ?? 0) + ($adaKinds['stop'] ?? 0)) > 0, 'user notified of new signals and results');
check((int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND symbol NOT IN ('AAPL','NVDA','TSLA')", [$adaId]) === 0,
    'only watchlist stocks are notified');
check((int) Db::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND symbol <> 'MSFT'", [$graceId]) === 0
    && (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$graceId]) > 0, 'second user only gets MSFT alerts');
check(count($pushed) === (int) Db::value('SELECT COUNT(*) FROM notifications WHERE pushed = 1'), 'push sent for every alert (' . count($pushed) . ')');
check(str_contains(json_encode($pushed), '"channel_id":"signals"') && isset($pushed[0]['data']['signal_id']), 'push payload has channel + deep-link data');
Mailer::processQueue(1000);
$m = mails();
$toAda = array_filter($m, fn($x) => $x['to'] === 'ada@example.com');
$toGrace = array_filter($m, fn($x) => $x['to'] === 'grace@gmail.com');
check(count($toAda) === (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND emailed = 1', [$adaId]) && count($toAda) > 0,
    'emails delivered for signals and results (' . count($toAda) . ')');
check(count($toGrace) === 0, 'user who turned emails off gets none');
$sample = array_values(array_filter($toAda, fn($x) => str_contains($x['subject'], 'BUY signal')))[0] ?? null;
check($sample && str_contains($sample['body'], 'Stop-loss') && str_contains($sample['body'], 'Why this signal'), 'signal email includes levels and reasons');
$res = array_values(array_filter($toAda, fn($x) => str_contains($x['subject'], 'hit')))[0] ?? null;
check($res !== null && str_contains($res['body'], 'Result'), 'result email includes the outcome');
clearMails();

// Rescanning the same bar must not duplicate anything.
$before = (int) Db::value('SELECT COUNT(*) FROM notifications');
Tracker::run(['AAPL', 'NVDA', 'TSLA', 'MSFT'], ['1d']);
check((int) Db::value('SELECT COUNT(*) FROM notifications') === $before, 're-running the scan is idempotent (no duplicate alerts)');

// -------------------------------------------------------------- 5. performance API
echo "\n5. Track record & reports\n";
$p = call('GET', '/performance', [], $access, ['tf' => '1d', 'scope' => 'all'])['json']['data'];
$closedAll = Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('won','lost')");
$sumR = round(array_sum(array_map(fn($r) => (float) $r['r_multiple'], $closedAll)), 2);
check($p['summary']['all']['trades'] === count($closedAll), 'all-time trade count matches database (' . count($closedAll) . ')');
check(abs($p['summary']['all']['total_r'] - $sumR) < 0.02, "all-time total R matches ($sumR R)");
$wins = count(array_filter($closedAll, fn($r) => $r['status'] === 'won'));
check(abs($p['summary']['all']['win_rate'] - round($wins / count($closedAll) * 100, 1)) < 0.01, 'win rate correct (' . $p['summary']['all']['win_rate'] . '%)');
$monthSum = array_sum(array_column($p['monthly'], 'trades'));
$firstMonth = end($p['monthly'])['from'];
$inWindow = count(array_filter($closedAll, fn($r) => substr($r['exit_time'], 0, 10) >= $firstMonth));
check($monthSum === $inWindow, 'monthly buckets add up');
check(count($p['weekly']) > 0 && count($p['daily']) > 0 && isset($p['weekly'][0]['label']), 'daily and weekly buckets present');
check(count($p['equity']) === count($closedAll), 'equity curve has a point per closed trade');
$sig = call('GET', '/signals', [], $access, ['status' => 'closed', 'tf' => '1d', 'limit' => 5])['json']['data'];
check(count($sig) === 5 && in_array($sig[0]['symbol'], ['AAPL', 'NVDA', 'TSLA'], true) && $sig[0]['r_multiple'] !== null, 'closed signals list (watchlist scope)');
$live = call('GET', '/signals', [], $access, ['status' => 'live', 'scope' => 'all'])['json']['data'];
check(is_array($live), 'live signals list (' . count($live) . ' open/pending/recent warnings)');
$inbox = call('GET', '/notifications', [], $access)['json']['data'];
check($inbox['unread'] > 0 && count($inbox['items']) > 0, 'in-app inbox with unread count');
call('POST', '/notifications/read', [], $access);
check(call('GET', '/notifications', [], $access)['json']['data']['unread'] === 0, 'mark all read');
$rec = call('GET', '/record');
check($rec['status'] === 200 && str_contains($rec['raw'], 'Live track record') && str_contains($rec['raw'], 'By month'), 'public track-record page renders');

// Reports
foreach (['daily', 'weekly', 'monthly'] as $period) {
    $n = Reports::send($period);
    $again = Reports::send($period);
    check($again === 0, "$period report is sent once per period");
    if ($period === 'daily') check($n === 1, 'daily report only for users who opted in (1)');
    if ($period === 'weekly') check($n === 1, 'weekly report to verified opted-in users (1)');
}
Mailer::processQueue();
$m = mails();
$subjects = array_column($m, 'subject');
check(count(array_filter($subjects, fn($s) => str_contains($s, 'weekly Stock Trading Signals report'))) === 1, 'weekly report email delivered');
check(count(array_filter($subjects, fn($s) => str_contains($s, 'monthly Stock Trading Signals report'))) === 1, 'monthly report email delivered');
$mr = array_values(array_filter($m, fn($x) => str_contains($x['subject'], 'monthly')))[0] ?? null;
check($mr && str_contains($mr['body'], 'Closed trades') && str_contains($mr['body'], 'All-time record'), 'report email has stats and trade list');

// -------------------------------------------------------------- 6. account deletion
echo "\n6. Account deletion\n";
check(call('DELETE', '/me', [], $gAccess)['status'] === 200, 'delete account');
check((int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$graceId]) === 0
    && (int) Db::value('SELECT COUNT(*) FROM devices WHERE user_id = ?', [$graceId]) === 0, 'all personal data removed');

// -------------------------------------------------------------- 7. store-required pages
echo "\n7. Play Store required pages\n";
$pp = call('GET', '/privacy');
check($pp['status'] === 200 && str_contains($pp['raw'], 'Swiftmynd Technologies Ltd') && str_contains($pp['raw'], 'Privacy policy') && str_contains($pp['raw'], 'delete-account'), 'public privacy policy page');
check(str_contains(call('GET', '/delete-account')['raw'], 'Delete my account permanently'), 'account deletion web page');
check(str_contains(call('GET', '/record')['raw'], 'wa.me/2347011401387') && str_contains(call('GET', '/record')['raw'], 'buymeacoffee.com/swalafblogM'),
    'public pages link to developer chat + coffee');
check(str_contains(call('GET', '/record')['raw'], 'Hire Swiftmynd Technologies Ltd</a>'), 'public pages have the hire-me WhatsApp link');
call('POST', '/auth/register', ['email' => 'temp@example.com', 'password' => 'temp12345']);
check(call('POST', '/delete-account', ['email' => 'temp@example.com', 'password' => 'wrong1234'])['status'] === 400, 'web deletion needs the right password');
$del = call('POST', '/delete-account', ['email' => 'temp@example.com', 'password' => 'temp12345']);
check($del['status'] === 200 && !Db::value("SELECT id FROM users WHERE email = 'temp@example.com'"), 'web deletion erases the account');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
