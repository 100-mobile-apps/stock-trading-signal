<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Affiliate program:
 *  - Every user gets a referral code; sign-ups through a link/code are attributed to the referrer.
 *  - Approved affiliates earn a % of each Premium payment by users they referred (first or every
 *    payment, admin setting), held for a refund window, then withdrawable.
 *  - They also earn a share of broker commissions from sign-ups that came through their links.
 */
final class Affiliates
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    // ------------------------------------------------------------------ codes & attribution

    public static function codeFor(int $userId): string
    {
        $c = Db::value('SELECT referral_code FROM users WHERE id = ?', [$userId]);
        if ($c) return (string) $c;
        for ($i = 0; $i < 20; $i++) {
            $code = '';
            for ($k = 0; $k < 7; $k++) $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            try {
                if (Db::exec('UPDATE users SET referral_code = ? WHERE id = ? AND referral_code IS NULL', [$code, $userId]) === 1) return $code;
                return (string) Db::value('SELECT referral_code FROM users WHERE id = ?', [$userId]);
            } catch (\PDOException) {
                // duplicate code, try another
            }
        }
        throw new \RuntimeException('Could not allocate a referral code');
    }

    public static function userByCode(string $code): ?int
    {
        $code = strtoupper(trim($code));
        if (!preg_match('/^[A-Z0-9]{4,16}$/', $code)) return null;
        $id = Db::value("SELECT id FROM users WHERE referral_code = ? AND status = 'active'", [$code]);
        return $id === null ? null : (int) $id;
    }

    /** Called right after a new account is created. First referrer wins; self-referral ignored. */
    public static function attribute(int $newUserId, ?string $code): void
    {
        self::codeFor($newUserId);
        if ($code === null || $code === '') return;
        $ref = self::userByCode($code);
        if ($ref === null || $ref === $newUserId) return;
        Db::exec('UPDATE users SET referred_by = ?, referred_at = ? WHERE id = ? AND referred_by IS NULL', [$ref, Db::now(), $newUserId]);
    }

    public static function logClick(int $affiliateUserId, string $ip, string $landing): void
    {
        Db::insert('referral_clicks', [
            'affiliate_user_id' => $affiliateUserId, 'ip_hash' => hash('sha256', $ip . Config::get('jwt_secret')),
            'landing' => mb_substr($landing, 0, 190), 'created_at' => Db::now(),
        ]);
    }

    // ------------------------------------------------------------------ membership

    public static function apply(int $userId, string $promoPlan): array
    {
        if (!Settings::on('affiliate_enabled')) throw new ApiError(403, 'closed', 'The affiliate program is not open right now.');
        self::codeFor($userId);
        $status = Settings::on('affiliate_auto_approve') ? 'approved' : 'pending';
        Db::exec(
            'INSERT INTO affiliates (user_id, status, promo_plan, applied_at, approved_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE promo_plan = VALUES(promo_plan)',
            [$userId, $status, mb_substr($promoPlan, 0, 500), Db::now(), $status === 'approved' ? Db::now() : null]
        );
        return self::get($userId);
    }

    public static function get(int $userId): ?array
    {
        return Db::one('SELECT * FROM affiliates WHERE user_id = ?', [$userId]);
    }

    public static function setStatus(int $userId, string $status, string $note = ''): void
    {
        if (!in_array($status, ['pending', 'approved', 'rejected', 'suspended'], true)) throw new ApiError(422, 'invalid', 'Bad status');
        Db::exec('UPDATE affiliates SET status = ?, admin_note = ?, approved_at = IF(? = "approved", COALESCE(approved_at, ?), approved_at) WHERE user_id = ?',
            [$status, mb_substr($note, 0, 255), $status, Db::now(), $userId]);
    }

    public static function savePayout(int $userId, string $method, array $details): void
    {
        $allowed = array_filter(array_map('trim', explode(',', Settings::get('payout_methods'))));
        if (!in_array($method, $allowed, true)) throw new ApiError(422, 'invalid', 'Choose a payout method.');
        $clean = [];
        foreach (['bank_name', 'account_name', 'account_number', 'usdt_address', 'usdt_network', 'paypal_email'] as $k) {
            if (isset($details[$k])) $clean[$k] = mb_substr(trim((string) $details[$k]), 0, 120);
        }
        $need = ['bank' => ['bank_name', 'account_name', 'account_number'], 'usdt' => ['usdt_address', 'usdt_network'], 'paypal' => ['paypal_email']][$method] ?? [];
        foreach ($need as $k) {
            if (($clean[$k] ?? '') === '') throw new ApiError(422, 'invalid', 'Please fill in all payout details.');
        }
        Db::exec('UPDATE affiliates SET payout_method = ?, payout_details = ? WHERE user_id = ?', [$method, json_encode($clean), $userId]);
    }

    // ------------------------------------------------------------------ commissions

    public static function rateFor(int $affiliateUserId): float
    {
        $r = Db::value('SELECT commission_rate FROM affiliates WHERE user_id = ?', [$affiliateUserId]);
        return $r !== null ? (float) $r : Settings::num('commission_rate');
    }

    /** Creates the referrer's commission for a successful Premium payment (idempotent). */
    public static function onPayment(int $paymentId): void
    {
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'success' || !$p['user_id']) return;
        $u = Db::one('SELECT id, referred_by FROM users WHERE id = ?', [$p['user_id']]);
        if (!$u || !$u['referred_by']) return;
        $aff = (int) $u['referred_by'];
        if ((Db::value('SELECT status FROM affiliates WHERE user_id = ?', [$aff]) ?? '') !== 'approved') return;
        if (!Settings::on('commission_recurring')) {
            $earlier = (int) Db::value("SELECT COUNT(*) FROM payments WHERE user_id = ? AND status IN ('success','refunded') AND id < ?", [$p['user_id'], $paymentId]);
            if ($earlier > 0) return;
        }
        $rate = self::rateFor($aff);
        $amount = round((float) $p['amount_usd'] * $rate / 100, 2);
        if ($amount <= 0) return;
        $hold = (int) Settings::num('commission_hold_days');
        Db::exec(
            'INSERT IGNORE INTO commissions (affiliate_user_id, referred_user_id, source, payment_id, base_usd, rate, amount_usd, status, available_at, note, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$aff, $p['user_id'], 'premium', $paymentId, $p['amount_usd'], $rate, $amount, 'pending',
                Clock::now()->modify("+$hold day")->format('Y-m-d H:i:s'), ucfirst($p['provider']) . ' payment', Db::now()]
        );
    }

    public static function reverseForPayment(int $paymentId, string $note): void
    {
        foreach (Db::all('SELECT * FROM commissions WHERE payment_id = ?', [$paymentId]) as $c) {
            if (in_array($c['status'], ['pending', 'approved'], true) && $c['payout_id'] === null) {
                Db::exec("UPDATE commissions SET status = 'reversed', note = ? WHERE id = ?", [mb_substr($note, 0, 255), $c['id']]);
            } elseif ($c['status'] === 'paid' || $c['payout_id'] !== null) {
                // Already paid out: claw back from future earnings.
                self::adjust((int) $c['affiliate_user_id'], -(float) $c['amount_usd'], 'Clawback: ' . $note, (int) $c['referred_user_id']);
            }
        }
    }

    public static function adjust(int $affiliateUserId, float $amountUsd, string $note, ?int $referred = null): void
    {
        Db::insert('commissions', [
            'affiliate_user_id' => $affiliateUserId, 'referred_user_id' => $referred, 'source' => 'adjustment',
            'base_usd' => abs($amountUsd), 'rate' => 100, 'amount_usd' => round($amountUsd, 2), 'status' => 'approved',
            'available_at' => Db::now(), 'note' => mb_substr($note, 0, 255), 'created_at' => Db::now(),
        ]);
    }

    /** Daily: pending commissions past the refund window become withdrawable. */
    public static function approveDue(): int
    {
        return Db::exec("UPDATE commissions SET status = 'approved' WHERE status = 'pending' AND available_at <= ?", [Db::now()]);
    }

    // ------------------------------------------------------------------ balances & payouts

    public static function summary(int $userId): array
    {
        $sum = fn(string $where, array $p = []) => round((float) Db::value("SELECT COALESCE(SUM(amount_usd),0) FROM commissions WHERE affiliate_user_id = ? AND $where", [$userId, ...$p]), 2);
        $since30 = Clock::now()->modify('-30 day')->format('Y-m-d H:i:s');
        return [
            'code' => self::codeFor($userId),
            'link' => Urls::site('/r/' . self::codeFor($userId)),
            'affiliate' => self::get($userId),
            'clicks_30d' => (int) Db::value('SELECT COUNT(*) FROM referral_clicks WHERE affiliate_user_id = ? AND created_at > ?', [$userId, $since30]),
            'clicks_all' => (int) Db::value('SELECT COUNT(*) FROM referral_clicks WHERE affiliate_user_id = ?', [$userId]),
            'signups' => (int) Db::value('SELECT COUNT(*) FROM users WHERE referred_by = ?', [$userId]),
            'premium_customers' => (int) Db::value("SELECT COUNT(DISTINCT p.user_id) FROM payments p JOIN users u ON u.id = p.user_id WHERE u.referred_by = ? AND p.status = 'success'", [$userId]),
            'broker_clicks' => (int) Db::value('SELECT COUNT(*) FROM broker_clicks WHERE affiliate_user_id = ?', [$userId]),
            'pending' => $sum("status = 'pending'"),
            'available' => $sum("status = 'approved' AND payout_id IS NULL"),
            'in_payout' => $sum("status = 'approved' AND payout_id IS NOT NULL"),
            'paid' => $sum("status = 'paid'"),
            'lifetime' => $sum("status IN ('pending','approved','paid')"),
            'min_payout' => Settings::num('min_payout_usd'),
        ];
    }

    public static function requestPayout(int $userId): int
    {
        $aff = self::get($userId);
        if (!$aff || $aff['status'] !== 'approved') throw new ApiError(403, 'not_affiliate', 'Your affiliate account is not approved.');
        if ($aff['payout_method'] === '') throw new ApiError(422, 'no_method', 'Add your payout details first.');
        if (Db::value("SELECT id FROM payouts WHERE affiliate_user_id = ? AND status = 'requested'", [$userId])) {
            throw new ApiError(409, 'open_request', 'You already have a payout request being processed.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $rows = Db::all("SELECT id, amount_usd FROM commissions WHERE affiliate_user_id = ? AND status = 'approved' AND payout_id IS NULL FOR UPDATE", [$userId]);
            $total = round(array_sum(array_map(fn($r) => (float) $r['amount_usd'], $rows)), 2);
            if ($total < Settings::num('min_payout_usd') || $total <= 0) {
                throw new ApiError(422, 'below_min', 'The minimum payout is $' . number_format(Settings::num('min_payout_usd'), 2) . '.');
            }
            $pid = Db::insert('payouts', [
                'affiliate_user_id' => $userId, 'amount_usd' => $total, 'method' => $aff['payout_method'],
                'details' => $aff['payout_details'], 'status' => 'requested', 'requested_at' => Db::now(),
            ]);
            $ids = array_column($rows, 'id');
            Db::exec('UPDATE commissions SET payout_id = ? WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [$pid]);
            $pdo->commit();
            return $pid;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function markPayout(int $payoutId, string $status, string $txnRef, string $note): void
    {
        $p = Db::one('SELECT * FROM payouts WHERE id = ?', [$payoutId]);
        if (!$p || $p['status'] !== 'requested') throw new ApiError(400, 'invalid', 'This payout was already processed.');
        if ($status === 'paid') {
            Db::exec("UPDATE commissions SET status = 'paid' WHERE payout_id = ?", [$payoutId]);
        } elseif ($status === 'rejected') {
            Db::exec('UPDATE commissions SET payout_id = NULL WHERE payout_id = ?', [$payoutId]);
        } else {
            throw new ApiError(422, 'invalid', 'Bad status');
        }
        Db::exec('UPDATE payouts SET status = ?, txn_ref = ?, admin_note = ?, processed_at = ? WHERE id = ?',
            [$status, mb_substr($txnRef, 0, 120), mb_substr($note, 0, 255), Db::now(), $payoutId]);
        Notifier::system((int) $p['affiliate_user_id'], $status === 'paid' ? 'Payout sent' : 'Payout request declined',
            $status === 'paid' ? '$' . number_format((float) $p['amount_usd'], 2) . ' has been sent. Ref: ' . ($txnRef ?: '—')
                : 'Your payout request was declined' . ($note ? ": $note" : '.') . ' The balance is available again.');
    }
}
