<?php
declare(strict_types=1);

namespace SignalLab;

/** Public website pages (server-rendered for SEO). */
final class WebPages
{
    private static function e(mixed $s): string { return View::e($s); }

    public static function priceLabel(array $p): string
    {
        return Settings::get('checkout_currency') === 'USD'
            ? '$' . number_format((float) $p['price_usd'], 2) : '₦' . number_format((float) $p['price_ngn'], 0);
    }

    private static function stats(): array
    {
        $rep = Performance::report(null, '1d', 1.0);
        return [$rep, $rep['summary']['all']];
    }

    private static function recentClosed(int $n): array
    {
        return Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('won','lost') ORDER BY exit_time DESC, id DESC LIMIT $n");
    }

    public static function closedRows(array $rows): array
    {
        return array_map(fn($s) => [
            '<span class="sym">' . self::e($s['symbol']) . '</span> <span class="xs dim">' . ($s['tf'] === '1d' ? 'Daily' : 'Hourly') . '</span>',
            self::e(Fmt::price((float) $s['entry_price'])) . ' → ' . self::e(Fmt::price((float) $s['exit_price'])),
            View::statusPill($s),
            '<span class="nowrap">' . View::date($s['exit_time']) . '</span>',
            View::rCell((float) $s['r_multiple']),
        ], $rows);
    }

    // ------------------------------------------------------------------ home

    public static function home(): string
    {
        [$rep, $a] = self::stats();
        $live = (int) Db::value("SELECT COUNT(*) FROM signals WHERE kind='trade' AND status IN ('pending','open')");
        $month = $rep['summary']['month'];
        $pf = $a['profit_factor'] === null ? '∞' : ($a['trades'] ? number_format((float) $a['profit_factor'], 2) : '—');
        $since = $rep['record_started'] ? Fmt::date(str_replace(['T', 'Z'], [' ', ''], $rep['record_started'])) : 'today';
        $cls = fn($v) => $v > 0 ? 'pos' : ($v < 0 ? 'neg' : '');
        $recent = View::table(['Stock', 'Entry → exit', 'Outcome', 'Closed', '>Result'], self::closedRows(self::recentClosed(6)),
            'The first signals are being tracked now. Results appear here as soon as trades close.');
        $play = (string) Config::get('play_url', '');
        $playBtn = $play ? '<a class="btn lg ghost" href="' . self::e($play) . '" target="_blank" rel="noopener">▶ Get the Android app</a>' : '';
        $premiumOn = Settings::on('premium_enabled');
        $plans = Plans::active();
        $from = $plans ? self::priceLabel($plans[0]) : '';

        $faq = [
            ['Are the signals really free?', $premiumOn
                ? "Yes. Every signal alert and every result is free, and the full track record is public. Premium ($from/month) unlocks the exact entry, stop-loss and target prices on live signals."
                : 'Yes. Every signal, every level and the full track record are free.'],
            ['Can signals be 100% profitable?', 'No, and anyone promising that is not being honest. Our rules win some and lose some. What we guarantee is transparency: every signal is recorded the moment it fires and closed automatically, losses included, so you can judge the results yourself.'],
            ['How are signals generated?', 'A fixed rule set runs on daily (swing) and hourly (intraday) candles: trend filter (50/200 EMA), MACD momentum crossover, RSI filter and volume. Stops and targets are set from volatility (ATR): stop 1.5×ATR, Target 1 at 1.5×ATR and Target 2 at 3×ATR (2:1 reward-to-risk).'],
            ['Which stocks do you cover?', 'Large US stocks and ETFs such as AAPL, MSFT, NVDA, AMZN, TSLA, SPY and QQQ, plus any US ticker you add to your watchlist.'],
            ['How do I get alerts?', 'Push notifications in the Android app and email alerts. You also get daily, weekly and monthly result reports by email.'],
            ['Do I need a broker?', 'Yes. We don\'t hold money or place trades. You trade with your own broker. See our broker page for options (some links are affiliate links).'],
        ];
        $faqHtml = '';
        $ld = [];
        foreach ($faq as [$q, $ans]) {
            $faqHtml .= '<details><summary>' . self::e($q) . '</summary><p>' . self::e($ans) . '</p></details>';
            $ld[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $ans]];
        }
        $jsonld = '<script type="application/ld+json">' . json_encode([
            '@context' => 'https://schema.org', '@graph' => [
                ['@type' => 'WebApplication', 'name' => Config::get('app_name'), 'url' => Urls::site('/'), 'applicationCategory' => 'FinanceApplication',
                    'operatingSystem' => 'Web, Android', 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
                    'publisher' => ['@type' => 'Organization', 'name' => Config::get('company')]],
                ['@type' => 'FAQPage', 'mainEntity' => $ld],
            ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';

        $this_r = fn($v) => Fmt::r((float) $v);
        $this_e = fn($v) => self::e($v);
        $this_dev = fn() => View::devButtons();
        $wr = $a['trades'] ? $a['win_rate'] . '%' : '—';
        $tot = Fmt::r((float) $a['total_r']);
        $pricingCard = $premiumOn ? '<div class="card"><h3>💎 Premium</h3><p class="small">Exact entry, stop and targets on live signals, in the dashboard, app, push and email. From <b style="color:var(--t)">'
            . self::e($from) . '/month</b>.</p><a class="btn sm" href="/pricing">See plans</a></div>' : '';
        $earnText = View::free() ? 'Share free signals with your link and earn a share of broker referrals.' : 'Share your link and earn commission on Premium sign-ups and broker referrals.';
        $body = <<<HTML
<section class="hero"><div class="wrap">
<span class="pill p">✓ Public, fully tracked record · losses included</span>
<h1 style="margin-top:18px">Free <em>stock trading signals</em> with a track record you can check.</h1>
<p class="lead">Buy alerts on US stocks with entry, stop-loss and two targets, sent by push and email. Every signal is recorded live and closed by fixed rules, so the results are real.</p>
<div class="row" style="margin-top:26px"><a class="btn lg" href="/signup">Get free signals</a><a class="btn lg ghost" href="/record">View the track record</a>$playBtn</div>
<div class="proof"><div><b>{$a['trades']}</b>closed trades</div><div><b>$wr</b>win rate</div><div><b class="{$cls($a['total_r'])}">$tot</b>total result</div><div><b>$pf</b>profit factor</div><div><b>$live</b>open right now</div></div>
</div></section>
<section class="section" style="padding-top:20px"><div class="wrap split">
<div class="card"><div class="card-h"><h2>Latest closed signals</h2><a class="small" href="/record">Full record →</a></div>$recent
<p class="xs dim" style="margin:12px 0 0">R = result relative to the amount risked. +2R means twice the risk was won; −1R means the stop was hit. Tracked since $since (UTC).</p></div>
<div class="stack"><div class="card"><h3>This month</h3><dl class="kv"><dt>Signals issued</dt><dd>{$month['issued']}</dd><dt>Closed trades</dt><dd>{$month['trades']}</dd>
<dt>Win rate</dt><dd>{$month['win_rate']}%</dd><dt>Result</dt><dd class="{$cls($month['total_r'])}">{$this_r($month['total_r'])}</dd></dl></div>$pricingCard
<div class="card"><h3>🤝 Earn with us</h3><p class="small">{$earnText}</p><a class="btn sm ghost" href="/affiliate">Affiliate program</a></div></div>
</div></section>
<section class="section"><div class="wrap"><h2 class="big">Everything you need to act on a signal</h2><p class="sub">Built for people who want clear, rule-based trade ideas without hype.</p>
<div class="grid g3">
<div class="card feature"><div class="ic">⚡</div><h3>Clear buy signals</h3><p>Entry reference, stop-loss, Target 1 and Target 2 (2:1), signal strength and the exact reasons it fired.</p></div>
<div class="card feature"><div class="ic">🔔</div><h3>Push + email alerts</h3><p>Know the moment a signal fires, when Target 1 is hit, and when a trade closes, win or lose.</p></div>
<div class="card feature"><div class="ic">📊</div><h3>Daily, weekly, monthly results</h3><p>Win rate, total R, profit factor and drawdown for every period, plus an equity curve.</p></div>
<div class="card feature"><div class="ic">📉</div><h3>Bearish warnings</h3><p>When momentum turns down in a downtrend, you get a warning to avoid new buys and review open positions.</p></div>
<div class="card feature"><div class="ic">🧮</div><h3>Position sizing</h3><p>Set your account size and risk per trade; we work out how many shares to buy so a stop costs only what you planned.</p></div>
<div class="card feature"><div class="ic">★</div><h3>Your watchlist</h3><p>Follow the stocks you care about. Swing (daily) or intraday (hourly), your choice.</p></div>
</div></div></section>
<section class="section"><div class="wrap grid g2" style="align-items:center"><div><h2 class="big">How it works</h2><p class="sub">No black box. The same published rules run every time.</p>
<div class="stack steps"><div class="step"><h3>Scan</h3><p>Every hour during market hours we scan your watchlist and our core list of US stocks.</p></div>
<div class="step"><h3>Signal</h3><p>When trend, momentum and RSI line up, a signal is saved with its levels and sent to you instantly.</p></div>
<div class="step"><h3>Track</h3><p>The trade is followed bar by bar until it hits a target, the stop, or the 20-bar time limit. The result goes on the public record.</p></div></div></div>
<div class="card"><h3>Developer</h3><p class="small">Built and run by {$this_e(Config::get('company'))}. Questions, feedback or a project of your own? Get in touch.</p>{$this_dev()}</div></div></section>
<section class="section" id="faq"><div class="wrap" style="max-width:820px"><h2 class="big">Frequently asked questions</h2><div class="faq">$faqHtml</div></div></section>
<section class="section"><div class="wrap"><div class="card c" style="padding:40px 20px"><h2 class="big">Start following the signals today</h2><p>Free account. No card required.</p><a class="btn lg" href="/signup">Create free account</a></div></div></section>
HTML;
        return View::publicPage('', $body, [
            'description' => 'Free stock trading signals for US stocks: buy alerts with entry, stop-loss and targets, push & email alerts, and a public daily, weekly and monthly track record.',
            'jsonld' => $jsonld,
        ]);
    }


    // ------------------------------------------------------------------ pricing

    public static function pricing(): string
    {
        $plans = Plans::active();
        $u = View::$user;
        $feat = Api::premiumFeatures();
        $free = ['Every signal alert (push & email)', 'All win/loss results and reports', 'Public track record', 'Watchlist, charts and bearish warnings'];
        $li = fn(array $xs) => '<ul>' . implode('', array_map(fn($x) => '<li>' . self::e($x) . '</li>', $xs)) . '</ul>';
        $cards = '<div class="card price"><h3>Free</h3><div class="amt">' . (Settings::get('checkout_currency') === 'USD' ? '$0' : '₦0') . ' <small>forever</small></div>'
            . $li($free) . '<a class="btn ghost block" href="' . ($u ? '/app' : '/signup') . '">' . ($u ? 'Go to dashboard' : 'Create free account') . '</a></div>';
        foreach ($plans as $i => $p) {
            $best = $p['badge'] !== '';
            $per = (int) $p['interval_months'] === 1 ? 'month' : $p['interval_months'] . ' months';
            $action = $u
                ? '<form method="post" action="/app/billing/checkout">' . View::csrf() . '<input type="hidden" name="plan" value="' . self::e($p['code'])
                    . '"><button class="btn block" type="submit">Pay with Paystack</button></form>'
                : '<a class="btn block" href="/signup?next=' . rawurlencode('/pricing') . '">Sign up to subscribe</a>';
            $cards .= '<div class="card price' . ($best ? ' best' : '') . '">' . ($best ? '<span class="pill a badge">' . self::e($p['badge']) . '</span>' : '')
                . '<h3>' . self::e($p['name']) . '</h3><div class="amt">' . self::e(self::priceLabel($p)) . ' <small>/ ' . $per . '</small></div>'
                . $li($feat) . $action . '</div>';
        }
        if (!Settings::on('premium_enabled')) {
            $cards = '<div class="card c" style="grid-column:1/-1"><h2>Everything is free right now 🎉</h2><p>All signal levels are unlocked for everyone.</p><a class="btn" href="/signup">Get free signals</a></div>';
        }
        $body = '<section class="hero" style="padding-bottom:20px"><div class="wrap"><h1>Simple pricing. Results stay free.</h1><p class="lead">Signal alerts and the full win/loss record are free for everyone. Premium unlocks the exact trade levels on live signals.</p></div></section>'
            . '<section class="section" style="padding-top:10px"><div class="wrap"><div class="grid g3">' . $cards . '</div>'
            . '<p class="small dim" style="margin-top:18px">One-time payments on the website (card, bank transfer or USSD via Paystack); nothing renews automatically. On Android you can also subscribe through Google Play in the app. '
            . 'Premium works on the website and in the app with the same account. Refunds: if something goes wrong with your payment, contact us within 7 days. Signals are educational and not financial advice.</p></div></section>';
        return View::publicPage('Pricing', $body, ['description' => 'Free stock signals and track record. Premium unlocks exact entry, stop-loss and targets on live signals.']);
    }

    // ------------------------------------------------------------------ track record

    public static function record(): string
    {
        $tf = in_array($_GET['tf'] ?? '', ['1d', '1h'], true) ? $_GET['tf'] : '1d';
        $rep = Performance::report(null, $tf, 1.0);
        $body = '<section class="section" style="padding-top:40px"><div class="wrap"><div class="row" style="margin-bottom:18px"><div class="grow"><h1>Live track record</h1>'
            . '<p style="max-width:760px">Every signal is saved the moment it is issued and closed automatically by fixed rules. Nothing is deleted or edited, and losses count. '
            . 'Results are in R (1R = the amount risked per trade). Equity assumes 1% risk per trade.</p></div>'
            . View::tabs(['1d' => ['Swing (daily)', '/record?tf=1d'], '1h' => ['Intraday (hourly)', '/record?tf=1h']], $tf) . '</div>'
            . self::performanceBlock($rep, 'monthly', '/record?tf=' . $tf . '&p=')
            . '<div class="card"><div class="card-h"><h2>Latest closed signals</h2></div>'
            . View::table(['Stock', 'Entry → exit', 'Outcome', 'Closed', '>Result'], self::closedRows(
                Db::all("SELECT * FROM signals WHERE kind='trade' AND tf = ? AND status IN ('won','lost') ORDER BY exit_time DESC, id DESC LIMIT 40", [$tf])),
                'No closed trades yet on this timeframe.') . '</div></div></section>';
        return View::publicPage('Live track record', $body, ['description' => 'Public, verifiable track record of every stock signal: win rate, total R, profit factor and drawdown by day, week and month.']);
    }

    /** Summary tiles + equity curve + period table + by-symbol (shared by /record and /app/results). */
    public static function performanceBlock(array $rep, string $default, string $periodLink): string
    {
        $period = in_array($_GET['p'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $_GET['p'] : $default;
        $cls = fn($v) => $v > 0 ? 'pos' : ($v < 0 ? 'neg' : '');
        $tiles = '';
        foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'all' => 'All time'] as $k => $label) {
            $s = $rep['summary'][$k];
            $tiles .= View::tile($label, Fmt::r((float) $s['total_r']), $s['trades'] . ' closed · ' . ($s['trades'] ? $s['win_rate'] . '% wins' : 'no closes') . ' · ' . $s['issued'] . ' signals', $cls($s['total_r']));
        }
        $a = $rep['summary']['all'];
        $pf = $a['profit_factor'] === null ? '∞' : ($a['trades'] ? number_format((float) $a['profit_factor'], 2) : '—');
        $eq = array_map(fn($p) => round(($p['equity'] - 1) * 100, 3), $rep['equity']);
        array_unshift($eq, 0);
        $rows = array_map(fn($b) => [self::e($b['label']), (string) $b['issued'], (string) $b['trades'],
            $b['trades'] ? $b['wins'] . ' / ' . $b['losses'] : '—', $b['trades'] ? $b['win_rate'] . '%' : '—', View::rCell($b['trades'] ? (float) $b['total_r'] : null)], $rep[$period]);
        $sym = array_map(fn($x) => ['<span class="sym">' . self::e($x['symbol']) . '</span>', (string) $x['trades'], $x['win_rate'] . '%', View::rCell((float) $x['total_r'])],
            array_slice($rep['by_symbol'], 0, 12));
        return '<div class="grid g4">' . $tiles . '</div>'
            . '<div class="split" style="margin-top:16px"><div class="card"><div class="card-h"><h2>Equity curve</h2><span class="small muted">Return '
            . '<b class="' . $cls($a['return_pct']) . '">' . Fmt::pct((float) $a['return_pct'], 2) . '</b> · max drawdown <b class="neg">-' . $a['max_drawdown_pct'] . '%</b></span></div>'
            . '<canvas class="chart" style="height:230px" data-line="' . self::e(json_encode($eq)) . '"></canvas></div>'
            . '<div class="card"><h2>All-time stats</h2><dl class="kv"><dt>Closed trades</dt><dd>' . $a['trades'] . '</dd><dt>Wins / losses</dt><dd>' . $a['wins'] . ' / ' . $a['losses'] . '</dd>'
            . '<dt>Win rate</dt><dd>' . ($a['trades'] ? $a['win_rate'] . '%' : '—') . '</dd><dt>Average result</dt><dd>' . Fmt::r((float) $a['avg_r']) . '</dd>'
            . '<dt>Profit factor</dt><dd>' . $pf . '</dd><dt>Targets / stops / time exits</dt><dd>' . $a['targets'] . ' / ' . $a['stops'] . ' / ' . $a['time_exits'] . '</dd>'
            . '<dt>Worst losing streak</dt><dd>' . $a['losing_streak'] . '</dd><dt>Open trades now</dt><dd>' . $rep['open_trades'] . '</dd></dl></div></div>'
            . '<div class="split" style="margin-top:16px"><div class="card"><div class="card-h"><h2>Results by period</h2>'
            . View::tabs(['daily' => ['Daily', $periodLink . 'daily'], 'weekly' => ['Weekly', $periodLink . 'weekly'], 'monthly' => ['Monthly', $periodLink . 'monthly']], $period) . '</div>'
            . View::table(['Period', 'Signals', 'Closed', 'W / L', 'Win rate', '>Result'], $rows, 'No periods yet.') . '</div>'
            . '<div class="card"><h2>Best stocks</h2>' . View::table(['Stock', 'Trades', 'Win rate', '>Result'], $sym, 'No closed trades yet.') . '</div></div><div style="height:16px"></div>';
    }

    // ------------------------------------------------------------------ brokers

    public static function brokerCards(?string $ref, bool $app = false): string
    {
        $list = Brokers::active();
        if (!$list) return '<div class="card empty">Broker partners will be listed here soon.</div>';
        $h = '<div class="grid g3">';
        foreach ($list as $b) {
            $link = '/go/' . rawurlencode($b['slug']) . ($ref ? '?ref=' . rawurlencode($ref) : '');
            $logo = $b['logo_url'] ? '<img src="' . self::e($b['logo_url']) . '" alt="" style="width:40px;height:40px;border-radius:10px;object-fit:contain;background:#fff">'
                : '<div style="width:40px;height:40px;border-radius:10px;background:var(--card2);display:grid;place-items:center;font-weight:800">' . self::e(mb_substr($b['name'], 0, 1)) . '</div>';
            $h .= '<div class="card"><div class="row" style="margin-bottom:10px">' . $logo . '<div><h3 style="margin:0">' . self::e($b['name']) . '</h3><div class="small muted">' . self::e($b['tagline']) . '</div></div></div>'
                . '<p class="small">' . nl2br(self::e($b['description'])) . '</p><dl class="kv" style="margin-bottom:14px"><dt>Min. deposit</dt><dd>' . self::e($b['min_deposit'] ?: '—') . '</dd>'
                . '<dt>Available in</dt><dd>' . self::e($b['regions'] ?: '—') . '</dd></dl><a class="btn block" href="' . self::e($link) . '" target="_blank" rel="sponsored noopener">Open account ↗</a></div>';
        }
        return $h . '</div><p class="xs dim" style="margin-top:14px">Disclosure: ' . self::e(Api::brokerDisclosure()) . '</p>';
    }

    public static function brokers(?string $ref): string
    {
        $body = '<section class="section" style="padding-top:40px"><div class="wrap"><h1>Brokers for trading US stocks</h1><p style="max-width:720px">To act on a signal you need a brokerage account. '
            . 'These are brokers our users trade with. Compare fees, deposit methods and regulation before you choose.</p>' . self::brokerCards($ref) . '</div></section>';
        return View::publicPage('Brokers', $body, ['description' => 'Brokers you can use to trade US stocks and act on our signals. Compare minimum deposits and availability.']);
    }

    // ------------------------------------------------------------------ affiliate program

    public static function affiliateProgram(): string
    {
        $rate = (float) Settings::num('commission_rate');
        $share = (float) Settings::num('broker_share_pct');
        $hold = (int) Settings::num('commission_hold_days');
        $min = View::money(Settings::num('min_payout_usd'));
        $days = (int) Settings::num('cookie_days');
        $recurring = Settings::on('commission_recurring') ? 'every payment they make, including renewals' : 'their first payment';
        $methods = implode(', ', array_map(fn($m) => ['bank' => 'bank transfer', 'usdt' => 'USDT', 'paypal' => 'PayPal'][$m] ?? $m,
            array_filter(array_map('trim', explode(',', Settings::get('payout_methods'))))));
        $this_tile = fn(string $l, string $v, string $sub) => View::tile($l, self::e($v), self::e($sub));
        $affLead = View::free()
            ? "Every signal is free, so people are happy to join through your link. Earn {$share}% of the commission we receive when someone you refer opens a broker account through your link."
            : "Get your personal link. Earn {$rate}% of $recurring from people you refer, plus {$share}% of the commission we receive when they open a broker account through your link.";
        $affTiles = (View::free() ? '' : $this_tile('Premium commission', $rate . '%', 'of each referred payment')) . $this_tile('Broker share', $share . '%', 'of broker commissions');
        $cta = View::$user ? '<a class="btn lg" href="/app/affiliate">Go to your affiliate dashboard</a>' : '<a class="btn lg" href="/signup?next=%2Fapp%2Faffiliate">Join the program</a>';
        $closed = Settings::on('affiliate_enabled') ? '' : '<div class="alert warn">The affiliate program is not accepting new applications right now.</div>';
        $body = <<<HTML
<section class="hero"><div class="wrap">$closed<span class="pill v">🤝 Affiliate program</span><h1 style="margin-top:18px">Earn by sharing <em>honest signals</em>.</h1>
<p class="lead">{$affLead}</p>
<div class="row" style="margin-top:24px">$cta</div></div></section>
<section class="section" style="padding-top:10px"><div class="wrap"><div class="grid g4">
{$affTiles}
{$this_tile('Cookie window', $days . ' days', 'from the first click')}{$this_tile('Minimum payout', $min, $methods)}</div>
<div class="grid g2" style="margin-top:16px"><div class="card"><h2>How it works</h2><div class="stack steps"><div class="step"><h3>Apply</h3><p>Create a free account and apply from your dashboard. Tell us where you'll promote.</p></div>
<div class="step"><h3>Share your link</h3><p>Anyone who signs up within $days days of clicking your link, or enters your code in the app, is yours for good.</p></div>
<div class="step"><h3>Get paid</h3><p>Commissions become withdrawable after a $hold-day refund window. Request a payout from $min.</p></div></div></div>
<div class="card"><h2>Rules</h2><ul class="small" style="color:var(--m);padding-left:18px;line-height:1.8">
<li>No promises of guaranteed or "100%" profits. Describe the service honestly and link the public track record.</li>
<li>No spam, fake reviews, or bidding on our brand name in ads.</li><li>Self-referrals and duplicate accounts are not paid.</li>
<li>Refunded or charged-back payments reverse their commission.</li><li>Broker commissions are paid only after the broker pays us for a qualifying account.</li>
<li>We may change rates for future earnings with notice; earned commissions are never reduced.</li></ul></div></div></div></section>
HTML;
        return View::publicPage('Affiliate program', $body, ['description' => View::free() ? 'Join the Stock Trading Signals affiliate program: share free signals and earn a share of broker commissions.' : "Join the Stock Trading Signals affiliate program: earn {$rate}% on Premium payments and a share of broker commissions."]);
    }


    // ------------------------------------------------------------------ auth pages

    private static function googleButton(string $next): string
    {
        $cid = (string) Config::get('google.web_client_id', '');
        if ($cid === '') return '';
        $login = self::e(Urls::site('/login/google'));
        return '<script src="https://accounts.google.com/gsi/client" async></script>'
            . '<div id="g_id_onload" data-client_id="' . self::e($cid) . '" data-ux_mode="redirect" data-login_uri="' . $login . '" data-state="' . self::e($next) . '" data-auto_prompt="false"></div>'
            . '<div class="g_id_signin" data-type="standard" data-size="large" data-theme="filled_black" data-text="continue_with" data-shape="pill" data-width="380" style="display:flex;justify-content:center"></div>'
            . '<div class="or">or</div>';
    }

    private static function authCard(string $title, string $sub, string $inner): string
    {
        return '<div class="auth"><div class="card"><h1 style="font-size:24px">' . $title . '</h1><p class="small">' . $sub . '</p>' . View::flashes() . $inner . '</div></div>';
    }

    public static function login(string $next): string
    {
        $next = Web::safeNext($next);
        $form = self::googleButton($next) . '<form method="post" action="/login">' . View::csrf() . '<input type="hidden" name="next" value="' . self::e($next) . '">'
            . View::field('email', 'Email', '', 'email', 'required autocomplete="email" autofocus')
            . View::field('password', 'Password', '', 'password', 'required autocomplete="current-password"')
            . '<div class="row" style="justify-content:space-between;margin:-4px 0 16px"><a class="small" href="/forgot">Forgot password?</a></div>'
            . '<button class="btn block" type="submit">Log in</button></form><p class="small c" style="margin:18px 0 0">New here? <a href="/signup?next=' . rawurlencode($next) . '">Create a free account</a></p>';
        return View::publicPage('Log in', self::authCard('Welcome back', 'Same account as the Android app.', $form), ['noindex' => true]);
    }

    public static function signup(?string $ref, string $next): string
    {
        $next = Web::safeNext($next);
        $refName = $ref && ($rid = Affiliates::userByCode($ref)) ? (string) Db::value('SELECT name FROM users WHERE id = ?', [$rid]) : '';
        $note = $refName !== '' ? '<div class="alert info">Invited by ' . self::e($refName) . '</div>' : '';
        $form = $note . self::googleButton($next) . '<form method="post" action="/signup">' . View::csrf() . '<input type="hidden" name="next" value="' . self::e($next) . '">'
            . View::field('name', 'Name', '', 'text', 'autocomplete="name" maxlength="120"')
            . View::field('email', 'Email', '', 'email', 'required autocomplete="email"')
            . View::field('password', 'Password', '', 'password', 'required minlength="8" autocomplete="new-password"', '8+ characters with letters and numbers.')
            . View::field('referral_code', 'Referral code (optional)', (string) $ref, 'text', 'maxlength="16" style="text-transform:uppercase"')
            . '<label class="check" style="border:0;padding:4px 0 16px"><input type="checkbox" name="agree" value="1" required><span>I agree to the <a href="/terms" target="_blank">Terms</a> and <a href="/privacy" target="_blank">Privacy policy</a>, and understand signals are not financial advice.</span></label>'
            . '<button class="btn block" type="submit">Create free account</button></form><p class="small c" style="margin:18px 0 0">Already have an account? <a href="/login">Log in</a></p>';
        return View::publicPage('Create your free account', self::authCard('Get free stock signals', 'Alerts, results and the full track record. No card needed.', $form),
            ['description' => 'Create a free account to get stock trading signals by push and email.']);
    }

    public static function forgot(): string
    {
        $form = '<form method="post" action="/forgot">' . View::csrf() . View::field('email', 'Email', '', 'email', 'required autocomplete="email"')
            . '<button class="btn block" type="submit">Send reset link</button></form><p class="small c" style="margin:18px 0 0"><a href="/login">Back to log in</a></p>';
        return View::publicPage('Reset password', self::authCard('Forgot your password?', 'We\'ll email you a link to choose a new one.', $form), ['noindex' => true]);
    }

    public static function reset(string $token): string
    {
        $form = '<form method="post" action="/reset">' . View::csrf() . '<input type="hidden" name="token" value="' . self::e($token) . '">'
            . View::field('password', 'New password', '', 'password', 'required minlength="8" autocomplete="new-password"', '8+ characters with letters and numbers.')
            . '<button class="btn block" type="submit">Save new password</button></form>';
        return View::publicPage('Choose a new password', self::authCard('Choose a new password', 'You\'ll be signed out on other devices.', $form), ['noindex' => true]);
    }

    public static function message(string $title, string $msg, bool $ok = true): string
    {
        $btn = View::$user ? '<a class="btn" href="/app">Go to dashboard</a>' : '<a class="btn" href="/login">Log in</a>';
        return View::publicPage($title, '<div class="auth"><div class="card c"><div style="font-size:42px">' . ($ok ? '✅' : '⚠️') . '</div><h1>' . self::e($title) . '</h1><p>'
            . self::e($msg) . '</p>' . $btn . '</div></div>', ['noindex' => true]);
    }

    public static function deleteAccount(): string
    {
        $form = '<p class="small">This permanently erases your account, settings, watchlist, devices, alerts and affiliate data. It cannot be undone. Active Premium time is not refunded.</p>'
            . '<p class="small">Signed up with Google? <a href="/login?next=%2Fapp%2Fsettings">Log in</a> and use Settings → Delete account, or in the app: Settings → Account → Delete account.</p>'
            . '<form method="post" action="/delete-account" data-confirm="Permanently delete your account?">' . View::csrf()
            . View::field('email', 'Email', '', 'email', 'required') . View::field('password', 'Password', '', 'password', 'required')
            . '<button class="btn danger block" type="submit">Delete my account permanently</button></form>';
        return View::publicPage('Delete account', self::authCard('Delete your account', 'For the website and the Android app.', $form));
    }

    // ------------------------------------------------------------------ legal

    private static function prose(string $title, array $sections): string
    {
        $h = '<section class="section" style="padding-top:40px"><div class="wrap prose"><h1>' . self::e($title) . '</h1><p class="small">Last updated ' . gmdate('j F Y') . '</p>';
        foreach ($sections as [$t, $b]) $h .= '<h2>' . self::e($t) . '</h2><p>' . $b . '</p>';
        return View::publicPage($title, $h . '</div></section>');
    }

    public static function privacy(): string
    {
        $app = View::app();
        $company = self::e(Config::get('company', ''));
        $contact = self::e(Settings::get('support_email') ?: Config::get('mail.from', ''));
        return self::prose('Privacy policy', [
            ['Who we are', "$app (website and Android app) is operated by $company."],
            ['What we collect', 'Your name and email (or those of your Google account if you use Google sign-in), a securely hashed password, your risk settings, watchlist and notification preferences, the alerts we sent you, push-notification tokens for your devices, and a website session cookie. If you buy Premium we store the plan, amount, date and payment reference, never your card details. If you join the affiliate program we store your payout details and referral statistics. Referral and broker-link clicks are logged with a one-way hashed IP address.'],
            ['How we use it', 'To provide the service: sign you in, scan stocks, send push and email alerts and reports, show your results, process Premium, credit referrals and pay affiliates. We do not sell or rent your data, show ads, or use advertising trackers.'],
            ['Third parties', 'Payments on the website are processed by Paystack; in the Android app by Google Play. Push notifications use Google Firebase Cloud Messaging. Google Sign-In is provided by Google. Market prices come from Yahoo Finance using only ticker symbols. When you open a broker link you leave our site and the broker\'s own privacy policy applies; we pass the broker only an anonymous click ID.'],
            ['Cookies', 'We use a session cookie to keep you signed in and, if you arrived through a referral link, a cookie that remembers the referrer for up to ' . (int) Settings::num('cookie_days') . ' days. No advertising cookies.'],
            ['Storage & security', 'Data is stored on our server and transmitted over HTTPS. Passwords are hashed and never stored in plain text.'],
            ['Retention & deletion', 'We keep your data while your account exists. Payment records are kept as required for accounting. You can delete your account at any time in Settings or at <a href="/delete-account">' . self::e(Urls::site('/delete-account')) . '</a>.'],
            ['Children', "$app is not intended for anyone under 18."],
            ['Contact', $contact !== '' ? "Questions about privacy: <a href=\"mailto:$contact\">$contact</a>" : 'Contact us via the developer chat link at the bottom of every page.'],
        ]);
    }

    public static function terms(): string
    {
        $app = View::app();
        $company = self::e(Config::get('company', ''));
        return self::prose('Terms of use', [
            ['Agreement', "By using $app you agree to these terms. The service is provided by $company."],
            ['Not financial advice', 'Signals are generated automatically by published technical rules and are provided for education and information only. They are not investment, financial, tax or legal advice, and not a recommendation to buy or sell any security. You are solely responsible for your trading decisions.'],
            ['Risk', 'Trading stocks involves substantial risk of loss. No signal or strategy wins every time, and past results, including our track record, do not guarantee future results. Only trade money you can afford to lose.'],
            ['Accounts', 'You must be 18 or older. Keep your password safe. We may suspend accounts that abuse the service, attempt to defraud the affiliate program, or redistribute Premium signal levels.'],
            ['Premium', 'Premium on the website is a one-time payment for the period shown and does not auto-renew. Premium bought in the Android app is a Google Play subscription you manage in Google Play. Premium unlocks the exact levels on live signals; results stay free for everyone. If we can\'t deliver what you paid for, contact us within 7 days for a refund.'],
            ['Affiliate program', 'Affiliates earn the commission shown on the affiliate page at the time the payment is made. Commissions are held for a refund window before they can be withdrawn, and are reversed if a payment is refunded or charged back. Self-referrals, spam, misleading claims (including "guaranteed profit") and fake accounts are not allowed and forfeit commissions. We pay to the payout details you provide and are not responsible for errors in them.'],
            ['Broker links', 'Some broker links are affiliate links and we may be paid if you open an account. We do not control brokers and are not responsible for their services. Check that a broker is regulated where you live.'],
            ['Data accuracy', 'Market data may be delayed or contain errors. We are not liable for losses arising from data issues, delayed alerts, or service interruptions.'],
            ['Liability', "To the fullest extent permitted by law, $company is not liable for any trading losses or indirect damages arising from use of the service."],
            ['Changes', 'We may update these terms. Continued use after changes means you accept them.'],
        ]);
    }
}
