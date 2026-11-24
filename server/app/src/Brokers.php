<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Broker partner links. Each click gets a unique sub-ID that is passed to the broker's affiliate
 * link (use {sub_id} in the URL template). When the broker reports a funded sign-up for that sub-ID,
 * the admin records it and the affiliate who brought the user earns their share.
 */
final class Brokers
{
    public static function active(): array
    {
        return Db::all('SELECT * FROM brokers WHERE active = 1 ORDER BY sort, name');
    }

    public static function payload(array $b, ?int $userId = null): array
    {
        $q = $userId ? '?u=' . self::userToken($userId) . '&src=app' : '';
        return [
            'slug' => $b['slug'], 'name' => $b['name'], 'logo_url' => $b['logo_url'], 'tagline' => $b['tagline'],
            'description' => (string) $b['description'], 'min_deposit' => $b['min_deposit'], 'regions' => $b['regions'],
            'link' => Urls::site('/go/' . $b['slug']) . $q,
        ];
    }

    /** Short signed token so app users can be credited when the link opens in their browser. */
    public static function userToken(int $userId): string
    {
        return $userId . '.' . substr(hash_hmac('sha256', 'broker-' . $userId, (string) Config::get('jwt_secret')), 0, 16);
    }

    public static function userFromToken(string $t): ?int
    {
        if (!preg_match('/^(\d+)\.([a-f0-9]{16})$/', $t, $m)) return null;
        return hash_equals(substr(hash_hmac('sha256', 'broker-' . $m[1], (string) Config::get('jwt_secret')), 0, 16), $m[2]) ? (int) $m[1] : null;
    }

    /**
     * Records a click and returns the broker URL to redirect to.
     * Credit goes to: the affiliate whose ?ref= code is on the link, else the person who referred the user.
     */
    public static function click(string $slug, ?int $userId, ?string $refCode, string $source, string $ip): ?string
    {
        $b = Db::one('SELECT * FROM brokers WHERE slug = ? AND active = 1', [$slug]);
        if (!$b) return null;
        $aff = $refCode ? Affiliates::userByCode($refCode) : null;
        if ($aff === null && $userId) {
            $r = Db::value('SELECT referred_by FROM users WHERE id = ?', [$userId]);
            $aff = $r === null ? null : (int) $r;
        }
        if ($aff !== null && $aff === $userId) $aff = null;
        $sub = 'st' . bin2hex(random_bytes(6));
        Db::insert('broker_clicks', [
            'broker_id' => $b['id'], 'user_id' => $userId, 'affiliate_user_id' => $aff, 'sub_id' => $sub,
            'source' => in_array($source, ['web', 'app', 'email'], true) ? $source : 'web',
            'ip_hash' => hash('sha256', $ip . Config::get('jwt_secret')), 'created_at' => Db::now(),
        ]);
        return str_replace(['{sub_id}', '{subid}', '{click_id}'], rawurlencode($sub), $b['url_template']);
    }

    /**
     * Admin: a broker paid you $amount for the sign-up with this sub-ID.
     * Credits the affiliate their share (approved immediately — you already received the money).
     */
    public static function recordConversion(string $subId, float $amountUsd, string $note): array
    {
        $c = Db::one('SELECT * FROM broker_clicks WHERE sub_id = ?', [trim($subId)]);
        if (!$c) throw new ApiError(404, 'not_found', 'No click found with that sub-ID.');
        if (!$c['affiliate_user_id']) return ['credited' => false, 'reason' => 'This click has no affiliate attached.'];
        if (Db::value('SELECT id FROM commissions WHERE broker_click_id = ?', [$c['id']])) {
            throw new ApiError(409, 'duplicate', 'This conversion was already recorded.');
        }
        $aff = (int) $c['affiliate_user_id'];
        if ((Db::value('SELECT status FROM affiliates WHERE user_id = ?', [$aff]) ?? '') !== 'approved') {
            return ['credited' => false, 'reason' => 'The referring user is not an approved affiliate.'];
        }
        $rate = Settings::num('broker_share_pct');
        $amount = round($amountUsd * $rate / 100, 2);
        Db::insert('commissions', [
            'affiliate_user_id' => $aff, 'referred_user_id' => $c['user_id'], 'source' => 'broker', 'broker_id' => $c['broker_id'],
            'broker_click_id' => $c['id'], 'base_usd' => round($amountUsd, 2), 'rate' => $rate, 'amount_usd' => $amount,
            'status' => 'approved', 'available_at' => Db::now(), 'note' => mb_substr($note ?: 'Broker conversion', 0, 255), 'created_at' => Db::now(),
        ]);
        return ['credited' => true, 'affiliate_user_id' => $aff, 'amount_usd' => $amount];
    }
}
