<?php
declare(strict_types=1);

namespace SignalLab;

/** Who has Premium, and what that unlocks. */
final class Premium
{
    public static function isActive(int $userId): bool
    {
        if (!Settings::on('premium_enabled')) return true; // Premium switched off = everything free
        $until = Db::value('SELECT premium_until FROM users WHERE id = ?', [$userId]);
        return $until !== null && $until > Db::now();
    }

    public static function status(int $userId): array
    {
        $until = Db::value('SELECT premium_until FROM users WHERE id = ?', [$userId]);
        $gs = Db::one('SELECT product_id, auto_renewing, expiry_time FROM google_subscriptions WHERE user_id = ? ORDER BY expiry_time DESC LIMIT 1', [$userId]);
        return [
            'enabled' => Settings::on('premium_enabled'),
            'active' => self::isActive($userId),
            'until' => Fmt::iso($until),
            'source' => $gs && $gs['expiry_time'] === $until ? 'google' : ($until ? 'web' : null),
            'auto_renewing' => $gs ? (bool) $gs['auto_renewing'] : false,
        ];
    }

    /** Extends Premium by $months from max(now, current expiry). Returns the new expiry. */
    public static function extend(int $userId, int $months): string
    {
        $cur = Db::value('SELECT premium_until FROM users WHERE id = ?', [$userId]);
        $base = ($cur !== null && $cur > Db::now()) ? new \DateTimeImmutable($cur, new \DateTimeZone('UTC')) : Clock::now();
        $until = $base->modify("+$months month")->format('Y-m-d H:i:s');
        Db::exec('UPDATE users SET premium_until = ? WHERE id = ?', [$until, $userId]);
        return $until;
    }

    public static function setUntil(int $userId, ?string $until): void
    {
        Db::exec('UPDATE users SET premium_until = ? WHERE id = ?', [$until, $userId]);
    }
}
