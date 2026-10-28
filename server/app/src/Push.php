<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Firebase Cloud Messaging (HTTP v1). Free, and works for Android and iOS.
 * Authenticates with a service-account key (RS256 JWT → OAuth access token).
 */
final class Push
{
    /** Tests can capture messages instead of calling Google. */
    public static $transport = null;
    private static ?array $token = null;

    public static function toUser(int $userId, string $title, string $body, array $data = []): bool
    {
        $devices = Db::all('SELECT id, fcm_token FROM devices WHERE user_id = ?', [$userId]);
        $ok = false;
        foreach ($devices as $d) {
            $res = self::send($d['fcm_token'], $title, $body, $data);
            if ($res === 'ok') $ok = true;
            if ($res === 'invalid') Db::exec('DELETE FROM devices WHERE id = ?', [$d['id']]);
        }
        return $ok;
    }

    /** @return 'ok'|'invalid'|'error'|'disabled' */
    public static function send(string $deviceToken, string $title, string $body, array $data = []): string
    {
        $message = [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => array_map('strval', $data),
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'signals', 'sound' => 'default']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ];
        if (is_callable(self::$transport)) return (self::$transport)($message);

        $sa = self::serviceAccount();
        if (!$sa) return 'disabled';
        $access = self::accessToken($sa);
        if (!$access) return 'error';
        $res = HttpClient::post(
            'https://fcm.googleapis.com/v1/projects/' . $sa['project_id'] . '/messages:send',
            json_encode($message),
            ['Authorization: Bearer ' . $access, 'Content-Type: application/json']
        );
        if ($res['status'] === 200) return 'ok';
        if ($res['status'] === 404 || str_contains($res['body'], 'UNREGISTERED') || str_contains($res['body'], 'INVALID_ARGUMENT')) {
            return 'invalid';
        }
        error_log('[SignalLab] FCM ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
        return 'error';
    }

    private static function serviceAccount(): ?array
    {
        $file = (string) Config::get('fcm.service_account_file', '');
        if ($file === '' || !is_file($file)) return null;
        $sa = json_decode((string) file_get_contents($file), true);
        return is_array($sa) && isset($sa['private_key'], $sa['client_email'], $sa['project_id']) ? $sa : null;
    }

    private static function accessToken(array $sa): ?string
    {
        if (self::$token && self::$token['exp'] > time() + 60) return self::$token['value'];
        $cache = __DIR__ . '/../storage/fcm_token.json';
        if (is_file($cache)) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && ($c['exp'] ?? 0) > time() + 60) {
                self::$token = $c;
                return $c['value'];
            }
        }
        $now = time();
        $header = Jwt::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = Jwt::b64(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));
        $sig = '';
        if (!openssl_sign("$header.$claims", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) return null;
        $assertion = "$header.$claims." . Jwt::b64($sig);
        $res = HttpClient::post(
            $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($j['access_token'])) {
            error_log('[SignalLab] FCM auth failed: ' . substr($res['body'], 0, 300));
            return null;
        }
        self::$token = ['value' => $j['access_token'], 'exp' => $now + (int) ($j['expires_in'] ?? 3600)];
        @file_put_contents($cache, json_encode(self::$token));
        return self::$token['value'];
    }
}
