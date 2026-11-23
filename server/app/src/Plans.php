<?php
declare(strict_types=1);

namespace SignalLab;

final class Plans
{
    /** Default plans (edit prices in Admin → Plans & pricing). */
    public static function seed(): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM plans') > 0) return;
        Db::insert('plans', ['code' => 'monthly', 'name' => 'Premium Monthly', 'interval_months' => 1, 'price_usd' => 1.50,
            'price_ngn' => 2400, 'google_product_id' => 'premium_monthly', 'badge' => '', 'active' => 1, 'sort' => 1]);
        Db::insert('plans', ['code' => 'yearly', 'name' => 'Premium Yearly', 'interval_months' => 12, 'price_usd' => 12.00,
            'price_ngn' => 19200, 'google_product_id' => 'premium_yearly', 'badge' => 'Save 33%', 'active' => 1, 'sort' => 2]);
    }

    public static function active(): array
    {
        return Db::all('SELECT * FROM plans WHERE active = 1 ORDER BY sort, id');
    }

    public static function byCode(string $code): ?array
    {
        return Db::one('SELECT * FROM plans WHERE code = ?', [$code]);
    }

    public static function byGoogleProduct(string $productId): ?array
    {
        return Db::one('SELECT * FROM plans WHERE google_product_id = ? AND google_product_id <> ""', [$productId]);
    }

    public static function payload(array $p): array
    {
        return [
            'code' => $p['code'], 'name' => $p['name'], 'interval_months' => (int) $p['interval_months'],
            'price_usd' => (float) $p['price_usd'], 'price_ngn' => (float) $p['price_ngn'],
            'google_product_id' => $p['google_product_id'], 'badge' => $p['badge'],
        ];
    }
}
