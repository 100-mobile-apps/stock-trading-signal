<?php
declare(strict_types=1);

namespace SignalLab;

use DateTimeImmutable;
use DateTimeZone;

/** Wall clock that tests can freeze and move forward. */
final class Clock
{
    private static ?DateTimeImmutable $frozen = null;

    public static function now(): DateTimeImmutable
    {
        return self::$frozen ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function freeze(?DateTimeImmutable $t): void
    {
        self::$frozen = $t;
    }

    public static function ts(): int
    {
        return self::now()->getTimestamp();
    }
}
