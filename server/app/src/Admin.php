<?php
declare(strict_types=1);

namespace SignalLab;

/** Admin panel at /admin. Every change is written to the audit log. */
final class Admin
{
    private static function e(mixed $s): string { return View::e($s); }

    public static function log(string $action, string $target = '', array $details = [], string $ip = ''): void
    {
        Db::insert('admin_log', ['admin_id' => View::$user['id'] ?? null, 'action' => $action, 'target' => mb_substr($target, 0, 120),
            'details' => json_encode($details, JSON_UNESCAPED_UNICODE), 'ip' => $ip, 'created_at' => Db::now()]);
    }

    public static function routes(Router $r): void
    {
        $page = fn(string $a, string $t, string $b, string $act = '') => Response::html(View::adminPage($a, $t, $b, $act));
        $done = function (string $to, string $msg, string $type = 'ok'): Response {
            View::$session->flash($type, $msg);
            return Response::redirect($to);
        };
        $act = function (Request $q, string $to, callable $fn) use ($done): Response {
            Web::admin($q);
            try {
                return $done($to, (string) $fn());
            } catch (ApiError $e) {
                if ($e->status === 419) throw $e;
                return $done($to, $e->getMessage(), 'err');
            }
        };

        $r->add('GET', '/admin', function (Request $q) use ($page) { Web::admin($q); return $page('overview', 'Overview', self::overview()); });

        // ---------- users
        $r->add('GET', '/admin/users', function (Request $q) use ($page) { Web::admin($q); return $page('users', 'Users', self::users($q)); });
        $r->add('GET', '/admin/users/{id}', function (Request $q, array $p) use ($page) { Web::admin($q); return $page('users', 'User #' . (int) $p['id'], self::user((int) $p['id'])); });
        $r->add('POST', '/admin/users/{id}/premium', fn(Request $q, array $p) => $act($q, '/admin/users/' . (int) $p['id'], function () use ($q, $p) {
            $id = (int) $p['id'];
            $days = $q->int('days');
            if ($q->str('mode', 10) === 'revoke') {
                Premium::setUntil($id, null);
                self::log('premium.revoke', "user:$id", [], $q->ip);
                return 'Premium removed.';
            }
            if ($days < 1 || $days > 3660) throw new ApiError(422, 'invalid', 'Enter 1–3660 days.');
            $cur = Db::value('SELECT premium_until FROM users WHERE id = ?', [$id]);
            $base = $cur && $cur > Db::now() ? new \DateTimeImmutable($cur, new \DateTimeZone('UTC')) : Clock::now();
            $until = $base->modify("+$days day")->format('Y-m-d H:i:s');
            Premium::setUntil($id, $until);
            self::log('premium.grant', "user:$id", ['days' => $days, 'until' => $until], $q->ip);
            return "Premium extended to " . Fmt::date($until) . '.';
        }));
        $r->add('POST', '/admin/users/{id}/status', fn(Request $q, array $p) => $act($q, '/admin/users/' . (int) $p['id'], function () use ($q, $p) {
            $id = (int) $p['id'];
            if ($id === (int) View::$user['id']) throw new ApiError(422, 'self', 'You cannot change your own account here.');
            $s = $q->str('status', 10) === 'suspended' ? 'suspended' : 'active';
            Db::exec('UPDATE users SET status = ? WHERE id = ?', [$s, $id]);
            if ($s === 'suspended') {
                Db::exec('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Db::now(), $id]);
                Db::exec('DELETE FROM web_sessions WHERE user_id = ?', [$id]);
            }
            self::log("user.$s", "user:$id", [], $q->ip);
            return $s === 'suspended' ? 'User suspended and signed out everywhere.' : 'User re-activated.';
        }));
        $r->add('POST', '/admin/users/{id}/role', fn(Request $q, array $p) => $act($q, '/admin/users/' . (int) $p['id'], function () use ($q, $p) {
            $id = (int) $p['id'];
            if ($id === (int) View::$user['id']) throw new ApiError(422, 'self', 'You cannot change your own role.');
            $role = $q->str('role', 10) === 'admin' ? 'admin' : 'user';
            Db::exec('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
            self::log('user.role', "user:$id", ['role' => $role], $q->ip);
            return "Role set to $role.";
        }));
        $r->add('POST', '/admin/users/{id}/verify', fn(Request $q, array $p) => $act($q, '/admin/users/' . (int) $p['id'], function () use ($q, $p) {
            Db::exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [Db::now(), (int) $p['id']]);
            self::log('user.verify_email', 'user:' . (int) $p['id'], [], $q->ip);
            return 'Email marked as confirmed.';
        }));
        $r->add('POST', '/admin/users/{id}/delete', function (Request $q, array $p) use ($done) {
            Web::admin($q);
            $id = (int) $p['id'];
            if ($id === (int) View::$user['id']) return $done('/admin/users/' . $id, 'You cannot delete your own account here.', 'err');
            $email = Db::value('SELECT email FROM users WHERE id = ?', [$id]);
            Auth::deleteAccount($id);
            self::log('user.delete', "user:$id", ['email' => $email], $q->ip);
            return $done('/admin/users', 'User deleted.');
        });
        $r->add('POST', '/admin/users/{id}/notify', fn(Request $q, array $p) => $act($q, '/admin/users/' . (int) $p['id'], function () use ($q, $p) {
            $t = $q->str('title', 120);
            $b = $q->str('body', 500);
            if ($t === '' || $b === '') throw new ApiError(422, 'invalid', 'Title and message are required.');
            Notifier::system((int) $p['id'], $t, $b);
            self::log('user.notify', 'user:' . (int) $p['id'], ['title' => $t], $q->ip);
            return 'Message sent (inbox, push, and email if confirmed).';
        }));

        // ---------- payments & plans
        $r->add('GET', '/admin/payments', function (Request $q) use ($page) { Web::admin($q); return $page('payments', 'Payments', self::payments($q)); });
        $r->add('POST', '/admin/payments/{id}/refund', fn(Request $q, array $p) => $act($q, '/admin/payments', function () use ($q, $p) {
            Billing::refund((int) $p['id'], $q->str('note', 200) ?: 'Refunded by admin');
            self::log('payment.refund', 'payment:' . (int) $p['id'], ['note' => $q->str('note', 200)], $q->ip);
            return 'Payment marked refunded. Premium time and unpaid commission reversed. Remember to refund the money in Paystack / Play Console.';
        }));
        $r->add('POST', '/admin/payments/{id}/verify', fn(Request $q, array $p) => $act($q, '/admin/payments', function () use ($q, $p) {
            $ref = (string) Db::value("SELECT provider_ref FROM payments WHERE id = ? AND provider = 'paystack'", [(int) $p['id']]);
            $ok = $ref !== '' && Billing::paystackVerify($ref);
            self::log('payment.reverify', 'payment:' . (int) $p['id'], ['ok' => $ok], $q->ip);
            return $ok ? 'Paystack confirmed the payment. Premium activated.' : 'Paystack has not confirmed this payment.';
        }));
        $r->add('GET', '/admin/plans', function (Request $q) use ($page) { Web::admin($q); return $page('plans', 'Plans & pricing', self::plans()); });
        $r->add('POST', '/admin/plans/{id}', fn(Request $q, array $p) => $act($q, '/admin/plans', function () use ($q, $p) {
            $upd = ['name' => $q->str('name', 60), 'price_usd' => max(0, (float) $q->str('price_usd', 20)), 'price_ngn' => max(0, (float) $q->str('price_ngn', 20)),
                'google_product_id' => $q->str('google_product_id', 100), 'badge' => $q->str('badge', 30), 'active' => ($q->body['active'] ?? '0') === '1' ? 1 : 0,
                'interval_months' => max(1, min(36, $q->int('interval_months', 1)))];
            if ($upd['name'] === '') throw new ApiError(422, 'invalid', 'Name is required.');
            Db::update('plans', $upd, 'id = ?', [(int) $p['id']]);
            self::log('plan.update', 'plan:' . (int) $p['id'], $upd, $q->ip);
            return 'Plan saved.';
        }));

        // ---------- affiliates, commissions, payouts
        $r->add('GET', '/admin/affiliates', function (Request $q) use ($page) { Web::admin($q); return $page('affiliates', 'Affiliates', self::affiliates($q)); });
        $r->add('POST', '/admin/affiliates/{id}', fn(Request $q, array $p) => $act($q, '/admin/affiliates?status=' . rawurlencode($q->str('back', 20)), function () use ($q, $p) {
            $id = (int) $p['id'];
            $status = $q->str('status', 12);
            if ($status !== '') Affiliates::setStatus($id, $status, $q->str('note', 255));
            if (array_key_exists('rate', $q->body)) {
                $rate = trim((string) $q->body['rate']);
                Db::exec('UPDATE affiliates SET commission_rate = ? WHERE user_id = ?', [$rate === '' ? null : max(0, min(100, (float) $rate)), $id]);
            }
            self::log('affiliate.update', "user:$id", ['status' => $status, 'rate' => $q->body['rate'] ?? null], $q->ip);
            if ($status === 'approved') Notifier::system($id, 'Affiliate account approved', 'You can now earn commissions. Open the Affiliate page on the website to get your links.');
            return 'Affiliate updated.';
        }));
        $r->add('GET', '/admin/commissions', function (Request $q) use ($page) { Web::admin($q); return $page('commissions', 'Commissions', self::commissions($q)); });
        $r->add('POST', '/admin/commissions/adjust', fn(Request $q) => $act($q, '/admin/commissions', function () use ($q) {
            $uid = (int) Db::value('SELECT user_id FROM affiliates a JOIN users u ON u.id = a.user_id WHERE u.email = ? OR u.referral_code = ?',
                [strtolower($q->str('affiliate', 190)), strtoupper($q->str('affiliate', 190))]);
            if (!$uid) throw new ApiError(404, 'not_found', 'No affiliate with that email or code.');
            $amt = (float) $q->str('amount', 20);
            if ($amt == 0.0) throw new ApiError(422, 'invalid', 'Enter a non-zero amount.');
            Affiliates::adjust($uid, $amt, $q->str('note', 200) ?: 'Manual adjustment');
            self::log('commission.adjust', "user:$uid", ['amount' => $amt, 'note' => $q->str('note', 200)], $q->ip);
            return 'Adjustment of ' . View::money($amt) . ' added.';
        }));
        $r->add('POST', '/admin/commissions/{id}/reject', fn(Request $q, array $p) => $act($q, '/admin/commissions', function () use ($q, $p) {
            $n = Db::exec("UPDATE commissions SET status = 'rejected', note = ? WHERE id = ? AND status IN ('pending','approved') AND payout_id IS NULL",
                [$q->str('note', 200) ?: 'Rejected by admin', (int) $p['id']]);
            if (!$n) throw new ApiError(422, 'invalid', 'Only unpaid commissions that are not in a payout can be rejected.');
            self::log('commission.reject', 'commission:' . (int) $p['id'], [], $q->ip);
            return 'Commission rejected.';
        }));
        $r->add('POST', '/admin/commissions/release', fn(Request $q) => $act($q, '/admin/commissions', function () use ($q) {
            $n = Affiliates::approveDue();
            self::log('commission.release', '', ['count' => $n], $q->ip);
            return "$n commission(s) past the hold period released.";
        }));
        $r->add('GET', '/admin/payouts', function (Request $q) use ($page) { Web::admin($q); return $page('payouts', 'Payouts', self::payouts($q)); });
        $r->add('POST', '/admin/payouts/{id}', fn(Request $q, array $p) => $act($q, '/admin/payouts', function () use ($q, $p) {
            $status = $q->str('status', 10);
            Affiliates::markPayout((int) $p['id'], $status, $q->str('txn_ref', 120), $q->str('note', 255));
            self::log("payout.$status", 'payout:' . (int) $p['id'], ['txn_ref' => $q->str('txn_ref', 120)], $q->ip);
            return $status === 'paid' ? 'Payout marked as paid. The affiliate was notified.' : 'Payout rejected; the balance is available to the affiliate again.';
        }));

        // ---------- brokers
        $r->add('GET', '/admin/brokers', function (Request $q) use ($page) { Web::admin($q); return $page('brokers', 'Brokers', self::brokers($q)); });
        $r->add('POST', '/admin/brokers/save', fn(Request $q) => $act($q, '/admin/brokers', function () use ($q) {
            $id = $q->int('id');
            $row = ['slug' => strtolower(preg_replace('/[^a-z0-9\-]/i', '', $q->str('slug', 40))), 'name' => $q->str('name', 80), 'logo_url' => $q->str('logo_url', 255),
                'tagline' => $q->str('tagline', 160), 'description' => $q->str('description', 2000), 'url_template' => $q->str('url_template', 500),
                'min_deposit' => $q->str('min_deposit', 40), 'regions' => $q->str('regions', 120), 'active' => ($q->body['active'] ?? '0') === '1' ? 1 : 0, 'sort' => $q->int('sort')];
            if ($row['slug'] === '' || $row['name'] === '' || !preg_match('#^https://#', $row['url_template'])) {
                throw new ApiError(422, 'invalid', 'Slug, name and an https:// affiliate URL are required.');
            }
            if ($row['logo_url'] !== '' && !preg_match('#^https://#', $row['logo_url'])) throw new ApiError(422, 'invalid', 'Logo URL must start with https://');
            try {
                if ($id) Db::update('brokers', $row, 'id = ?', [$id]);
                else $id = Db::insert('brokers', $row + ['created_at' => Db::now()]);
            } catch (\PDOException) {
                throw new ApiError(409, 'duplicate', 'That slug is already used by another broker.');
            }
            self::log('broker.save', "broker:$id", $row, $q->ip);
            return 'Broker saved.';
        }));
        $r->add('POST', '/admin/brokers/conversion', fn(Request $q) => $act($q, '/admin/brokers', function () use ($q) {
            $amt = (float) $q->str('amount', 20);
            if ($amt <= 0) throw new ApiError(422, 'invalid', 'Enter the commission amount the broker paid you (USD).');
            $res = Brokers::recordConversion($q->str('sub_id', 40), $amt, $q->str('note', 200));
            self::log('broker.conversion', 'sub:' . $q->str('sub_id', 40), ['amount' => $amt] + $res, $q->ip);
            return $res['credited'] ? 'Conversion recorded: affiliate credited ' . View::money($res['amount_usd']) . '.' : 'Recorded, no affiliate credited: ' . $res['reason'];
        }));

        // ---------- signals, settings, log
        $r->add('GET', '/admin/signals', function (Request $q) use ($page) { Web::admin($q); return $page('signals', 'Signals & scanner', self::signals($q)); });
        $r->add('POST', '/admin/signals/scan', fn(Request $q) => $act($q, '/admin/signals', function () use ($q) {
            @set_time_limit(600);
            $res = Tracker::run();
            self::log('scanner.run', '', $res, $q->ip);
            return 'Scan finished: ' . json_encode($res);
        }));
        $r->add('POST', '/admin/signals/mail', fn(Request $q) => $act($q, '/admin/signals', function () use ($q) {
            $res = Mailer::processQueue();
            self::log('mail.process', '', ['result' => $res], $q->ip);
            return 'Email queue processed: ' . json_encode($res);
        }));
        $r->add('GET', '/admin/settings', function (Request $q) use ($page) { Web::admin($q); return $page('settings', 'Settings', self::settings()); });
        $r->add('POST', '/admin/settings', fn(Request $q) => $act($q, '/admin/settings', function () use ($q) {
            $changed = [];
            foreach (array_keys(Settings::DEFAULTS) as $k) {
                if (!array_key_exists($k, $q->body)) continue;
                $v = mb_substr(trim((string) $q->body[$k]), 0, 500);
                if (in_array($k, self::NUMERIC, true) && !is_numeric($v)) throw new ApiError(422, 'invalid', "$k must be a number.");
                if ($k === 'checkout_currency' && !in_array($v, ['NGN', 'USD'], true)) throw new ApiError(422, 'invalid', 'Currency must be NGN or USD.');
                if ($v !== Settings::get($k)) { Settings::set($k, $v); $changed[$k] = $v; }
            }
            self::log('settings.update', '', $changed, $q->ip);
            return $changed ? 'Settings saved.' : 'No changes.';
        }));
        $r->add('GET', '/admin/log', function (Request $q) use ($page) { Web::admin($q); return $page('log', 'Audit log', self::auditLog($q)); });
    }

    private const NUMERIC = ['usd_ngn_rate', 'google_fee_pct', 'commission_rate', 'commission_hold_days', 'cookie_days', 'min_payout_usd', 'broker_share_pct'];

    private static function page(Request $q): array
    {
        $p = max(1, $q->int('page', 1));
        return [$p, 40, ($p - 1) * 40];
    }

    private static function userLink(?int $id, ?string $email): string
    {
        return $id ? '<a href="/admin/users/' . $id . '">' . self::e($email ?: "#$id") . '</a>' : '<span class="dim">—</span>';
    }

    // ------------------------------------------------------------------ pages

    private static function overview(): string
    {
        $v = fn(string $sql, array $p = []) => Db::value($sql, $p);
        $now = Db::now();
        $d30 = Clock::now()->modify('-30 day')->format('Y-m-d H:i:s');
        $d1 = Clock::now()->modify('-1 day')->format('Y-m-d H:i:s');
        $users = (int) $v('SELECT COUNT(*) FROM users');
        $new30 = (int) $v('SELECT COUNT(*) FROM users WHERE created_at > ?', [$d30]);
        $prem = (int) $v('SELECT COUNT(*) FROM users WHERE premium_until > ?', [$now]);
        $rev30 = (float) $v("SELECT COALESCE(SUM(amount_usd),0) FROM payments WHERE status = 'success' AND created_at > ?", [$d30]);
        $revAll = (float) $v("SELECT COALESCE(SUM(amount_usd),0) FROM payments WHERE status = 'success'");
        $owed = (float) $v("SELECT COALESCE(SUM(amount_usd),0) FROM commissions WHERE status IN ('pending','approved')");
        $pendAff = (int) $v("SELECT COUNT(*) FROM affiliates WHERE status = 'pending'");
        $reqPay = (int) $v("SELECT COUNT(*) FROM payouts WHERE status = 'requested'");
        $reqAmt = (float) $v("SELECT COALESCE(SUM(amount_usd),0) FROM payouts WHERE status = 'requested'");
        $open = (int) $v("SELECT COUNT(*) FROM signals WHERE status IN ('pending','open')");
        $sig24 = (int) $v('SELECT COUNT(*) FROM signals WHERE created_at > ?', [$d1]);
        $scan = Db::one('SELECT * FROM scan_log ORDER BY id DESC LIMIT 1');
        $mailQ = (int) $v("SELECT COUNT(*) FROM email_queue WHERE sent_at IS NULL");
        $devices = (int) $v('SELECT COUNT(*) FROM devices');
        $web = (int) $v("SELECT COUNT(*) FROM users WHERE signup_source = 'web'");
        $todo = [];
        if ($pendAff) $todo[] = '<a href="/admin/affiliates?status=pending">' . $pendAff . ' affiliate application(s) to review</a>';
        if ($reqPay) $todo[] = '<a href="/admin/payouts">' . $reqPay . ' payout request(s) · ' . View::money($reqAmt) . '</a>';
        if (!Config::get('paystack.secret_key')) $todo[] = 'Add your Paystack secret key to config.php to accept web payments';
        if (!$scan || strtotime($scan['started_at'] . ' UTC') < Clock::ts() - 3 * 86400) $todo[] = 'Scanner has not run in 3+ days: check the cron job';
        $todoHtml = $todo ? '<div class="alert warn">' . implode('<br>', $todo) . '</div>' : '<div class="alert ok">All caught up. Nothing needs your attention.</div>';
        $recentPays = Db::all("SELECT p.*, u.email FROM payments p LEFT JOIN users u ON u.id = p.user_id WHERE p.status <> 'pending' ORDER BY p.id DESC LIMIT 8");
        $pr = array_map(fn($p) => [View::ago($p['created_at']), self::userLink((int) $p['user_id'], $p['email']), self::e(ucfirst($p['provider'])),
            View::amount((float) $p['amount'], $p['currency']), View::pill($p['status'], ['success' => 'g', 'refunded' => 'a', 'failed' => 'r'][$p['status']] ?? '')], $recentPays);
        $recentUsers = Db::all('SELECT id, email, name, created_at, signup_source, referred_by FROM users ORDER BY id DESC LIMIT 8');
        $ur = array_map(fn($u) => [View::ago($u['created_at']), self::userLink((int) $u['id'], $u['email']), self::e($u['signup_source']), $u['referred_by'] ? View::pill('referred', 'v') : ''], $recentUsers);
        $daily = Db::all("SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE created_at > ? GROUP BY DATE(created_at) ORDER BY d", [$d30]);
        $byDay = array_column($daily, 'n', 'd');
        $series = [];
        for ($i = 29; $i >= 0; $i--) $series[] = (int) ($byDay[Clock::now()->modify("-$i day")->format('Y-m-d')] ?? 0);
        return $todoHtml . '<div class="grid g4">' . View::tile('Users', number_format($users), "+$new30 in 30 days · $web via web")
            . View::tile('Premium active', number_format($prem), $users ? round($prem / $users * 100, 1) . '% of users' : '—', 'warn')
            . View::tile('Revenue (30 days)', View::money($rev30), View::money($revAll) . ' all time', 'pos')
            . View::tile('Affiliate balance owed', View::money($owed), 'pending + approved, unpaid') . '</div>'
            . '<div class="grid g4" style="margin-top:16px">' . View::tile('Open trades', (string) $open, "$sig24 signals in 24h")
            . View::tile('Last scan', $scan ? View::ago($scan['started_at']) : 'never', $scan ? $scan['series'] . ' series · ' . $scan['new_signals'] . ' new' : 'set up cron')
            . View::tile('Email queue', (string) $mailQ, 'unsent emails') . View::tile('Push devices', (string) $devices, 'registered phones') . '</div>'
            . '<div class="grid g2" style="margin-top:16px"><div class="card"><div class="card-h"><h2>Recent payments</h2><a class="small" href="/admin/payments">All →</a></div>'
            . View::table(['When', 'User', 'Via', 'Amount', 'Status'], $pr, 'No payments yet.') . '</div>'
            . '<div class="card"><div class="card-h"><h2>New users</h2><a class="small" href="/admin/users">All →</a></div><div class="xs dim">Sign-ups per day, last 30 days</div><canvas class="chart" style="height:90px;margin-bottom:10px" data-line="'
            . self::e(json_encode($series)) . '"></canvas>' . View::table(['Joined', 'Email', 'Source', ''], $ur) . '</div></div>';
    }

    private static function users(Request $q): string
    {
        [$p, $per, $off] = self::page($q);
        $s = $q->str('q', 100);
        $f = $q->str('filter', 12);
        $where = ['1=1'];
        $params = [];
        if ($s !== '') { $where[] = '(u.email LIKE ? OR u.name LIKE ? OR u.referral_code = ? OR u.id = ?)'; array_push($params, "%$s%", "%$s%", strtoupper($s), (int) $s); }
        if ($f === 'premium') { $where[] = 'u.premium_until > ?'; $params[] = Db::now(); }
        elseif ($f === 'suspended') $where[] = "u.status = 'suspended'";
        elseif ($f === 'admin') $where[] = "u.role = 'admin'";
        elseif ($f === 'referred') $where[] = 'u.referred_by IS NOT NULL';
        $rows = Db::all('SELECT u.*, r.email ref_email FROM users u LEFT JOIN users r ON r.id = u.referred_by WHERE ' . implode(' AND ', $where)
            . ' ORDER BY u.id DESC LIMIT ' . ($per + 1) . " OFFSET $off", $params);
        $more = count($rows) > $per;
        $out = array_map(fn($u) => ['#' . $u['id'], self::userLink((int) $u['id'], $u['email']) . '<div class="xs dim">' . self::e($u['name']) . '</div>',
            View::date($u['created_at']), self::e($u['signup_source']),
            ($u['premium_until'] && $u['premium_until'] > Db::now() ? View::pill('Premium → ' . Fmt::date($u['premium_until']), 'a') : '<span class="dim">Free</span>'),
            $u['ref_email'] ? self::userLink((int) $u['referred_by'], $u['ref_email']) : '<span class="dim">—</span>',
            ($u['status'] === 'suspended' ? View::pill('suspended', 'r') : '') . ($u['role'] === 'admin' ? View::pill('admin', 'v') : '') . ($u['email_verified_at'] ? '' : View::pill('unverified'))],
            array_slice($rows, 0, $per));
        $tabs = View::tabs(['' => ['All', '/admin/users'], 'premium' => ['Premium', '/admin/users?filter=premium'], 'referred' => ['Referred', '/admin/users?filter=referred'],
            'suspended' => ['Suspended', '/admin/users?filter=suspended'], 'admin' => ['Admins', '/admin/users?filter=admin']], $f);
        return '<div class="row" style="margin-bottom:16px">' . $tabs . '<form class="row grow" method="get" action="/admin/users"><input name="q" value="' . self::e($s)
            . '" placeholder="Search email, name, referral code or ID" style="max-width:360px"><button class="btn sm" type="submit">Search</button></form></div>'
            . '<div class="card">' . View::table(['ID', 'User', 'Joined', 'Source', 'Plan', 'Referred by', 'Flags'], $out, 'No users found.') . View::pager($p, $more, ['q' => $s, 'filter' => $f]) . '</div>';
    }

    private static function user(int $id): string
    {
        $u = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$u) throw new ApiError(404, 'not_found', 'User not found.');
        $st = Db::one('SELECT * FROM user_settings WHERE user_id = ?', [$id]) ?? [];
        $ref = $u['referred_by'] ? Db::one('SELECT id, email FROM users WHERE id = ?', [$u['referred_by']]) : null;
        $prem = Premium::status($id);
        $info = '<dl class="kv"><dt>Email</dt><dd>' . self::e($u['email']) . '</dd><dt>Name</dt><dd>' . self::e($u['name'] ?: '—') . '</dd>'
            . '<dt>Joined</dt><dd>' . View::date($u['created_at'], true) . ' (' . self::e($u['signup_source']) . ')</dd>'
            . '<dt>Email confirmed</dt><dd>' . ($u['email_verified_at'] ? 'Yes' : 'No') . '</dd><dt>Login</dt><dd>' . ($u['password_hash'] ? 'Password' : '') . ($u['google_sub'] ? ' Google' : '') . '</dd>'
            . '<dt>Status / role</dt><dd>' . self::e($u['status']) . ' · ' . self::e($u['role']) . '</dd>'
            . '<dt>Premium</dt><dd>' . ($prem['active'] && $u['premium_until'] ? 'until ' . View::date($u['premium_until']) . ' (' . self::e($prem['source']) . ')' : 'Free') . '</dd>'
            . '<dt>Referral code</dt><dd class="mono">' . self::e(Affiliates::codeFor($id)) . '</dd>'
            . '<dt>Referred by</dt><dd>' . ($ref ? self::userLink((int) $ref['id'], $ref['email']) : '—') . '</dd>'
            . '<dt>Style / risk</dt><dd>' . self::e($st['style'] ?? '—') . ' · ' . (float) ($st['risk_pct'] ?? 0) . '%</dd>'
            . '<dt>Watchlist</dt><dd>' . self::e(implode(', ', Api::watchlist($id))) . '</dd>'
            . '<dt>Devices</dt><dd>' . (int) Db::value('SELECT COUNT(*) FROM devices WHERE user_id = ?', [$id]) . '</dd></dl>';
        $base = "/admin/users/$id";
        $actions = '<div class="card"><h2>Premium</h2><form method="post" action="' . $base . '/premium" class="row">' . View::csrf()
            . '<input name="days" type="number" min="1" max="3660" value="30" style="width:110px"><button class="btn sm" type="submit">Grant / extend days</button></form>'
            . '<div style="margin-top:10px">' . View::postButton("$base/premium", 'Remove Premium', 'btn sm ghost', ['mode' => 'revoke'], 'Remove Premium from this user?') . '</div></div>'
            . '<div class="card"><h2>Account</h2><div class="row">'
            . ($u['status'] === 'active' ? View::postButton("$base/status", 'Suspend', 'btn sm ghost', ['status' => 'suspended'], 'Suspend and sign out this user?')
                : View::postButton("$base/status", 'Re-activate', 'btn sm', ['status' => 'active']))
            . ($u['role'] === 'admin' ? View::postButton("$base/role", 'Remove admin', 'btn sm ghost', ['role' => 'user'], 'Remove admin rights?')
                : View::postButton("$base/role", 'Make admin', 'btn sm ghost', ['role' => 'admin'], 'Give this user full admin access?'))
            . ($u['email_verified_at'] ? '' : View::postButton("$base/verify", 'Mark email confirmed', 'btn sm ghost'))
            . View::postButton("$base/delete", 'Delete user', 'btn sm danger', [], 'Permanently delete this user and all their data?') . '</div></div>'
            . '<div class="card"><h2>Send a message</h2><form method="post" action="' . $base . '/notify">' . View::csrf()
            . View::field('title', 'Title', '', 'text', 'required maxlength="120"') . '<div class="field"><label>Message</label><textarea name="body" maxlength="500" required></textarea></div>'
            . '<button class="btn sm" type="submit">Send (inbox + push + email)</button></form></div>';
        $pays = Db::all('SELECT * FROM payments WHERE user_id = ? ORDER BY id DESC LIMIT 20', [$id]);
        $pr = array_map(fn($p) => [View::date($p['created_at']), self::e($p['provider']), View::amount((float) $p['amount'], $p['currency']), self::e($p['status']), '<span class="mono xs">' . self::e($p['provider_ref']) . '</span>'], $pays);
        $refs = Db::all('SELECT id, email, created_at, premium_until FROM users WHERE referred_by = ? ORDER BY id DESC LIMIT 20', [$id]);
        $rr = array_map(fn($x) => [self::userLink((int) $x['id'], $x['email']), View::date($x['created_at']), $x['premium_until'] && $x['premium_until'] > Db::now() ? View::pill('Premium', 'a') : ''], $refs);
        return '<div class="row" style="margin-bottom:14px"><a class="btn sm ghost" href="/admin/users">← Users</a></div><div class="split"><div class="stack"><div class="card"><h2>Profile</h2>' . $info . '</div>'
            . '<div class="card"><h2>Payments</h2>' . View::table(['Date', 'Via', 'Amount', 'Status', 'Ref'], $pr, 'No payments.') . '</div>'
            . '<div class="card"><h2>Referred users</h2>' . View::table(['User', 'Joined', ''], $rr, 'No referrals.') . '</div></div><div class="stack">' . $actions . '</div></div>';
    }

    private static function payments(Request $q): string
    {
        [$p, $per, $off] = self::page($q);
        $status = in_array($q->str('status', 10), ['success', 'pending', 'failed', 'refunded'], true) ? $q->str('status', 10) : '';
        $where = $status ? 'WHERE p.status = ?' : '';
        $rows = Db::all("SELECT p.*, u.email, pl.name plan_name FROM payments p LEFT JOIN users u ON u.id = p.user_id LEFT JOIN plans pl ON pl.id = p.plan_id $where ORDER BY p.id DESC LIMIT "
            . ($per + 1) . " OFFSET $off", $status ? [$status] : []);
        $more = count($rows) > $per;
        $out = array_map(function ($x) {
            $actions = '';
            if ($x['status'] === 'success') {
                $actions = '<form class="inline row" method="post" action="/admin/payments/' . (int) $x['id'] . '/refund" data-confirm="Mark as refunded? This removes the Premium time and reverses the affiliate commission.">'
                    . View::csrf() . '<input name="note" placeholder="Reason" style="width:120px;padding:6px 8px;font-size:13px"><button class="btn sm ghost" type="submit">Refund</button></form>';
            } elseif ($x['status'] === 'pending' && $x['provider'] === 'paystack') {
                $actions = View::postButton('/admin/payments/' . (int) $x['id'] . '/verify', 'Re-check');
            }
            return [View::date($x['created_at'], true), self::userLink((int) $x['user_id'], $x['email']), self::e($x['plan_name'] ?? '—'), self::e(ucfirst($x['provider'])),
                View::amount((float) $x['amount'], $x['currency']) . ' <span class="xs dim">≈' . View::money((float) $x['amount_usd']) . '</span>',
                View::pill($x['status'], ['success' => 'g', 'refunded' => 'a', 'failed' => 'r'][$x['status']] ?? ''), '<span class="mono xs">' . self::e($x['provider_ref']) . '</span>', $actions];
        }, array_slice($rows, 0, $per));
        $tabs = View::tabs(['' => ['All', '/admin/payments'], 'success' => ['Successful', '/admin/payments?status=success'], 'pending' => ['Pending', '/admin/payments?status=pending'],
            'refunded' => ['Refunded', '/admin/payments?status=refunded'], 'failed' => ['Failed', '/admin/payments?status=failed']], $status);
        return '<div style="margin-bottom:16px">' . $tabs . '</div><div class="card">' . View::table(['Date', 'User', 'Plan', 'Via', 'Amount', 'Status', 'Reference', 'Actions'], $out, 'No payments.')
            . View::pager($p, $more, ['status' => $status]) . '</div><p class="xs dim" style="margin-top:12px">Refunding here only updates records. Send the money back in your Paystack dashboard or Google Play Console.</p>';
    }

    private static function plans(): string
    {
        $h = '<div class="grid g2">';
        foreach (Db::all('SELECT * FROM plans ORDER BY sort, id') as $p) {
            $h .= '<div class="card"><h2>' . self::e($p['name']) . ' <span class="xs dim mono">' . self::e($p['code']) . '</span></h2><form method="post" action="/admin/plans/' . (int) $p['id'] . '">' . View::csrf()
                . View::field('name', 'Name', $p['name']) . '<div class="grid g3">' . View::field('price_ngn', 'Price (NGN)', (string) (float) $p['price_ngn'], 'number', 'step="any" min="0"')
                . View::field('price_usd', 'Price (USD)', (string) (float) $p['price_usd'], 'number', 'step="any" min="0"')
                . View::field('interval_months', 'Months', (string) $p['interval_months'], 'number', 'min="1" max="36"') . '</div>'
                . View::field('google_product_id', 'Google Play product ID', $p['google_product_id'], 'text', '', 'Must match the subscription ID in Play Console.')
                . View::field('badge', 'Badge (optional)', $p['badge']) . View::checkbox('active', 'Active (shown to users)', (bool) $p['active'])
                . '<button class="btn" type="submit" style="margin-top:12px">Save plan</button></form></div>';
        }
        return $h . '</div><p class="xs dim" style="margin-top:12px">Website checkout uses the ' . self::e(Settings::get('checkout_currency')) . ' price (change in Settings). '
            . 'Play Store prices are set in Play Console; the USD price here is used to calculate affiliate commission on Play purchases (minus the Google fee).</p>';
    }

    private static function affiliates(Request $q): string
    {
        $status = in_array($q->str('status', 12), ['pending', 'approved', 'rejected', 'suspended'], true) ? $q->str('status', 12) : '';
        $rows = Db::all('SELECT a.*, u.email, u.name, u.referral_code,
                (SELECT COUNT(*) FROM users r WHERE r.referred_by = a.user_id) signups,
                (SELECT COALESCE(SUM(amount_usd),0) FROM commissions c WHERE c.affiliate_user_id = a.user_id AND c.status IN ("pending","approved","paid")) earned,
                (SELECT COALESCE(SUM(amount_usd),0) FROM commissions c WHERE c.affiliate_user_id = a.user_id AND c.status = "approved" AND c.payout_id IS NULL) available
            FROM affiliates a JOIN users u ON u.id = a.user_id ' . ($status ? 'WHERE a.status = ?' : '') . ' ORDER BY a.status = "pending" DESC, a.applied_at DESC LIMIT 200', $status ? [$status] : []);
        $def = (float) Settings::num('commission_rate');
        $out = array_map(function ($a) use ($status, $def) {
            $id = (int) $a['user_id'];
            $form = '<form class="row" method="post" action="/admin/affiliates/' . $id . '">' . View::csrf() . '<input type="hidden" name="back" value="' . self::e($status) . '">'
                . '<select name="status" style="width:auto;padding:6px 8px;font-size:13px">' . implode('', array_map(fn($s) => '<option' . ($s === $a['status'] ? ' selected' : '') . '>' . $s . '</option>',
                    ['pending', 'approved', 'rejected', 'suspended'])) . '</select>'
                . '<input name="rate" value="' . self::e($a['commission_rate'] === null ? '' : (float) $a['commission_rate']) . '" placeholder="' . $def . '% default" style="width:100px;padding:6px 8px;font-size:13px">'
                . '<input name="note" value="' . self::e($a['admin_note']) . '" placeholder="Note to affiliate" style="width:150px;padding:6px 8px;font-size:13px"><button class="btn sm" type="submit">Save</button></form>';
            return [self::userLink($id, $a['email']) . '<div class="xs dim mono">' . self::e($a['referral_code']) . '</div>', '<div class="small" style="max-width:260px">' . self::e($a['promo_plan']) . '</div>',
                View::date($a['applied_at']), (string) $a['signups'], View::money((float) $a['earned']) . '<div class="xs pos">' . View::money((float) $a['available']) . ' avail.</div>',
                View::pill($a['status'], ['pending' => 'a', 'approved' => 'g', 'rejected' => 'r', 'suspended' => 'r'][$a['status']]), $form];
        }, $rows);
        $tabs = View::tabs(['' => ['All', '/admin/affiliates'], 'pending' => ['Pending', '/admin/affiliates?status=pending'], 'approved' => ['Approved', '/admin/affiliates?status=approved'],
            'rejected' => ['Rejected', '/admin/affiliates?status=rejected'], 'suspended' => ['Suspended', '/admin/affiliates?status=suspended']], $status);
        $top = Db::all('SELECT u.id, u.email, COUNT(r.id) n FROM users r JOIN users u ON u.id = r.referred_by GROUP BY u.id, u.email ORDER BY n DESC LIMIT 5');
        $topHtml = implode(' · ', array_map(fn($t) => self::userLink((int) $t['id'], $t['email']) . ' (' . $t['n'] . ')', $top)) ?: '—';
        return '<div class="row" style="margin-bottom:16px">' . $tabs . '<span class="small muted">Top referrers: ' . $topHtml . '</span></div><div class="card">'
            . View::table(['Affiliate', 'Promotion plan', 'Applied', 'Sign-ups', 'Earned', 'Status', 'Manage (rate override %)'], $out, 'No affiliates.') . '</div>';
    }

    private static function commissions(Request $q): string
    {
        [$p, $per, $off] = self::page($q);
        $status = in_array($q->str('status', 10), ['pending', 'approved', 'paid', 'reversed', 'rejected'], true) ? $q->str('status', 10) : '';
        $rows = Db::all('SELECT c.*, a.email aff_email, r.email ref_email, b.name broker FROM commissions c JOIN users a ON a.id = c.affiliate_user_id
            LEFT JOIN users r ON r.id = c.referred_user_id LEFT JOIN brokers b ON b.id = c.broker_id ' . ($status ? 'WHERE c.status = ?' : '')
            . ' ORDER BY c.id DESC LIMIT ' . ($per + 1) . " OFFSET $off", $status ? [$status] : []);
        $more = count($rows) > $per;
        $out = array_map(fn($c) => [View::date($c['created_at']), self::userLink((int) $c['affiliate_user_id'], $c['aff_email']),
            self::e(ucfirst($c['source'])) . ($c['broker'] ? ' · ' . self::e($c['broker']) : ''), self::userLink($c['referred_user_id'] ? (int) $c['referred_user_id'] : null, $c['ref_email']),
            View::money((float) $c['base_usd']) . ' × ' . (float) $c['rate'] . '%', '<b>' . View::money((float) $c['amount_usd']) . '</b>',
            View::pill($c['status'], ['pending' => 'a', 'approved' => 'g', 'paid' => 'p', 'reversed' => 'r', 'rejected' => 'r'][$c['status']])
                . ($c['status'] === 'pending' ? '<div class="xs dim">until ' . View::date($c['available_at']) . '</div>' : '') . ($c['payout_id'] ? '<div class="xs dim">payout #' . (int) $c['payout_id'] . '</div>' : ''),
            self::e($c['note']), in_array($c['status'], ['pending', 'approved'], true) && !$c['payout_id']
                ? View::postButton('/admin/commissions/' . (int) $c['id'] . '/reject', 'Reject', 'btn sm ghost', [], 'Reject this commission?') : ''],
            array_slice($rows, 0, $per));
        $tabs = View::tabs(['' => ['All', '/admin/commissions'], 'pending' => ['Pending', '?status=pending'], 'approved' => ['Approved', '?status=approved'], 'paid' => ['Paid', '?status=paid'],
            'reversed' => ['Reversed', '?status=reversed'], 'rejected' => ['Rejected', '?status=rejected']], $status);
        $adj = '<div class="card" style="margin-top:16px"><h2>Manual adjustment</h2><form class="row" method="post" action="/admin/commissions/adjust">' . View::csrf()
            . '<input name="affiliate" placeholder="Affiliate email or code" required style="max-width:240px"><input name="amount" type="number" step="0.01" placeholder="Amount USD (negative to deduct)" required style="max-width:240px">'
            . '<input name="note" placeholder="Reason" style="max-width:240px"><button class="btn sm" type="submit">Add</button></form></div>';
        return '<div class="row" style="margin-bottom:16px">' . $tabs . '<div class="grow"></div>' . View::postButton('/admin/commissions/release', 'Release due commissions now') . '</div>'
            . '<div class="card">' . View::table(['Date', 'Affiliate', 'Source', 'Customer', 'Base × rate', 'Amount', 'Status', 'Note', ''], $out, 'No commissions.') . View::pager($p, $more, ['status' => $status]) . '</div>' . $adj;
    }

    private static function payouts(Request $q): string
    {
        $rows = Db::all('SELECT p.*, u.email FROM payouts p JOIN users u ON u.id = p.affiliate_user_id ORDER BY p.status = "requested" DESC, p.id DESC LIMIT 200');
        $out = array_map(function ($p) {
            $d = json_decode((string) $p['details'], true) ?: [];
            $det = implode('<br>', array_map(fn($k, $v) => '<span class="dim">' . self::e(str_replace('_', ' ', $k)) . ':</span> <span class="mono">' . self::e($v) . '</span>', array_keys($d), $d));
            $act = $p['status'] === 'requested'
                ? '<form method="post" action="/admin/payouts/' . (int) $p['id'] . '" class="stack" style="min-width:220px">' . View::csrf()
                    . '<input name="txn_ref" placeholder="Transaction ref" style="padding:6px 8px;font-size:13px"><input name="note" placeholder="Note (for rejections)" style="padding:6px 8px;font-size:13px">'
                    . '<div class="row"><button class="btn sm" name="status" value="paid" type="submit" data-keep>Mark paid</button><button class="btn sm ghost" name="status" value="rejected" type="submit" data-keep>Reject</button></div></form>'
                : self::e($p['txn_ref'] ?: $p['admin_note']) . '<div class="xs dim">' . View::date($p['processed_at']) . '</div>';
            return [View::date($p['requested_at'], true), self::userLink((int) $p['affiliate_user_id'], $p['email']), '<b>' . View::money((float) $p['amount_usd']) . '</b>',
                self::e(strtoupper($p['method'])) . '<div class="xs">' . $det . '</div>', View::pill($p['status'], ['requested' => 'a', 'paid' => 'g', 'rejected' => 'r'][$p['status']]), $act];
        }, $rows);
        $rate = (float) Settings::num('usd_ngn_rate');
        return '<div class="alert info">Send the money yourself (bank app, exchange or PayPal), then mark the payout as paid with the transaction reference. Bank payouts: ≈ ₦'
            . number_format($rate, 0) . ' per $1 (Settings).</div><div class="card">' . View::table(['Requested', 'Affiliate', 'Amount', 'Method & details', 'Status', 'Action'], $out, 'No payout requests.') . '</div>';
    }

    private static function brokers(Request $q): string
    {
        $edit = $q->int('edit') ? Db::one('SELECT * FROM brokers WHERE id = ?', [$q->int('edit')]) : null;
        $b = $edit ?? ['id' => 0, 'slug' => '', 'name' => '', 'logo_url' => '', 'tagline' => '', 'description' => '', 'url_template' => '', 'min_deposit' => '', 'regions' => '', 'active' => 1, 'sort' => 0];
        $rows = Db::all('SELECT b.*, (SELECT COUNT(*) FROM broker_clicks c WHERE c.broker_id = b.id) clicks,
                (SELECT COUNT(*) FROM broker_clicks c WHERE c.broker_id = b.id AND c.created_at > ?) clicks30,
                (SELECT COUNT(*) FROM commissions m WHERE m.broker_id = b.id) conv FROM brokers b ORDER BY b.sort, b.name', [Clock::now()->modify('-30 day')->format('Y-m-d H:i:s')]);
        $out = array_map(fn($x) => ['<b>' . self::e($x['name']) . '</b><div class="xs dim mono">/go/' . self::e($x['slug']) . '</div>', $x['active'] ? View::pill('active', 'g') : View::pill('hidden'),
            $x['clicks30'] . ' <span class="dim xs">/ ' . $x['clicks'] . ' all</span>', (string) $x['conv'], '<a class="btn sm ghost" href="/admin/brokers?edit=' . (int) $x['id'] . '">Edit</a>'], $rows);
        $form = '<div class="card"><h2>' . ($edit ? 'Edit ' . self::e($b['name']) : 'Add a broker') . '</h2><form method="post" action="/admin/brokers/save">' . View::csrf()
            . '<input type="hidden" name="id" value="' . (int) $b['id'] . '"><div class="grid g2">' . View::field('name', 'Name', $b['name'], 'text', 'required')
            . View::field('slug', 'Slug (link: /go/slug)', $b['slug'], 'text', 'required pattern="[a-zA-Z0-9\-]+"') . '</div>'
            . View::field('url_template', 'Your affiliate URL', $b['url_template'], 'url', 'required placeholder="https://broker.com/?aff=123&subid={sub_id}"',
                'Put <span class="mono">{sub_id}</span> where the broker accepts a tracking/sub ID, so you can match conversions to affiliates.')
            . View::field('tagline', 'Tagline', $b['tagline']) . '<div class="field"><label>Description</label><textarea name="description">' . self::e($b['description']) . '</textarea></div>'
            . '<div class="grid g3">' . View::field('min_deposit', 'Min. deposit', $b['min_deposit'], 'text', 'placeholder="$0"') . View::field('regions', 'Available in', $b['regions'], 'text', 'placeholder="Nigeria, UK, US"')
            . View::field('sort', 'Order', (string) $b['sort'], 'number') . '</div>' . View::field('logo_url', 'Logo URL (https, optional)', $b['logo_url'])
            . View::checkbox('active', 'Show on website and app', (bool) $b['active']) . '<div class="row" style="margin-top:12px"><button class="btn" type="submit">Save broker</button>'
            . ($edit ? '<a class="btn ghost" href="/admin/brokers">Cancel</a>' : '') . '</div></form></div>';
        $conv = '<div class="card"><h2>Record a conversion</h2><p class="small">When a broker reports a qualifying account and pays you, enter the sub-ID from their report and what they paid. The referring affiliate is credited '
            . (float) Settings::num('broker_share_pct') . '% immediately.</p><form method="post" action="/admin/brokers/conversion">' . View::csrf()
            . '<div class="grid g2">' . View::field('sub_id', 'Sub-ID', '', 'text', 'required placeholder="st…"') . View::field('amount', 'Amount you received (USD)', '', 'number', 'required step="0.01" min="0.01"') . '</div>'
            . View::field('note', 'Note', '', 'text', 'placeholder="e.g. Broker X, March CPA"') . '<button class="btn" type="submit">Record conversion</button></form></div>';
        $clicks = Db::all('SELECT c.*, b.name, u.email, a.email aff FROM broker_clicks c JOIN brokers b ON b.id = c.broker_id LEFT JOIN users u ON u.id = c.user_id LEFT JOIN users a ON a.id = c.affiliate_user_id ORDER BY c.id DESC LIMIT 25');
        $cr = array_map(fn($c) => [View::ago($c['created_at']), self::e($c['name']), '<span class="mono xs">' . self::e($c['sub_id']) . '</span>', self::e($c['source']),
            self::userLink($c['user_id'] ? (int) $c['user_id'] : null, $c['email']), self::userLink($c['affiliate_user_id'] ? (int) $c['affiliate_user_id'] : null, $c['aff'])], $clicks);
        return '<div class="split"><div class="stack"><div class="card"><h2>Brokers</h2>' . View::table(['Broker', 'Status', 'Clicks (30d)', 'Conversions', ''], $out, 'No brokers yet. Add your first affiliate broker.') . '</div>'
            . '<div class="card"><h2>Recent clicks</h2>' . View::table(['When', 'Broker', 'Sub-ID', 'From', 'User', 'Affiliate'], $cr, 'No clicks yet.') . '</div></div>'
            . '<div class="stack">' . $form . $conv . '</div></div>';
    }

    private static function signals(Request $q): string
    {
        $rows = Db::all('SELECT * FROM signals ORDER BY id DESC LIMIT 60');
        $out = array_map(fn($s) => ['#' . $s['id'], '<b>' . self::e($s['symbol']) . '</b> <span class="xs dim">' . self::e($s['tf']) . '</span>', self::e($s['kind']),
            View::date($s['signal_time'], true), self::e(Fmt::price((float) $s['signal_price'])), View::statusPill($s),
            $s['r_multiple'] !== null ? View::rCell((float) $s['r_multiple']) : '—'], $rows);
        $scans = Db::all('SELECT * FROM scan_log ORDER BY id DESC LIMIT 10');
        $sr = array_map(fn($x) => [View::date($x['started_at'], true), $x['finished_at'] ? round((strtotime($x['finished_at']) - strtotime($x['started_at']))) . 's' : 'running…',
            (string) $x['series'], (string) $x['new_signals'], (string) $x['closed'], self::e(mb_substr((string) ($x['errors'] ?? ''), 0, 120))], $scans);
        $mq = Db::one('SELECT COUNT(*) total, SUM(sent_at IS NULL) unsent, SUM(sent_at IS NULL AND attempts > 0) failing FROM email_queue');
        return '<div class="row" style="margin-bottom:16px">' . View::postButton('/admin/signals/scan', '▶ Run scanner now', 'btn', [], 'Run a full scan now? It can take a minute.')
            . View::postButton('/admin/signals/mail', '✉ Send queued emails', 'btn ghost') . '<span class="small muted">Email queue: ' . (int) $mq['unsent'] . ' unsent, ' . (int) $mq['failing'] . ' retrying</span></div>'
            . '<div class="card"><h2>Recent scans</h2>' . View::table(['Started', 'Took', 'Series', 'New', 'Closed', 'Errors'], $sr, 'No scans yet: set up the cron job.') . '</div>'
            . '<div class="card"><h2>Latest signals</h2>' . View::table(['ID', 'Stock', 'Kind', 'Time', 'Price', 'Status', '>Result'], $out, 'No signals yet.') . '</div>';
    }

    private static function settings(): string
    {
        $s = Settings::all();
        $f = fn(string $k, string $label, string $help = '', string $type = 'text') => View::field($k, $label, (string) $s[$k], $type, $type === 'number' ? 'step="any"' : '', $help);
        return '<form method="post" action="/admin/settings">' . View::csrf() . '<div class="grid g2"><div class="stack">'
            . '<div class="card"><h2>Premium & payments</h2>' . View::select('premium_enabled', 'Premium plan', ['1' => 'On: live signal levels need Premium', '0' => 'Off: everything free'], (string) $s['premium_enabled'])
            . View::select('checkout_currency', 'Website checkout currency', ['NGN' => 'NGN (₦) via Paystack', 'USD' => 'USD ($) via Paystack (USD must be enabled on your Paystack account)'], (string) $s['checkout_currency'])
            . $f('usd_ngn_rate', 'NGN per $1', 'Converts naira payments to USD for commissions and reports.', 'number')
            . $f('google_fee_pct', 'Google Play fee %', 'Deducted before commission on Play purchases (15% for subscriptions).', 'number') . '</div>'
            . '<div class="card"><h2>Website</h2>' . $f('site_announcement', 'Announcement bar', 'Shown at the top of public pages. Leave empty to hide.')
            . $f('support_email', 'Support email', 'Shown in the privacy policy.') . '</div></div>'
            . '<div class="stack"><div class="card"><h2>Affiliate program</h2>' . View::select('affiliate_enabled', 'Program', ['1' => 'Open', '0' => 'Closed to new applications'], (string) $s['affiliate_enabled'])
            . View::select('affiliate_auto_approve', 'New applications', ['0' => 'Review manually', '1' => 'Approve automatically'], (string) $s['affiliate_auto_approve'])
            . '<div class="grid g2">' . $f('commission_rate', 'Premium commission %', '', 'number') . View::select('commission_recurring', 'Pay on', ['1' => 'Every payment', '0' => 'First payment only'], (string) $s['commission_recurring']) . '</div>'
            . '<div class="grid g2">' . $f('broker_share_pct', 'Broker revenue share %', '', 'number') . $f('commission_hold_days', 'Hold days (refund window)', '', 'number') . '</div>'
            . '<div class="grid g2">' . $f('cookie_days', 'Referral cookie days', '', 'number') . $f('min_payout_usd', 'Minimum payout (USD)', '', 'number') . '</div>'
            . $f('payout_methods', 'Payout methods', 'Comma-separated: bank, usdt, paypal') . '</div></div></div>'
            . '<button class="btn lg" type="submit" style="margin-top:16px">Save settings</button></form>';
    }

    private static function auditLog(Request $q): string
    {
        [$p, $per, $off] = self::page($q);
        $rows = Db::all('SELECT l.*, u.email FROM admin_log l LEFT JOIN users u ON u.id = l.admin_id ORDER BY l.id DESC LIMIT ' . ($per + 1) . " OFFSET $off");
        $more = count($rows) > $per;
        $out = array_map(fn($l) => [View::date($l['created_at'], true), self::e($l['email'] ?? 'system'), '<span class="mono small">' . self::e($l['action']) . '</span>', self::e($l['target']),
            '<span class="mono xs" style="word-break:break-all">' . self::e(mb_substr((string) $l['details'], 0, 200)) . '</span>'], array_slice($rows, 0, $per));
        return '<div class="card">' . View::table(['When', 'Admin', 'Action', 'Target', 'Details'], $out, 'No admin actions yet.') . View::pager($p, $more, []) . '</div>';
    }
}
