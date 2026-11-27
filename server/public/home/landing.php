<?php
declare(strict_types=1);
/**
 * 100apps landing page kit: the Forex Signals Live homepage layout, reusable by every signal app.
 *
 * Usage (from the app's homepage file):
 *   $site = [...];                      // brand, colours, copy (see site keys below)
 *   $data = ['stats' => [...], 'live' => [...], 'results' => [...]];
 *   require '/path/to/landing.php';     // renders the full page
 *
 * stats:   win_rate (float|null), pips (float), closed (int), live (int), unit ('pips'|'R'|'%'),
 *          series: list of [Y-m-d, cumulative] for the growth chart (oldest first)
 * live:    list of [symbol, direction BUY|SELL, opened (unix ts), strength (int|null), tag (string|null)]
 * results: list of [symbol, direction, entry, close, label, result (float), closed (unix ts)]
 * best:    optional list of [symbol, trades, win_rate|null, result] (best recent markets, best first)
 */

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$S = $site; $D = $data;
$unit = $D['stats']['unit'] ?? 'pips';
$fmt = function ($v, bool $sign = true) use ($unit): string {
    $v = (float)$v; $abs = abs($v);
    $n = $unit === 'R' || $unit === '%' ? number_format($abs, 2) : ($abs >= 1000 ? number_format($abs, 0) : number_format($abs, 1));
    $n = rtrim(rtrim($n, '0'), '.') === '' ? '0' : (str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n);
    return ($sign ? ($v > 0 ? '+' : ($v < 0 ? '-' : '')) : ($v < 0 ? '-' : '')) . $n . ($unit === 'R' ? 'R' : ($unit === '%' ? '%' : ''));
};
$ago = function (int $ts): string {
    $d = time() - $ts;
    if ($d < 3600) return max(1, (int)floor($d / 60)) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
};
$cls = fn($v) => (float)$v > 0 ? 'up' : ((float)$v < 0 ? 'down' : 'muted');

// growth chart (SVG polyline)
$chart = '';
$series = $D['stats']['series'] ?? [];
if (count($series) >= 2) {
    $vals = array_map(fn($p) => (float)$p[1], $series);
    $min = min(0, min($vals)); $max = max(0, max($vals)); $rng = ($max - $min) ?: 1;
    $w = 600; $h = 140; $n = count($vals) - 1; $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i / $n * $w, 1) . ',' . round($h - ($v - $min) / $rng * ($h - 10) - 5, 1);
    $zero = round($h - (0 - $min) / $rng * ($h - 10) - 5, 1);
    $last = end($vals);
    $col = $last >= 0 ? 'var(--up)' : 'var(--down)';
    $chart = '<svg class="lk-chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<line x1="0" y1="' . $zero . '" x2="' . $w . '" y2="' . $zero . '" stroke="var(--line2)" stroke-dasharray="4 4"/>'
        . '<polyline fill="none" stroke="' . $col . '" stroke-width="2.2" vector-effect="non-scaling-stroke" points="' . implode(' ', $pts) . '"/></svg>';
}

$ic = [
    'plan' => '<path d="M4 6h16M4 12h10M4 18h7"/>', 'chart' => '<path d="M4 19V5M4 19h16M8 15l3-4 3 2 5-6"/>',
    'check' => '<path d="M5 12l4 4 10-10"/>', 'bell' => '<path d="M6 16V11a6 6 0 1112 0v5l2 2H4zM10 20h4"/>',
    'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>', 'calc' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h2M14 12h2M8 16h2M14 16h2"/>',
    'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>', 'globe' => '<circle cx="12" cy="12" r="8"/><path d="M4 12h16M12 4c3 3 3 13 0 16M12 4c-3 3-3 13 0 16"/>',
    'bot' => '<rect x="5" y="8" width="14" height="10" rx="3"/><path d="M12 4v4M9 13h.01M15 13h.01"/>', 'news' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 9h8M8 13h8M8 17h5"/>',
    'play' => '<path d="M7 4l13 8-13 8z" fill="currentColor" stroke="none"/>', 'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'wa' => '<path d="M5 19l1.3-3.6A7.5 7.5 0 1112 19.5a7.4 7.4 0 01-3.6-.9z"/>', 'tick' => '<path d="M5 12l4 4 10-10"/>',
];
$icon = fn($k) => '<svg class="ic" viewBox="0 0 24 24">' . ($ic[$k] ?? $ic['check']) . '</svg>';
$st = $D['stats'];
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $e($S['meta_title']) ?></title>
<meta name="description" content="<?= $e($S['meta_description']) ?>">
<link rel="canonical" href="<?= $e($S['url']) ?>/">
<meta property="og:title" content="<?= $e($S['meta_title']) ?>"><meta property="og:description" content="<?= $e($S['meta_description']) ?>">
<meta property="og:url" content="<?= $e($S['url']) ?>/"><meta property="og:type" content="website"><meta name="theme-color" content="#070B14">
<style><?php readfile(__DIR__ . '/landing.css'); ?>
:root{--a1:<?= $e($S['accent'][0]) ?>;--a2:<?= $e($S['accent'][1]) ?>;--a3:<?= $e($S['accent'][2]) ?>;--on-accent:<?= $e($S['accent'][3] ?? '#0B0F17') ?>}
</style>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org', '@graph' => [
        ['@type' => 'Organization', 'name' => 'Swiftmynd Technologies Ltd', 'url' => $S['url']],
        ['@type' => 'MobileApplication', 'name' => $S['brand'], 'operatingSystem' => 'ANDROID', 'applicationCategory' => 'FinanceApplication',
         'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'], 'url' => $S['play_url'] ?: $S['url']],
        ['@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $S['faq'])],
    ]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
</head>
<body>
<header class="lk-top"><div class="wrap lk-top-in">
  <a class="lk-brand" href="/"><span class="lk-logo"><?= $e($S['logo']) ?></span><b><?= $e($S['brand']) ?></b></a>
  <nav class="lk-links" id="lk-links">
    <?php foreach ($S['nav'] as [$href, $label]): ?><a href="<?= $e($href) ?>"><?= $e($label) ?></a><?php endforeach ?>
  </nav>
  <div class="lk-cta"><a class="btn btn-accent btn-sm" href="<?= $e($S['dashboard_url']) ?>"><?= $e($S['dashboard_label'] ?? 'Open dashboard') ?></a>
  <button class="iconbtn lk-burger" aria-label="Menu" onclick="document.getElementById('lk-links').classList.toggle('open')"><?= $icon('menu') ?></button></div>
</div></header>

<main>
<section class="lk-hero"><div class="wrap lk-hero-in">
  <div>
    <span class="eyebrow"><?= $e($S['eyebrow']) ?></span>
    <h1><?= $e($S['h1'][0]) ?> <span class="accent"><?= $e($S['h1'][1]) ?></span><?= isset($S['h1'][2]) ? ' ' . $e($S['h1'][2]) : '' ?></h1>
    <p class="lead"><?= $e($S['lead']) ?></p>
    <div class="lk-row">
      <a class="btn btn-accent btn-lg" href="<?= $e($S['signup_url']) ?>">Start free</a>
      <?php if (!empty($S['play_url'])): ?><a class="lk-play" href="<?= $e($S['play_url']) ?>" rel="noopener"><?= $icon('play') ?><span><small>GET IT ON</small>Google Play</span></a><?php endif ?>
    </div>
    <ul class="lk-ticks"><?php foreach ($S['ticks'] as $t): ?><li><?= $icon('tick') ?><?= $e($t) ?></li><?php endforeach ?></ul>
  </div>
  <div class="card lk-record">
    <div class="row-between"><span class="muted small">Track record · all time</span><?php if ($st['live'] > 0): ?><span class="pill pill-live"><i class="dot"></i><?= (int)$st['live'] ?> live</span><?php endif ?></div>
    <div class="lk-kpis">
      <div><b class="big"><?= $st['win_rate'] === null ? '—' : $e(number_format((float)$st['win_rate'], 1)) . '%' ?></b><span class="muted">Win rate</span></div>
      <div><b class="big <?= $cls($st['pips']) ?>"><?= $e($fmt($st['pips'])) ?></b><span class="muted"><?= $unit === 'pips' ? 'Pips' : ($unit === 'R' ? 'Net result' : 'Net return') ?></span></div>
      <div><b class="big"><?= number_format((int)$st['closed']) ?></b><span class="muted">Signals closed</span></div>
    </div>
    <?php if ($chart): ?><p class="muted small" style="margin:10px 0 6px">Growth, last 90 days</p><?= $chart ?><?php endif ?>
    <a class="link small" href="<?= $e($S['record_url']) ?>">See every result →</a>
  </div>
</div></section>

<?php if (!empty($D['best'])): ?>
<section class="section" id="best"><div class="wrap">
  <div class="row-between lk-sec-h"><h2>Best markets right now</h2><span class="muted small"><?= $e($D['best_note'] ?? 'Ranked by tracked results over the last 30 days') ?></span></div>
  <div class="grid4 lk-best">
    <?php foreach (array_slice($D['best'], 0, 4) as $i => [$sym, $trades, $wr, $res]): ?>
    <div class="card"><div class="row-between"><b><?= $e($sym) ?></b><span class="pill<?= $i === 0 ? ' pill-live' : '' ?>">#<?= $i + 1 ?></span></div>
      <b class="big <?= $cls($res) ?>" style="margin-top:10px"><?= $e($fmt($res)) ?></b>
      <span class="muted small"><?= (int)$trades ?> trades · <?= $wr === null ? '—' : $e(number_format((float)$wr, 0)) . '% win rate' ?></span></div>
    <?php endforeach ?>
  </div>
</div></section>
<?php endif ?>

<?php if ($D['live']): ?>
<section class="section"><div class="wrap">
  <div class="row-between lk-sec-h"><h2>Live signals right now</h2><a class="link" href="<?= $e($S['signup_url']) ?>">Sign up free to see the levels →</a></div>
  <div class="grid4 lk-live">
    <?php foreach (array_slice($D['live'], 0, 4) as [$sym, $dir, $opened, $strength, $tag]): $buy = strtoupper($dir) === 'BUY'; ?>
    <a class="card lk-sig" href="<?= $e($S['signup_url']) ?>">
      <div class="row-between"><b><?= $e($sym) ?></b><span class="dir <?= $buy ? 'buy' : 'sell' ?>"><?= $buy ? 'BUY' : 'SELL' ?></span></div>
      <p class="muted small">Opened <?= $e($ago((int)$opened)) ?><?= $tag ? ' · ' . $e($tag) : '' ?></p>
      <div class="lk-blur"><span>Entry</span><b>0.00000</b><span>SL</span><b>0.00000</b><span>TP</span><b>0.00000</b></div>
      <?php if ($strength): ?><p class="small" style="margin:8px 0 0">⚡ <?= (int)$strength ?>% strength</p><?php endif ?>
    </a>
    <?php endforeach ?>
  </div>
</div></section>
<?php endif ?>

<?php if ($D['results']): ?>
<section class="section" style="padding-top:0"><div class="wrap">
  <div class="row-between lk-sec-h"><h2>Latest results</h2><a class="link" href="<?= $e($S['record_url']) ?>">Full track record →</a></div>
  <div class="card lk-table-card"><div class="lk-table-wrap"><table class="lk-table">
    <thead><tr><th>Market</th><th>Direction</th><th class="r">Entry</th><th class="r">Close</th><th>Result</th><th class="r"><?= $unit === 'pips' ? 'Pips' : 'Result' ?></th><th class="r">Closed</th></tr></thead>
    <tbody><?php foreach (array_slice($D['results'], 0, 8) as [$sym, $dir, $entry, $close, $label, $res, $closed]): $buy = strtoupper($dir) === 'BUY'; ?>
      <tr><td><b><?= $e($sym) ?></b></td><td><span class="dir <?= $buy ? 'buy' : 'sell' ?>"><?= $buy ? 'BUY' : 'SELL' ?></span></td>
      <td class="r mono"><?= $e($entry) ?></td><td class="r mono"><?= $e($close) ?></td><td><?= $e($label) ?></td>
      <td class="r mono <?= $cls($res) ?>"><b><?= $e($fmt($res)) ?></b></td><td class="r muted small"><?= $e(gmdate('j M, H:i', (int)$closed)) ?></td></tr>
    <?php endforeach ?></tbody></table></div></div>
</div></section>
<?php endif ?>

<section class="section"><div class="wrap">
  <h2 class="center"><?= $e($S['features_title']) ?></h2>
  <div class="grid3 lk-features">
    <?php foreach ($S['features'] as [$icn, $title, $text]): ?>
    <div class="card"><span class="lk-fi"><?= $icon($icn) ?></span><h3><?= $e($title) ?></h3><p class="muted"><?= $e($text) ?></p></div>
    <?php endforeach ?>
  </div>
</div></section>

<section class="section"><div class="wrap">
  <h2 class="center">How it works</h2>
  <div class="grid3 lk-steps">
    <?php foreach ($S['steps'] as $i => [$title, $text]): ?>
    <div><span class="lk-stepn"><?= $i + 1 ?></span><h3><?= $e($title) ?></h3><p class="muted"><?= $e($text) ?></p></div>
    <?php endforeach ?>
  </div>
</div></section>

<?php if (!empty($S['band'])): ?>
<section class="section"><div class="wrap"><div class="card lk-band">
  <div><h2><?= $e($S['band'][0]) ?></h2><p class="muted" style="margin:0"><?= $e($S['band'][1]) ?></p></div>
  <a class="btn btn-ghost" href="<?= $e($S['band'][3]) ?>"><?= $e($S['band'][2]) ?></a>
</div></div></section>
<?php endif ?>

<section class="section"><div class="wrap narrow">
  <h2 class="center">Questions</h2>
  <?php foreach ($S['faq'] as [$q, $a]): ?><details class="lk-faq"><summary><?= $e($q) ?></summary><p class="muted"><?= $e($a) ?></p></details><?php endforeach ?>
  <p class="muted small center" style="margin-top:24px"><?= $e($S['risk']) ?></p>
</div></section>
</main>

<footer class="lk-foot"><div class="wrap lk-foot-in">
  <div><a class="lk-brand" href="/"><span class="lk-logo"><?= $e($S['logo']) ?></span><b><?= $e($S['brand']) ?></b></a>
    <p class="muted small" style="margin-top:10px;max-width:320px"><?= $e($S['tagline']) ?></p>
    <?php if (!empty($S['play_url'])): ?><a class="lk-play" href="<?= $e($S['play_url']) ?>" rel="noopener"><?= $icon('play') ?><span><small>GET IT ON</small>Google Play</span></a><?php endif ?></div>
  <?php foreach ($S['footer'] as $title => $links): ?><div><h4><?= $e($title) ?></h4><?php foreach ($links as [$href, $label]): ?><a href="<?= $e($href) ?>"><?= $e($label) ?></a><?php endforeach ?></div><?php endforeach ?>
</div><div class="wrap muted small lk-copy">© <?= gmdate('Y') ?> Swiftmynd Technologies Ltd · Built by Swiftmynd Technologies · <a class="link" href="https://buymeacoffee.com/swalafblogM" rel="noopener">Buy me a coffee</a></div></footer>
<a class="lk-wa" href="https://wa.me/2347011401387?text=<?= rawurlencode('Hi, I use ' . $S['brand']) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><?= $icon('wa') ?></a>
</body></html>
