<?php
declare(strict_types=1);

namespace SignalLab;

/** All HTTP routes. Mounted by public/index.php. */
final class Api
{
    public static function router(): Router
    {
        $r = new Router();

        // ---------------------------------------------------------- health
        $r->add('GET', '/', fn() => Response::json(['name' => Config::get('app_name'), 'status' => 'ok']));
        $r->add('GET', '/health', function () {
            $scan = Db::one('SELECT started_at, finished_at, series, new_signals, closed FROM scan_log ORDER BY id DESC LIMIT 1');
            return Response::json(['status' => 'ok', 'time' => Fmt::iso(Db::now()), 'last_scan' => $scan ? [
                'started_at' => Fmt::iso($scan['started_at']), 'finished_at' => Fmt::iso($scan['finished_at']),
                'series' => (int) $scan['series'], 'new_signals' => (int) $scan['new_signals'], 'closed' => (int) $scan['closed'],
            ] : null]);
        });

        // ---------------------------------------------------------- auth
        $r->add('POST', '/auth/register', fn(Request $q) => Response::json(
            Auth::register($q->str('email', 190), (string) ($q->body['password'] ?? ''), $q->str('name', 120), $q->ip), 201));
        $r->add('POST', '/auth/login', fn(Request $q) => Response::json(
            Auth::login($q->str('email', 190), (string) ($q->body['password'] ?? ''), $q->ip)));
        $r->add('POST', '/auth/google', fn(Request $q) => Response::json(Auth::google($q->str('id_token', 4096))));
        $r->add('POST', '/auth/refresh', fn(Request $q) => Response::json(Auth::refresh($q->str('refresh_token', 128))));
        $r->add('POST', '/auth/logout', function (Request $q) {
            Auth::logout($q->str('refresh_token', 128));
            $token = $q->str('fcm_token', 255);
            if ($token !== '') Db::exec('DELETE FROM devices WHERE fcm_token = ?', [$token]);
            return Response::json(['signed_out' => true]);
        });
        $r->add('POST', '/auth/forgot', function (Request $q) {
            Auth::forgotPassword($q->str('email', 190), $q->ip);
            return Response::json(['message' => 'If an account exists for that email, a reset link is on its way.']);
        });
        $r->add('GET', '/auth/reset', fn(Request $q) => Response::html(Templates::resetForm($q->str('token', 100))));
        $r->add('POST', '/auth/reset', function (Request $q) {
            $token = $q->str('token', 100);
            $pw = (string) ($q->body['password'] ?? '');
            $wantsHtml = !str_contains($q->headers['content-type'] ?? '', 'json');
            try {
                Auth::resetPassword($token, $pw);
            } catch (ApiError $e) {
                if ($wantsHtml) return Response::html(Templates::resetForm($token, $e->getMessage()), $e->status);
                throw $e;
            }
            return $wantsHtml
                ? Response::html(Templates::messagePage('Password updated', 'You can now sign in to the app with your new password.'))
                : Response::json(['reset' => true]);
        });
        $r->add('GET', '/auth/verify', fn(Request $q) => Auth::verifyEmail($q->str('token', 100))
            ? Response::html(Templates::messagePage('Email confirmed', 'Thanks! Signal alerts and reports will now reach your inbox. You can return to the app.'))
            : Response::html(Templates::messagePage('Link expired', 'This confirmation link is invalid or has expired. Request a new one in the app.', false), 400));
        $r->add('POST', '/auth/resend-verification', function (Request $q) {
            Auth::sendVerification(Auth::requireUser($q));
            return Response::json(['sent' => true]);
        });
        $r->add('POST', '/auth/change-password', function (Request $q) {
            Auth::changePassword(Auth::requireUser($q), (string) ($q->body['current_password'] ?? ''), (string) ($q->body['new_password'] ?? ''));
            return Response::json(['changed' => true]);
        });

        // ---------------------------------------------------------- account
        $r->add('GET', '/me', function (Request $q) {
            $id = Auth::requireUser($q);
            return Response::json(['user' => Auth::userPayload($id), 'settings' => self::settings($id), 'watchlist' => self::watchlist($id)]);
        });
        $r->add('PATCH', '/me', function (Request $q) {
            $id = Auth::requireUser($q);
            if (isset($q->body['name'])) Db::exec('UPDATE users SET name = ? WHERE id = ?', [$q->str('name', 120), $id]);
            return Response::json(['user' => Auth::userPayload($id)]);
        });
        $r->add('DELETE', '/me', function (Request $q) {
            Auth::deleteAccount(Auth::requireUser($q));
            return Response::json(['deleted' => true]);
        });
        $r->add('GET', '/me/settings', fn(Request $q) => Response::json(self::settings(Auth::requireUser($q))));
        $r->add('PUT', '/me/settings', function (Request $q) {
            $id = Auth::requireUser($q);
            $b = $q->body;
            $upd = [];
            if (isset($b['account_size']) && is_numeric($b['account_size'])) $upd['account_size'] = max(1, min(1e9, (float) $b['account_size']));
            if (isset($b['risk_pct']) && is_numeric($b['risk_pct'])) $upd['risk_pct'] = max(0.1, min(10, (float) $b['risk_pct']));
            if (isset($b['style']) && in_array($b['style'], ['1d', '1h'], true)) $upd['style'] = $b['style'];
            foreach (['allow_short', 'fractional', 'all_signals', 'push_signals', 'push_results', 'email_signals', 'email_results',
                         'report_daily', 'report_weekly', 'report_monthly'] as $k) {
                if (array_key_exists($k, $b)) $upd[$k] = filter_var($b[$k], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
            if ($upd) Db::update('user_settings', $upd + ['updated_at' => Db::now()], 'user_id = ?', [$id]);
            return Response::json(self::settings($id));
        });

        // ---------------------------------------------------------- watchlist
        $r->add('GET', '/watchlist', fn(Request $q) => Response::json(self::watchlist(Auth::requireUser($q))));
        $r->add('PUT', '/watchlist', function (Request $q) {
            $id = Auth::requireUser($q);
            $symbols = [];
            foreach ((array) ($q->body['symbols'] ?? []) as $s) {
                $s = strtoupper(trim((string) $s));
                if (preg_match('/^[A-Z0-9.\-^=]{1,15}$/', $s) && !in_array($s, $symbols, true)) $symbols[] = $s;
            }
            if (count($symbols) > 60) throw new ApiError(422, 'too_many', 'A watchlist can hold up to 60 stocks.');
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            Db::exec('DELETE FROM watchlist WHERE user_id = ?', [$id]);
            foreach ($symbols as $i => $s) Db::exec('INSERT INTO watchlist (user_id, symbol, position) VALUES (?, ?, ?)', [$id, $s, $i]);
            $pdo->commit();
            return Response::json(self::watchlist($id));
        });

        // ---------------------------------------------------------- devices (push)
        $r->add('POST', '/devices', function (Request $q) {
            $id = Auth::requireUser($q);
            $token = $q->str('token', 255);
            if (strlen($token) < 20) throw new ApiError(422, 'invalid_token', 'Invalid device token.');
            Db::exec('INSERT INTO devices (user_id, fcm_token, platform, updated_at) VALUES (?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), updated_at = VALUES(updated_at)',
                [$id, $token, $q->str('platform', 16), Db::now()]);
            return Response::json(['registered' => true]);
        });
        $r->add('DELETE', '/devices', function (Request $q) {
            $id = Auth::requireUser($q);
            Db::exec('DELETE FROM devices WHERE user_id = ? AND fcm_token = ?', [$id, $q->str('token', 255)]);
            return Response::json(['removed' => true]);
        });
        $r->add('POST', '/devices/test', function (Request $q) {
            $id = Auth::requireUser($q);
            $ok = Push::toUser($id, 'Test notification', 'Push notifications are working. You will be alerted about new signals.');
            return Response::json(['delivered' => $ok]);
        });

        // ---------------------------------------------------------- signals
        $r->add('GET', '/signals', function (Request $q) {
            $id = Auth::requireUser($q);
            $where = ['1=1'];
            $params = [];
            $tf = $q->str('tf', 2);
            if (in_array($tf, ['1d', '1h'], true)) { $where[] = 'tf = ?'; $params[] = $tf; }
            $status = $q->str('status', 10) ?: 'all';
            if ($status === 'live') $where[] = "(status IN ('pending','open') OR (status = 'warning' AND signal_time > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)))";
            elseif ($status === 'closed') $where[] = "status IN ('won','lost')";
            $sym = strtoupper($q->str('symbol', 20));
            if ($sym !== '') { $where[] = 'symbol = ?'; $params[] = $sym; }
            if (($q->str('scope', 10) ?: 'watchlist') === 'watchlist' && $sym === '') {
                $where[] = 'symbol IN (SELECT symbol FROM watchlist WHERE user_id = ?)';
                $params[] = $id;
            }
            $before = $q->int('before_id');
            if ($before > 0) { $where[] = 'id < ?'; $params[] = $before; }
            $limit = max(1, min(200, $q->int('limit', 50)));
            $order = $status === 'closed' ? 'exit_time DESC, id DESC' : 'id DESC';
            $rows = Db::all('SELECT * FROM signals WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT $limit", $params);
            return Response::json(array_map([self::class, 'signalPayload'], $rows));
        });
        $r->add('GET', '/signals/{id}', function (Request $q, array $p) {
            Auth::requireUser($q);
            $row = Db::one('SELECT * FROM signals WHERE id = ?', [(int) $p['id']]);
            if (!$row) throw new ApiError(404, 'not_found', 'Signal not found.');
            return Response::json(self::signalPayload($row));
        });

        // ---------------------------------------------------------- performance
        $r->add('GET', '/performance', function (Request $q) {
            $id = Auth::requireUser($q);
            $s = self::settings($id);
            $tf = $q->str('tf', 2);
            $tf = in_array($tf, ['1d', '1h'], true) ? $tf : $s['style'];
            $symbols = ($q->str('scope', 10) ?: 'all') === 'watchlist' ? self::watchlist($id) : null;
            return Response::json(Performance::report($symbols, $tf, (float) $s['risk_pct']));
        });

        // ---------------------------------------------------------- inbox
        $r->add('GET', '/notifications', function (Request $q) {
            $id = Auth::requireUser($q);
            $limit = max(1, min(200, $q->int('limit', 60)));
            $rows = Db::all("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT $limit", [$id]);
            $unread = (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$id]);
            return Response::json(['unread' => $unread, 'items' => array_map(fn($n) => [
                'id' => (int) $n['id'], 'signal_id' => $n['signal_id'] === null ? null : (int) $n['signal_id'], 'kind' => $n['kind'],
                'symbol' => $n['symbol'], 'title' => $n['title'], 'body' => $n['body'], 'created_at' => Fmt::iso($n['created_at']),
                'read' => $n['read_at'] !== null, 'pushed' => (bool) $n['pushed'], 'emailed' => (bool) $n['emailed'],
            ], $rows)]);
        });
        $r->add('POST', '/notifications/read', function (Request $q) {
            $id = Auth::requireUser($q);
            Db::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [Db::now(), $id]);
            return Response::json(['ok' => true]);
        });

        // ---------------------------------------------------------- public record
        $r->add('GET', '/record', function () {
            $rep = Performance::report(null, '1d', 1.0);
            $recent = Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('won','lost') ORDER BY exit_time DESC LIMIT 30");
            return Response::html(Templates::recordPage($rep, $recent));
        });
        $r->add('GET', '/record.json', fn() => Response::json(Performance::report(null, '1d', 1.0)));

        // ---------------------------------------------------------- store-required pages
        $r->add('GET', '/privacy', fn() => Response::html(Templates::privacyPage()));
        $r->add('GET', '/delete-account', fn() => Response::html(Templates::deleteAccountPage()));
        $r->add('POST', '/delete-account', function (Request $q) {
            $email = strtolower($q->str('email', 190));
            $u = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
            $pw = (string) ($q->body['password'] ?? '');
            if (!$u || !$u['password_hash'] || !password_verify($pw, $u['password_hash'])) {
                Db::insert('login_attempts', ['ip' => $q->ip, 'email' => $email, 'attempted_at' => Db::now()]);
                return Response::html(Templates::deleteAccountPage(
                    'Email or password is incorrect. If you signed up with Google, delete your account in the app: Settings → Account → Delete account.'), 400);
            }
            Auth::deleteAccount((int) $u['id']);
            return Response::html(Templates::messagePage('Account deleted', 'Your account and all its data have been permanently erased.'));
        });

        // ---------------------------------------------------------- web cron (optional)
        $r->add('GET', '/cron/{job}', function (Request $q, array $p) {
            $key = (string) Config::get('cron_key', '');
            if ($key === '' || !hash_equals($key, $q->str('key', 200))) throw new ApiError(403, 'forbidden', 'Forbidden.');
            @set_time_limit(600);
            return Response::json(match ($p['job']) {
                'scan' => Tracker::run(),
                'mail' => Mailer::processQueue(),
                'daily', 'weekly', 'monthly' => ['sent' => Reports::send($p['job'])],
                default => throw new ApiError(404, 'not_found', 'Unknown job.'),
            });
        });

        return $r;
    }

    public static function settings(int $userId): array
    {
        $s = Db::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        if (!$s) {
            Auth::ensureSettings($userId);
            $s = Db::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        }
        $out = ['account_size' => (float) $s['account_size'], 'risk_pct' => (float) $s['risk_pct'], 'style' => $s['style']];
        foreach (['allow_short', 'fractional', 'all_signals', 'push_signals', 'push_results', 'email_signals', 'email_results',
                     'report_daily', 'report_weekly', 'report_monthly'] as $k) {
            $out[$k] = (bool) $s[$k];
        }
        return $out;
    }

    /** @return list<string> */
    public static function watchlist(int $userId): array
    {
        return array_column(Db::all('SELECT symbol FROM watchlist WHERE user_id = ? ORDER BY position, symbol', [$userId]), 'symbol');
    }

    public static function signalPayload(array $r): array
    {
        $f = fn($k) => $r[$k] === null ? null : (float) $r[$k];
        return [
            'id' => (int) $r['id'],
            'symbol' => $r['symbol'],
            'tf' => $r['tf'],
            'direction' => $r['direction'],
            'kind' => $r['kind'],
            'status' => $r['status'],
            'signal_time' => Fmt::iso($r['signal_time']),
            'signal_price' => $f('signal_price'),
            'stop_loss' => $f('stop_loss'),
            'target1' => $f('target1'),
            'target2' => $f('target2'),
            'strength' => (int) $r['strength'],
            'reasons' => json_decode((string) $r['reasons'], true) ?: [],
            'entry_time' => Fmt::iso($r['entry_time']),
            'entry_price' => $f('entry_price'),
            'live_stop' => $f('live_stop'),
            'live_target1' => $f('live_target1'),
            'live_target2' => $f('live_target2'),
            'tp1_hit_time' => Fmt::iso($r['tp1_hit_time']),
            'exit_time' => Fmt::iso($r['exit_time']),
            'exit_price' => $f('exit_price'),
            'exit_reason' => $r['exit_reason'],
            'return_pct' => $f('return_pct'),
            'r_multiple' => $f('r_multiple'),
            'bars_held' => $r['bars_held'] === null ? null : (int) $r['bars_held'],
            'last_price' => $f('last_price'),
        ];
    }
}
