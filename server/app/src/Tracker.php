<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * The live signal tracker, run by cron/scan.php.
 *
 * On every run, for each symbol and timeframe it:
 *   1. moves existing trades forward: pending → open (entry at the next bar's open)
 *      → won/lost when the stop, the final target or the time limit is reached;
 *   2. records any NEW signal on bars it hasn't seen before;
 *   3. notifies users (in-app inbox, push, email) about each event.
 *
 * Every signal is stored when it is issued and is never edited afterwards
 * except to record what actually happened, so the track record is verifiable.
 * The official record is long-only; SELL signals are published as bearish warnings.
 */
final class Tracker
{
    public const MIN_BARS = 210;

    /** @return list<string> */
    public static function universe(): array
    {
        $default = array_map('strtoupper', (array) Config::get('universe.default', []));
        $popular = array_column(Db::all(
            'SELECT symbol, COUNT(*) AS n FROM watchlist GROUP BY symbol ORDER BY n DESC, symbol LIMIT 500'
        ), 'symbol');
        $all = array_values(array_unique([...$default, ...$popular]));
        return array_slice($all, 0, (int) Config::get('universe.max_symbols', 80));
    }

    public static function run(?array $symbols = null, ?array $tfs = null): array
    {
        $scanId = Db::insert('scan_log', ['started_at' => Db::now()]);
        $symbols ??= self::universe();
        $tfs ??= (array) Config::get('universe.timeframes', ['1d', '1h']);
        $totals = ['series' => 0, 'new' => 0, 'closed' => 0, 'errors' => []];
        foreach ($symbols as $sym) {
            foreach ($tfs as $tf) {
                try {
                    $r = self::scanSeries($sym, $tf);
                    $totals['series']++;
                    $totals['new'] += $r['new'];
                    $totals['closed'] += $r['closed'];
                } catch (\Throwable $e) {
                    $totals['errors'][] = "$sym/$tf: " . $e->getMessage();
                    Db::exec(
                        'INSERT INTO tracked_series (symbol, tf, tracked_since, last_scan_at, last_error) VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE last_scan_at = VALUES(last_scan_at), last_error = VALUES(last_error)',
                        [$sym, $tf, Db::now(), Db::now(), mb_substr($e->getMessage(), 0, 250)]
                    );
                }
            }
        }
        Db::update('scan_log', [
            'finished_at' => Db::now(),
            'series' => $totals['series'],
            'new_signals' => $totals['new'],
            'closed' => $totals['closed'],
            'errors' => $totals['errors'] ? mb_substr(implode("\n", $totals['errors']), 0, 60000) : null,
        ], 'id = ?', [$scanId]);
        return $totals;
    }

    /** @return array{new:int, closed:int} */
    public static function scanSeries(string $sym, string $tf): array
    {
        $data = Market::fetch($sym, $tf);
        $C = $data['candles'];
        $row = Db::one('SELECT * FROM tracked_series WHERE symbol = ? AND tf = ?', [$sym, $tf]);
        if (!$row) {
            Db::insert('tracked_series', ['symbol' => $sym, 'tf' => $tf, 'tracked_since' => Db::now()]);
            $row = ['last_bar_time' => null];
        }
        $closed = 0;
        $new = 0;

        // 1) advance running trades
        foreach (Db::all("SELECT * FROM signals WHERE symbol = ? AND tf = ? AND status IN ('pending','open') ORDER BY id", [$sym, $tf]) as $s) {
            if (self::advance($s, $C, $data['last_price'])) $closed++;
        }

        // 2) record new signals on bars we haven't processed yet
        if (count($C) >= self::MIN_BARS) {
            $lastBar = $C[count($C) - 1]['t'];
            $prev = $row['last_bar_time'] ? strtotime($row['last_bar_time'] . ' UTC') : null;
            foreach (Engine::signals($C) as $sig) {
                $isNew = $prev === null ? $sig['t'] === $lastBar : $sig['t'] > $prev;
                if (!$isNew) continue;
                $id = self::record($sym, $tf, $sig);
                if ($id === null) continue;
                $new++;
                $s = Db::one('SELECT * FROM signals WHERE id = ?', [$id]);
                Notifier::signalEvent($s, $s['kind'] === 'trade' ? 'new_buy' : 'bearish');
                if ($s['kind'] === 'trade' && self::advance($s, $C, $data['last_price'])) $closed++;
            }
            Db::update('tracked_series', [
                'name' => mb_substr($data['name'], 0, 190),
                'last_bar_time' => gmdate('Y-m-d H:i:s', $lastBar),
                'last_price' => $data['last_price'],
                'last_scan_at' => Db::now(),
                'last_error' => null,
            ], 'symbol = ? AND tf = ?', [$sym, $tf]);
        } else {
            Db::update('tracked_series', ['name' => mb_substr($data['name'], 0, 190), 'last_scan_at' => Db::now(),
                'last_error' => 'Not enough history yet'], 'symbol = ? AND tf = ?', [$sym, $tf]);
        }
        return ['new' => $new, 'closed' => $closed];
    }

    /** Inserts a signal. Returns null if it already exists or a trade is already running. */
    private static function record(string $sym, string $tf, array $sig): ?int
    {
        $kind = $sig['buy'] ? 'trade' : 'warning';
        if ($kind === 'trade' && Db::value(
            "SELECT id FROM signals WHERE symbol = ? AND tf = ? AND kind = 'trade' AND status IN ('pending','open')",
            [$sym, $tf]
        )) {
            return null; // one position per stock and timeframe, like the backtest
        }
        $now = Db::now();
        $inserted = Db::exec(
            'INSERT IGNORE INTO signals (symbol, tf, direction, kind, status, signal_time, signal_price, stop_loss, target1, target2,
              atr, strength, reasons, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$sym, $tf, $sig['buy'] ? 'buy' : 'sell', $kind, $kind === 'trade' ? 'pending' : 'warning',
                gmdate('Y-m-d H:i:s', $sig['t']), $sig['price'], $sig['sl'], $sig['tp1'], $sig['tp2'], $sig['atr'],
                $sig['strength'], json_encode($sig['reasons']), $now, $now]
        );
        return $inserted === 1 ? (int) Db::pdo()->lastInsertId() : null;
    }

    /** Moves one trade forward. Returns true if it closed during this call. */
    public static function advance(array $s, array $C, float $lastPrice): bool
    {
        $now = Db::now();
        $signalTs = strtotime($s['signal_time'] . ' UTC');

        if ($s['status'] === 'pending') {
            $entryBar = null;
            foreach ($C as $c) {
                if ($c['t'] > $signalTs) { $entryBar = $c; break; }
            }
            if ($entryBar === null) {
                Db::update('signals', ['last_price' => $lastPrice, 'updated_at' => $now], 'id = ?', [$s['id']]);
                return false;
            }
            $entry = $entryBar['o'];
            $risk = (float) $s['signal_price'] - (float) $s['stop_loss'];
            $s['entry_time'] = gmdate('Y-m-d H:i:s', $entryBar['t']);
            $s['entry_price'] = $entry;
            $s['live_stop'] = $entry - $risk;
            $s['live_target1'] = $entry + ((float) $s['target1'] - (float) $s['signal_price']);
            $s['live_target2'] = $entry + ((float) $s['target2'] - (float) $s['signal_price']);
            $s['status'] = 'open';
            Db::update('signals', [
                'status' => 'open', 'entry_time' => $s['entry_time'], 'entry_price' => $entry,
                'live_stop' => $s['live_stop'], 'live_target1' => $s['live_target1'], 'live_target2' => $s['live_target2'],
                'updated_at' => $now,
            ], 'id = ?', [$s['id']]);
        }

        if ($s['status'] !== 'open') return false;
        $entryTs = strtotime($s['entry_time'] . ' UTC');
        $bars = array_values(array_filter($C, fn($c) => $c['t'] >= $entryTs));
        if (!$bars) return false;
        $entry = (float) $s['entry_price'];
        $sl = (float) $s['live_stop'];
        $tp = (float) $s['live_target2'];
        $t1 = (float) $s['live_target1'];
        $exit = Engine::resolveLong($bars, $sl, $tp);

        // Partial target (Target 1) reached before the trade closed?
        if ($s['tp1_hit_time'] === null) {
            $limit = $exit ? $exit['j'] : count($bars) - 1;
            for ($k = 0; $k <= $limit; $k++) {
                $b = $bars[$k];
                $stoppedHere = $exit && $k === $exit['j'] && $exit['reason'] === 'stop';
                if ($b['h'] >= $t1 && !$stoppedHere) {
                    $s['tp1_hit_time'] = gmdate('Y-m-d H:i:s', $b['t']);
                    Db::update('signals', ['tp1_hit_time' => $s['tp1_hit_time']], 'id = ?', [$s['id']]);
                    // Only announce separately if the trade is still running.
                    if (!$exit || $exit['reason'] !== 'target' || $exit['j'] !== $k) {
                        Notifier::signalEvent($s, 'target1');
                    }
                    break;
                }
            }
        }

        if ($exit === null) {
            Db::update('signals', [
                'last_price' => $lastPrice,
                'bars_held' => count($bars),
                'last_checked_time' => gmdate('Y-m-d H:i:s', $bars[count($bars) - 1]['t']),
                'updated_at' => $now,
            ], 'id = ?', [$s['id']]);
            return false;
        }

        $px = $exit['price'];
        $risk = $entry - $sl;
        $ret = ($px - $entry) / $entry * 100 - Engine::COST_PCT;
        $r = $risk > 0 ? (($px - $entry) - $entry * Engine::COST_PCT / 100) / $risk : 0.0;
        $upd = [
            'status' => $ret > 0 ? 'won' : 'lost',
            'exit_time' => gmdate('Y-m-d H:i:s', $bars[$exit['j']]['t']),
            'exit_price' => $px,
            'exit_reason' => $exit['reason'],
            'return_pct' => round($ret, 4),
            'r_multiple' => round($r, 4),
            'bars_held' => $exit['j'] + 1,
            'last_price' => $lastPrice,
            'last_checked_time' => gmdate('Y-m-d H:i:s', $bars[$exit['j']]['t']),
            'updated_at' => $now,
        ];
        Db::update('signals', $upd, 'id = ?', [$s['id']]);
        Notifier::signalEvent(array_merge($s, $upd), $exit['reason']);
        return true;
    }
}
