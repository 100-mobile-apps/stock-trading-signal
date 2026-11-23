<?php
declare(strict_types=1);

namespace SignalLab;

/**
 * The website at https://stocksignal.bulksend.top:
 * public pages (SEO), sign-in, the member dashboard (/app) and the admin panel (/admin).
 */
final class Web
{
    public const REF_COOKIE = 'sts_ref';

    public static function handle(Request $req): Response
    {
        $session = Session::load($req);
        View::$session = $session;
        View::$path = $req->path;
        View::$user = $session->userId() ? Db::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [$session->userId()]) : null;
        if ($session->userId() && !View::$user) $session->logout();

        $r = self::router();
        $r->onError = function (int $status, string $code, string $message, Request $q) {
            if (str_ends_with($q->path, '.json')) return Response::error($status, $code, $message);
            if ($status === 401) return Response::redirect('/login?next=' . rawurlencode($q->path));
            return Response::html(View::errorPage($status, $message), $status);
        };
        $res = $r->dispatch($req);
        return $session->commit($res, str_starts_with((string) Config::get('base_url'), 'https://'));
    }

    // ------------------------------------------------------------------ guards & helpers

    public static function uid(): int
    {
        if (!View::$user) throw new ApiError(401, 'unauthorized', 'Please sign in.');
        return (int) View::$user['id'];
    }

    public static function admin(Request $q): int
    {
        $id = self::uid();
        if ((View::$user['role'] ?? '') !== 'admin') throw new ApiError(403, 'forbidden', 'This area is for administrators only.');
        if ($q->method === 'POST') View::$session->checkCsrf($q);
        return $id;
    }

    /** Signed-in member POST: checks CSRF and returns user id. */
    private static function post(Request $q): int
    {
        $id = self::uid();
        View::$session->checkCsrf($q);
        return $id;
    }

    private static function back(string $to, string $type = '', string $msg = ''): Response
    {
        if ($msg !== '') View::$session->flash($type, $msg);
        return Response::redirect($to);
    }

    public static function safeNext(?string $next, string $default = '/app'): string
    {
        $next = (string) $next;
        return preg_match('#^/(?!/)[A-Za-z0-9/_\-?=&.%]*$#', $next) ? $next : $default;
    }

    private static function refCode(Request $q): ?string
    {
        $c = $q->str('ref', 16) ?: (string) $q->cookie(self::REF_COOKIE);
        return $c !== '' ? strtoupper($c) : null;
    }

    /** @return array{0: Response} catches ApiError from form handlers and shows it as a flash message */
    private static function attempt(string $backTo, callable $fn): Response
    {
        try {
            return $fn();
        } catch (ApiError $e) {
            if ($e->status === 419) throw $e;
            return self::back($backTo, 'err', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ routes

    public static function router(): Router
    {
        $r = new Router();
        $html = fn(string $s, int $st = 200) => Response::html($s, $st);

        // ---------- public
        $r->add('GET', '/', fn() => $html(WebPages::home()));
        $r->add('GET', '/pricing', fn() => $html(WebPages::pricing()));
        $r->add('GET', '/record', fn() => $html(WebPages::record()));
        $r->add('GET', '/brokers', fn(Request $q) => $html(WebPages::brokers(self::refCode($q))));
        $r->add('GET', '/affiliate', fn() => $html(WebPages::affiliateProgram()));
        $r->add('GET', '/terms', fn() => $html(WebPages::terms()));
        $r->add('GET', '/privacy', fn() => $html(WebPages::privacy()));
        $r->add('GET', '/robots.txt', fn() => Response::text("User-agent: *\nDisallow: /app\nDisallow: /admin\nDisallow: /api\nDisallow: /go/\nSitemap: " . Urls::site('/sitemap.xml') . "\n"));
        $r->add('GET', '/sitemap.xml', function () {
            $x = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (['/', '/record', '/pricing', '/brokers', '/affiliate', '/signup', '/terms', '/privacy'] as $p) {
                $x .= '<url><loc>' . View::e(Urls::site($p)) . '</loc><changefreq>' . ($p === '/record' ? 'daily' : 'weekly') . '</changefreq></url>';
            }
            return Response::text($x . '</urlset>', 'application/xml; charset=utf-8');
        });

        // ---------- referral & broker links
        $r->add('GET', '/r/{code}', function (Request $q, array $p) {
            $to = self::safeNext($q->str('to', 200), '/');
            $aff = Affiliates::userByCode($p['code']);
            $res = Response::redirect($to, 302);
            if ($aff !== null && (!View::$user || (int) View::$user['id'] !== $aff)) {
                Affiliates::logClick($aff, $q->ip, $to);
                $days = (int) Settings::num('cookie_days');
                // First click wins for the cookie window.
                if (!$q->cookie(self::REF_COOKIE)) {
                    $res->with('Set-Cookie', self::REF_COOKIE . '=' . strtoupper($p['code']) . '; Path=/; Max-Age=' . ($days * 86400) . '; SameSite=Lax');
                }
            }
            return $res;
        });
        $r->add('GET', '/go/{slug}', function (Request $q, array $p) {
            $uid = View::$user ? (int) View::$user['id'] : Brokers::userFromToken($q->str('u', 60));
            $url = Brokers::click($p['slug'], $uid, self::refCode($q), $q->str('src', 10) ?: 'web', $q->ip);
            if ($url === null) throw new ApiError(404, 'not_found', 'This partner link is no longer available.');
            return Response::redirect($url, 302)->with('X-Robots-Tag', 'noindex');
        });

        // ---------- auth
        $r->add('GET', '/login', fn(Request $q) => View::$user ? Response::redirect(self::safeNext($q->str('next', 200)))
            : $html(WebPages::login($q->str('next', 200))));
        $r->add('POST', '/login', function (Request $q) {
            View::$session->checkCsrf($q);
            $next = self::safeNext($q->str('next', 200));
            return self::attempt('/login?next=' . rawurlencode($next), function () use ($q, $next) {
                $id = Auth::checkPassword($q->str('email', 190), (string) ($q->body['password'] ?? ''), $q->ip);
                View::$session->login($id);
                return Response::redirect(self::isAdminId($id) && $next === '/app' ? '/admin' : $next);
            });
        });
        $r->add('POST', '/login/google', function (Request $q) {
            // Google Identity Services posts the credential with a double-submit CSRF cookie.
            $c = $q->str('g_csrf_token', 200);
            if ($c === '' || !hash_equals((string) $q->cookie('g_csrf_token'), $c)) throw new ApiError(419, 'csrf', 'Google sign-in expired. Please try again.');
            return self::attempt('/login', function () use ($q) {
                $id = Auth::googleUser($q->str('credential', 4096), null, self::refCode($q), 'web');
                View::$session->login($id);
                return Response::redirect(self::safeNext($q->str('state', 200)));
            });
        });
        $r->add('GET', '/signup', fn(Request $q) => View::$user ? Response::redirect('/app') : $html(WebPages::signup(self::refCode($q), $q->str('next', 200))));
        $r->add('POST', '/signup', function (Request $q) {
            View::$session->checkCsrf($q);
            $next = self::safeNext($q->str('next', 200));
            return self::attempt('/signup', function () use ($q, $next) {
                if (empty($q->body['agree'])) throw new ApiError(422, 'terms', 'Please accept the Terms and risk disclaimer.');
                $id = Auth::createUser($q->str('email', 190), (string) ($q->body['password'] ?? ''), $q->str('name', 120), $q->ip,
                    $q->str('referral_code', 16) ?: self::refCode($q), 'web');
                View::$session->login($id);
                View::$session->flash('ok', 'Welcome! Check your inbox to confirm your email so alerts and reports can reach you.');
                return Response::redirect($next);
            });
        });
        $r->add('POST', '/logout', function (Request $q) {
            View::$session->checkCsrf($q);
            View::$session->logout();
            return Response::redirect('/');
        });
        $r->add('GET', '/forgot', fn() => $html(WebPages::forgot()));
        $r->add('POST', '/forgot', function (Request $q) {
            View::$session->checkCsrf($q);
            return self::attempt('/forgot', function () use ($q) {
                Auth::forgotPassword($q->str('email', 190), $q->ip);
                return self::back('/login', 'ok', 'If an account exists for that email, a reset link is on its way. It expires in 1 hour.');
            });
        });
        $r->add('GET', '/reset', fn(Request $q) => $html(WebPages::reset($q->str('token', 100))));
        $r->add('POST', '/reset', function (Request $q) {
            View::$session->checkCsrf($q);
            $token = $q->str('token', 100);
            return self::attempt('/reset?token=' . rawurlencode($token), function () use ($q, $token) {
                Auth::resetPassword($token, (string) ($q->body['password'] ?? ''));
                return self::back('/login', 'ok', 'Password updated. You can now log in on the website and in the app.');
            });
        });
        $r->add('GET', '/verify', function (Request $q) use ($html) {
            $ok = Auth::verifyEmail($q->str('token', 100));
            return $html(WebPages::message($ok ? 'Email confirmed' : 'Link expired', $ok
                ? 'Thanks! Signal alerts and reports will now reach your inbox.'
                : 'This confirmation link is invalid or has expired. Request a new one from Settings.', $ok), $ok ? 200 : 400);
        });
        $r->add('GET', '/delete-account', fn() => $html(WebPages::deleteAccount()));
        $r->add('POST', '/delete-account', function (Request $q) use ($html) {
            View::$session->checkCsrf($q);
            $email = strtolower($q->str('email', 190));
            $u = Db::one('SELECT id, password_hash FROM users WHERE email = ?', [$email]);
            if (!$u || !$u['password_hash'] || !password_verify((string) ($q->body['password'] ?? ''), $u['password_hash'])) {
                Db::insert('login_attempts', ['ip' => $q->ip, 'email' => $email, 'attempted_at' => Db::now()]);
                return self::back('/delete-account', 'err', 'Email or password is incorrect. Signed up with Google? Log in and delete your account from Settings.');
            }
            Auth::deleteAccount((int) $u['id']);
            View::$session->logout();
            return $html(WebPages::message('Account deleted', 'Your account and all its data have been permanently erased.'));
        });

        // ---------- payments
        $r->add('GET', '/billing/callback', function (Request $q) {
            $ref = $q->str('reference', 100) ?: $q->str('trxref', 100);
            $ok = $ref !== '' && Billing::paystackVerify($ref);
            return self::back('/app/billing', $ok ? 'ok' : 'err', $ok ? 'Payment received. Premium is now active. Thank you!'
                : 'We could not confirm that payment yet. If you were charged, it will activate within a few minutes, or contact support.');
        });
        $r->add('POST', '/paystack/webhook', function (Request $q) {
            // Signature is over the exact raw body (tests inject it via $GLOBALS).
            $raw = $GLOBALS['__raw_body'] ?? (file_get_contents('php://input') ?: json_encode($q->body));
            $ok = Billing::paystackWebhook((string) $raw, (string) ($q->headers['x-paystack-signature'] ?? ''));
            return new Response($ok ? 200 : 401, $ok ? 'ok' : 'bad signature', 'text/plain');
        });

        WebApp::routes($r);
        Admin::routes($r);
        return $r;
    }

    private static function isAdminId(int $id): bool
    {
        return Db::value('SELECT role FROM users WHERE id = ?', [$id]) === 'admin';
    }
}
