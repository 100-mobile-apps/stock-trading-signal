<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * PHP port of the app's signal engine (lib/engine/*.dart). The rules must stay
 * identical so the app's backtests and the server's live record agree.
 *
 * Candles: list of ['t' => unix seconds, 'o', 'h', 'l', 'c', 'v'].
 */
final class Engine
{
    public const FAST = 50;
    public const SLOW = 200;
    public const STOP_ATR = 1.5;
    public const TP1_ATR = 1.5;
    public const TP2_ATR = 3.0;
    public const MAX_HOLD = 20;
    public const COST_PCT = 0.1;

    public static function ema(array $v, int $p): array
    {
        $n = count($v);
        $out = array_fill(0, $n, null);
        if ($n < $p) return $out;
        $prev = array_sum(array_slice($v, 0, $p)) / $p;
        $out[$p - 1] = $prev;
        $k = 2 / ($p + 1);
        for ($i = $p; $i < $n; $i++) {
            $prev = $v[$i] * $k + $prev * (1 - $k);
            $out[$i] = $prev;
        }
        return $out;
    }

    public static function sma(array $v, int $p): array
    {
        $n = count($v);
        $out = array_fill(0, $n, null);
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $v[$i];
            if ($i >= $p) $sum -= $v[$i - $p];
            if ($i >= $p - 1) $out[$i] = $sum / $p;
        }
        return $out;
    }

    public static function rsi(array $c, int $p = 14): array
    {
        $n = count($c);
        $out = array_fill(0, $n, null);
        if ($n <= $p) return $out;
        $g = $l = 0.0;
        for ($i = 1; $i <= $p; $i++) {
            $d = $c[$i] - $c[$i - 1];
            if ($d > 0) $g += $d; else $l -= $d;
        }
        $ag = $g / $p;
        $al = $l / $p;
        $out[$p] = self::rsiVal($ag, $al);
        for ($i = $p + 1; $i < $n; $i++) {
            $d = $c[$i] - $c[$i - 1];
            $ag = ($ag * ($p - 1) + max($d, 0)) / $p;
            $al = ($al * ($p - 1) + max(-$d, 0)) / $p;
            $out[$i] = self::rsiVal($ag, $al);
        }
        return $out;
    }

    private static function rsiVal(float $g, float $l): float
    {
        if ($l == 0.0) return $g == 0.0 ? 50.0 : 100.0;
        return 100 - 100 / (1 + $g / $l);
    }

    /** @return array{0: array, 1: array} [line, signal] */
    public static function macd(array $c): array
    {
        $n = count($c);
        $f = self::ema($c, 12);
        $s = self::ema($c, 26);
        $line = array_fill(0, $n, null);
        for ($i = 0; $i < $n; $i++) {
            if ($f[$i] !== null && $s[$i] !== null) $line[$i] = $f[$i] - $s[$i];
        }
        $sig = array_fill(0, $n, null);
        $start = null;
        foreach ($line as $i => $x) {
            if ($x !== null) { $start = $i; break; }
        }
        if ($start !== null) {
            $e = self::ema(array_slice($line, $start), 9);
            foreach ($e as $j => $x) {
                if ($x !== null) $sig[$start + $j] = $x;
            }
        }
        return [$line, $sig];
    }

    public static function atr(array $C, int $p = 14): array
    {
        $n = count($C);
        $out = array_fill(0, $n, null);
        if ($n < $p) return $out;
        $tr = [];
        foreach ($C as $i => $c) {
            $tr[$i] = $i === 0 ? $c['h'] - $c['l']
                : max($c['h'] - $c['l'], abs($c['h'] - $C[$i - 1]['c']), abs($c['l'] - $C[$i - 1]['c']));
        }
        $prev = array_sum(array_slice($tr, 0, $p)) / $p;
        $out[$p - 1] = $prev;
        for ($i = $p; $i < $n; $i++) {
            $prev = ($prev * ($p - 1) + $tr[$i]) / $p;
            $out[$i] = $prev;
        }
        return $out;
    }

    /**
     * All signals on the given (closed) candles.
     * @return list<array{i:int,t:int,buy:bool,price:float,sl:float,tp1:float,tp2:float,atr:float,strength:int,reasons:list<string>}>
     */
    public static function signals(array $C): array
    {
        $n = count($C);
        if ($n < 2) return [];
        $cl = array_column($C, 'c');
        $f = self::ema($cl, self::FAST);
        $s = self::ema($cl, self::SLOW);
        $r = self::rsi($cl);
        [$ml, $ms] = self::macd($cl);
        $a = self::atr($C);
        $va = self::sma(array_column($C, 'v'), 20);
        $ready = fn(int $i) => $f[$i] !== null && $s[$i] !== null && $r[$i] !== null && $ml[$i] !== null && $ms[$i] !== null && $a[$i] !== null;
        $start = $n;
        for ($i = 1; $i < $n; $i++) {
            if ($ready($i) && $ready($i - 1)) { $start = $i; break; }
        }
        $out = [];
        for ($i = $start; $i < $n; $i++) {
            $up = $ml[$i - 1] <= $ms[$i - 1] && $ml[$i] > $ms[$i];
            $dn = $ml[$i - 1] >= $ms[$i - 1] && $ml[$i] < $ms[$i];
            if (!$up && !$dn) continue;
            $c = $C[$i];
            $atr = $a[$i];
            if ($atr <= 0) continue;
            $rsi = $r[$i];
            $volUp = $va[$i] !== null && $va[$i] > 0 && $c['v'] > $va[$i];
            if ($up && $c['c'] > $s[$i] && $f[$i] > $s[$i] && $rsi >= 40 && $rsi <= 70) {
                $reasons = ['Uptrend: price and 50-EMA above 200-EMA', 'MACD crossed above its signal line', sprintf('RSI %.0f (not overbought)', $rsi)];
                $st = 2;
                if ($volUp) { $st++; $reasons[] = 'Volume above 20-bar average'; }
                if ($rsi >= 50 && $rsi <= 65) { $st++; $reasons[] = 'RSI in the healthy momentum zone (50–65)'; }
                $out[] = ['i' => $i, 't' => $c['t'], 'buy' => true, 'price' => $c['c'], 'sl' => $c['c'] - self::STOP_ATR * $atr,
                    'tp1' => $c['c'] + self::TP1_ATR * $atr, 'tp2' => $c['c'] + self::TP2_ATR * $atr, 'atr' => $atr, 'strength' => $st, 'reasons' => $reasons];
            } elseif ($dn && $c['c'] < $s[$i] && $f[$i] < $s[$i] && $rsi >= 30 && $rsi <= 60) {
                $reasons = ['Downtrend: price and 50-EMA below 200-EMA', 'MACD crossed below its signal line', sprintf('RSI %.0f (not oversold)', $rsi)];
                $st = 2;
                if ($volUp) { $st++; $reasons[] = 'Volume above 20-bar average'; }
                if ($rsi >= 35 && $rsi <= 50) { $st++; $reasons[] = 'RSI in the bearish momentum zone (35–50)'; }
                $out[] = ['i' => $i, 't' => $c['t'], 'buy' => false, 'price' => $c['c'], 'sl' => $c['c'] + self::STOP_ATR * $atr,
                    'tp1' => $c['c'] - self::TP1_ATR * $atr, 'tp2' => $c['c'] - self::TP2_ATR * $atr, 'atr' => $atr, 'strength' => $st, 'reasons' => $reasons];
            }
        }
        return $out;
    }

    /**
     * Walks bars for an open long trade (same rules as the app's backtester).
     * Bars are those from the entry bar onward. Returns null while still open.
     * @return array{j:int, price:float, reason:string}|null
     */
    public static function resolveLong(array $bars, float $sl, float $tp, int $barsAlreadyHeld = 0): ?array
    {
        foreach ($bars as $k => $b) {
            if ($b['o'] <= $sl) return ['j' => $k, 'price' => $b['o'], 'reason' => 'stop'];
            if ($b['o'] >= $tp) return ['j' => $k, 'price' => $b['o'], 'reason' => 'target'];
            if ($b['l'] <= $sl) return ['j' => $k, 'price' => $sl, 'reason' => 'stop'];
            if ($b['h'] >= $tp) return ['j' => $k, 'price' => $tp, 'reason' => 'target'];
            if ($barsAlreadyHeld + $k + 1 >= self::MAX_HOLD) return ['j' => $k, 'price' => $b['c'], 'reason' => 'time'];
        }
        return null;
    }
}
