<?php
declare(strict_types=1);

namespace SignalLab;

/** Public URLs. `base_url` is the site root (e.g. https://stocksignal.bulksend.top); the API lives at /api. */
final class Urls
{
    public static function site(string $path = '/'): string
    {
        return rtrim((string) Config::get('base_url'), '/') . '/' . ltrim($path, '/');
    }

    public static function api(string $path = '/'): string
    {
        return self::site('/api/' . ltrim($path, '/'));
    }
}
