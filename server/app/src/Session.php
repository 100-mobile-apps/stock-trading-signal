<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Cookie session for the website (database-backed so it works on any cPanel host).
 * Holds the signed-in user, a CSRF token and one-time flash messages.
 */
final class Session
{
    public const COOKIE = 'sts_sid';
    private const TTL = 60 * 60 * 24 * 30;

    private ?string $rawId = null;
    private array $row = [];
    private array $data = [];
    private bool $dirty = false;
    private bool $isNew = false;

    public static function load(Request $req): self
    {
        $s = new self();
        $id = $req->cookie(self::COOKIE);
        if ($id !== null && preg_match('/^[a-f0-9]{64}$/', $id)) {
            $row = Db::one('SELECT * FROM web_sessions WHERE id_hash = ? AND expires_at > ?', [hash('sha256', $id), Db::now()]);
            if ($row) {
                $s->rawId = $id;
                $s->row = $row;
                $s->data = json_decode((string) $row['data'], true) ?: [];
                if (substr((string) $row['last_seen'], 0, 13) !== substr(Db::now(), 0, 13)) $s->dirty = true;
            }
        }
        if ($s->rawId === null) {
            $s->rawId = bin2hex(random_bytes(32));
            $s->row = ['user_id' => null, 'csrf' => bin2hex(random_bytes(16))];
            $s->isNew = true;
            $s->row['ip'] = $req->ip;
            $s->row['user_agent'] = mb_substr((string) ($req->headers['user-agent'] ?? ''), 0, 190);
        }
        return $s;
    }

    public function userId(): ?int
    {
        return $this->row['user_id'] === null ? null : (int) $this->row['user_id'];
    }

    public function csrf(): string
    {
        return (string) $this->row['csrf'];
    }

    public function checkCsrf(Request $req): void
    {
        $t = (string) ($req->body['_csrf'] ?? $req->headers['x-csrf-token'] ?? '');
        if (!hash_equals($this->csrf(), $t)) {
            throw new ApiError(419, 'csrf', 'Your session expired. Please go back, refresh the page and try again.');
        }
    }

    /** Signs a user in (new session id to prevent fixation). */
    public function login(int $userId): void
    {
        if (!$this->isNew) Db::exec('DELETE FROM web_sessions WHERE id_hash = ?', [hash('sha256', $this->rawId)]);
        $this->rawId = bin2hex(random_bytes(32));
        $this->row['user_id'] = $userId;
        $this->row['csrf'] = bin2hex(random_bytes(16));
        $this->isNew = true;
        $this->dirty = true;
    }

    public function logout(): void
    {
        Db::exec('DELETE FROM web_sessions WHERE id_hash = ?', [hash('sha256', $this->rawId)]);
        $this->row['user_id'] = null;
        $this->data = [];
        $this->rawId = bin2hex(random_bytes(32));
        $this->row['csrf'] = bin2hex(random_bytes(16));
        $this->isNew = true;
    }

    public function get(string $k, mixed $default = null): mixed
    {
        return $this->data[$k] ?? $default;
    }

    public function set(string $k, mixed $v): void
    {
        $this->data[$k] = $v;
        $this->dirty = true;
    }

    public function flash(string $type, string $message): void
    {
        $this->data['_flash'][] = [$type, $message];
        $this->dirty = true;
    }

    /** @return list<array{0:string,1:string}> */
    public function takeFlash(): array
    {
        $f = $this->data['_flash'] ?? [];
        if ($f) {
            unset($this->data['_flash']);
            $this->dirty = true;
        }
        return $f;
    }

    /** Persists the session and attaches the cookie. Anonymous visitors only get a row once something is stored. */
    public function commit(Response $res, bool $secure): Response
    {
        $needsRow = $this->row['user_id'] !== null || $this->data;
        if (!$needsRow && $this->isNew) return $res;
        if ($this->dirty || $this->isNew) {
            $now = Db::now();
            Db::exec(
                'INSERT INTO web_sessions (id_hash, user_id, csrf, data, ip, user_agent, created_at, last_seen, expires_at)
                 VALUES (?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), csrf = VALUES(csrf), data = VALUES(data), last_seen = VALUES(last_seen), expires_at = VALUES(expires_at)',
                [hash('sha256', $this->rawId), $this->row['user_id'], $this->row['csrf'], json_encode($this->data),
                    $this->row['ip'] ?? '', $this->row['user_agent'] ?? '', $now, $now, gmdate('Y-m-d H:i:s', Clock::ts() + self::TTL)]
            );
        }
        if ($this->isNew) {
            $res->with('Set-Cookie', self::COOKIE . '=' . $this->rawId . '; Path=/; Max-Age=' . self::TTL . '; HttpOnly; SameSite=Lax' . ($secure ? '; Secure' : ''));
        }
        return $res;
    }

    /** CSRF token for anonymous forms must exist before the form is rendered. */
    public function ensurePersisted(): void
    {
        if ($this->isNew && !$this->data) $this->set('_v', 1);
    }

    public static function purgeExpired(): int
    {
        return Db::exec('DELETE FROM web_sessions WHERE expires_at < ?', [Db::now()]);
    }
}
