<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * Payments:
 *  - Website: Paystack one-time payments (1 month / 12 months of Premium).
 *  - Android app: Google Play subscriptions (required by Play policy for in-app digital goods),
 *    verified server-side with the Google Play Developer API.
 * Every successful payment extends Premium and pays the referring affiliate a commission.
 */
final class Billing
{
    // ------------------------------------------------------------------ shared

    /** Records a successful payment once (idempotent on provider_ref). Returns payment id or null if duplicate. */
    public static function recordSuccess(int $userId, string $provider, string $ref, ?array $plan, float $amount,
                                         string $currency, float $amountUsd, ?string $periodEnd, array $meta = []): ?int
    {
        $now = Db::now();
        $ins = Db::exec(
            'INSERT IGNORE INTO payments (user_id, provider, provider_ref, plan_id, amount, currency, amount_usd, status, period_end, meta, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$userId, $provider, $ref, $plan['id'] ?? null, round($amount, 2), $currency, round($amountUsd, 2), 'success',
                $periodEnd, json_encode($meta), $now, $now]
        );
        if ($ins !== 1) return null;
        $pid = (int) Db::pdo()->lastInsertId();
        Affiliates::onPayment($pid);
        return $pid;
    }

    public static function usdValue(float $amount, string $currency): float
    {
        if (strtoupper($currency) === 'USD') return $amount;
        $rate = max(1.0, Settings::num('usd_ngn_rate'));
        return $amount / $rate;
    }

    /** Admin refund: marks refunded, ends the Premium period it bought, reverses unpaid commission. */
    public static function refund(int $paymentId, string $note = ''): void
    {
        $p = Db::one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        if (!$p || $p['status'] !== 'success') throw new ApiError(400, 'invalid', 'Only successful payments can be refunded.');
        Db::exec("UPDATE payments SET status = 'refunded', updated_at = ? WHERE id = ?", [Db::now(), $paymentId]);
        if ($p['user_id'] && $p['plan_id']) {
            $months = (int) Db::value('SELECT interval_months FROM plans WHERE id = ?', [$p['plan_id']]);
            $cur = Db::value('SELECT premium_until FROM users WHERE id = ?', [$p['user_id']]);
            if ($cur && $months > 0) {
                $new = (new \DateTimeImmutable($cur, new \DateTimeZone('UTC')))->modify("-$months month");
                $new = $new < Clock::now() ? Clock::now() : $new;
                Premium::setUntil((int) $p['user_id'], $new->format('Y-m-d H:i:s'));
            }
        }
        Affiliates::reverseForPayment($paymentId, $note ?: 'Payment refunded');
    }

    // ------------------------------------------------------------------ Paystack (web)

    /** Starts a checkout. Returns the Paystack URL to redirect the user to. */
    public static function paystackInit(int $userId, string $planCode): string
    {
        $plan = Plans::byCode($planCode);
        if (!$plan || !$plan['active']) throw new ApiError(404, 'not_found', 'Plan not found.');
        $secret = (string) Config::get('paystack.secret_key', '');
        if ($secret === '') throw new ApiError(503, 'payments_off', 'Payments are not configured yet.');
        $email = (string) Db::value('SELECT email FROM users WHERE id = ?', [$userId]);
        $currency = Settings::get('checkout_currency') === 'USD' ? 'USD' : 'NGN';
        $amount = $currency === 'USD' ? (float) $plan['price_usd'] : (float) $plan['price_ngn'];
        $ref = 'STS-' . $userId . '-' . bin2hex(random_bytes(6));
        Db::insert('payments', [
            'user_id' => $userId, 'provider' => 'paystack', 'provider_ref' => $ref, 'plan_id' => $plan['id'],
            'amount' => $amount, 'currency' => $currency, 'amount_usd' => round(self::usdValue($amount, $currency), 2),
            'status' => 'pending', 'meta' => '{}', 'created_at' => Db::now(), 'updated_at' => Db::now(),
        ]);
        $res = HttpClient::post('https://api.paystack.co/transaction/initialize', json_encode([
            'email' => $email,
            'amount' => (int) round($amount * 100),
            'currency' => $currency,
            'reference' => $ref,
            'callback_url' => Urls::site('/billing/callback'),
            'metadata' => ['user_id' => $userId, 'plan' => $plan['code']],
        ]), ['Authorization: Bearer ' . $secret, 'Content-Type: application/json']);
        $j = json_decode($res['body'], true);
        if ($res['status'] !== 200 || empty($j['data']['authorization_url'])) {
            error_log('[SignalLab] Paystack init failed: ' . substr($res['body'], 0, 300));
            throw new ApiError(502, 'payment_failed', 'Could not start the payment. Please try again.');
        }
        return $j['data']['authorization_url'];
    }

    /** Verifies a Paystack reference with Paystack's API and activates Premium. Safe to call repeatedly. */
    public static function paystackVerify(string $ref): bool
    {
        $pay = Db::one("SELECT * FROM payments WHERE provider = 'paystack' AND provider_ref = ?", [$ref]);
        if (!$pay) return false;
        if ($pay['status'] === 'success') return true;
        $res = HttpClient::get('https://api.paystack.co/transaction/verify/' . rawurlencode($ref),
            ['Authorization: Bearer ' . Config::get('paystack.secret_key', '')]);
        $j = json_decode($res['body'], true);
        $d = $j['data'] ?? [];
        $ok = $res['status'] === 200 && ($d['status'] ?? '') === 'success'
            && (int) ($d['amount'] ?? 0) >= (int) round((float) $pay['amount'] * 100)
            && strtoupper((string) ($d['currency'] ?? '')) === $pay['currency'];
        if (!$ok) {
            if (in_array($d['status'] ?? '', ['failed', 'abandoned', 'reversed'], true)) {
                Db::exec("UPDATE payments SET status = 'failed', updated_at = ? WHERE id = ? AND status = 'pending'", [Db::now(), $pay['id']]);
            }
            return false;
        }
        // Claim the pending row atomically so webhook + callback can't double-credit.
        $claimed = Db::exec("UPDATE payments SET status = 'success', updated_at = ?, meta = ? WHERE id = ? AND status = 'pending'",
            [Db::now(), json_encode(['channel' => $d['channel'] ?? '', 'paid_at' => $d['paid_at'] ?? '']), $pay['id']]);
        if ($claimed !== 1) return true;
        $months = (int) Db::value('SELECT interval_months FROM plans WHERE id = ?', [$pay['plan_id']]);
        $until = Premium::extend((int) $pay['user_id'], max(1, $months));
        Db::exec('UPDATE payments SET period_end = ? WHERE id = ?', [$until, $pay['id']]);
        Affiliates::onPayment((int) $pay['id']);
        Notifier::system((int) $pay['user_id'], 'Premium activated',
            'Thank you! Premium is active until ' . Fmt::date($until) . '. All live signal levels are unlocked.');
        return true;
    }

    /** Paystack webhook: verifies the HMAC signature, then re-verifies the transaction via the API. */
    public static function paystackWebhook(string $raw, string $signature): bool
    {
        $secret = (string) Config::get('paystack.secret_key', '');
        if ($secret === '' || !hash_equals(hash_hmac('sha512', $raw, $secret), strtolower($signature))) return false;
        $e = json_decode($raw, true);
        if (($e['event'] ?? '') === 'charge.success' && !empty($e['data']['reference'])) {
            self::paystackVerify((string) $e['data']['reference']);
        }
        return true;
    }

    // ------------------------------------------------------------------ Google Play (Android app)

    /**
     * Verifies a Play subscription purchase for this user and syncs Premium.
     * Each new order id (first purchase and every renewal) is recorded once and pays commission.
     */
    public static function googleVerify(int $userId, string $productId, string $token): array
    {
        $plan = Plans::byGoogleProduct($productId);
        if (!$plan) throw new ApiError(404, 'not_found', 'Unknown product.');
        $hash = hash('sha256', $token);
        $owner = Db::value('SELECT user_id FROM google_subscriptions WHERE token_hash = ?', [$hash]);
        if ($owner !== null && (int) $owner !== $userId) {
            throw new ApiError(409, 'token_used', 'This purchase belongs to another account.');
        }
        $sub = self::googleFetch($token);
        if ($sub === null) throw new ApiError(502, 'verify_failed', 'Could not verify the purchase with Google. Please try again.');
        $ext = $sub['externalAccountIdentifiers']['obfuscatedExternalAccountId'] ?? null;
        if ($ext !== null && $ext !== self::obfuscatedId($userId)) {
            throw new ApiError(409, 'wrong_account', 'This purchase was made from another account.');
        }
        self::googleSync($userId, $plan, $token, $sub);
        return Premium::status($userId);
    }

    /** Daily: re-check Google subscriptions that are due, to pick up renewals and cancellations. */
    public static function googleRecheckDue(): int
    {
        $rows = Db::all("SELECT * FROM google_subscriptions WHERE expiry_time IS NULL OR expiry_time < DATE_ADD(?, INTERVAL 2 DAY)
                           AND expiry_time > DATE_SUB(?, INTERVAL 30 DAY)", [Db::now(), Db::now()]);
        $n = 0;
        foreach ($rows as $r) {
            $plan = Plans::byGoogleProduct($r['product_id']);
            $sub = self::googleFetch($r['purchase_token']);
            if ($plan && $sub) {
                self::googleSync((int) $r['user_id'], $plan, $r['purchase_token'], $sub);
                $n++;
            }
        }
        return $n;
    }

    public static function obfuscatedId(int $userId): string
    {
        return substr(hash('sha256', 'sts-user-' . $userId . '-' . Config::get('jwt_secret')), 0, 40);
    }

    private static function googleFetch(string $token): ?array
    {
        $sa = GoogleAuth::serviceAccount((string) Config::get('play.service_account_file', ''));
        $pkg = (string) Config::get('play.package_name', '');
        if (!$sa || $pkg === '') return null;
        $access = GoogleAuth::token($sa, 'https://www.googleapis.com/auth/androidpublisher');
        if (!$access) return null;
        $res = HttpClient::get('https://androidpublisher.googleapis.com/androidpublisher/v3/applications/'
            . rawurlencode($pkg) . '/purchases/subscriptionsv2/tokens/' . rawurlencode($token), ['Authorization: Bearer ' . $access]);
        if ($res['status'] !== 200) {
            error_log('[SignalLab] Play verify ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
            return null;
        }
        return json_decode($res['body'], true) ?: null;
    }

    private static function googleSync(int $userId, array $plan, string $token, array $sub): void
    {
        $item = $sub['lineItems'][0] ?? [];
        $expiry = isset($item['expiryTime']) ? gmdate('Y-m-d H:i:s', strtotime((string) $item['expiryTime'])) : null;
        $state = (string) ($sub['subscriptionState'] ?? '');
        $order = (string) ($sub['latestOrderId'] ?? '');
        $auto = !empty($item['autoRenewingPlan']['autoRenewEnabled']);
        Db::exec(
            'INSERT INTO google_subscriptions (user_id, token_hash, purchase_token, product_id, latest_order_id, state, expiry_time, auto_renewing, last_checked_at, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE latest_order_id = VALUES(latest_order_id), state = VALUES(state), expiry_time = VALUES(expiry_time),
               auto_renewing = VALUES(auto_renewing), last_checked_at = VALUES(last_checked_at)',
            [$userId, hash('sha256', $token), $token, $plan['google_product_id'], $order, $state, $expiry, $auto ? 1 : 0, Db::now(), Db::now()]
        );
        $paidStates = ['SUBSCRIPTION_STATE_ACTIVE', 'SUBSCRIPTION_STATE_IN_GRACE_PERIOD', 'SUBSCRIPTION_STATE_CANCELED'];
        if ($expiry && in_array($state, $paidStates, true)) {
            // Premium lasts until the later of the Play expiry and any web-paid period.
            $cur = Db::value('SELECT premium_until FROM users WHERE id = ?', [$userId]);
            if ($cur === null || $expiry > $cur) Premium::setUntil($userId, $expiry);
            if ($order !== '' && empty($sub['testPurchase'])) {
                $usd = (float) $plan['price_usd'] * (1 - Settings::num('google_fee_pct') / 100);
                self::recordSuccess($userId, 'google', $order, $plan, (float) $plan['price_usd'], 'USD', $usd, $expiry,
                    ['product' => $plan['google_product_id'], 'state' => $state]);
            }
        }
    }
}
