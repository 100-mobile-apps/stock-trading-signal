<?php
declare(strict_types=1);

namespace SignalLab;

/** OAuth2 access tokens from a Google service-account key (RS256 JWT bearer flow). */
final class GoogleAuth
{
    /** @var array<string, array{value:string, exp:int}> */
    private static array $mem = [];

    public static function serviceAccount(string $file): ?array
    {
        if ($file === '' || !is_file($file)) return null;
        $sa = json_decode((string) file_get_contents($file), true);
        return is_array($sa) && isset($sa['private_key'], $sa['client_email']) ? $sa : null;
    }

    public static function token(array $sa, string $scope): ?string
    {
        $key = $sa['client_email'] . '|' . $scope;
        if (isset(self::$mem[$key]) && self::$mem[$key]['exp'] > time() + 60) return self::$mem[$key]['value'];
        $cache = __DIR__ . '/../storage/gtoken_' . substr(hash('sha256', $key), 0, 16) . '.json';
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) return (self::$mem[$key] = $c)['value'];
        }
        $now = time();
        $uri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $h = Jwt::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $c = Jwt::b64(json_encode(['iss' => $sa['client_email'], 'scope' => $scope, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600]));
        $sig = '';
        if (!openssl_sign("$h.$c", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return null;
        $res = HttpClient::post($uri, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "$h.$c." . Jwt::b64($sig),
        ]), ['Content-Type: application/x-www-form-urlencoded']);
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($j['access_token'])) {
            error_log('[SignalLab] Google auth failed: ' . substr($res['body'], 0, 300));
            return null;
        }
        self::$mem[$key] = ['value' => $j['access_token'], 'exp' => $now + (int) ($j['expires_in'] ?? 3600)];
        @file_put_contents($cache, json_encode(self::$mem[$key]));
        return self::$mem[$key]['value'];
    }
}
