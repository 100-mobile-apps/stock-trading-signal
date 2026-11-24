<?php
declare(strict_types=1);
// Stock Trading Signals homepage (100apps landing kit, Forex Signals Live design). Served at "/".
require __DIR__ . '/../../app/bootstrap.php';
use SignalLab\Db;

$px = fn($v) => $v === null ? '—' : number_format((float)$v, 2, '.', '');
$ts = fn($d) => $d ? strtotime($d . ' UTC') : time();

$data = ['stats' => ['win_rate' => null, 'pips' => 0, 'closed' => 0, 'live' => 0, 'unit' => 'R', 'series' => []], 'live' => [], 'results' => [], 'best' => []];
try {
    $closed = Db::all("SELECT status, r_multiple, exit_time FROM signals WHERE kind='trade' AND status IN ('won','lost') ORDER BY exit_time");
    $w = count(array_filter($closed, fn($r) => $r['status'] === 'won'));
    $data['stats']['closed'] = count($closed);
    $data['stats']['win_rate'] = $closed ? $w / count($closed) * 100 : null;
    $data['stats']['pips'] = array_sum(array_map(fn($r) => (float)$r['r_multiple'], $closed));
    $data['stats']['live'] = (int)Db::value("SELECT COUNT(*) FROM signals WHERE kind='trade' AND status IN ('pending','open')");
    $cum = 0; $byDay = [];
    foreach ($closed as $r) { $cum += (float)$r['r_multiple']; $byDay[substr((string)$r['exit_time'], 0, 10)] = $cum; }
    $from = gmdate('Y-m-d', time() - 90 * 86400);
    $data['stats']['series'] = array_values(array_filter(array_map(null, array_keys($byDay), array_values($byDay)), fn($p) => $p[0] >= $from));
    foreach (Db::all("SELECT symbol, direction, signal_time, strength, tf FROM signals WHERE kind='trade' AND status IN ('pending','open') ORDER BY id DESC LIMIT 4") as $r)
        $data['live'][] = [$r['symbol'], $r['direction'], $ts($r['signal_time']), null, strtoupper((string)$r['tf']) . ' · strength ' . (int)$r['strength'] . '/4'];
    $labels = ['target' => 'Target', 'stop' => 'Stop loss', 'time' => 'Time exit'];
    foreach (Db::all("SELECT symbol, direction, entry_price, exit_price, exit_reason, r_multiple, exit_time FROM signals WHERE kind='trade' AND status IN ('won','lost') ORDER BY exit_time DESC LIMIT 8") as $r)
        $data['results'][] = [$r['symbol'], $r['direction'], $px($r['entry_price']), $px($r['exit_price']), $labels[$r['exit_reason']] ?? ucfirst((string)$r['exit_reason']), (float)$r['r_multiple'], $ts($r['exit_time'])];
} catch (Throwable $e) {
    error_log('[signalab home] ' . $e->getMessage());
}

try {
    foreach (Db::all("SELECT symbol, COUNT(*) n, SUM(status='won') w, SUM(r_multiple) r FROM signals WHERE kind='trade' AND status IN ('won','lost') AND exit_time >= UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY symbol HAVING n >= 3 AND r > 0 ORDER BY r DESC LIMIT 4") as $r)
        $data['best'][] = [$r['symbol'], (int)$r['n'], $r['w'] / $r['n'] * 100, (float)$r['r']];
} catch (Throwable $e) { error_log('[signalab home best] ' . $e->getMessage()); }

$site = [
    'brand' => 'Stock Trading Signals', 'logo' => 'ST', 'url' => 'https://signalab.100apkgoals.com',
    'accent' => ['#5EEAD4', '#14B8A6', '#0F766E', '#03120F'],
    'meta_title' => 'Stock Trading Signals: Free US Stock Buy Alerts with a Public Track Record',
    'meta_description' => 'Free US stock trading signals with entry, stop loss and two targets, sent by push and email. Every signal is tracked live and closed by fixed rules, losses included.',
    'nav' => [['/record', 'Track record'], ['/brokers', 'Brokers'], ['/affiliate', 'Affiliates'], ['#faq', 'FAQ']],
    'dashboard_url' => '/app', 'dashboard_label' => 'Open dashboard', 'signup_url' => '/signup', 'record_url' => '/record',
    'play_url' => 'https://play.google.com/store/apps/details?id=com.swiftmynd.signal_lab',
    'eyebrow' => 'US stocks · daily chart',
    'h1' => ['Free stock trading signals with a', 'track record you can check'],
    'lead' => 'Buy alerts on US stocks with entry, stop loss and two targets, sent by push and email. Every signal is recorded live and closed by fixed rules, so the results are real.',
    'ticks' => ['No card needed', 'Same account on web and app', 'Every signal free'],
    'features_title' => 'Everything you need to follow a signal',
    'features' => [
        ['plan', 'Full trade plan', 'Entry, stop loss, two targets and the reasons behind every signal, plus a strength score.'],
        ['chart', 'Chart for every signal', 'See the setup on the candlestick chart with the moving averages and the levels marked.'],
        ['check', 'Every result tracked', 'Win rate, R-multiples and equity, with daily, weekly and monthly reports. Losses included.'],
        ['shield', 'Market filter', 'Buys only while the S&P 500 is in an uptrend, so you are not buying into a falling market.'],
        ['bell', 'Alerts your way', 'Push notifications in the app and email alerts for new signals and results.'],
        ['calc', 'Position sizing', 'Size each trade from your account and risk so one loss never hurts too much.'],
    ],
    'steps' => [
        ['Create a free account', 'Sign up on the website or in the app. It is the same account everywhere.'],
        ['Build your watchlist', 'Follow the stocks you care about; the scanner also covers the most traded US names.'],
        ['Follow the plan', 'Buy at the next open, set the stop and targets, and size the trade with the calculator.'],
    ],
    'band' => ['Share free signals, earn from broker referrals', 'Get your personal link and earn a share of the commission when people you refer open a broker account through it.', 'Join the affiliate programme', '/affiliate'],
    'faq' => [
        ['Are the signals really free?', 'Yes. Every signal, every level and the full track record are free.'],
        ['How are results tracked?', 'Every signal is recorded when it fires and closed by fixed rules at the target, the stop or after a set number of bars. Nothing is edited or deleted.'],
        ['Which stocks do you cover?', 'Large, liquid US stocks and ETFs such as AAPL, MSFT, NVDA, AMZN, GOOGL, META, TSLA, AMD, SPY and QQQ, plus anything on members\' watchlists.'],
        ['Why do I see losses?', 'Because every trade is published. No strategy wins every time; the track record shows the real numbers so you can decide for yourself.'],
        ['Can I use the website and the app together?', 'Yes. Log in with the same email and password on both.'],
        ['Is this financial advice?', 'No. Signals are generated automatically by fixed rules for education. Trading involves risk of loss.'],
    ],
    'risk' => 'Trading stocks involves risk of loss. Past results do not guarantee future results. Signals are information, not financial advice.',
    'tagline' => 'Rule-based US stock signals with a public, fully tracked record.',
    'footer' => [
        'Product' => [['/record', 'Track record'], ['/brokers', 'Brokers'], ['/app', 'Dashboard']],
        'Earn' => [['/affiliate', 'Affiliate programme'], ['https://wa.me/2347011401387?text=' . rawurlencode('Hi, I want to hire you to build an app'), 'Hire the developer']],
        'Legal' => [['/terms', 'Terms'], ['/privacy', 'Privacy'], ['/delete-account', 'Delete account']],
    ],
];
require __DIR__ . '/landing.php';
