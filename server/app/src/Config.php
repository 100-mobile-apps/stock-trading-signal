<?php
declare(strict_types=1);

namespace SignalLab;

final class Config
{
    private static array $c = [];

    public static function load(array $c): void
    {
        self::$c = $c;
    }

    /** Dot-path lookup, e.g. Config::get('mail.host'). */
    public static function get(string $path, mixed $default = null): mixed
    {
        $v = self::$c;
        foreach (explode('.', $path) as $k) {
            if (!is_array($v) || !array_key_exists($k, $v)) return $default;
            $v = $v[$k];
        }
        return $v;
    }

    public static function set(string $path, mixed $value): void
    {
        $ref = &self::$c;
        foreach (explode('.', $path) as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = $ref[$k] ?? [];
            $ref = &$ref[$k];
        }
        $ref = $value;
    }
}
