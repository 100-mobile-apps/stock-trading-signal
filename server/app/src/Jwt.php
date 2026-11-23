<?php
declare(strict_types=1);

namespace SignalLab;

/** Minimal HS256 JSON Web Tokens (no dependencies). */
final class Jwt
{
    public static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        return base64_decode(strtr($s, '-_', '+/'), true);
    }

    public static function encode(array $claims, string $secret): string
    {
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode($claims));
        $sig = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        return "$h.$p.$sig";
    }

    /** Returns claims, or null when the token is malformed, forged or expired. */
    public static function decode(string $jwt, string $secret): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) return null;
        [$h, $p, $s] = $parts;
        $header = json_decode((string) self::unb64($h), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') return null;
        $expected = self::b64(hash_hmac('sha256', "$h.$p", $secret, true));
        if (!hash_equals($expected, $s)) return null;
        $claims = json_decode((string) self::unb64($p), true);
        if (!is_array($claims)) return null;
        if (isset($claims['exp']) && Clock::ts() >= (int) $claims['exp']) return null;
        return $claims;
    }
}
