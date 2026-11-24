<?php
declare(strict_types=1);

namespace SignalLab;

/** Admin-editable settings (stored in the `settings` table, with safe defaults). */
final class Settings
{
    public const DEFAULTS = [
        // Premium
        'premium_enabled' => '1',          // 0 = everything free
        'checkout_currency' => 'NGN',      // NGN or USD (USD must be enabled on your Paystack account)
        'usd_ngn_rate' => '1600',          // used to value NGN payments in USD for commissions/reports
        'google_fee_pct' => '15',          // Google Play's cut on subscriptions
        // Affiliates
        'affiliate_enabled' => '1',
        'affiliate_auto_approve' => '0',
        'commission_rate' => '30',         // % of each Premium payment
        'commission_recurring' => '1',     // 1 = every renewal pays commission, 0 = first payment only
        'commission_hold_days' => '14',    // refund window before commission becomes withdrawable
        'cookie_days' => '30',
        'min_payout_usd' => '10',
        'payout_methods' => 'bank,usdt,paypal',
        'broker_share_pct' => '50',        // affiliate's share of broker commission you receive
        // Site
        'site_announcement' => '',
        'support_email' => '',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            $rows = [];
            try {
                foreach (Db::all('SELECT k, v FROM settings') as $r) $rows[$r['k']] = $r['v'];
            } catch (\Throwable) {
            }
            self::$cache = $rows + self::DEFAULTS;
        }
        return self::$cache;
    }

    public static function get(string $k): string
    {
        return (string) (self::all()[$k] ?? '');
    }

    public static function num(string $k): float
    {
        return (float) self::get($k);
    }

    public static function on(string $k): bool
    {
        return self::get($k) === '1';
    }

    public static function set(string $k, string $v): void
    {
        Db::exec('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
        self::$cache = null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
