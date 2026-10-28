<?php
declare(strict_types=1);

namespace SignalLab;

/** HTML emails and small web pages (verify / reset / public track record). */
final class Templates
{
    private const BRAND = '#0F766E';

    // ------------------------------------------------------------------ emails

    /** @return array{html:string, text:string} */
    public static function email(string $heading, string $innerHtml, string $text, ?array $button = null): array
    {
        $app = Fmt::e((string) Config::get('app_name', 'Stock Trading Signals'));
        $btn = $button
            ? '<tr><td style="padding:8px 0 4px"><a href="' . Fmt::e($button[1]) . '" style="display:inline-block;background:' . self::BRAND
              . ';color:#ffffff;text-decoration:none;font-weight:700;padding:13px 22px;border-radius:10px">' . Fmt::e($button[0]) . '</a></td></tr>'
            : '';
        $dev = self::devLinks('#0F766E');
        $html = <<<HTML
<!DOCTYPE html><html><body style="margin:0;background:#EEF2F6;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#0F172A">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F6;padding:24px 12px"><tr><td align="center">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">
<tr><td style="padding:4px 4px 14px;font-size:18px;font-weight:800;color:#0F172A">
  <span style="display:inline-block;width:26px;height:26px;border-radius:8px;background:linear-gradient(135deg,#14B8A6,#22C55E);vertical-align:middle;margin-right:8px"></span>$app</td></tr>
<tr><td style="background:#ffffff;border-radius:16px;padding:26px 24px;border:1px solid #E2E8F0">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td style="font-size:20px;font-weight:800;padding-bottom:12px">{$heading}</td></tr>
<tr><td style="font-size:15px;line-height:1.55;color:#334155">{$innerHtml}</td></tr>
$btn
</table></td></tr>
<tr><td style="padding:16px 8px;font-size:11.5px;line-height:1.5;color:#64748B">
Signals are generated automatically by fixed rules for educational purposes and are not financial advice.
Trading involves risk of loss. Past results do not guarantee future results.<br>
You can change email preferences anytime in the app: Settings → Notifications.
{$dev}
</td></tr></table></td></tr></table></body></html>
HTML;
        return ['html' => $html, 'text' => $text . "\n\n—\nNot financial advice. Change email preferences in the app: Settings → Notifications."];
    }

    public static function verifyEmail(string $name, string $link): array
    {
        $hi = $name !== '' ? 'Hi ' . Fmt::e($name) . ',' : 'Hi,';
        return self::email('Confirm your email',
            "$hi<br><br>Confirm your email address to receive signal alerts and performance reports.",
            "Confirm your email: $link", ['Confirm email', $link]);
    }

    public static function resetEmail(string $name, string $link): array
    {
        return self::email('Reset your password',
            'Someone (hopefully you) asked to reset your password. This link expires in 1 hour. '
            . 'If it wasn\'t you, ignore this email and your password stays the same.',
            "Reset your password (valid 1 hour): $link", ['Choose a new password', $link]);
    }

    public static function signalEmail(array $s, string $kind, string $title, string $body): array
    {
        $rows = [];
        $p = fn($k) => Fmt::price((float) $s[$k]);
        if ($kind === 'new_buy') {
            $rows = [['Entry (reference)', $p('signal_price')], ['Stop-loss', $p('stop_loss')], ['Target 1', $p('target1')],
                ['Target 2', $p('target2')], ['Strength', $s['strength'] . '/4'], ['Timeframe', $s['tf'] === '1d' ? 'Daily (swing)' : 'Hourly (intraday)'],
                ['Signal time', Fmt::date($s['signal_time'], true)]];
        } elseif (in_array($kind, ['target', 'stop', 'time'], true)) {
            $rows = [['Entry', $p('entry_price') . ' · ' . Fmt::date($s['entry_time'])], ['Exit', $p('exit_price') . ' · ' . Fmt::date($s['exit_time'])],
                ['Result', Fmt::pct((float) $s['return_pct']) . ' · ' . Fmt::r((float) $s['r_multiple'])], ['Held', $s['bars_held'] . ' bars']];
        }
        $table = '';
        if ($rows) {
            $table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:14px;border-top:1px solid #E2E8F0">';
            foreach ($rows as [$k, $v]) {
                $table .= '<tr><td style="padding:9px 0;border-bottom:1px solid #E2E8F0;color:#64748B">' . Fmt::e($k)
                    . '</td><td align="right" style="padding:9px 0;border-bottom:1px solid #E2E8F0;font-weight:700">' . Fmt::e($v) . '</td></tr>';
            }
            $table .= '</table>';
        }
        $reasons = '';
        if ($kind === 'new_buy') {
            $list = json_decode((string) $s['reasons'], true) ?: [];
            $reasons = '<p style="margin:16px 0 0;font-weight:700">Why this signal</p><ul style="margin:6px 0 0;padding-left:18px">'
                . implode('', array_map(fn($r) => '<li>' . Fmt::e($r) . '</li>', $list)) . '</ul>'
                . '<p style="margin:14px 0 0;font-size:13px;color:#64748B">Place your stop-loss order as soon as you enter. Use the app\'s position-size calculator to size the trade to your risk.</p>';
        }
        $text = "$title\n$body\n" . implode("\n", array_map(fn($r) => "$r[0]: $r[1]", $rows));
        return self::email(Fmt::e($title), Fmt::e($body) . $table . $reasons, $text, ['Open in app', (string) Config::get('app_url')]);
    }

    public static function reportEmail(string $name, string $period, string $label, array $d): array
    {
        $o = $d['overall'];
        $w = $d['watchlist'];
        $a = $d['all_time'];
        $tile = fn($k, $v, $c = '#0F172A') => '<td width="33%" style="padding:10px;background:#F8FAFC;border-radius:10px"><div style="font-size:12px;color:#64748B">'
            . $k . '</div><div style="font-size:18px;font-weight:800;color:' . $c . '">' . $v . '</div></td>';
        $col = fn($v) => $v > 0 ? '#16A34A' : ($v < 0 ? '#DC2626' : '#0F172A');
        $pf = $o['profit_factor'] === null ? '∞' : number_format((float) $o['profit_factor'], 2);

        $html = '<p style="margin:0 0 14px">' . ($name !== '' ? 'Hi ' . Fmt::e($name) . ', h' : 'H') . 'ere is how every '
            . ($d['tf'] === '1d' ? 'daily' : 'hourly') . ' signal performed for <b>' . Fmt::e($label) . '</b>. All trades are counted, including losses.</p>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="6"><tr>'
            . $tile('Closed trades', (string) $o['trades']) . $tile('Win rate', $o['trades'] ? $o['win_rate'] . '%' : '—')
            . $tile('Result', Fmt::r((float) $o['total_r']), $col((float) $o['total_r'])) . '</tr><tr>'
            . $tile('New signals', (string) $d['issued']) . $tile('Profit factor', $o['trades'] ? $pf : '—')
            . $tile('At ' . rtrim(rtrim(number_format((float) $d['risk_pct'], 2), '0'), '.') . '% risk', Fmt::pct((float) $o['return_pct']), $col((float) $o['return_pct']))
            . '</tr></table>';

        if ($d['closed']) {
            $html .= '<p style="margin:18px 0 6px;font-weight:700">Closed trades</p><table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
            foreach (array_slice($d['closed'], 0, 25) as $r) {
                $R = (float) $r['r_multiple'];
                $why = ['target' => 'Target', 'stop' => 'Stop', 'time' => 'Time exit'][$r['exit_reason']] ?? '';
                $html .= '<tr><td style="padding:7px 0;border-bottom:1px solid #E2E8F0"><b>' . Fmt::e($r['symbol']) . '</b> <span style="color:#64748B;font-size:13px">'
                    . Fmt::price((float) $r['entry_price']) . ' → ' . Fmt::price((float) $r['exit_price']) . ' · ' . $why . '</span></td>'
                    . '<td align="right" style="padding:7px 0;border-bottom:1px solid #E2E8F0;font-weight:700;color:' . $col($R) . '">'
                    . Fmt::pct((float) $r['return_pct']) . ' · ' . Fmt::r($R) . '</td></tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<p style="margin:18px 0 0;color:#64748B">No trades closed in this period.</p>';
        }
        if ($d['open']) {
            $html .= '<p style="margin:18px 0 6px;font-weight:700">Still open (' . count($d['open']) . ')</p><p style="margin:0;color:#334155">'
                . Fmt::e(implode(', ', array_map(fn($r) => $r['symbol'], array_slice($d['open'], 0, 20)))) . '</p>';
        }
        $html .= '<p style="margin:18px 0 0;font-size:13px;color:#64748B">Your watchlist this period: ' . $w['trades'] . ' closed, '
            . $w['wins'] . ' wins, ' . Fmt::r((float) $w['total_r']) . '. All-time record: ' . $a['trades'] . ' trades, '
            . $a['win_rate'] . '% wins, ' . Fmt::r((float) $a['total_r']) . '.</p>';

        $text = sprintf("%s report (%s)\nClosed: %d · Wins: %d · Result: %s · New signals: %d\nAll-time: %d trades, %s%% wins, %s",
            ucfirst($period), $label, $o['trades'], $o['wins'], Fmt::r((float) $o['total_r']), $d['issued'],
            $a['trades'], $a['win_rate'], Fmt::r((float) $a['total_r']));
        return self::email(ucfirst($period) . ' report · ' . Fmt::e($label), $html, $text,
            ['View full track record', rtrim((string) Config::get('base_url'), '/') . '/record']);
    }

    /** "Chat with the developer · Buy me a coffee" footer links (from config.developer). */
    public static function devLinks(string $color): string
    {
        $links = [];
        if ($u = (string) Config::get('developer.chat_url', '')) {
            $links[] = '<a href="' . Fmt::e($u) . '" style="color:' . $color . '">💬 Chat with the developer</a>';
        }
        if ($u = (string) Config::get('developer.coffee_url', '')) {
            $links[] = '<a href="' . Fmt::e($u) . '" style="color:' . $color . '">☕ Buy me a coffee</a>';
        }
        if ($u = (string) Config::get('developer.hire_url', '')) {
            $links[] = '<a href="' . Fmt::e($u) . '" style="color:' . $color . '">🚀 Need an app or website? Hire '
                . Fmt::e((string) Config::get('company', 'the developer')) . '</a>';
        }
        return $links ? '<br><br>' . implode(' &nbsp;·&nbsp; ', $links) : '';
    }

    // ------------------------------------------------------------------ pages

    public static function page(string $title, string $body): string
    {
        $t = Fmt::e($title);
        $app = Fmt::e((string) Config::get('app_name', 'Stock Trading Signals'));
        $dev = self::devLinks('#14B8A6');
        $dev = $dev === '' ? '' : '<div class="dev">' . substr($dev, 8) . '</div>';
        return <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>$t · $app</title>
<style>
.dev{margin-top:18px;font-size:13px;color:#8A96AD;text-align:center}.dev a{color:#14B8A6;text-decoration:none;font-weight:600}
:root{--bg:#0A0F1C;--card:#111827;--b:#223049;--t:#E6EAF2;--m:#8A96AD;--p:#14B8A6;--g:#22C55E;--r:#F43F5E}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--t);font-family:-apple-system,Segoe UI,Roboto,Inter,sans-serif;padding:24px 16px}
.w{max-width:760px;margin:0 auto}.logo{display:flex;align-items:center;gap:10px;font-weight:800;font-size:20px;margin-bottom:22px}
.logo i{width:30px;height:30px;border-radius:9px;background:linear-gradient(135deg,#14B8A6,#22C55E);display:block}
.card{background:var(--card);border:1px solid var(--b);border-radius:16px;padding:20px;margin-bottom:14px}
h1{font-size:24px;margin:0 0 8px}h2{font-size:17px;margin:0 0 12px}p{color:var(--m);line-height:1.55}
input{width:100%;padding:13px 14px;border-radius:12px;border:1px solid var(--b);background:#172136;color:var(--t);font-size:16px;margin:6px 0 12px}
button{width:100%;padding:14px;border:0;border-radius:12px;background:var(--p);color:#042F2E;font-weight:800;font-size:16px;cursor:pointer}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px}.tile{background:#172136;border-radius:12px;padding:12px}
.tile .l{color:var(--m);font-size:12px}.tile .v{font-size:20px;font-weight:800;margin-top:4px}
table{width:100%;border-collapse:collapse;font-size:14px}td,th{padding:9px 6px;border-bottom:1px solid var(--b);text-align:left}th{color:var(--m);font-weight:600;font-size:12px}
.pos{color:var(--g)}.neg{color:var(--r)}.muted{color:var(--m)}.r{text-align:right}.small{font-size:12px}
</style></head><body><div class="w"><div class="logo"><i></i>$app</div>$body$dev</div></body></html>
HTML;
    }

    public static function messagePage(string $title, string $message, bool $ok = true): string
    {
        $icon = $ok ? '✅' : '⚠️';
        return self::page($title, '<div class="card" style="text-align:center;padding:36px 20px"><div style="font-size:44px">' . $icon
            . '</div><h1>' . Fmt::e($title) . '</h1><p>' . Fmt::e($message) . '</p></div>');
    }

    public static function resetForm(string $token, ?string $error = null): string
    {
        $err = $error ? '<p style="color:#F43F5E">' . Fmt::e($error) . '</p>' : '';
        return self::page('Choose a new password', '<div class="card"><h1>Choose a new password</h1>' . $err
            . '<form method="post"><input type="hidden" name="token" value="' . Fmt::e($token) . '">'
            . '<label class="small muted">New password (8+ characters, letters and numbers)</label>'
            . '<input type="password" name="password" minlength="8" required autocomplete="new-password">'
            . '<button type="submit">Save password</button></form></div>');
    }

    public static function privacyPage(): string
    {
        $app = Fmt::e((string) Config::get('app_name', 'Stock Trading Signals'));
        $company = Fmt::e((string) Config::get('company', $app));
        $from = Fmt::e((string) Config::get('mail.from', ''));
        $base = Fmt::e(rtrim((string) Config::get('base_url'), '/'));
        $sections = [
            ['About this policy', "$app is operated by $company. This policy covers the app and our website and API at $base. The service provides educational stock-market information, not brokerage accounts or custody of funds."],
            ['Account information', 'When you register, we receive your name and email address and store a hash of your password, not the password itself. If you use Google Sign-In, we receive your Google email address and account identifier, your name if provided, and verify your sign-in token with Google. We store account creation, verification and last-login times.'],
            ['Settings and alerts', 'We store your watchlist, trading-style and risk settings (including an account-size figure you enter, not a linked bank balance), notification choices, the alerts sent to your account and whether they were read or delivered. If you enable push notifications, we store your Firebase device token to send alerts. Our servers also keep queued email content and delivery status.'],
            ['Technical and local data', 'For security and troubleshooting, we record IP addresses, email addresses and times for failed sign-ins and password-reset requests. Your device or browser stores sign-in tokens and small local preferences such as whether you completed the introduction and setup. Requests to our hosting provider may also generate standard server logs.'],
            ['Why we use this data', 'We use this information to create and secure your account, remember your settings, follow watchlist symbols, deliver the signals, results and reports you request, prevent abuse and respond to support or deletion requests. We do not sell personal information, display ads or include advertising or analytics SDKs in the app.'],
            ['Market data and public results', 'The app and server request stock prices from Yahoo Finance using ticker symbols; the provider may also receive standard connection information such as an IP address. Our public track-record page shows aggregate trading signals and results, not your identity or personal watchlist.'],
            ['Service providers and external links', 'Google provides Google Sign-In, token verification and Firebase Cloud Messaging for push notifications. Our hosting and email providers process data needed to operate the API and deliver messages. The app may load fonts from Google Fonts. WhatsApp, Buy Me a Coffee and Google Play links open external services only when you choose them; their privacy policies then apply. Providers may process data in other countries.'],
            ['Security', 'The app uses HTTPS to communicate with our API. Passwords are hashed, refresh tokens are stored as hashes on the server, and the mobile app uses platform-protected storage for sign-in tokens. Browser storage depends on your browser. No internet service or storage method can be guaranteed completely secure.'],
            ['Retention and deletion', "Account-linked settings, watchlists, devices, sessions and alert history are deleted when you delete your account. You can delete it in the app under Settings > Account > Delete account. Password-account users can also use <a href=\"$base/delete-account\" style=\"color:#14B8A6\">$base/delete-account</a>; Google-only account holders can use the app or contact us. Some records are not linked for automatic deletion, including login-attempt records, queued or sent emails, server logs and backups. These may remain for security, operations or legal obligations; contact us about any remaining personal information. Market-wide signal history and anonymous aggregate results are not deleted with an individual account."],
            ['Your choices and requests', 'You can update your watchlist, settings and alert preferences in the app, disable push notifications in your device settings, and request access to, correction of or deletion of your personal information by contacting us. We may need to verify your identity before handling a request.'],
            ['Children and updates', "$app is not intended for anyone under 18. We may update this policy as the service changes; the date shown at the top identifies the current version."],
            ['Contact', $from !== '' ? "Privacy questions or requests: <a href=\"mailto:$from\" style=\"color:#14B8A6\">$from</a>" : 'Contact us through the app for privacy questions or requests.'],
        ];
        $h = '<div class="card"><h1>Privacy policy</h1><p class="small">Last updated 11 October 2026</p>';
        foreach ($sections as [$t, $b]) $h .= '<h2 style="margin-top:22px">' . Fmt::e($t) . '</h2><p>' . $b . '</p>';
        return self::page('Privacy policy', $h . '</div>');
    }

    public static function deleteAccountPage(?string $error = null): string
    {
        $app = Fmt::e((string) Config::get('app_name', 'Stock Trading Signals'));
        $err = $error ? '<p style="color:#F43F5E">' . Fmt::e($error) . '</p>' : '';
        return self::page('Delete account', '<div class="card"><h1>Delete your ' . $app . ' account</h1>'
            . '<p>This permanently erases your account, settings, watchlist, devices and alert history. It cannot be undone.</p>'
            . '<p class="small">Signed up with Google? Open the app → Settings → Account → Delete account.</p>' . $err
            . '<form method="post"><label class="small muted">Email</label><input type="email" name="email" required>'
            . '<label class="small muted">Password</label><input type="password" name="password" required>'
            . '<button type="submit" style="background:#F43F5E;color:#fff">Delete my account permanently</button></form></div>');
    }

    /** Public, verifiable track record page. */
    public static function recordPage(array $rep, array $recent): string
    {
        $a = $rep['summary']['all'];
        $cls = fn($v) => $v > 0 ? 'pos' : ($v < 0 ? 'neg' : '');
        $pf = $a['profit_factor'] === null ? '∞' : ($a['trades'] ? number_format((float) $a['profit_factor'], 2) : '—');
        $since = $rep['record_started'] ? substr($rep['record_started'], 0, 10) : '—';
        $h = '<div class="card"><h1>Live track record</h1><p>Every signal is saved the moment it is issued and closed automatically by fixed rules. '
            . 'Nothing is deleted or edited, and losses count. Record started ' . Fmt::e($since) . ' (UTC). Results in R: 1R = the amount risked per trade.</p>'
            . '<div class="grid">'
            . '<div class="tile"><div class="l">Closed trades</div><div class="v">' . $a['trades'] . '</div></div>'
            . '<div class="tile"><div class="l">Win rate</div><div class="v">' . ($a['trades'] ? $a['win_rate'] . '%' : '—') . '</div></div>'
            . '<div class="tile"><div class="l">Total result</div><div class="v ' . $cls($a['total_r']) . '">' . Fmt::r((float) $a['total_r']) . '</div></div>'
            . '<div class="tile"><div class="l">Profit factor</div><div class="v">' . $pf . '</div></div>'
            . '<div class="tile"><div class="l">Max drawdown (1% risk)</div><div class="v neg">-' . $a['max_drawdown_pct'] . '%</div></div>'
            . '<div class="tile"><div class="l">Open now</div><div class="v">' . $rep['open_trades'] . '</div></div>'
            . '</div></div>';
        $h .= '<div class="card"><h2>By month</h2><table><tr><th>Month</th><th class="r">Signals</th><th class="r">Closed</th><th class="r">Win rate</th><th class="r">Result</th></tr>';
        foreach ($rep['monthly'] as $m) {
            $h .= '<tr><td>' . Fmt::e($m['label']) . '</td><td class="r">' . $m['issued'] . '</td><td class="r">' . $m['trades'] . '</td><td class="r">'
                . ($m['trades'] ? $m['win_rate'] . '%' : '—') . '</td><td class="r ' . $cls($m['total_r']) . '">' . Fmt::r((float) $m['total_r']) . '</td></tr>';
        }
        $h .= '</table></div><div class="card"><h2>Latest closed signals</h2><table><tr><th>Stock</th><th>Signal</th><th>Entry → exit</th><th class="r">Result</th></tr>';
        foreach ($recent as $r) {
            $R = (float) $r['r_multiple'];
            $h .= '<tr><td><b>' . Fmt::e($r['symbol']) . '</b> <span class="muted small">' . ($r['tf'] === '1d' ? 'D' : 'H') . '</span></td><td class="small">'
                . Fmt::e(Fmt::date($r['signal_time'])) . '</td><td class="small">' . Fmt::price((float) $r['entry_price']) . ' → ' . Fmt::price((float) $r['exit_price'])
                . ' <span class="muted">(' . Fmt::e((string) $r['exit_reason']) . ')</span></td><td class="r ' . $cls($R) . '"><b>' . Fmt::r($R) . '</b></td></tr>';
        }
        if (!$recent) $h .= '<tr><td colspan="4" class="muted">No closed signals yet. The record fills in as trades finish.</td></tr>';
        $h .= '</table></div><p class="small">Educational information only, not financial advice. Prices from public market data, delayed.</p>';
        return self::page('Live track record', $h);
    }
}
