<?php
// Integration tests:  SIGNALLAB_CONFIG=app/tests/config.test.php php app/tests/run.php
// Needs: MariaDB/MySQL test DB, SMTP catcher on 127.0.0.1:2525 writing to $MAILDIR, fake data in storage/fake.
declare(strict_types=1);
putenv('SIGNALLAB_CONFIG=' . (getenv('SIGNALLAB_CONFIG') ?: __DIR__ . '/config.test.php'));
require __DIR__ . '/../bootstrap.php';

use SignalLab\{Api, Auth, Clock, Db, Engine, Mailer, Market, Performance, Push, Reports, Request, Tracker, ApiError,
    Affiliates, Billing, Brokers, HttpClient, Notifier, Premium, Settings, Web, Response};

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
SignalLab\Migrator::run();
// Sections 1–7 cover the original free app; Premium locking is tested in section 8.
SignalLab\Settings::set('premium_enabled', '0');
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
preg_match('#/verify\?token=([0-9a-f]+)#', $m[0]['body'] ?? '', $tm);
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
preg_match('#/reset\?token=([0-9a-f]+)#', $m[0]['body'] ?? '', $rm);
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

// ============================================================== v2: website, Premium, payments, affiliates, admin
$JAR = [];
/** Browser-like call to the website with a cookie jar. */
function web(string $method, string $path, array $body = [], array $query = [], array $jarOverride = null): array
{
    global $JAR;
    $jar = $jarOverride ?? $JAR;
    if (str_contains($path, '?')) { [$path, $qs] = explode('?', $path, 2); parse_str($qs, $extra); $query += $extra; }
    $cookie = implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
    $_GET = $query;
    $res = Web::handle(new Request($method, $path, $query, $body, $cookie ? ['cookie' => $cookie] : [], '10.1.0.' . random_int(1, 250)));
    foreach ((array) ($res->headers['Set-Cookie'] ?? []) as $c) {
        [$kv] = explode(';', $c);
        [$k, $v] = explode('=', $kv, 2);
        if ($jarOverride === null) $JAR[$k] = $v;
    }
    return ['status' => $res->status, 'raw' => $res->body, 'loc' => $res->headers['Location'] ?? null, 'headers' => $res->headers];
}
function csrf(): string
{
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', web('GET', '/login')['raw'] . web('GET', '/app/settings')['raw'], $m);
    return $m[1] ?? '';
}
function webLogin(string $email, string $pw): void
{
    global $JAR;
    $JAR = [];
    $t = csrf();
    web('POST', '/login', ['_csrf' => $t, 'email' => $email, 'password' => $pw, 'next' => '/app']);
}
function flashOf(string $path): string
{
    $h = web('GET', $path)['raw'];
    preg_match_all('#<div class="alert (ok|err|info|warn)">(.*?)</div>#s', $h, $m);
    return implode(' | ', array_map(fn($t, $x) => "$t: " . html_entity_decode(strip_tags($x), ENT_QUOTES), $m[1], $m[2]));
}
Clock::freeze(null);
Settings::flush();

// -------------------------------------------------------------- 8. Premium locking
echo "\n8. Premium: live levels locked for free users, results free\n";
Settings::set('premium_enabled', '1');
$reg = call('POST', '/auth/register', ['email' => 'free@example.com', 'password' => 'free12345', 'name' => 'Fred']);
$ft = $reg['json']['data']['access_token'];
$freeId = (int) Db::value("SELECT id FROM users WHERE email = 'free@example.com'");
$liveRow = Db::one("SELECT * FROM signals WHERE kind = 'trade' AND status IN ('pending','open') ORDER BY id DESC LIMIT 1");
if (!$liveRow) { // make sure a live trade exists
    $c = Db::one("SELECT * FROM signals WHERE kind = 'trade' ORDER BY id DESC LIMIT 1");
    Db::exec("UPDATE signals SET status = 'open', exit_time = NULL, exit_price = NULL, exit_reason = NULL, r_multiple = NULL, return_pct = NULL WHERE id = ?", [$c['id']]);
    $liveRow = Db::one('SELECT * FROM signals WHERE id = ?', [$c['id']]);
}
$sg = call('GET', '/signals/' . $liveRow['id'], [], $ft)['json']['data'];
check($sg['locked'] === true && $sg['stop_loss'] === null && $sg['target2'] === null && $sg['signal_price'] === null, 'free user: live signal levels hidden by the API');
$closedRow = Db::one("SELECT * FROM signals WHERE kind = 'trade' AND status = 'won' LIMIT 1");
$cg = call('GET', '/signals/' . $closedRow['id'], [], $ft)['json']['data'];
check($cg['locked'] === false && $cg['stop_loss'] !== null && $cg['exit_price'] !== null, 'free user: closed results fully visible');
$me = call('GET', '/me', [], $ft)['json']['data'];
check($me['user']['premium']['enabled'] === true && $me['user']['premium']['active'] === false && strlen($me['user']['referral_code']) === 7 && strlen($me['billing_account_id']) === 40,
    '/me reports Premium status, referral code and billing account id');
$plans = call('GET', '/plans')['json']['data'];
check(count($plans['plans']) === 2 && $plans['plans'][0]['google_product_id'] === 'premium_monthly', 'GET /plans lists monthly + yearly');
Db::exec("UPDATE user_settings SET all_signals = 1, style = ?, email_signals = 1, push_signals = 1 WHERE user_id = ?", [$liveRow['tf'], $freeId]);
Db::exec('UPDATE users SET email_verified_at = ? WHERE id = ?', [Db::now(), $freeId]);
Db::exec('INSERT INTO devices (user_id, fcm_token, platform, updated_at) VALUES (?,?,?,?)', [$freeId, str_repeat('f', 40), 'android', Db::now()]);
clearMails(); $pushed = [];
Db::exec('DELETE FROM notifications WHERE signal_id = ? AND kind = ?', [$liveRow['id'], 'new_buy']);
Notifier::signalEvent($liveRow, 'new_buy');
Mailer::processQueue();
$fp = array_values(array_filter($pushed, fn($m) => ($m['token'] ?? '') === str_repeat('f', 40)));
$fm = array_values(array_filter(mails(), fn($m) => $m['to'] === 'free@example.com' && str_starts_with($m['subject'], 'BUY')));
check($fp && str_contains($fp[0]['notification']['body'], 'Premium') && !str_contains($fp[0]['notification']['body'], 'Stop'), 'free user push: signal announced, levels withheld');
check($fm && str_contains($fm[0]['body'], 'See Premium plans') && !str_contains($fm[0]['body'], 'Target 2'), 'free user email: upsell instead of levels');
Premium::extend($freeId, 1);
check(call('GET', '/signals/' . $liveRow['id'], [], $ft)['json']['data']['stop_loss'] !== null, 'after Premium: levels unlocked');
Premium::setUntil($freeId, null);

// -------------------------------------------------------------- 9. website auth, referrals, Paystack, commissions, payouts
echo "\n9. Website, referrals, Paystack checkout, commissions & payouts\n";
$JAR = [];
check(web('GET', '/app')['loc'] === '/login?next=%2Fapp', 'dashboard requires login (redirect)');
$home = web('GET', '/');
check($home['status'] === 200 && str_contains($home['raw'], 'Free <em>stock trading signals</em>') && str_contains($home['raw'], 'FAQPage') && str_contains($home['raw'], 'wa.me/2347011401387'),
    'SEO homepage with FAQ schema and developer links');
foreach (['/pricing', '/record', '/brokers', '/affiliate', '/terms', '/privacy', '/signup', '/forgot', '/sitemap.xml', '/robots.txt'] as $pg) {
    if (web('GET', $pg)['status'] !== 200) check(false, "public page $pg");
}
check(true, 'all public pages render');
$t = csrf();
check(web('POST', '/signup', ['email' => 'x@example.com', 'password' => 'abc12345', 'agree' => '1'])['status'] === 419, 'form without CSRF token is rejected (419)');
web('POST', '/signup', ['_csrf' => $t, 'name' => 'Amina Affiliate', 'email' => 'amina@example.com', 'password' => 'amina1234', 'agree' => '1', 'next' => '/app']);
$aminaId = (int) Db::value("SELECT id FROM users WHERE email = 'amina@example.com'");
check($aminaId > 0 && Db::value('SELECT signup_source FROM users WHERE id = ?', [$aminaId]) === 'web', 'web sign-up creates account (source=web)');
$dash = web('GET', '/app');
check($dash['status'] === 200 && str_contains($dash['raw'], 'Live signals') && str_contains($dash['raw'], 'Unlock Premium'), 'member dashboard renders with Premium upsell');
foreach (['/app/signals', '/app/signals?status=closed', '/app/results', '/app/results?p=weekly', '/app/watchlist', '/app/alerts', '/app/settings', '/app/billing', '/app/brokers',
             '/app/affiliate', '/app/stock/AAPL', '/app/signals/' . $closedRow['id']] as $pg) {
    $rp = web('GET', $pg);
    if ($rp['status'] !== 200) check(false, "member page $pg", (string) $rp['status']);
}
check(true, 'all member pages render');
$ch = web('GET', '/app/chart.json', [], ['symbol' => 'AAPL', 'tf' => '1d', 'signal' => (string) $closedRow['id']]);
$chj = json_decode($ch['raw'], true)['data'] ?? [];
check(count($chj['candles'] ?? []) === 160 && count($chj['ema50']) === 160 && ($closedRow['symbol'] !== 'AAPL' || count($chj['levels']) >= 4), 'chart JSON: candles, EMAs, markers, levels');
$lv = web('GET', '/app/signals/' . $liveRow['id']);
check(str_contains($lv['raw'], '🔒 Premium') && !str_contains($lv['raw'], 'Position size'), 'web: live signal locked for free member');
$t = csrf();
web('POST', '/app/watchlist/add', ['_csrf' => $t, 'symbol' => 'NVDA']);
web('POST', '/app/watchlist/add', ['_csrf' => $t, 'symbol' => 'ZZZZ']);
check(str_contains(flashOf('/app/watchlist'), "couldn't find price data for ZZZZ"), 'watchlist rejects unknown tickers');
web('POST', '/app/settings/trading', ['_csrf' => $t, 'account_size' => '5000', 'risk_pct' => '2', 'style' => '1d', 'email_signals' => '0', 'report_weekly' => '1']);
$sr = Db::one('SELECT * FROM user_settings WHERE user_id = ?', [$aminaId]);
check((float) $sr['account_size'] === 5000.0 && (float) $sr['risk_pct'] === 2.0 && (int) $sr['email_signals'] === 0, 'settings form saves (shared with app)');
// apply as affiliate; admin approves
web('POST', '/app/affiliate/apply', ['_csrf' => $t, 'promo_plan' => 'Telegram channel with 3k traders']);
check(Affiliates::get($aminaId)['status'] === 'pending', 'affiliate application is pending review');
$code = Affiliates::codeFor($aminaId);
$aminaJar = $JAR;

// admin
call('POST', '/auth/register', ['email' => 'boss@example.com', 'password' => 'boss12345', 'name' => 'Boss']);
$bossId = (int) Db::value("SELECT id FROM users WHERE email = 'boss@example.com'");
webLogin('boss@example.com', 'boss12345');
check(web('GET', '/admin')['status'] === 403, 'non-admin cannot open /admin (403)');
Db::exec("UPDATE users SET role = 'admin' WHERE id = ?", [$bossId]);
$ov = web('GET', '/admin');
check($ov['status'] === 200 && str_contains($ov['raw'], '1 affiliate application(s) to review'), 'admin overview shows pending work');
$t = csrf();
web('POST', "/admin/affiliates/$aminaId", ['_csrf' => $t, 'status' => 'approved', 'rate' => '', 'note' => '']);
check(Affiliates::get($aminaId)['status'] === 'approved' && Db::value("SELECT COUNT(*) FROM admin_log WHERE action = 'affiliate.update'") == 1, 'admin approves affiliate (audit logged)');
$adminJar = $JAR;

// referral click + sign-up
$JAR = [];
$rc = web('GET', "/r/$code", [], ['to' => '/pricing']);
check($rc['loc'] === '/pricing' && isset($JAR['sts_ref']) && $JAR['sts_ref'] === $code && (int) Db::value('SELECT COUNT(*) FROM referral_clicks WHERE affiliate_user_id = ?', [$aminaId]) === 1,
    'referral link sets cookie, logs click and redirects');
$t = csrf();
web('POST', '/signup', ['_csrf' => $t, 'name' => 'Bola', 'email' => 'bola@example.com', 'password' => 'bola12345', 'agree' => '1', 'next' => '/pricing']);
$bolaId = (int) Db::value("SELECT id FROM users WHERE email = 'bola@example.com'");
check((int) Db::value('SELECT referred_by FROM users WHERE id = ?', [$bolaId]) === $aminaId, 'sign-up after referral click is attributed to the affiliate');

// Paystack checkout
$paystack = ['verify_status' => 'success'];
HttpClient::$mock = function (string $m, string $url, ?string $body, array $h) use (&$paystack) {
    if (str_contains($url, 'paystack.co/transaction/initialize')) {
        $b = json_decode($body, true);
        $paystack['init'] = $b;
        return ['status' => 200, 'body' => json_encode(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => $b['reference']]])];
    }
    if (str_contains($url, 'paystack.co/transaction/verify/')) {
        return ['status' => 200, 'body' => json_encode(['status' => true, 'data' => ['status' => $paystack['verify_status'], 'amount' => $paystack['init']['amount'],
            'currency' => $paystack['init']['currency'], 'channel' => 'card', 'paid_at' => '2026-01-01T00:00:00Z']])];
    }
    if (str_contains($url, 'oauth2.googleapis.com/token')) return ['status' => 200, 'body' => json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])];
    if (str_contains($url, 'androidpublisher')) return ($GLOBALS['playMock'])($url);
    return null;
};
$t = csrf();
$co = web('POST', '/app/billing/checkout', ['_csrf' => $t, 'plan' => 'monthly']);
$ref = $paystack['init']['reference'] ?? '';
check($co['loc'] === 'https://checkout.paystack.com/abc' && $paystack['init']['amount'] === 240000 && $paystack['init']['currency'] === 'NGN'
    && str_contains($paystack['init']['callback_url'], '/billing/callback'), 'checkout redirects to Paystack (₦2,400 in kobo)');
$cb = web('GET', '/billing/callback', [], ['reference' => $ref]);
$pay = Db::one('SELECT * FROM payments WHERE provider_ref = ?', [$ref]);
check($cb['loc'] === '/app/billing' && $pay['status'] === 'success' && Premium::isActive($bolaId), 'callback verifies with Paystack and activates Premium');
$com = Db::one('SELECT * FROM commissions WHERE payment_id = ?', [$pay['id']]);
check($com && (float) $com['amount_usd'] === 0.45 && $com['status'] === 'pending' && (int) $com['affiliate_user_id'] === $aminaId, 'affiliate earns 30% commission ($0.45 of $1.50), held as pending');
web('GET', '/billing/callback', [], ['reference' => $ref]);
$raw = json_encode(['event' => 'charge.success', 'data' => ['reference' => $ref]]);
$GLOBALS['__raw_body'] = $raw;
$bad = web('POST', '/paystack/webhook', [], [], []);
check($bad['status'] === 401, 'webhook with bad signature rejected');
$GLOBALS['__raw_body'] = $raw;
$ok = Web::handle(new Request('POST', '/paystack/webhook', [], [], ['x-paystack-signature' => hash_hmac('sha512', $raw, 'sk_test_xxx')], '1.1.1.1'));
unset($GLOBALS['__raw_body']);
check($ok->status === 200 && (int) Db::value("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status = 'success'", [$bolaId]) === 1
    && (int) Db::value('SELECT COUNT(*) FROM commissions WHERE affiliate_user_id = ?', [$aminaId]) === 1, 'callback + webhook repeats never double-credit');
$until1 = Db::value('SELECT premium_until FROM users WHERE id = ?', [$bolaId]);
check(str_contains(web('GET', '/app/signals/' . $liveRow['id'])['raw'], 'Position size'), 'Premium member sees levels + position size on the web');

// commissions mature; payout
$JAR = $aminaJar;
$t = csrf();
check(str_contains(web('GET', '/app/affiliate')['raw'], '$0.45'), 'affiliate dashboard shows the pending commission');
Clock::freeze(Clock::now()->modify('+15 day'));
check(Affiliates::approveDue() === 1, 'after the 14-day hold, commission becomes withdrawable');
web('POST', '/app/affiliate/payout-details', ['_csrf' => $t, 'method' => 'bank', 'bank_name' => 'GTBank', 'account_name' => 'Amina A', 'account_number' => '0123456789']);
web('POST', '/app/affiliate/payout', ['_csrf' => $t]);
check(str_contains(flashOf('/app/affiliate'), 'minimum payout is $10.00'), 'payout below the minimum is refused');
Affiliates::adjust($aminaId, 12.00, 'Launch bonus');
web('POST', '/app/affiliate/payout', ['_csrf' => $t]);
$po = Db::one('SELECT * FROM payouts WHERE affiliate_user_id = ?', [$aminaId]);
check($po && (float) $po['amount_usd'] === 12.45 && $po['status'] === 'requested', 'payout request of the full available balance ($12.45)');
web('POST', '/app/affiliate/payout', ['_csrf' => $t]);
check((int) Db::value('SELECT COUNT(*) FROM payouts WHERE affiliate_user_id = ?', [$aminaId]) === 1, 'only one open payout request at a time');
$JAR = $adminJar;
$t = csrf();
web('POST', '/admin/payouts/' . $po['id'], ['_csrf' => $t, 'status' => 'paid', 'txn_ref' => 'GTB-778899']);
check(Db::value('SELECT status FROM payouts WHERE id = ?', [$po['id']]) === 'paid'
    && (int) Db::value("SELECT COUNT(*) FROM commissions WHERE affiliate_user_id = ? AND status = 'paid'", [$aminaId]) === 2
    && Db::value("SELECT title FROM notifications WHERE user_id = ? AND kind = 'system' ORDER BY id DESC LIMIT 1", [$aminaId]) === 'Payout sent', 'admin marks payout paid; affiliate notified');
// refund after commission was paid → clawback
web('POST', '/admin/payments/' . $pay['id'] . '/refund', ['_csrf' => $t, 'note' => 'Customer request']);
$sum = Affiliates::summary($aminaId);
check(Db::value('SELECT status FROM payments WHERE id = ?', [$pay['id']]) === 'refunded' && $sum['available'] === -0.45
    && Db::value('SELECT premium_until FROM users WHERE id = ?', [$bolaId]) < $until1, 'refund removes Premium time and claws back the paid commission');
Clock::freeze(null);

// -------------------------------------------------------------- 10. Google Play subscriptions (app)
echo "\n10. Google Play in-app subscription verification\n";
$cr = call('POST', '/auth/register', ['email' => 'chidi@example.com', 'password' => 'chidi1234', 'referral_code' => strtolower($code)]);
$ct = $cr['json']['data']['access_token'];
$chidiId = (int) Db::value("SELECT id FROM users WHERE email = 'chidi@example.com'");
check((int) Db::value('SELECT referred_by FROM users WHERE id = ?', [$chidiId]) === $aminaId, 'app sign-up with a referral code is attributed');
$exp = gmdate('Y-m-d\TH:i:s\Z', Clock::ts() + 30 * 86400);
$GLOBALS['playMock'] = fn($url) => ['status' => 200, 'body' => json_encode([
    'subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'latestOrderId' => 'GPA.1111-2222-3333-44444',
    'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => Billing::obfuscatedId($chidiId)],
    'lineItems' => [['productId' => 'premium_monthly', 'expiryTime' => $exp, 'autoRenewingPlan' => ['autoRenewEnabled' => true]]]])];
$gv = call('POST', '/billing/google/verify', ['product_id' => 'premium_monthly', 'purchase_token' => 'tok-chidi-123456'], $ct);
check($gv['status'] === 200 && $gv['json']['data']['active'] === true && $gv['json']['data']['source'] === 'google' && $gv['json']['data']['auto_renewing'] === true,
    'Play purchase verified server-side → Premium active (auto-renewing)');
call('POST', '/billing/google/verify', ['product_id' => 'premium_monthly', 'purchase_token' => 'tok-chidi-123456'], $ct);
$gc = Db::one("SELECT c.* FROM commissions c JOIN payments p ON p.id = c.payment_id WHERE p.provider = 'google' AND p.user_id = ?", [$chidiId]);
check((int) Db::value("SELECT COUNT(*) FROM payments WHERE provider = 'google' AND user_id = ?", [$chidiId]) === 1 && $gc && (float) $gc['amount_usd'] === 0.38,
    'one payment per order id; commission on price minus Google fee ($0.38)');
$ot = call('POST', '/auth/register', ['email' => 'other@example.com', 'password' => 'other1234'])['json']['data']['access_token'];
check(call('POST', '/billing/google/verify', ['product_id' => 'premium_monthly', 'purchase_token' => 'tok-chidi-123456'], $ot)['status'] === 409, 'a purchase token cannot be reused by another account');
$GLOBALS['playMock'] = fn($url) => ['status' => 200, 'body' => json_encode(['subscriptionState' => 'SUBSCRIPTION_STATE_ACTIVE', 'latestOrderId' => 'GPA.9',
    'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'someone-else'], 'lineItems' => [['expiryTime' => $exp]]])];
check(call('POST', '/billing/google/verify', ['product_id' => 'premium_monthly', 'purchase_token' => 'tok-other-999999'], $ot)['status'] === 409, 'purchase bought under another account id is refused');

// -------------------------------------------------------------- 11. brokers
echo "\n11. Broker partner links & conversions\n";
$JAR = $adminJar;
$t = csrf();
web('POST', '/admin/brokers/save', ['_csrf' => $t, 'name' => 'Acme Brokerage', 'slug' => 'acme', 'url_template' => 'https://acme.example/open?aff=77&sub={sub_id}',
    'tagline' => 'US stocks from $1', 'description' => 'Commission-free US stocks.', 'min_deposit' => '$10', 'regions' => 'Nigeria, Ghana', 'active' => '1', 'sort' => '1']);
check((bool) Db::value("SELECT id FROM brokers WHERE slug = 'acme'"), 'admin adds a broker');
$JAR = [];
check(str_contains(web('GET', '/brokers')['raw'], 'Acme Brokerage') && str_contains(web('GET', '/brokers')['raw'], 'Disclosure'), 'public broker page with disclosure');
$go = web('GET', '/go/acme', [], ['ref' => $code]);
preg_match('/sub=(st[a-f0-9]+)/', (string) $go['loc'], $sm);
$click = Db::one('SELECT * FROM broker_clicks WHERE sub_id = ?', [$sm[1] ?? '']);
check($go['status'] === 302 && $click && (int) $click['affiliate_user_id'] === $aminaId, 'broker link redirects with a tracked sub-ID credited to the affiliate');
$bl = call('GET', '/brokers', [], $ct)['json']['data'];
check(count($bl['items']) === 1 && str_contains($bl['items'][0]['link'], '/go/acme?u=') && $bl['disclosure'] !== '', 'API lists brokers with signed app link + disclosure');
parse_str((string) parse_url($bl['items'][0]['link'], PHP_URL_QUERY), $lq);
web('GET', '/go/acme', [], ['u' => $lq['u'], 'src' => 'app']);
check((int) Db::value("SELECT affiliate_user_id FROM broker_clicks WHERE source = 'app' ORDER BY id DESC LIMIT 1") === $aminaId, 'app user\'s broker click credits their referrer');
$JAR = $adminJar;
$t = csrf();
web('POST', '/admin/brokers/conversion', ['_csrf' => $t, 'sub_id' => $sm[1], 'amount' => '40', 'note' => 'CPA']);
check((float) Db::value("SELECT amount_usd FROM commissions WHERE source = 'broker' AND affiliate_user_id = ?", [$aminaId]) === 20.0, 'recorded broker conversion pays the affiliate 50% ($20)');
web('POST', '/admin/brokers/conversion', ['_csrf' => $t, 'sub_id' => $sm[1], 'amount' => '40']);
check(str_contains(flashOf('/admin/brokers'), 'already recorded'), 'duplicate conversion refused');

// -------------------------------------------------------------- 12. admin panel
echo "\n12. Admin panel\n";
foreach (['/admin', '/admin/users', '/admin/users?filter=premium', "/admin/users/$aminaId", '/admin/payments', '/admin/plans', '/admin/affiliates', '/admin/commissions',
             '/admin/payouts', '/admin/brokers', '/admin/brokers?edit=1', '/admin/signals', '/admin/settings', '/admin/log'] as $pg) {
    $rp = web('GET', $pg);
    if ($rp['status'] !== 200) check(false, "admin page $pg", (string) $rp['status'] . ' ' . substr(strip_tags($rp['raw']), 0, 200));
}
check(true, 'all admin pages render');
check(str_contains(web('GET', '/admin/users', [], ['q' => 'bola'])['raw'], 'bola@example.com'), 'user search');
web('POST', "/admin/users/$freeId/premium", ['_csrf' => $t, 'days' => '30']);
check(Premium::isActive($freeId), 'admin grants Premium days');
web('POST', '/admin/plans/1', ['_csrf' => $t, 'name' => 'Premium Monthly', 'price_ngn' => '3000', 'price_usd' => '2', 'interval_months' => '1', 'google_product_id' => 'premium_monthly', 'badge' => '', 'active' => '1']);
check((float) Db::value('SELECT price_ngn FROM plans WHERE id = 1') === 3000.0 && str_contains(web('GET', '/pricing')['raw'], '₦3,000'), 'admin edits plan price → shown on pricing page');
web('POST', '/admin/settings', ['_csrf' => $t, 'commission_rate' => '40', 'site_announcement' => 'Black Friday: 2 months for 1']);
Settings::flush();
check(Settings::num('commission_rate') === 40.0 && str_contains(web('GET', '/')['raw'], 'Black Friday'), 'admin settings saved (commission rate, announcement bar)');
web('POST', '/admin/settings', ['_csrf' => $t, 'commission_rate' => 'abc']);
check(str_contains(flashOf('/admin/settings'), 'must be a number'), 'settings validation');
web('POST', "/admin/users/$bolaId/status", ['_csrf' => $t, 'status' => 'suspended']);
check(call('POST', '/auth/login', ['email' => 'bola@example.com', 'password' => 'bola12345'])['status'] === 403, 'suspended user cannot sign in');
web('POST', "/admin/users/$bolaId/status", ['_csrf' => $t, 'status' => 'active']);
web('POST', "/admin/users/$bossId/role", ['_csrf' => $t, 'role' => 'user']);
check(Db::value('SELECT role FROM users WHERE id = ?', [$bossId]) === 'admin', 'admin cannot demote themselves');
check((int) Db::value('SELECT COUNT(*) FROM admin_log') >= 8, 'admin actions are audit-logged');
web('POST', '/logout', ['_csrf' => $t]);
check(web('GET', '/admin')['loc'] === '/login?next=%2Fadmin', 'logout ends the session');
HttpClient::$mock = null;

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
