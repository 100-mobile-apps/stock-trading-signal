<?php
declare(strict_types=1);

namespace SignalLab;

/** HTML layouts and small UI components for the website, dashboard and admin panel. */
final class View
{
    public static ?Session $session = null;
    public static ?array $user = null;      // signed-in user row
    public static string $path = '/';

    public static function e(mixed $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    public static function app(): string
    {
        return self::e(Config::get('app_name', 'Stock Trading Signals'));
    }

    public static function csrf(): string
    {
        self::$session?->ensurePersisted();
        return '<input type="hidden" name="_csrf" value="' . self::e(self::$session?->csrf()) . '">';
    }

    /** A one-button POST form (actions in tables). */
    public static function postButton(string $action, string $label, string $cls = 'btn sm ghost', array $hidden = [], ?string $confirm = null): string
    {
        $h = '';
        foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . self::e($k) . '" value="' . self::e($v) . '">';
        return '<form class="inline" method="post" action="' . self::e($action) . '"' . ($confirm ? ' data-confirm="' . self::e($confirm) . '"' : '') . '>'
            . self::csrf() . $h . '<button type="submit" class="' . self::e($cls) . '">' . $label . '</button></form>';
    }

    public static function flashes(): string
    {
        $out = '';
        foreach (self::$session?->takeFlash() ?? [] as [$type, $msg]) {
            $out .= '<div class="alert ' . self::e($type) . '">' . self::e($msg) . '</div>';
        }
        return $out;
    }

    // ------------------------------------------------------------------ formatting

    public static function money(float $usd): string
    {
        return ($usd < 0 ? '-$' : '$') . number_format(abs($usd), 2);
    }

    public static function amount(float $amount, string $currency): string
    {
        return match (strtoupper($currency)) {
            'NGN' => '₦' . number_format($amount, 0),
            'USD' => '$' . number_format($amount, 2),
            default => number_format($amount, 2) . ' ' . self::e($currency),
        };
    }

    public static function date(?string $mysql, bool $time = false): string
    {
        return $mysql ? '<span class="nowrap">' . Fmt::date($mysql, $time) . '</span>' : '—';
    }

    public static function ago(?string $mysql): string
    {
        if (!$mysql) return '—';
        $d = Clock::ts() - strtotime($mysql . ' UTC');
        return match (true) {
            $d < 60 => 'just now',
            $d < 3600 => intdiv($d, 60) . 'm ago',
            $d < 86400 => intdiv($d, 3600) . 'h ago',
            $d < 86400 * 30 => intdiv($d, 86400) . 'd ago',
            default => Fmt::date($mysql),
        };
    }

    public static function rCell(?float $r): string
    {
        if ($r === null) return '<span class="dim">—</span>';
        return '<span class="' . ($r > 0 ? 'pos' : ($r < 0 ? 'neg' : '')) . '"><b>' . Fmt::r($r) . '</b></span>';
    }

    public static function pill(string $text, string $tone = ''): string
    {
        return '<span class="pill ' . $tone . '">' . self::e($text) . '</span>';
    }

    public static function statusPill(array $s): string
    {
        return match ($s['status']) {
            'pending' => self::pill('New · enter next open', 'p'),
            'open' => self::pill($s['tp1_hit_time'] ? 'Open · T1 hit' : 'Open', 'p'),
            'won' => self::pill(($s['exit_reason'] === 'time' ? 'Time exit' : 'Target hit') . ' ✓', 'g'),
            'lost' => self::pill($s['exit_reason'] === 'time' ? 'Time exit' : 'Stopped out', 'r'),
            'warning' => self::pill('Bearish warning', 'a'),
            default => self::pill($s['status']),
        };
    }

    public static function tile(string $label, string $value, string $sub = '', string $cls = ''): string
    {
        return '<div class="tile"><div class="l">' . self::e($label) . '</div><div class="v ' . $cls . '">' . $value . '</div>'
            . ($sub !== '' ? '<div class="s">' . $sub . '</div>' : '') . '</div>';
    }

    /** @param list<string> $head  @param list<list<string>> $rows (cells are trusted HTML) */
    public static function table(array $head, array $rows, string $empty = 'Nothing here yet.'): string
    {
        $h = '<div class="tw"><table><thead><tr>';
        foreach ($head as $c) {
            $r = str_starts_with($c, '>') ? ' class="r"' : '';
            $h .= "<th$r>" . self::e(ltrim($c, '>')) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        if (!$rows) $h .= '<tr><td colspan="' . count($head) . '" class="empty">' . self::e($empty) . '</td></tr>';
        foreach ($rows as $row) {
            $h .= '<tr>';
            foreach ($row as $i => $cell) {
                $h .= '<td' . (str_starts_with($head[$i] ?? '', '>') ? ' class="r"' : '') . '>' . $cell . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    }

    public static function pager(int $page, bool $hasMore, array $query): string
    {
        if ($page <= 1 && !$hasMore) return '';
        $link = fn(int $p) => '?' . http_build_query(['page' => $p] + $query);
        return '<div class="pager">' . ($page > 1 ? '<a class="btn sm ghost" href="' . self::e($link($page - 1)) . '">← Newer</a>' : '')
            . ($hasMore ? '<a class="btn sm ghost" href="' . self::e($link($page + 1)) . '">Older →</a>' : '') . '</div>';
    }

    public static function tabs(array $items, string $current): string
    {
        $h = '<div class="tabs">';
        foreach ($items as $key => [$label, $href]) {
            $h .= '<a href="' . self::e($href) . '"' . ((string) $key === $current ? ' class="on"' : '') . '>' . self::e($label) . '</a>';
        }
        return $h . '</div>';
    }

    public static function field(string $name, string $label, string $value = '', string $type = 'text', string $extra = '', string $help = ''): string
    {
        return '<div class="field"><label for="f_' . self::e($name) . '">' . self::e($label) . '</label><input id="f_' . self::e($name) . '" type="' . $type
            . '" name="' . self::e($name) . '" value="' . self::e($value) . '" ' . $extra . '>'
            . ($help ? '<div class="help">' . $help . '</div>' : '') . '</div>';
    }

    public static function select(string $name, string $label, array $options, string $value, string $help = ''): string
    {
        $h = '<div class="field"><label for="f_' . self::e($name) . '">' . self::e($label) . '</label><select id="f_' . self::e($name) . '" name="' . self::e($name) . '">';
        foreach ($options as $k => $v) {
            $h .= '<option value="' . self::e($k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . self::e($v) . '</option>';
        }
        return $h . '</select>' . ($help ? '<div class="help">' . $help . '</div>' : '') . '</div>';
    }

    public static function checkbox(string $name, string $label, bool $on, string $sub = ''): string
    {
        return '<label class="check"><input type="hidden" name="' . self::e($name) . '" value="0"><input type="checkbox" name="' . self::e($name)
            . '" value="1"' . ($on ? ' checked' : '') . '><span>' . self::e($label) . ($sub ? '<small>' . self::e($sub) . '</small>' : '') . '</span></label>';
    }

    public static function copyBox(string $id, string $value): string
    {
        return '<div class="copy"><input id="' . self::e($id) . '" value="' . self::e($value) . '" readonly><button type="button" class="btn sm" data-copy="'
            . self::e($id) . '">Copy</button></div>';
    }

    public static function devButtons(): string
    {
        $out = [];
        if ($u = (string) Config::get('developer.chat_url', '')) $out[] = '<a class="btn sm ghost" href="' . self::e($u) . '" target="_blank" rel="noopener">💬 Chat with the developer</a>';
        if ($u = (string) Config::get('developer.coffee_url', '')) $out[] = '<a class="btn sm ghost" href="' . self::e($u) . '" target="_blank" rel="noopener">☕ Buy me a coffee</a>';
        if ($u = (string) Config::get('developer.hire_url', '')) $out[] = '<a class="btn sm amber" href="' . self::e($u) . '" target="_blank" rel="noopener">🚀 Hire us to build your app</a>';
        return '<div class="devbar">' . implode('', $out) . '</div>';
    }

    // ------------------------------------------------------------------ layouts

    private static function head(string $title, array $meta): string
    {
        $app = self::app();
        $full = $title === '' ? $app : self::e($title) . ' · ' . $app;
        $desc = self::e($meta['description'] ?? 'Free US stock trading signals with entry, stop-loss and targets, plus a public, fully tracked win/loss record. Daily, weekly and monthly results.');
        $canon = self::e(Urls::site(self::$path));
        $robots = !empty($meta['noindex']) ? '<meta name="robots" content="noindex">' : '';
        $ld = $meta['jsonld'] ?? '';
        $v = '4';
        return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>$full</title><meta name="description" content="$desc">$robots
<link rel="canonical" href="$canon"><meta name="theme-color" content="#0A0F1C">
<meta property="og:type" content="website"><meta property="og:title" content="$full"><meta property="og:description" content="$desc"><meta property="og:url" content="$canon">
<meta name="twitter:card" content="summary">
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/site.css?v=$v">$ld</head>
HTML;
    }

    public static function publicPage(string $title, string $body, array $meta = []): string
    {
        $u = self::$user;
        $on = fn(string $p) => str_starts_with(self::$path, $p) ? ' class="on"' : '';
        $cta = $u
            ? '<a class="btn sm" href="/app">Dashboard</a>'
            : '<a class="btn sm ghost" href="/login">Log in</a><a class="btn sm" href="/signup">Get free signals</a>';
        $app = self::app();
        $company = self::e(Config::get('company', ''));
        $year = gmdate('Y');
        $play = (string) Config::get('play_url', '');
        $playLink = $play ? '<a href="' . self::e($play) . '" target="_blank" rel="noopener">Android app</a>' : '';
        $dev = self::devButtons();
        $ann = Settings::get('site_announcement');
        $annHtml = $ann !== '' ? '<div style="background:#0F2A2A;border-bottom:1px solid var(--b);text-align:center;padding:8px 16px;font-size:14px;color:#99F6E4">' . self::e($ann) . '</div>' : '';
        return self::head($title, $meta) . <<<HTML
<body>$annHtml<header class="nav"><div class="wrap">
<a class="logo" href="/"><i>↗</i>$app</a>
<nav class="links"><a href="/record"{$on('/record')}>Track record</a><a href="/pricing"{$on('/pricing')}>Pricing</a><a href="/brokers"{$on('/brokers')}>Brokers</a><a href="/affiliate"{$on('/affiliate')}>Affiliates</a><a href="/#faq">FAQ</a></nav>
<div class="cta">$cta</div><button class="menu-btn" data-menu aria-label="Menu">☰</button></div></header>
<main>{$body}</main>
<footer class="foot"><div class="wrap"><div class="cols">
<div><a class="logo" href="/" style="margin-bottom:10px"><i>↗</i>$app</a><p class="small">Rule-based US stock signals with a public, fully tracked record. Built by $company.</p>$dev</div>
<div><h4>Product</h4><a href="/record">Track record</a><a href="/pricing">Pricing</a><a href="/brokers">Brokers</a>$playLink</div>
<div><h4>Earn</h4><a href="/affiliate">Affiliate program</a><a href="/signup">Create account</a><a href="/login">Log in</a></div>
<div><h4>Legal</h4><a href="/terms">Terms</a><a href="/privacy">Privacy</a><a href="/delete-account">Delete account</a></div>
</div><div class="disc">© $year $company. Signals are generated automatically by technical rules and are for education and information only. They are not financial advice or a recommendation to buy or sell any security. Trading involves risk of loss; past results do not guarantee future results. Only trade money you can afford to lose.</div></div></footer>
<script src="/assets/site.js?v=3" defer></script></body></html>
HTML;
    }

    /** Member dashboard layout. */
    public static function appPage(string $active, string $title, string $body, string $actions = ''): string
    {
        $u = self::$user;
        $unread = (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$u['id']]);
        $items = [
            'dashboard' => ['/app', '◧', 'Dashboard'], 'signals' => ['/app/signals', '⚡', 'Signals'], 'results' => ['/app/results', '📈', 'Results'],
            'watchlist' => ['/app/watchlist', '★', 'Watchlist'], 'alerts' => ['/app/alerts', '🔔', 'Alerts'],
        ];
        $more = ['brokers' => ['/app/brokers', '🏦', 'Brokers'], 'affiliate' => ['/app/affiliate', '🤝', 'Affiliate'],
            'billing' => ['/app/billing', '💎', 'Premium'], 'settings' => ['/app/settings', '⚙', 'Settings']];
        $nav = '';
        foreach ($items as $k => [$href, $ic, $label]) {
            $n = $k === 'alerts' && $unread ? '<span class="n">' . min($unread, 99) . '</span>' : '';
            $nav .= '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '><span class="ic">' . $ic . '</span>' . $label . $n . '</a>';
        }
        $nav .= '<div class="sec">Account</div>';
        foreach ($more as $k => [$href, $ic, $label]) {
            $nav .= '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '><span class="ic">' . $ic . '</span>' . $label . '</a>';
        }
        if (($u['role'] ?? '') === 'admin') $nav .= '<a href="/admin"><span class="ic">🛡</span>Admin panel</a>';
        $premium = Premium::isActive((int) $u['id']) && Settings::on('premium_enabled')
            ? self::pill('💎 Premium', 'a') : (Settings::on('premium_enabled') ? '<a class="btn sm amber" href="/app/billing">Unlock Premium</a>' : '');
        $name = self::e($u['name'] ?: $u['email']);
        $app = self::app();
        $flash = self::flashes();
        $logout = self::postButton('/logout', 'Log out', 'btn sm ghost block');
        return self::head($title, ['noindex' => true]) . <<<HTML
<body><div class="shell"><aside class="side"><a class="logo" href="/app"><i>↗</i>$app</a><nav>$nav</nav>
<div class="bottom"><div class="small muted" style="padding:0 11px 8px;overflow:hidden;text-overflow:ellipsis">$name</div>$logout</div></aside>
<main class="main"><div class="top"><h1>{$title}</h1><div class="sp"></div>$actions $premium</div>$flash$body
<p class="xs dim" style="margin-top:34px">Signals are educational, generated by fixed technical rules, and are not financial advice. Trading involves risk of loss.</p></main></div>
<script src="/assets/site.js?v=3" defer></script></body></html>
HTML;
    }

    public static function adminPage(string $active, string $title, string $body, string $actions = ''): string
    {
        $c = fn(string $sql) => (int) Db::value($sql);
        $badges = [
            'affiliates' => $c("SELECT COUNT(*) FROM affiliates WHERE status = 'pending'"),
            'payouts' => $c("SELECT COUNT(*) FROM payouts WHERE status = 'requested'"),
        ];
        $items = ['overview' => ['/admin', '◧', 'Overview'], 'users' => ['/admin/users', '👤', 'Users'], 'payments' => ['/admin/payments', '💳', 'Payments'],
            'plans' => ['/admin/plans', '💎', 'Plans & pricing'], 'affiliates' => ['/admin/affiliates', '🤝', 'Affiliates'],
            'commissions' => ['/admin/commissions', '💵', 'Commissions'], 'payouts' => ['/admin/payouts', '🏧', 'Payouts'],
            'brokers' => ['/admin/brokers', '🏦', 'Brokers'], 'signals' => ['/admin/signals', '⚡', 'Signals & scanner'],
            'settings' => ['/admin/settings', '⚙', 'Settings'], 'log' => ['/admin/log', '🧾', 'Audit log']];
        $nav = '';
        foreach ($items as $k => [$href, $ic, $label]) {
            $n = !empty($badges[$k]) ? '<span class="n">' . $badges[$k] . '</span>' : '';
            $nav .= '<a href="' . $href . '"' . ($k === $active ? ' class="on"' : '') . '><span class="ic">' . $ic . '</span>' . $label . $n . '</a>';
        }
        $flash = self::flashes();
        $logout = self::postButton('/logout', 'Log out', 'btn sm ghost block');
        return self::head('Admin · ' . $title, ['noindex' => true]) . <<<HTML
<body class="admin"><div class="shell"><aside class="side"><a class="logo" href="/admin"><i>⛨</i>Admin</a><nav>$nav<div class="sec">Site</div>
<a href="/app"><span class="ic">↩</span>Member dashboard</a><a href="/" target="_blank"><span class="ic">↗</span>View website</a></nav>
<div class="bottom">$logout</div></aside>
<main class="main"><div class="top"><h1>{$title}</h1><div class="sp"></div>$actions</div>$flash$body</main></div>
<script src="/assets/site.js?v=3" defer></script></body></html>
HTML;
    }

    public static function errorPage(int $status, string $message): string
    {
        $title = match ($status) { 404 => 'Page not found', 403 => 'Not allowed', 419 => 'Session expired', default => 'Something went wrong' };
        return self::publicPage($title, '<div class="auth"><div class="card c"><div style="font-size:42px">' . ($status === 404 ? '🧭' : '⚠️')
            . '</div><h1>' . self::e($title) . '</h1><p>' . self::e($message) . '</p><a class="btn" href="/">Go to the homepage</a></div></div>', ['noindex' => true]);
    }
}
