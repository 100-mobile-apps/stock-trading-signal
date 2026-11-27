<?php
declare(strict_types=1);

namespace SignalLab;

/** Member dashboard at /app (same account, data and rules as the Android app). */
final class WebApp
{
    private static function e(mixed $s): string { return View::e($s); }

    public static function routes(Router $r): void
    {
        $page = fn(string $active, string $title, string $body, string $actions = '') => Response::html(View::appPage($active, $title, $body, $actions));
        $post = function (Request $q): int {
            $id = Web::uid();
            View::$session->checkCsrf($q);
            return $id;
        };
        $back = function (string $to, string $type, string $msg): Response {
            View::$session->flash($type, $msg);
            return Response::redirect($to);
        };
        $try = function (string $to, callable $fn) use ($back): Response {
            try { return $fn(); } catch (ApiError $e) { if ($e->status === 419) throw $e; return $back($to, 'err', $e->getMessage()); }
        };

        $r->add('GET', '/app', fn() => $page('dashboard', 'Dashboard', self::dashboard(Web::uid())));
        $r->add('GET', '/app/signals', fn(Request $q) => $page('signals', 'Signals', self::signals(Web::uid(), $q)));
        $r->add('GET', '/app/signals/{id}', fn(Request $q, array $p) => $page('signals', 'Signal', self::signal(Web::uid(), (int) $p['id'])));
        $r->add('GET', '/app/results', fn(Request $q) => $page('results', 'Results', self::results(Web::uid(), $q)));
        $r->add('GET', '/app/chart.json', fn(Request $q) => Response::json(self::chart(Web::uid(), $q)));
        $r->add('GET', '/app/stock/{symbol}', fn(Request $q, array $p) => $page('watchlist', strtoupper($p['symbol']), self::stock(Web::uid(), strtoupper($p['symbol']), $q)));

        // watchlist
        $r->add('GET', '/app/watchlist', fn() => $page('watchlist', 'Watchlist', self::watchlist(Web::uid())));
        $r->add('POST', '/app/watchlist/add', function (Request $q) use ($post, $back) {
            $id = $post($q);
            $sym = strtoupper($q->str('symbol', 15));
            if (!preg_match('/^[A-Z0-9.\-^=]{1,15}$/', $sym)) return $back('/app/watchlist', 'err', 'Enter a valid ticker symbol, e.g. AAPL.');
            if ((int) Db::value('SELECT COUNT(*) FROM watchlist WHERE user_id = ?', [$id]) >= 60) return $back('/app/watchlist', 'err', 'A watchlist can hold up to 60 stocks.');
            try {
                $d = Market::fetch($sym, '1d');
                if (count($d['candles']) < 30) throw new \RuntimeException('short');
            } catch (\Throwable) {
                return $back('/app/watchlist', 'err', "We couldn't find price data for $sym. Check the ticker (US stocks only).");
            }
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), -1) + 1 FROM watchlist WHERE user_id = ?', [$id]);
            Db::exec('INSERT IGNORE INTO watchlist (user_id, symbol, position) VALUES (?, ?, ?)', [$id, $sym, $pos]);
            return $back('/app/watchlist', 'ok', "$sym added. It will be scanned from the next run.");
        });
        $r->add('POST', '/app/watchlist/remove', function (Request $q) use ($post, $back) {
            $id = $post($q);
            Db::exec('DELETE FROM watchlist WHERE user_id = ? AND symbol = ?', [$id, strtoupper($q->str('symbol', 15))]);
            return $back('/app/watchlist', 'ok', 'Removed from your watchlist.');
        });

        // alerts
        $r->add('GET', '/app/alerts', fn() => $page('alerts', 'Alerts', self::alerts(Web::uid()),
            View::postButton('/app/alerts/read', 'Mark all read')));
        $r->add('POST', '/app/alerts/read', function (Request $q) use ($post) {
            $id = $post($q);
            Db::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Db::now(), $id]);
            return Response::redirect('/app/alerts');
        });

        // settings
        $r->add('GET', '/app/settings', fn() => $page('settings', 'Settings', self::settings(Web::uid())));
        $r->add('POST', '/app/settings/profile', function (Request $q) use ($post, $back) {
            $id = $post($q);
            Db::exec('UPDATE users SET name = ? WHERE id = ?', [$q->str('name', 120), $id]);
            return $back('/app/settings', 'ok', 'Profile saved.');
        });
        $r->add('POST', '/app/settings/trading', function (Request $q) use ($post, $back) {
            $id = $post($q);
            $b = $q->body;
            $upd = ['updated_at' => Db::now()];
            if (is_numeric($b['account_size'] ?? null)) $upd['account_size'] = max(1, min(1e9, (float) $b['account_size']));
            if (is_numeric($b['risk_pct'] ?? null)) $upd['risk_pct'] = max(0.1, min(10, (float) $b['risk_pct']));
            if (in_array($b['style'] ?? '', ['1d', '1h'], true)) $upd['style'] = $b['style'];
            foreach (['fractional', 'all_signals', 'push_signals', 'push_results', 'email_signals', 'email_results', 'report_daily', 'report_weekly', 'report_monthly'] as $k) {
                if (array_key_exists($k, $b)) $upd[$k] = $b[$k] === '1' || $b[$k] === 1 || $b[$k] === true ? 1 : 0;
            }
            Auth::ensureSettings($id);
            Db::update('user_settings', $upd, 'user_id = ?', [$id]);
            return $back('/app/settings', 'ok', 'Settings saved. They apply in the app too.');
        });
        $r->add('POST', '/app/settings/password', function (Request $q) use ($post, $back, $try) {
            $id = $post($q);
            return $try('/app/settings', function () use ($q, $id, $back) {
                Auth::changePassword($id, (string) ($q->body['current_password'] ?? ''), (string) ($q->body['new_password'] ?? ''));
                return $back('/app/settings', 'ok', 'Password updated.');
            });
        });
        $r->add('POST', '/app/settings/verify', function (Request $q) use ($post, $back) {
            Auth::sendVerification($post($q));
            return $back('/app/settings', 'ok', 'Confirmation email sent. Check your inbox (and spam folder).');
        });
        $r->add('POST', '/app/settings/delete', function (Request $q) use ($post, $back) {
            $id = $post($q);
            if ($q->str('confirm', 10) !== 'DELETE') return $back('/app/settings', 'err', 'Type DELETE to confirm.');
            Auth::deleteAccount($id);
            View::$session->logout();
            View::$session->flash('ok', 'Your account has been permanently deleted.');
            return Response::redirect('/');
        });

        // billing
        $r->add('GET', '/app/billing', fn() => View::free() ? Response::redirect('/app') : $page('billing', 'Premium', self::billing(Web::uid())));
        $r->add('POST', '/app/billing/checkout', function (Request $q) use ($post, $try) {
            $id = $post($q);
            return $try('/app/billing', fn() => Response::redirect(Billing::paystackInit($id, $q->str('plan', 20))));
        });

        // brokers
        $r->add('GET', '/app/brokers', fn() => $page('brokers', 'Brokers', '<p>Open an account with a broker to act on signals. Compare fees and regulation first.</p>'
            . WebPages::brokerCards(null)));

        // affiliate
        $r->add('GET', '/app/affiliate', fn() => $page('affiliate', 'Affiliate', self::affiliate(Web::uid())));
        $r->add('POST', '/app/affiliate/apply', function (Request $q) use ($post, $back, $try) {
            $id = $post($q);
            return $try('/app/affiliate', function () use ($q, $id, $back) {
                $a = Affiliates::apply($id, $q->str('promo_plan', 500));
                return $back('/app/affiliate', 'ok', $a['status'] === 'approved' ? 'You\'re in! Start sharing your link.' : 'Application received. We usually review within 48 hours.');
            });
        });
        $r->add('POST', '/app/affiliate/payout-details', function (Request $q) use ($post, $back, $try) {
            $id = $post($q);
            return $try('/app/affiliate', function () use ($q, $id, $back) {
                if (!Affiliates::get($id)) throw new ApiError(403, 'not_affiliate', 'Apply to the program first.');
                Affiliates::savePayout($id, $q->str('method', 10), $q->body);
                return $back('/app/affiliate', 'ok', 'Payout details saved.');
            });
        });
        $r->add('POST', '/app/affiliate/payout', function (Request $q) use ($post, $back, $try) {
            $id = $post($q);
            return $try('/app/affiliate', function () use ($id, $back) {
                $pid = Affiliates::requestPayout($id);
                $amt = (float) Db::value('SELECT amount_usd FROM payouts WHERE id = ?', [$pid]);
                return $back('/app/affiliate', 'ok', 'Payout of ' . View::money($amt) . ' requested. We\'ll notify you when it\'s sent.');
            });
        });
    }

    // ------------------------------------------------------------------ helpers

    private static function settingsRow(int $id): array
    {
        Auth::ensureSettings($id);
        return Db::one('SELECT * FROM user_settings WHERE user_id = ?', [$id]);
    }

    private static function lockCell(): string
    {
        return '<a class="lock" href="/app/billing" title="Premium unlocks exact levels">🔒 Premium</a>';
    }

    private static function signalRows(array $rows, bool $premium): array
    {
        $out = [];
        foreach ($rows as $s) {
            $locked = !$premium && Api::isLive($s);
            $lv = fn(string $k) => $locked ? self::lockCell() : ($s[$k] === null ? '<span class="dim">—</span>' : self::e(Fmt::price((float) $s[$k])));
            $isTrade = $s['kind'] === 'trade';
            $out[] = [
                '<a href="/app/signals/' . (int) $s['id'] . '"><span class="sym">' . self::e($s['symbol']) . '</span></a> <span class="xs dim">' . ($s['tf'] === '1d' ? 'D' : 'H') . '</span>',
                $isTrade ? View::pill('BUY', 'g') : View::pill('SELL ⚠', 'r'),
                '<span class="nowrap small">' . View::date($s['signal_time'], $s['tf'] === '1h') . '</span>',
                $isTrade ? ($s['entry_price'] !== null ? $lv('entry_price') : $lv('signal_price')) : self::e(Fmt::price((float) $s['signal_price'])),
                $isTrade ? $lv($s['status'] === 'open' ? 'live_stop' : 'stop_loss') : '—',
                $isTrade ? $lv('target2') : '—',
                str_repeat('●', (int) $s['strength']) . '<span class="dim">' . str_repeat('●', max(0, 4 - (int) $s['strength'])) . '</span>',
                View::statusPill($s),
                $s['r_multiple'] !== null ? View::rCell((float) $s['r_multiple']) : '<span class="dim">—</span>',
            ];
        }
        return $out;
    }

    private const SIGNAL_HEAD = ['Stock', 'Type', 'Signal time', 'Entry', 'Stop', 'Target 2', 'Strength', 'Status', '>Result'];
    private const COMPACT_HEAD = ['Stock', 'Type', 'Entry', 'Stop', 'Target 2', 'Status', '>Result'];

    /** Narrow version for the dashboard column (drops time and strength). */
    private static function compactRows(array $rows, bool $premium): array
    {
        return array_map(fn($r) => [$r[0], $r[1], $r[3], $r[4], $r[5], $r[7], $r[8]], self::signalRows($rows, $premium));
    }

    private static function upsell(int $id): string
    {
        if (Premium::isActive($id)) return '';
        $plans = Plans::active();
        $from = $plans ? WebPages::priceLabel($plans[0]) : '';
        return '<div class="upsell"><span style="font-size:24px">🔒</span><p><b>Live signal levels are a Premium feature.</b> You see every signal and every result for free. '
            . 'Premium shows the exact entry, stop-loss and targets while the trade is live. From ' . self::e($from) . '/month.</p><a class="btn amber" href="/app/billing">Unlock Premium</a></div><div style="height:16px"></div>';
    }

    // ------------------------------------------------------------------ pages

    private static function dashboard(int $id): string
    {
        $st = self::settingsRow($id);
        $premium = Premium::isActive($id);
        $wl = Api::watchlist($id);
        $rep = Performance::report(null, $st['style'], (float) $st['risk_pct']);
        $cls = fn($v) => $v > 0 ? 'pos' : ($v < 0 ? 'neg' : '');
        $m = $rep['summary']['month'];
        $w = $rep['summary']['week'];
        $a = $rep['summary']['all'];
        $live = Db::all("SELECT * FROM signals WHERE tf = ? AND (status IN ('pending','open') OR (status = 'warning' AND signal_time > DATE_SUB(?, INTERVAL 7 DAY))) ORDER BY id DESC LIMIT 8",
            [$st['style'], Db::now()]);
        $closed = Db::all("SELECT * FROM signals WHERE tf = ? AND status IN ('won','lost') ORDER BY exit_time DESC, id DESC LIMIT 6", [$st['style']]);
        $notes = Db::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 6', [$id]);
        $u = View::$user;
        $verify = $u['email_verified_at'] ? '' : '<div class="alert warn">Confirm your email to receive alerts and reports by email. '
            . View::postButton('/app/settings/verify', 'Resend link', 'btn sm ghost') . '</div>';
        $noteHtml = '';
        foreach ($notes as $n) {
            $noteHtml .= '<div style="padding:10px 0;border-bottom:1px solid var(--b)"><div class="small"><b>' . self::e($n['title']) . '</b> <span class="dim xs">' . View::ago($n['created_at'])
                . '</span></div><div class="xs muted">' . self::e($n['body']) . '</div></div>';
        }
        $noteHtml = $noteHtml ?: '<p class="small muted">No alerts yet. You\'ll be notified when a signal fires on your watchlist.</p>';
        $style = $st['style'] === '1d' ? 'Swing (daily)' : 'Intraday (hourly)';
        return $verify . self::upsell($id)
            . '<div class="grid g4">' . View::tile('This week', Fmt::r((float) $w['total_r']), $w['trades'] . ' closed · ' . $w['issued'] . ' signals', $cls($w['total_r']))
            . View::tile('This month', Fmt::r((float) $m['total_r']), $m['trades'] . ' closed · ' . ($m['trades'] ? $m['win_rate'] . '% wins' : '—'), $cls($m['total_r']))
            . View::tile('All-time win rate', $a['trades'] ? $a['win_rate'] . '%' : '—', $a['trades'] . ' closed trades')
            . View::tile('Open trades', (string) $rep['open_trades'], self::e($style) . ' · <a href="/app/settings">change</a>') . '</div>'
            . '<div class="split" style="margin-top:16px"><div class="stack"><div class="card"><div class="card-h"><h2>Live signals</h2><a class="small" href="/app/signals?status=live">All live →</a></div>'
            . View::table(self::COMPACT_HEAD, self::compactRows($live, $premium), 'No live signals right now. The scanner runs every hour during US market hours.') . '</div>'
            . '<div class="card"><div class="card-h"><h2>Recently closed</h2><a class="small" href="/app/results">Results →</a></div>'
            . View::table(self::COMPACT_HEAD, self::compactRows($closed, $premium), 'No closed trades yet.') . '</div></div>'
            . '<div class="stack"><div class="card"><div class="card-h"><h2>Latest alerts</h2><a class="small" href="/app/alerts">All →</a></div>' . $noteHtml . '</div>'
            . '<div class="card"><div class="card-h"><h2>Watchlist</h2><a class="small" href="/app/watchlist">Edit →</a></div><div class="row">'
            . implode('', array_map(fn($s) => '<a class="pill" href="/app/stock/' . rawurlencode($s) . '">' . self::e($s) . '</a>', $wl)) . '</div></div>'
            . '<div class="card"><h2>Get the app</h2><p class="small">Instant push alerts on your phone, same account.</p>'
            . ((string) Config::get('play_url') ? '<a class="btn sm" href="' . self::e(Config::get('play_url')) . '" target="_blank" rel="noopener">▶ Google Play</a>' : '') . '</div>'
            . '<div class="card"><h2>Developer</h2>' . View::devButtons() . '</div></div></div>';
    }

    private static function signals(int $id, Request $q): string
    {
        $st = self::settingsRow($id);
        $tf = in_array($q->str('tf', 2), ['1d', '1h'], true) ? $q->str('tf', 2) : $st['style'];
        $status = in_array($q->str('status', 10), ['live', 'closed', 'all'], true) ? $q->str('status', 10) : 'live';
        $scope = $q->str('scope', 10) === 'watchlist' ? 'watchlist' : 'all';
        $page = max(1, $q->int('page', 1));
        $where = ['tf = ?'];
        $params = [$tf];
        if ($status === 'live') { $where[] = "(status IN ('pending','open') OR (status = 'warning' AND signal_time > DATE_SUB(?, INTERVAL 7 DAY)))"; $params[] = Db::now(); }
        elseif ($status === 'closed') $where[] = "status IN ('won','lost')";
        if ($scope === 'watchlist') { $where[] = 'symbol IN (SELECT symbol FROM watchlist WHERE user_id = ?)'; $params[] = $id; }
        $per = 30;
        $off = ($page - 1) * $per;
        $order = $status === 'closed' ? 'exit_time DESC, id DESC' : 'id DESC';
        $rows = Db::all('SELECT * FROM signals WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT " . ($per + 1) . " OFFSET $off", $params);
        $more = count($rows) > $per;
        $rows = array_slice($rows, 0, $per);
        $link = fn(array $over) => '/app/signals?' . http_build_query($over + ['tf' => $tf, 'status' => $status, 'scope' => $scope]);
        $filters = '<div class="row" style="margin-bottom:16px">'
            . View::tabs(['live' => ['Live', $link(['status' => 'live'])], 'closed' => ['Closed', $link(['status' => 'closed'])], 'all' => ['All', $link(['status' => 'all'])]], $status)
            . View::tabs(['1d' => ['Swing (daily)', $link(['tf' => '1d'])], '1h' => ['Intraday (hourly)', $link(['tf' => '1h'])]], $tf)
            . View::tabs(['all' => ['All stocks', $link(['scope' => 'all'])], 'watchlist' => ['My watchlist', $link(['scope' => 'watchlist'])]], $scope) . '</div>';
        return self::upsell($id) . $filters . '<div class="card">' . View::table(self::SIGNAL_HEAD, self::signalRows($rows, Premium::isActive($id)),
            $status === 'live' ? 'No live signals right now. New signals appear here and are pushed to you instantly.' : 'No signals yet.')
            . View::pager($page, $more, ['tf' => $tf, 'status' => $status, 'scope' => $scope]) . '</div>';
    }

    private static function signal(int $id, int $sid): string
    {
        $s = Db::one('SELECT * FROM signals WHERE id = ?', [$sid]);
        if (!$s) throw new ApiError(404, 'not_found', 'Signal not found.');
        $premium = Premium::isActive($id);
        $locked = !$premium && Api::isLive($s);
        $st = self::settingsRow($id);
        $p = fn(string $k) => $locked ? self::lockCell() : ($s[$k] === null ? '—' : self::e(Fmt::price((float) $s[$k])));
        $isTrade = $s['kind'] === 'trade';
        $kv = '<dl class="kv"><dt>Status</dt><dd>' . View::statusPill($s) . '</dd><dt>Timeframe</dt><dd>' . ($s['tf'] === '1d' ? 'Daily (swing)' : 'Hourly (intraday)') . '</dd>'
            . '<dt>Signal time</dt><dd>' . View::date($s['signal_time'], true) . '</dd><dt>Strength</dt><dd>' . (int) $s['strength'] . ' / 4</dd>';
        if ($isTrade) {
            $kv .= '<dt>Entry reference</dt><dd>' . $p('signal_price') . '</dd>'
                . ($s['entry_price'] !== null ? '<dt>Filled at (next open)</dt><dd>' . $p('entry_price') . '</dd>' : '')
                . '<dt>Stop-loss</dt><dd class="neg">' . $p($s['live_stop'] !== null ? 'live_stop' : 'stop_loss') . '</dd>'
                . '<dt>Target 1 (1:1)</dt><dd class="pos">' . $p($s['live_target1'] !== null ? 'live_target1' : 'target1') . '</dd>'
                . '<dt>Target 2 (2:1)</dt><dd class="pos">' . $p($s['live_target2'] !== null ? 'live_target2' : 'target2') . '</dd>';
            if ($s['tp1_hit_time']) $kv .= '<dt>Target 1 hit</dt><dd>' . View::date($s['tp1_hit_time'], true) . '</dd>';
            if ($s['exit_time']) {
                $kv .= '<dt>Exit</dt><dd>' . self::e(Fmt::price((float) $s['exit_price'])) . ' · ' . View::date($s['exit_time']) . '</dd>'
                    . '<dt>Result</dt><dd>' . View::rCell((float) $s['r_multiple']) . ' <span class="small">(' . Fmt::pct((float) $s['return_pct']) . ')</span></dd>'
                    . '<dt>Held</dt><dd>' . (int) $s['bars_held'] . ' bars</dd>';
            }
        }
        $kv .= '</dl>';
        $reasons = '<ul class="small" style="color:var(--t);padding-left:18px;margin:0">' . implode('', array_map(fn($x) => '<li>' . self::e($x) . '</li>',
            json_decode((string) $s['reasons'], true) ?: [])) . '</ul>';
        $size = '';
        if ($isTrade && !$locked && Api::isLive($s)) {
            $entry = (float) ($s['entry_price'] ?? $s['signal_price']);
            $stop = (float) ($s['live_stop'] ?? $s['stop_loss']);
            $riskAmt = (float) $st['account_size'] * (float) $st['risk_pct'] / 100;
            $per = max(0.0001, $entry - $stop);
            $sh = $riskAmt / $per;
            $shares = $st['fractional'] ? round($sh, 2) : floor($sh);
            $size = '<div class="card"><h2>Position size</h2><p class="small">Account ' . View::money((float) $st['account_size']) . ', risking ' . (float) $st['risk_pct'] . '% ('
                . View::money($riskAmt) . ') per trade. <a href="/app/settings">Change</a></p><dl class="kv"><dt>Shares to buy</dt><dd>' . $shares . '</dd><dt>Position value</dt><dd>'
                . View::money($shares * $entry) . '</dd><dt>Loss if stopped</dt><dd class="neg">' . View::money($shares * $per) . '</dd><dt>Gain at Target 2</dt><dd class="pos">'
                . View::money($shares * $per * 2) . '</dd></dl></div>';
        }
        $chart = '/app/chart.json?' . http_build_query(['symbol' => $s['symbol'], 'tf' => $s['tf'], 'signal' => $s['id']]);
        $title = '<div class="row" style="margin-bottom:14px"><a class="btn sm ghost" href="/app/signals">← Signals</a><h2 style="margin:0">'
            . ($isTrade ? 'Buy signal' : 'Bearish warning') . ' · <a href="/app/stock/' . rawurlencode($s['symbol']) . '">' . self::e($s['symbol']) . '</a></h2></div>';
        return $title . ($locked ? self::upsell($id) : '') . '<div class="split"><div class="stack"><div class="card"><div class="card-h"><h2 id="c1-name">' . self::e($s['symbol'])
            . '</h2><span class="xs muted"><span style="color:#F59E0B">━</span> EMA 50 <span style="color:#8B5CF6">━</span> EMA 200 ▲ buy ▼ warning</span></div><canvas id="c1" class="chart" data-chart="' . self::e($chart) . '"></canvas></div>'
            . '<div class="card"><h2>Why this signal</h2>' . $reasons . ($isTrade ? '<p class="xs dim" style="margin:12px 0 0">Rules: enter at the next open, place the stop immediately, take partial profit at Target 1 and move the stop to entry, close the rest at Target 2. Trades still open after ' . Engine::MAX_HOLD . ' bars are closed at the market.</p>' : '') . '</div></div>'
            . '<div class="stack"><div class="card"><h2>Levels</h2>' . $kv . '</div>' . $size . '</div></div>';
    }

    private static function results(int $id, Request $q): string
    {
        $st = self::settingsRow($id);
        $tf = in_array($q->str('tf', 2), ['1d', '1h'], true) ? $q->str('tf', 2) : $st['style'];
        $scope = $q->str('scope', 10) === 'watchlist' ? 'watchlist' : 'all';
        $rep = Performance::report($scope === 'watchlist' ? Api::watchlist($id) : null, $tf, (float) $st['risk_pct']);
        $link = fn(array $o) => '/app/results?' . http_build_query($o + ['tf' => $tf, 'scope' => $scope]);
        return '<div class="row" style="margin-bottom:16px">' . View::tabs(['1d' => ['Swing (daily)', $link(['tf' => '1d'])], '1h' => ['Intraday (hourly)', $link(['tf' => '1h'])]], $tf)
            . View::tabs(['all' => ['All stocks', $link(['scope' => 'all'])], 'watchlist' => ['My watchlist', $link(['scope' => 'watchlist'])]], $scope)
            . '<span class="small muted">Equity at your ' . (float) $st['risk_pct'] . '% risk per trade</span></div>'
            . WebPages::performanceBlock($rep, 'daily', $link([]) . '&p=');
    }

    private static function watchlist(int $id): string
    {
        $wl = Api::watchlist($id);
        $last = [];
        if ($wl) {
            $in = implode(',', array_fill(0, count($wl), '?'));
            foreach (Db::all("SELECT s.* FROM signals s JOIN (SELECT symbol, MAX(id) mid FROM signals WHERE symbol IN ($in) GROUP BY symbol) x ON x.mid = s.id", $wl) as $s) {
                $last[$s['symbol']] = $s;
            }
        }
        $rows = array_map(fn($sym) => [
            '<a href="/app/stock/' . rawurlencode($sym) . '"><span class="sym">' . self::e($sym) . '</span></a>',
            isset($last[$sym]) ? View::statusPill($last[$sym]) . ' <span class="xs dim">' . View::ago($last[$sym]['signal_time']) . '</span>' : '<span class="dim small">No signal yet</span>',
            '<div class="r">' . '<a class="btn sm ghost" href="/app/stock/' . rawurlencode($sym) . '">Chart</a> '
            . View::postButton('/app/watchlist/remove', 'Remove', 'btn sm ghost', ['symbol' => $sym]) . '</div>',
        ], $wl);
        return '<div class="split"><div class="card"><div class="card-h"><h2>Your stocks (' . count($wl) . '/60)</h2></div>'
            . View::table(['Stock', 'Latest signal', '>Actions'], $rows, 'Your watchlist is empty. Add a ticker to start.') . '</div>'
            . '<div class="card"><h2>Add a stock</h2><form method="post" action="/app/watchlist/add">' . View::csrf()
            . View::field('symbol', 'US ticker symbol', '', 'text', 'required maxlength="15" placeholder="e.g. AAPL" style="text-transform:uppercase"', 'US-listed stocks and ETFs. Scanned every hour in market hours.')
            . '<button class="btn block" type="submit">Add to watchlist</button></form>'
            . '<p class="xs dim" style="margin:14px 0 0">Alerts: you get signals for stocks on your watchlist' . (self::settingsRow($id)['all_signals'] ? ' and for all tracked stocks (turn off in Settings).' : '. Turn on "all tracked stocks" in Settings for more.') . '</p></div></div>';
    }

    private static function stock(int $id, string $sym, Request $q): string
    {
        if (!preg_match('/^[A-Z0-9.\-^=]{1,15}$/', $sym)) throw new ApiError(404, 'not_found', 'Unknown symbol.');
        $st = self::settingsRow($id);
        $tf = in_array($q->str('tf', 2), ['1d', '1h'], true) ? $q->str('tf', 2) : $st['style'];
        $rows = Db::all('SELECT * FROM signals WHERE symbol = ? AND tf = ? ORDER BY id DESC LIMIT 30', [$sym, $tf]);
        $inWl = in_array($sym, Api::watchlist($id), true);
        $chart = '/app/chart.json?' . http_build_query(['symbol' => $sym, 'tf' => $tf]);
        $btn = $inWl ? View::postButton('/app/watchlist/remove', '★ On watchlist · remove', 'btn sm ghost', ['symbol' => $sym])
            : View::postButton('/app/watchlist/add', '☆ Add to watchlist', 'btn sm', ['symbol' => $sym]);
        return '<div class="row" style="margin-bottom:16px">' . View::tabs(['1d' => ['Daily', "/app/stock/$sym?tf=1d"], '1h' => ['Hourly', "/app/stock/$sym?tf=1h"]], $tf) . $btn . '</div>'
            . '<div class="card"><div class="card-h"><h2 id="c2-name">' . self::e($sym) . '</h2><span class="xs muted"><span style="color:#F59E0B">━</span> EMA 50 <span style="color:#8B5CF6">━</span> EMA 200 ▲ buy ▼ warning</span></div>'
            . '<canvas id="c2" class="chart" data-chart="' . self::e($chart) . '"></canvas></div>'
            . '<div class="card"><h2>Signals on ' . self::e($sym) . '</h2>' . View::table(self::SIGNAL_HEAD, self::signalRows($rows, Premium::isActive($id)),
                'No signals on this stock yet. It is scanned while it is on a watchlist.') . '</div>';
    }

    /** Candles (cached), EMAs, signal markers, and levels for one signal (if unlocked). */
    private static function chart(int $id, Request $q): array
    {
        $sym = strtoupper($q->str('symbol', 15));
        $tf = $q->str('tf', 2) === '1h' ? '1h' : '1d';
        if (!preg_match('/^[A-Z0-9.\-^=]{1,15}$/', $sym)) throw new ApiError(422, 'invalid', 'Bad symbol.');
        $dir = __DIR__ . '/../storage/cache';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/chart_' . preg_replace('/[^A-Z0-9]/', '_', $sym) . "_$tf.json";
        $ttl = $tf === '1h' ? 300 : 900;
        $d = is_file($file) && filemtime($file) > time() - $ttl ? json_decode((string) file_get_contents($file), true) : null;
        if (!$d) {
            try {
                $d = Market::fetch($sym, $tf);
            } catch (\Throwable) {
                throw new ApiError(502, 'no_data', 'Price data is unavailable right now.');
            }
            @file_put_contents($file, json_encode($d));
        }
        $all = $d['candles'];
        $cl = array_column($all, 'c');
        $e50 = Engine::ema($cl, 50);
        $e200 = Engine::ema($cl, 200);
        $n = 160;
        $start = max(0, count($all) - $n);
        // For a specific signal, show the window around it rather than only the latest bars.
        if (($sid = $q->int('signal')) && ($st = Db::value('SELECT signal_time FROM signals WHERE id = ?', [$sid]))) {
            $ts = strtotime($st . ' UTC');
            foreach ($all as $i => $c) {
                if ($c['t'] >= $ts) { $start = max(0, min($start, $i - 110)); break; }
            }
        }
        $candles = array_slice($all, $start, $n);
        $round = fn(array $xs) => array_map(fn($v) => $v === null ? null : round($v, 4), array_slice($xs, $start, $n));
        $from = gmdate('Y-m-d H:i:s', $candles[0]['t'] ?? 0);
        $markers = array_map(fn($s) => ['t' => strtotime($s['signal_time'] . ' UTC'), 'buy' => $s['kind'] === 'trade'],
            Db::all('SELECT kind, signal_time FROM signals WHERE symbol = ? AND tf = ? AND signal_time >= ?', [$sym, $tf, $from]));
        $levels = [];
        if ($sid = $q->int('signal')) {
            $s = Db::one('SELECT * FROM signals WHERE id = ? AND symbol = ?', [$sid, $sym]);
            if ($s && $s['kind'] === 'trade' && !(Api::isLive($s) && !Premium::isActive($id))) {
                $v = fn($a, $b) => (float) ($s[$a] ?? $s[$b]);
                $levels = [['n' => 'Entry', 'v' => $v('entry_price', 'signal_price'), 'c' => '#E6EAF2'], ['n' => 'Stop', 'v' => $v('live_stop', 'stop_loss'), 'c' => '#F43F5E'],
                    ['n' => 'T1', 'v' => $v('live_target1', 'target1'), 'c' => '#4ADE80'], ['n' => 'T2', 'v' => $v('live_target2', 'target2'), 'c' => '#22C55E']];
                if ($s['exit_price'] !== null) $levels[] = ['n' => 'Exit', 'v' => (float) $s['exit_price'], 'c' => '#F59E0B'];
            }
        }
        return ['symbol' => $sym, 'name' => $d['name'] ?? $sym, 'tf' => $tf, 'candles' => $candles, 'ema50' => $round($e50), 'ema200' => $round($e200),
            'markers' => $markers, 'levels' => $levels];
    }

    private static function alerts(int $id): string
    {
        $rows = Db::all('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100', [$id]);
        $icon = ['new_buy' => '⚡', 'bearish' => '⚠️', 'target1' => '🎯', 'target' => '✅', 'stop' => '🛑', 'time' => '⏱', 'report' => '📊', 'system' => '💬'];
        $out = array_map(fn($n) => [
            '<span style="font-size:18px">' . ($icon[$n['kind']] ?? '•') . '</span>',
            ($n['read_at'] ? '' : '<span class="pill p" style="margin-right:6px">New</span>') . '<b>' . ($n['signal_id'] ? '<a href="/app/signals/' . (int) $n['signal_id'] . '">' . self::e($n['title']) . '</a>' : self::e($n['title']))
            . '</b><div class="small muted">' . self::e($n['body']) . '</div>',
            '<span class="xs dim nowrap">' . View::ago($n['created_at']) . ($n['pushed'] ? ' · push' : '') . ($n['emailed'] ? ' · email' : '') . '</span>',
        ], $rows);
        return '<div class="card">' . View::table(['', 'Alert', '>When'], $out, 'No alerts yet.') . '</div>';
    }

    private static function settings(int $id): string
    {
        $u = View::$user;
        $s = self::settingsRow($id);
        $profile = '<div class="card"><h2>Profile</h2><form method="post" action="/app/settings/profile">' . View::csrf()
            . View::field('name', 'Name', (string) $u['name'], 'text', 'maxlength="120"')
            . '<div class="field"><label>Email</label><input value="' . self::e($u['email']) . '" disabled><div class="help">'
            . ($u['email_verified_at'] ? '✓ Confirmed' : 'Not confirmed yet') . ($u['google_sub'] ? ' · Google linked' : '') . '</div></div>'
            . '<button class="btn" type="submit">Save profile</button></form>'
            . ($u['email_verified_at'] ? '' : '<div style="margin-top:12px">' . View::postButton('/app/settings/verify', 'Resend confirmation email') . '</div>') . '</div>';
        $trading = '<div class="card"><h2>Trading & alerts</h2><form method="post" action="/app/settings/trading">' . View::csrf()
            . '<div class="grid g2">' . View::field('account_size', 'Account size (USD)', (string) (float) $s['account_size'], 'number', 'min="1" step="any"')
            . View::field('risk_pct', 'Risk per trade (%)', (string) (float) $s['risk_pct'], 'number', 'min="0.1" max="10" step="0.1"', '1% is a common choice.') . '</div>'
            . View::select('style', 'Trading style', ['1d' => 'Swing: daily candles, hold days to weeks', '1h' => 'Intraday: hourly candles, hold hours to days'], $s['style'],
                'You receive alerts for this timeframe.')
            . View::checkbox('fractional', 'Fractional shares', (bool) $s['fractional'], 'Position sizes can include decimals.')
            . View::checkbox('all_signals', 'Alerts for all tracked stocks', (bool) $s['all_signals'], 'Off = only your watchlist.')
            . View::checkbox('push_signals', 'Push: new signals', (bool) $s['push_signals'])
            . View::checkbox('push_results', 'Push: targets, stops and closes', (bool) $s['push_results'])
            . View::checkbox('email_signals', 'Email: new signals', (bool) $s['email_signals'])
            . View::checkbox('email_results', 'Email: trade results', (bool) $s['email_results'])
            . View::checkbox('report_daily', 'Daily report email', (bool) $s['report_daily'])
            . View::checkbox('report_weekly', 'Weekly report email', (bool) $s['report_weekly'])
            . View::checkbox('report_monthly', 'Monthly report email', (bool) $s['report_monthly'])
            . '<button class="btn" type="submit" style="margin-top:14px">Save settings</button></form></div>';
        $pw = '<div class="card"><h2>' . ($u['password_hash'] ? 'Change password' : 'Set a password') . '</h2><form method="post" action="/app/settings/password">' . View::csrf()
            . ($u['password_hash'] ? View::field('current_password', 'Current password', '', 'password', 'required autocomplete="current-password"') : '<p class="small">You sign in with Google. Add a password to also log in with email.</p>')
            . View::field('new_password', 'New password', '', 'password', 'required minlength="8" autocomplete="new-password"') . '<button class="btn" type="submit">Save password</button></form></div>';
        $del = '<div class="card" style="border-color:rgba(244,63,94,.4)"><h2>Delete account</h2><p class="small">Permanently erases your account and all its data, on the website and in the app.</p>'
            . '<form method="post" action="/app/settings/delete" data-confirm="This cannot be undone. Delete your account?">' . View::csrf()
            . View::field('confirm', 'Type DELETE to confirm', '', 'text', 'required') . '<button class="btn danger" type="submit">Delete my account</button></form></div>';
        return '<div class="split"><div class="stack">' . $trading . '</div><div class="stack">' . $profile . $pw . $del . '</div></div>';
    }

    private static function billing(int $id): string
    {
        $st = Premium::status($id);
        $plans = Plans::active();
        if (!$st['enabled']) return '<div class="card c"><h2>Everything is free right now 🎉</h2><p>All signal levels are unlocked for every account.</p></div>';
        $status = $st['active']
            ? '<div class="alert ok">💎 Premium is active until <b>' . View::date(str_replace(['T', 'Z'], [' ', ''], (string) $st['until'])) . '</b>'
                . ($st['source'] === 'google' ? ($st['auto_renewing'] ? ' and renews automatically through Google Play.' : ' (Google Play subscription, not renewing).') : '.')
                . ' Buying again adds time on top.</div>'
            : '<div class="alert info">You\'re on the free plan. Every signal and result is visible; Premium unlocks exact levels on live signals.</div>';
        $cards = '';
        foreach ($plans as $p) {
            $per = (int) $p['interval_months'] === 1 ? 'month' : $p['interval_months'] . ' months';
            $cards .= '<div class="card price' . ($p['badge'] ? ' best' : '') . '">' . ($p['badge'] ? '<span class="pill a badge">' . self::e($p['badge']) . '</span>' : '')
                . '<h3>' . self::e($p['name']) . '</h3><div class="amt">' . self::e(WebPages::priceLabel($p)) . ' <small>/ ' . $per . '</small></div>'
                . '<ul>' . implode('', array_map(fn($x) => '<li>' . self::e($x) . '</li>', Api::premiumFeatures())) . '</ul>'
                . '<form method="post" action="/app/billing/checkout">' . View::csrf() . '<input type="hidden" name="plan" value="' . self::e($p['code'])
                . '"><button class="btn block" type="submit">' . ($st['active'] ? 'Extend' : 'Get') . ' ' . self::e($p['name']) . '</button></form></div>';
        }
        $pays = Db::all('SELECT p.*, pl.name plan_name FROM payments p LEFT JOIN plans pl ON pl.id = p.plan_id WHERE p.user_id = ? AND p.status <> "pending" ORDER BY p.id DESC LIMIT 30', [$id]);
        $rows = array_map(fn($x) => [View::date($x['created_at']), self::e($x['plan_name'] ?? '—'), self::e(ucfirst($x['provider'])),
            View::amount((float) $x['amount'], $x['currency']), View::pill($x['status'], ['success' => 'g', 'failed' => 'r', 'refunded' => 'a'][$x['status']] ?? ''),
            '<span class="mono xs">' . self::e($x['provider_ref']) . '</span>'], $pays);
        return $status . '<div class="grid g2">' . $cards . '</div><p class="xs dim" style="margin:12px 0 18px">Secure checkout by Paystack (card, bank transfer, USSD). One-time payment, no auto-renewal. '
            . 'On Android you can also subscribe in the app through Google Play.</p>'
            . '<div class="card"><h2>Payment history</h2>' . View::table(['Date', 'Plan', 'Method', 'Amount', 'Status', 'Reference'], $rows, 'No payments yet.') . '</div>';
    }

    private static function affiliate(int $id): string
    {
        $aff = Affiliates::get($id);
        $code = Affiliates::codeFor($id);
        $link = Urls::site('/r/' . $code);
        $rate = (float) Settings::num('commission_rate');
        if (!$aff) {
            if (!Settings::on('affiliate_enabled')) return '<div class="card"><h2>Affiliate program</h2><p>The program is closed to new applications right now.</p></div>';
            return '<div class="split"><div class="card"><h2>Join the affiliate program</h2><p>' . (View::free() ? 'Earn ' : 'Earn ' . $rate . '% of Premium payments from people you refer and ')
                . (float) Settings::num('broker_share_pct') . '% of broker commissions' . (View::free() ? ' from people you refer' : '') . '. <a href="/affiliate">Program details</a></p>'
                . '<form method="post" action="/app/affiliate/apply">' . View::csrf() . '<div class="field"><label for="pp">Where will you promote us?</label>'
                . '<textarea id="pp" name="promo_plan" maxlength="500" required placeholder="e.g. My Telegram channel (5k members) about US stocks, a YouTube channel, a blog…"></textarea></div>'
                . '<label class="check" style="border:0"><input type="checkbox" required><span>I agree to the affiliate rules in the <a href="/terms" target="_blank">Terms</a>: no guaranteed-profit claims, no spam, no self-referrals.</span></label>'
                . '<button class="btn" type="submit" style="margin-top:10px">Apply now</button></form></div>'
                . '<div class="card"><h2>Your referral code</h2><p class="small">Even before approval, friends can sign up with your code. Commissions start once you\'re approved.</p>'
                . View::copyBox('reflink', $link) . '<p class="small" style="margin-top:10px">Code: <b class="mono">' . self::e($code) . '</b></p></div></div>';
        }
        if ($aff['status'] !== 'approved') {
            $msg = ['pending' => 'Your application is being reviewed. We usually reply within 48 hours.', 'rejected' => 'Your application was not approved.' . ($aff['admin_note'] ? ' Note: ' . $aff['admin_note'] : ''),
                'suspended' => 'Your affiliate account is suspended.' . ($aff['admin_note'] ? ' Note: ' . $aff['admin_note'] : '')][$aff['status']];
            return '<div class="alert ' . ($aff['status'] === 'pending' ? 'info' : 'warn') . '">' . self::e($msg) . '</div><div class="card"><h2>Your referral link</h2>' . View::copyBox('reflink', $link) . '</div>';
        }
        $s = Affiliates::summary($id);
        $myRate = Affiliates::rateFor($id);
        $tiles = '<div class="grid g4">' . View::tile('Available', View::money($s['available']), 'withdrawable now', 'pos')
            . View::tile('Pending', View::money($s['pending']), 'in ' . (int) Settings::num('commission_hold_days') . '-day refund window')
            . View::tile('Paid out', View::money($s['paid']), View::money($s['in_payout']) . ' being processed')
            . View::tile('Lifetime earnings', View::money($s['lifetime']), (View::free() ? 'broker commissions' : $myRate . '% Premium commission')) . '</div>';
        $funnel = '<div class="grid g4" style="margin-top:16px">' . View::tile('Clicks (30 days)', (string) $s['clicks_30d'], $s['clicks_all'] . ' all time')
            . View::tile('Sign-ups', (string) $s['signups'], 'referred accounts') . View::tile('Premium customers', (string) $s['premium_customers'], $s['signups'] ? round($s['premium_customers'] / $s['signups'] * 100, 1) . '% conversion' : '—')
            . View::tile('Broker clicks', (string) $s['broker_clicks'], 'via your links') . '</div>';
        $brokerLinks = '';
        foreach (Brokers::active() as $b) {
            $brokerLinks .= '<div class="field"><label>' . self::e($b['name']) . '</label>' . View::copyBox('b_' . $b['id'], Urls::site('/go/' . $b['slug'] . '?ref=' . $code)) . '</div>';
        }
        $links = '<div class="card"><h2>Your links</h2><div class="field"><label>Homepage</label>' . View::copyBox('reflink', $link) . '</div>'
            . (View::free() ? '' : '<div class="field"><label>Pricing page</label>' . View::copyBox('reflink2', $link . '?to=%2Fpricing') . '</div>')
            . '<div class="field"><label>Track record (best for social posts)</label>' . View::copyBox('reflink3', $link . '?to=%2Frecord') . '</div>'
            . '<p class="small">App users can enter your code <b class="mono">' . self::e($code) . '</b> when they sign up.</p>' . $brokerLinks . '</div>';
        $methods = array_filter(array_map('trim', explode(',', Settings::get('payout_methods'))));
        $labels = ['bank' => 'Bank transfer (NGN)', 'usdt' => 'USDT (crypto)', 'paypal' => 'PayPal'];
        $d = json_decode((string) $aff['payout_details'], true) ?: [];
        $opts = [];
        foreach ($methods as $m) $opts[$m] = $labels[$m] ?? $m;
        $payForm = '<div class="card"><h2>Payout details</h2><form method="post" action="/app/affiliate/payout-details">' . View::csrf()
            . View::select('method', 'Method', $opts, (string) $aff['payout_method'])
            . (in_array('bank', $methods, true) ? '<div class="grid g3">' . View::field('bank_name', 'Bank', $d['bank_name'] ?? '') . View::field('account_name', 'Account name', $d['account_name'] ?? '')
                . View::field('account_number', 'Account number', $d['account_number'] ?? '') . '</div>' : '')
            . (in_array('usdt', $methods, true) ? '<div class="grid g2">' . View::field('usdt_address', 'USDT address', $d['usdt_address'] ?? '') . View::field('usdt_network', 'Network (e.g. TRC20)', $d['usdt_network'] ?? '') . '</div>' : '')
            . (in_array('paypal', $methods, true) ? View::field('paypal_email', 'PayPal email', $d['paypal_email'] ?? '', 'email') : '')
            . '<p class="help" style="margin-top:-6px">Fill in the fields for the method you choose.</p><button class="btn" type="submit">Save payout details</button></form>'
            . '<hr style="border:0;border-top:1px solid var(--b);margin:18px 0"><div class="row"><div class="grow small">Minimum payout ' . View::money($s['min_payout']) . '. Available: <b class="pos">' . View::money($s['available']) . '</b></div>'
            . View::postButton('/app/affiliate/payout', 'Request payout', 'btn', [], 'Request a payout of your available balance?') . '</div></div>';
        $com = Db::all('SELECT c.*, u.email ref_email, b.name broker FROM commissions c LEFT JOIN users u ON u.id = c.referred_user_id LEFT JOIN brokers b ON b.id = c.broker_id
                        WHERE c.affiliate_user_id = ? ORDER BY c.id DESC LIMIT 50', [$id]);
        $mask = fn(?string $e) => $e ? substr($e, 0, 2) . '•••@' . (explode('@', $e)[1] ?? '') : '—';
        $tone = ['pending' => 'a', 'approved' => 'g', 'paid' => 'p', 'reversed' => 'r', 'rejected' => 'r'];
        $comRows = array_map(fn($c) => [View::date($c['created_at']), self::e(ucfirst($c['source'])) . ($c['broker'] ? ' · ' . self::e($c['broker']) : ''),
            self::e($mask($c['ref_email'])), self::e($c['note']), View::pill($c['status'], $tone[$c['status']] ?? ''),
            '<b class="' . ((float) $c['amount_usd'] < 0 ? 'neg' : '') . '">' . View::money((float) $c['amount_usd']) . '</b>'], $com);
        $pays = Db::all('SELECT * FROM payouts WHERE affiliate_user_id = ? ORDER BY id DESC LIMIT 20', [$id]);
        $payRows = array_map(fn($p) => [View::date($p['requested_at']), View::money((float) $p['amount_usd']), self::e(strtoupper($p['method'])),
            View::pill($p['status'], ['requested' => 'a', 'paid' => 'g', 'rejected' => 'r'][$p['status']]), self::e($p['txn_ref'] ?: $p['admin_note'] ?: '—')], $pays);
        return $tiles . $funnel . '<div class="split" style="margin-top:16px"><div class="stack">' . $links . '<div class="card"><h2>Commissions</h2>'
            . View::table(['Date', 'Source', 'Customer', 'Note', 'Status', '>Amount'], $comRows, 'No commissions yet. Share your link to get started.') . '</div></div>'
            . '<div class="stack">' . $payForm . '<div class="card"><h2>Payouts</h2>' . View::table(['Requested', 'Amount', 'Method', 'Status', 'Ref'], $payRows, 'No payouts yet.') . '</div></div></div>';
    }
}
