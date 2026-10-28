<?php
declare(strict_types=1);

namespace SignalLab;

/** Daily / weekly / monthly performance emails (cron/reports.php). */
final class Reports
{
    /**
     * Sends the report for the most recently COMPLETED period:
     *   daily → today (run after the US close), weekly → the week that just ended,
     *   monthly → last month. Safe to run repeatedly; each user gets one per period.
     */
    public static function send(string $period): int
    {
        [$key, $from, $to] = self::targetPeriod($period);
        $col = ['daily' => 'report_daily', 'weekly' => 'report_weekly', 'monthly' => 'report_monthly'][$period];
        $users = Db::all(
            "SELECT u.id, u.email, u.name, st.* FROM users u JOIN user_settings st ON st.user_id = u.id
             WHERE st.$col = 1 AND u.email_verified_at IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM report_log r WHERE r.user_id = u.id AND r.period = ? AND r.period_key = ?)",
            [$period, $key]
        );
        $sent = 0;
        foreach ($users as $u) {
            $data = self::build((int) $u['id'], $u['style'], $period, $from, $to, (float) $u['risk_pct']);
            $label = Performance::label($period, $from, $to);
            $subject = sprintf('Your %s Stock Trading Signals report · %s', $period, $label);
            Mailer::queue($u['email'], $subject, Templates::reportEmail($u['name'], $period, $label, $data));
            Db::insert('report_log', ['user_id' => $u['id'], 'period' => $period, 'period_key' => $key, 'sent_at' => Db::now()]);
            $o = $data['overall'];
            Db::exec(
                'INSERT INTO notifications (user_id, signal_id, kind, symbol, title, body, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?)',
                [$u['id'], 'report', '', ucfirst($period) . ' report · ' . $label,
                    sprintf('%d closed · %d wins · %s · %d new signals. Full report sent to your email.',
                        $o['trades'], $o['wins'], Fmt::r((float) $o['total_r']), $data['issued']), Db::now()]
            );
            $sent++;
        }
        return $sent;
    }

    /** @return array{0:string,1:string,2:string} */
    public static function targetPeriod(string $period): array
    {
        $now = Clock::now();
        return match ($period) {
            'daily' => Performance::periodOf('daily', $now),
            'weekly' => Performance::periodOf('weekly', $now->modify('-2 days')), // run Sat/Sun → Mon–Sun just ending
            'monthly' => Performance::periodOf('monthly', $now->modify('first day of last month')),
            default => throw new \InvalidArgumentException('period must be daily, weekly or monthly'),
        };
    }

    public static function build(int $userId, string $tf, string $period, string $from, string $to, float $riskPct): array
    {
        $in = fn(string $col) => "DATE($col) BETWEEN ? AND ?";
        $closedAll = Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('won','lost') AND tf = ? AND {$in('exit_time')} ORDER BY exit_time", [$tf, $from, $to]);
        $symbols = array_column(Db::all('SELECT symbol FROM watchlist WHERE user_id = ?', [$userId]), 'symbol');
        $mine = array_values(array_filter($closedAll, fn($r) => in_array($r['symbol'], $symbols, true)));
        $issued = Db::all("SELECT * FROM signals WHERE tf = ? AND {$in('signal_time')} ORDER BY signal_time", [$tf, $from, $to]);
        $open = Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('pending','open') AND tf = ? ORDER BY signal_time", [$tf]);
        $all = Performance::stats(Db::all("SELECT * FROM signals WHERE kind='trade' AND status IN ('won','lost') AND tf = ? ORDER BY exit_time", [$tf]), $riskPct);
        return [
            'tf' => $tf,
            'overall' => Performance::stats($closedAll, $riskPct),
            'watchlist' => Performance::stats($mine, $riskPct),
            'all_time' => $all,
            'closed' => $closedAll,
            'issued' => count($issued),
            'new_signals' => $issued,
            'open' => $open,
            'risk_pct' => $riskPct,
        ];
    }
}
