<?php
declare(strict_types=1);

namespace SignalLab;

final class Auth
{
    public const MIN_PASSWORD = 8;

    // ------------------------------------------------------------ sessions

    /** Issues an access token (JWT) and a rotating refresh token. */
    public static function issueTokens(int $userId): array
    {
        $now = Clock::ts();
        $ttl = (int) Config::get('access_ttl', 3600);
        $access = Jwt::encode(['sub' => $userId, 'iat' => $now, 'exp' => $now + $ttl, 'typ' => 'access'], self::secret());
        $refresh = bin2hex(random_bytes(32));
        Db::insert('refresh_tokens', [
            'user_id' => $userId,
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + (int) Config::get('refresh_ttl', 5184000)),
            'created_at' => Db::now(),
        ]);
        Db::exec('UPDATE users SET last_login_at = ? WHERE id = ?', [Db::now(), $userId]);
        return ['access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => $ttl, 'user' => self::userPayload($userId)];
    }

    public static function refresh(string $token): array
    {
        $row = Db::one(
            'SELECT * FROM refresh_tokens WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), Db::now()]
        );
        if (!$row) throw new ApiError(401, 'invalid_refresh', 'Your session has expired. Please sign in again.');
        Db::exec('UPDATE refresh_tokens SET revoked_at = ? WHERE id = ?', [Db::now(), $row['id']]);
        return self::issueTokens((int) $row['user_id']);
    }

    public static function logout(string $token): void
    {
        Db::exec('UPDATE refresh_tokens SET revoked_at = ? WHERE token_hash = ?', [Db::now(), hash('sha256', $token)]);
    }

    /** Returns the user id from the Authorization header or throws 401. */
    public static function requireUser(Request $req): int
    {
        $jwt = $req->bearer();
        $claims = $jwt ? Jwt::decode($jwt, self::secret()) : null;
        if (!$claims || ($claims['typ'] ?? '') !== 'access') {
            throw new ApiError(401, 'unauthorized', 'Please sign in.');
        }
        $id = (int) $claims['sub'];
        if (!Db::value('SELECT id FROM users WHERE id = ?', [$id])) {
            throw new ApiError(401, 'unauthorized', 'Account not found.');
        }
        return $id;
    }

    // ------------------------------------------------------------ accounts

    public static function register(string $email, string $password, string $name, string $ip): array
    {
        $email = self::normalizeEmail($email);
        self::validatePassword($password);
        self::throttle($ip, $email);
        if (Db::value('SELECT id FROM users WHERE email = ?', [$email])) {
            throw new ApiError(409, 'email_taken', 'An account with this email already exists. Try signing in.');
        }
        $id = Db::insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'name' => mb_substr($name, 0, 120),
            'created_at' => Db::now(),
        ]);
        self::ensureSettings($id);
        self::sendVerification($id);
        return self::issueTokens($id);
    }

    public static function login(string $email, string $password, string $ip): array
    {
        $email = self::normalizeEmail($email);
        self::throttle($ip, $email);
        $u = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
        if (!$u || !$u['password_hash'] || !password_verify($password, $u['password_hash'])) {
            Db::insert('login_attempts', ['ip' => $ip, 'email' => $email, 'attempted_at' => Db::now()]);
            if ($u && !$u['password_hash']) {
                throw new ApiError(401, 'use_google', 'This account uses Google sign-in. Tap "Continue with Google".');
            }
            throw new ApiError(401, 'invalid_credentials', 'Email or password is incorrect.');
        }
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        }
        return self::issueTokens((int) $u['id']);
    }

    /**
     * Signs in with a Google ID token from the app. The token is verified by
     * Google's tokeninfo endpoint and must be issued for one of our client IDs.
     */
    public static function google(string $idToken, ?callable $verifier = null): array
    {
        $info = ($verifier ?? [self::class, 'verifyGoogleToken'])($idToken);
        $aud = (string) ($info['aud'] ?? '');
        $iss = (string) ($info['iss'] ?? '');
        if (!in_array($aud, (array) Config::get('google.client_ids', []), true)
            || !in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)
            || (int) ($info['exp'] ?? 0) < Clock::ts()
            || empty($info['sub']) || empty($info['email'])) {
            throw new ApiError(401, 'google_invalid', 'Google sign-in could not be verified.');
        }
        if (!filter_var($info['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw new ApiError(401, 'google_unverified', 'Your Google email is not verified.');
        }
        $email = self::normalizeEmail((string) $info['email']);
        $sub = (string) $info['sub'];
        $u = Db::one('SELECT id, google_sub, email_verified_at FROM users WHERE google_sub = ? OR email = ? ORDER BY google_sub = ? DESC LIMIT 1', [$sub, $email, $sub]);
        if ($u) {
            $id = (int) $u['id'];
            Db::exec('UPDATE users SET google_sub = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [$sub, Db::now(), $id]);
        } else {
            $id = Db::insert('users', [
                'email' => $email,
                'name' => mb_substr((string) ($info['name'] ?? ''), 0, 120),
                'google_sub' => $sub,
                'email_verified_at' => Db::now(),
                'created_at' => Db::now(),
            ]);
            self::ensureSettings($id);
        }
        return self::issueTokens($id);
    }

    public static function verifyGoogleToken(string $idToken): array
    {
        $res = HttpClient::get('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
        if ($res['status'] !== 200) throw new ApiError(401, 'google_invalid', 'Google sign-in could not be verified.');
        return json_decode($res['body'], true) ?: [];
    }

    // ------------------------------------------------------------ email flows

    public static function sendVerification(int $userId): void
    {
        $u = Db::one('SELECT email, name, email_verified_at FROM users WHERE id = ?', [$userId]);
        if (!$u || $u['email_verified_at']) return;
        $token = self::emailToken($userId, 'verify', 60 * 60 * 48);
        $link = rtrim((string) Config::get('base_url'), '/') . '/auth/verify?token=' . $token;
        Mailer::queue($u['email'], 'Confirm your email for ' . Config::get('app_name'), Templates::verifyEmail($u['name'], $link));
    }

    public static function verifyEmail(string $token): bool
    {
        $row = self::consumeToken($token, 'verify');
        if (!$row) return false;
        Db::exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [Db::now(), $row['user_id']]);
        return true;
    }

    public static function forgotPassword(string $email, string $ip): void
    {
        $email = self::normalizeEmail($email);
        self::throttle($ip, $email);
        Db::insert('login_attempts', ['ip' => $ip, 'email' => $email, 'attempted_at' => Db::now()]);
        $u = Db::one('SELECT id, name FROM users WHERE email = ?', [$email]);
        if (!$u) return; // Don't reveal whether the email exists.
        $token = self::emailToken((int) $u['id'], 'reset', 60 * 60);
        $link = rtrim((string) Config::get('base_url'), '/') . '/auth/reset?token=' . $token;
        Mailer::queue($email, 'Reset your ' . Config::get('app_name') . ' password', Templates::resetEmail($u['name'], $link));
    }

    public static function resetPassword(string $token, string $password): void
    {
        self::validatePassword($password);
        $row = self::consumeToken($token, 'reset');
        if (!$row) throw new ApiError(400, 'invalid_token', 'This reset link is invalid or has expired.');
        Db::exec('UPDATE users SET password_hash = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), Db::now(), $row['user_id']]);
        // Sign out everywhere after a reset.
        Db::exec('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [Db::now(), $row['user_id']]);
    }

    public static function changePassword(int $userId, string $current, string $new): void
    {
        $hash = Db::value('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if ($hash && !password_verify($current, (string) $hash)) {
            throw new ApiError(400, 'invalid_credentials', 'Current password is incorrect.');
        }
        self::validatePassword($new);
        Db::exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
    }

    public static function deleteAccount(int $userId): void
    {
        Db::exec('DELETE FROM users WHERE id = ?', [$userId]); // cascades to all user data
    }

    // ------------------------------------------------------------ helpers

    public static function userPayload(int $id): array
    {
        $u = Db::one('SELECT id, email, name, google_sub, password_hash, email_verified_at, created_at FROM users WHERE id = ?', [$id]);
        return [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'name' => $u['name'],
            'email_verified' => $u['email_verified_at'] !== null,
            'has_password' => $u['password_hash'] !== null,
            'google_linked' => $u['google_sub'] !== null,
            'created_at' => Fmt::iso($u['created_at']),
        ];
    }

    public static function ensureSettings(int $userId): void
    {
        Db::exec('INSERT IGNORE INTO user_settings (user_id, updated_at) VALUES (?, ?)', [$userId, Db::now()]);
        if (!Db::value('SELECT COUNT(*) FROM watchlist WHERE user_id = ?', [$userId])) {
            foreach (array_values((array) Config::get('universe.default', [])) as $i => $sym) {
                Db::exec('INSERT IGNORE INTO watchlist (user_id, symbol, position) VALUES (?, ?, ?)', [$userId, $sym, $i]);
            }
        }
    }

    private static function emailToken(int $userId, string $purpose, int $ttl): string
    {
        $token = bin2hex(random_bytes(24));
        Db::insert('email_tokens', [
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', Clock::ts() + $ttl),
            'created_at' => Db::now(),
        ]);
        return $token;
    }

    private static function consumeToken(string $token, string $purpose): ?array
    {
        if ($token === '') return null;
        $row = Db::one(
            'SELECT * FROM email_tokens WHERE token_hash = ? AND purpose = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), $purpose, Db::now()]
        );
        if ($row) Db::exec('UPDATE email_tokens SET used_at = ? WHERE id = ?', [Db::now(), $row['id']]);
        return $row;
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiError(422, 'invalid_email', 'Please enter a valid email address.');
        }
        return $email;
    }

    private static function validatePassword(string $p): void
    {
        if (mb_strlen($p) < self::MIN_PASSWORD) {
            throw new ApiError(422, 'weak_password', 'Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            throw new ApiError(422, 'weak_password', 'Password must contain letters and numbers.');
        }
    }

    /** Max 10 failed attempts per IP and per email in 15 minutes. */
    private static function throttle(string $ip, string $email): void
    {
        $since = gmdate('Y-m-d H:i:s', Clock::ts() - 900);
        $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > ?', [$ip, $since]);
        $byEmail = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > ?', [$email, $since]);
        if ($byIp >= 20 || $byEmail >= 10) {
            throw new ApiError(429, 'too_many_attempts', 'Too many attempts. Please wait 15 minutes and try again.');
        }
    }

    private static function secret(): string
    {
        $s = (string) Config::get('jwt_secret', '');
        if (strlen($s) < 32 || str_contains($s, 'CHANGE_ME')) {
            throw new \RuntimeException('Set a long random jwt_secret in config.php');
        }
        return $s;
    }
}
