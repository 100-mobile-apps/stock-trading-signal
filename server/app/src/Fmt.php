<?php
declare(strict_types=1);

namespace SignalLab;

final class Fmt
{
    public static function iso(?string $mysqlUtc): ?string
    {
        if ($mysqlUtc === null || $mysqlUtc === '') return null;
        return str_replace(' ', 'T', $mysqlUtc) . 'Z';
    }

    public static function price(float $v): string
    {
        $a = abs($v);
        if ($a >= 1000) return number_format($v, 2);
        if ($a >= 1) return number_format($v, 2, '.', '');
        return number_format($v, 4, '.', '');
    }

    public static function pct(float $v, int $d = 1): string
    {
        return ($v > 0 ? '+' : '') . number_format($v, $d, '.', '') . '%';
    }

    public static function r(float $v): string
    {
        return ($v > 0 ? '+' : '') . number_format($v, 2, '.', '') . 'R';
    }

    public static function date(string $mysqlUtc, bool $time = false): string
    {
        $t = new \DateTimeImmutable($mysqlUtc, new \DateTimeZone('UTC'));
        return $t->format($time ? 'j M Y, H:i \U\T\C' : 'j M Y');
    }

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
