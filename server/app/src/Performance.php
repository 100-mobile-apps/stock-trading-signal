<?php
declare(strict_types=1);

namespace SignalLab;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Track-record statistics built only from signals the server actually issued
 * and closed (never from backtests). Periods are in UTC.
 */
final class Performance
{
    /**
     * @param list<string>|null $symbols limit to these symbols (null = everything tracked)
     */
    public static function report(?array $symbols, ?string $tf, float $riskPct = 1.0): array
    {
        [$where, $params] = self::filter($symbols, $tf);
        $closed = Db::all("SELECT * FROM signals WHERE kind = 'trade' AND status IN ('won','lost') $where ORDER BY exit_time, id", $params);
        $issued = Db::all("SELECT id, kind, direction, signal_time FROM signals WHERE 1=1 $where ORDER BY signal_time", $params);
        $open = (int) Db::value("SELECT COUNT(*) FROM signals WHERE kind = 'trade' AND status IN ('pending','open') $where", $params);
        $since = Db::value("SELECT MIN(tracked_since) FROM tracked_series" . ($tf ? ' WHERE tf = ?' : ''), $tf ? [$tf] : []);

        $now = Clock::now();
        $today = $now->format('Y-m-d');
        $weekStart = $now->modify('monday this week')->format('Y-m-d');
        $monthStart = $now->format('Y-m-01');
        $inRange = fn(array $rows, string $from) => array_values(array_filter($rows, fn($r) => substr($r['exit_time'], 0, 10) >= $from));

        $equity = [];
        $e = 1.0;
        foreach ($closed as $r) {
            $e *= 1 + (float) $r['r_multiple'] * $riskPct / 100;
            $equity[] = ['t' => Fmt::iso($r['exit_time']), 'equity' => round($e, 6)];
        }

        return [
            'record_started' => Fmt::iso($since),
            'risk_pct' => $riskPct,
            'open_trades' => $open,
            'summary' => [
                'today' => self::stats($inRange($closed, $today), $riskPct) + ['issued' => self::countIssued($issued, $today)],
                'week' => self::stats($inRange($closed, $weekStart), $riskPct) + ['issued' => self::countIssued($issued, $weekStart)],
                'month' => self::stats($inRange($closed, $monthStart), $riskPct) + ['issued' => self::countIssued($issued, $monthStart)],
                'all' => self::stats($closed, $riskPct) + ['issued' => count($issued)],
            ],
            'daily' => self::buckets($closed, $issued, 'daily', 30, $riskPct),
            'weekly' => self::buckets($closed, $issued, 'weekly', 12, $riskPct),
            'monthly' => self::buckets($closed, $issued, 'monthly', 12, $riskPct),
            'equity' => $equity,
            'by_symbol' => self::bySymbol($closed),
        ];
    }

    public static function stats(array $rows, float $riskPct): array
    {
        $n = count($rows);
        $wins = count(array_filter($rows, fn($r) => $r['status'] === 'won'));
        $gp = $gl = $totR = 0.0;
        $eq = $peak = 1.0;
        $dd = 0.0;
        $streak = $worstStreak = 0;
        $best = $worst = null;
        foreach ($rows as $r) {
            $R = (float) $r['r_multiple'];
            $totR += $R;
            if ($R > 0) $gp += $R; else $gl -= $R;
            $eq *= 1 + $R * $riskPct / 100;
            $peak = max($peak, $eq);
            $dd = max($dd, ($peak - $eq) / $peak);
            if ($r['status'] === 'won') { $streak = 0; } else { $streak++; $worstStreak = max($worstStreak, $streak); }
            $best = $best === null ? $R : max($best, $R);
            $worst = $worst === null ? $R : min($worst, $R);
        }
        return [
            'trades' => $n,
            'wins' => $wins,
            'losses' => $n - $wins,
            'win_rate' => $n ? round($wins / $n * 100, 1) : 0.0,
            'total_r' => round($totR, 2),
            'avg_r' => $n ? round($totR / $n, 2) : 0.0,
            'profit_factor' => $gl > 0 ? round($gp / $gl, 2) : ($gp > 0 ? null : 0.0), // null = no losses yet
            'return_pct' => round(($eq - 1) * 100, 2),
            'max_drawdown_pct' => round($dd * 100, 2),
            'losing_streak' => $worstStreak,
            'best_r' => $best === null ? null : round($best, 2),
            'worst_r' => $worst === null ? null : round($worst, 2),
            'targets' => count(array_filter($rows, fn($r) => $r['exit_reason'] === 'target')),
            'stops' => count(array_filter($rows, fn($r) => $r['exit_reason'] === 'stop')),
            'time_exits' => count(array_filter($rows, fn($r) => $r['exit_reason'] === 'time')),
        ];
    }

    /** Period key and range (UTC) for a timestamp. */
    public static function periodOf(string $period, DateTimeImmutable $t): array
    {
        return match ($period) {
            'daily' => [$t->format('Y-m-d'), $t->format('Y-m-d'), $t->format('Y-m-d')],
            'weekly' => (function () use ($t) {
                $mon = $t->modify('monday this week');
                return [$mon->format('o-\WW'), $mon->format('Y-m-d'), $mon->modify('+6 days')->format('Y-m-d')];
            })(),
            'monthly' => [$t->format('Y-m'), $t->format('Y-m-01'), $t->format('Y-m-t')],
        };
    }

    public static function label(string $period, string $from, string $to): string
    {
        $f = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $t = new DateTimeImmutable($to, new DateTimeZone('UTC'));
        return match ($period) {
            'daily' => $f->format('D j M'),
            'weekly' => $f->format('j M') . ' – ' . $t->format('j M'),
            'monthly' => $f->format('F Y'),
        };
    }

    /** Most recent $limit periods (newest first), including empty ones since the record began. */
    private static function buckets(array $closed, array $issued, string $period, int $limit, float $riskPct): array
    {
        $now = Clock::now();
        $step = ['daily' => '-1 day', 'weekly' => '-1 week', 'monthly' => 'first day of last month'][$period];
        $firstIssued = $issued ? substr($issued[0]['signal_time'], 0, 10) : $now->format('Y-m-d');
        $out = [];
        $cursor = $now;
        for ($i = 0; $i < $limit; $i++) {
            [$key, $from, $to] = self::periodOf($period, $cursor);
            if ($to < $firstIssued && $i > 0) break;
            $rows = array_values(array_filter($closed, fn($r) => ($d = substr($r['exit_time'], 0, 10)) >= $from && $d <= $to));
            $iss = array_filter($issued, fn($r) => ($d = substr($r['signal_time'], 0, 10)) >= $from && $d <= $to);
            $out[] = [
                'key' => $key,
                'label' => self::label($period, $from, $to),
                'from' => $from,
                'to' => $to,
                'issued' => count($iss),
                'buy_signals' => count(array_filter($iss, fn($r) => $r['kind'] === 'trade')),
                'warnings' => count(array_filter($iss, fn($r) => $r['kind'] === 'warning')),
            ] + self::stats($rows, $riskPct);
            $cursor = $period === 'monthly' ? $cursor->modify('first day of last month') : $cursor->modify($step);
        }
        return $out;
    }

    private static function bySymbol(array $closed): array
    {
        $g = [];
        foreach ($closed as $r) {
            $s = $r['symbol'];
            $g[$s] ??= ['symbol' => $s, 'trades' => 0, 'wins' => 0, 'total_r' => 0.0];
            $g[$s]['trades']++;
            if ($r['status'] === 'won') $g[$s]['wins']++;
            $g[$s]['total_r'] += (float) $r['r_multiple'];
        }
        $out = array_values(array_map(fn($x) => $x + [
            'win_rate' => round($x['wins'] / $x['trades'] * 100, 1),
        ], $g));
        foreach ($out as &$x) $x['total_r'] = round($x['total_r'], 2);
        usort($out, fn($a, $b) => $b['total_r'] <=> $a['total_r']);
        return $out;
    }

    private static function countIssued(array $issued, string $from): int
    {
        return count(array_filter($issued, fn($r) => substr($r['signal_time'], 0, 10) >= $from));
    }

    private static function filter(?array $symbols, ?string $tf): array
    {
        $where = '';
        $params = [];
        if ($tf) { $where .= ' AND tf = ?'; $params[] = $tf; }
        if ($symbols !== null) {
            if (!$symbols) return [' AND 1=0', []];
            $where .= ' AND symbol IN (' . implode(',', array_fill(0, count($symbols), '?')) . ')';
            array_push($params, ...$symbols);
        }
        return [$where, $params];
    }
}
