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
        $sa = GoogleAuth::serviceAccount((string) Config::get('fcm.service_account_file', ''));
        return $sa && isset($sa['project_id']) ? $sa : null;
    }

    private static function accessToken(array $sa): ?string
    {
        return GoogleAuth::token($sa, 'https://www.googleapis.com/auth/firebase.messaging');
    }
}
