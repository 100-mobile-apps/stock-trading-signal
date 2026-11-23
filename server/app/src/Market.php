<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Market data. Production uses Yahoo Finance's public chart endpoint (the same
 * source as the app). Only CLOSED bars are returned, so signals never repaint.
 */
final class Market
{
    public const TF = [
        '1d' => ['interval' => '1d', 'range' => '2y', 'seconds' => 86400],
        '1h' => ['interval' => '1h', 'range' => '6mo', 'seconds' => 3600],
    ];

    /** @return array{name:string, candles:list<array>, last_price:float} */
    public static function fetch(string $symbol, string $tf): array
    {
        return Config::get('market.provider', 'yahoo') === 'fake'
            ? self::fake($symbol, $tf)
            : self::yahoo($symbol, $tf);
    }

    private static function yahoo(string $symbol, string $tf): array
    {
        $cfg = self::TF[$tf];
        $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
            . '?range=' . $cfg['range'] . '&interval=' . $cfg['interval'] . '&includePrePost=false';
        $res = HttpClient::get($url, ['Accept: application/json']);
        if ($res['status'] !== 200) throw new \RuntimeException("Yahoo HTTP {$res['status']} for $symbol");
        $j = json_decode($res['body'], true);
        $r = $j['chart']['result'][0] ?? null;
        if (!$r) throw new \RuntimeException("No data for $symbol");
        $ts = $r['timestamp'] ?? [];
        $q = $r['indicators']['quote'][0] ?? [];
        $candles = [];
        foreach ($ts as $i => $t) {
            $o = $q['open'][$i] ?? null; $h = $q['high'][$i] ?? null; $l = $q['low'][$i] ?? null; $c = $q['close'][$i] ?? null;
            if ($o === null || $h === null || $l === null || $c === null) continue;
            $candles[] = ['t' => (int) $t, 'o' => (float) $o, 'h' => (float) $h, 'l' => (float) $l, 'c' => (float) $c, 'v' => (float) ($q['volume'][$i] ?? 0)];
        }
        if (!$candles) throw new \RuntimeException("No candles for $symbol");
        $meta = $r['meta'] ?? [];
        $last = end($candles);
        $lastPrice = (float) ($meta['regularMarketPrice'] ?? $last['c']);
        $period = $meta['currentTradingPeriod']['regular'] ?? null;
        if (!self::lastBarClosed($last['t'], $tf, $period['start'] ?? null, $period['end'] ?? null)) array_pop($candles);
        return ['name' => (string) ($meta['longName'] ?? $meta['shortName'] ?? $symbol), 'candles' => $candles, 'last_price' => $lastPrice];
    }

    /** Same rule as the app's MarketDataService._lastBarComplete. */
    public static function lastBarClosed(int $barTime, string $tf, ?int $start, ?int $end): bool
    {
        $now = Clock::ts();
        if ($start === null || $end === null) {
            return $tf === '1d' || $barTime + 3600 < $now;
        }
        $open = $now >= $start && $now < $end;
        if ($tf === '1d') return !($open && $barTime >= $start);
        return $now >= min($barTime + 3600, $end);
    }

    /** Test provider: storage/fake/SYMBOL_tf.json, revealed bar by bar as the test clock moves. */
    private static function fake(string $symbol, string $tf): array
    {
        $file = __DIR__ . '/../storage/fake/' . $symbol . '_' . $tf . '.json';
        if (!is_file($file)) throw new \RuntimeException("No data for $symbol");
        $all = json_decode((string) file_get_contents($file), true);
        $now = Clock::ts();
        $sec = self::TF[$tf]['seconds'];
        $closed = array_values(array_filter($all, fn($c) => $c['t'] + $sec <= $now));
        if (!$closed) throw new \RuntimeException("No closed bars for $symbol");
        return ['name' => $symbol . ' Inc.', 'candles' => $closed, 'last_price' => end($closed)['c']];
    }
}
