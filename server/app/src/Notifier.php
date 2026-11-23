<?php
declare(strict_types=1);

namespace SignalLab;

/** Fans a signal event out to users: in-app inbox, push notification, email. */
final class Notifier
{
    /** kind: new_buy | bearish | target1 | target | stop | time */
    public static function signalEvent(array $s, string $kind): int
    {
        [$title, $body] = self::text($s, $kind);
        [$lockedTitle, $lockedBody] = self::lockedText($s);
        $isResult = in_array($kind, ['target1', 'target', 'stop', 'time'], true);
        $users = Db::all(
            'SELECT u.id, u.email, u.email_verified_at, st.* FROM users u
             JOIN user_settings st ON st.user_id = u.id
             WHERE st.all_signals = 1 OR EXISTS (SELECT 1 FROM watchlist w WHERE w.user_id = u.id AND w.symbol = ?)',
            [$s['symbol']]
        );
        $count = 0;
        foreach ($users as $u) {
            // Users only get alerts for their own timeframe (swing = daily, intraday = hourly).
            if ($u['style'] !== $s['tf']) continue;
            // Free users are told a signal fired; the entry/stop/target levels are a Premium feature.
            $locked = $kind === 'new_buy' && !Premium::isActive((int) $u['id']);
            [$t, $b] = $locked ? [$lockedTitle, $lockedBody] : [$title, $body];
            $inserted = Db::exec(
                'INSERT IGNORE INTO notifications (user_id, signal_id, kind, symbol, title, body, created_at) VALUES (?,?,?,?,?,?,?)',
                [$u['id'], $s['id'], $kind, $s['symbol'], $t, $b, Db::now()]
            );
            if ($inserted !== 1) continue; // already told this user
            $nid = (int) Db::pdo()->lastInsertId();
            $count++;

            $wantPush = $isResult ? $u['push_results'] : $u['push_signals'];
            if ($wantPush && Push::toUser((int) $u['id'], $t, $b, [
                'type' => $kind, 'signal_id' => (string) $s['id'], 'symbol' => $s['symbol'],
            ])) {
                Db::exec('UPDATE notifications SET pushed = 1 WHERE id = ?', [$nid]);
            }

            $wantEmail = $isResult ? $u['email_results'] : $u['email_signals'];
            if ($wantEmail && $u['email_verified_at']) {
                Mailer::queue($u['email'], $t, Templates::signalEmail($s, $kind, $t, $b, $locked));
                Db::exec('UPDATE notifications SET emailed = 1 WHERE id = ?', [$nid]);
            }
        }
        return $count;
    }

    /** In-app inbox + push + email for account events (payments, payouts). */
    public static function system(int $userId, string $title, string $body, bool $email = true): void
    {
        Db::exec('INSERT INTO notifications (user_id, signal_id, kind, symbol, title, body, created_at) VALUES (?,?,?,?,?,?,?)',
            [$userId, null, 'system', '', mb_substr($title, 0, 190), mb_substr($body, 0, 500), Db::now()]);
        Push::toUser($userId, $title, $body, ['type' => 'system']);
        $u = Db::one('SELECT email, email_verified_at FROM users WHERE id = ?', [$userId]);
        if ($email && $u && $u['email_verified_at']) {
            Mailer::queue($u['email'], $title, Templates::email(Fmt::e($title), Fmt::e($body), "$title\n$body"));
        }
    }

    /** @return array{0:string, 1:string} */
    public static function lockedText(array $s): array
    {
        $tfLabel = $s['tf'] === '1d' ? 'daily' : 'hourly';
        return ["BUY signal · {$s['symbol']}",
            "New $tfLabel buy signal, strength {$s['strength']}/4. Entry, stop-loss and targets are available with Premium."];
    }

    /** @return array{0:string, 1:string} */
    public static function text(array $s, string $kind): array
    {
        $sym = $s['symbol'];
        $tfLabel = $s['tf'] === '1d' ? 'daily' : 'hourly';
        $p = fn($k) => Fmt::price((float) $s[$k]);
        return match ($kind) {
            'new_buy' => ["BUY signal · $sym",
                "Entry ≈ {$p('signal_price')} · Stop {$p('stop_loss')} · Target {$p('target2')} (2:1). Strength {$s['strength']}/4 · $tfLabel."],
            'bearish' => ["Bearish warning · $sym",
                "Downtrend momentum confirmed at {$p('signal_price')} ($tfLabel). Avoid new buys and review any open position."],
            'target1' => ["Target 1 reached · $sym",
                "Price hit {$p('live_target1')}. Consider taking partial profit and moving your stop to entry ({$p('entry_price')})."],
            'target' => ["✅ Target hit · $sym",
                'Closed at ' . $p('exit_price') . ' (' . Fmt::pct((float) $s['return_pct']) . ', ' . Fmt::r((float) $s['r_multiple']) . ').'],
            'stop' => ["Stop-loss hit · $sym",
                'Closed at ' . $p('exit_price') . ' (' . Fmt::pct((float) $s['return_pct']) . ', ' . Fmt::r((float) $s['r_multiple']) . '). Losses are part of the plan.'],
            'time' => ["Time exit · $sym",
                'Closed after ' . Engine::MAX_HOLD . ' bars at ' . $p('exit_price') . ' (' . Fmt::pct((float) $s['return_pct']) . ', ' . Fmt::r((float) $s['r_multiple']) . ').'],
            default => ["Update · $sym", ''],
        };
    }
}
