import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/analyzer.dart';
import '../engine/backtest.dart';
import '../models/tracked.dart';
import '../services/market_data.dart';
import '../state/app_state.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import '../widgets/signal_widgets.dart';
import '../widgets/tracked_widgets.dart';
import 'info_screens.dart';
import 'signals_screen.dart';
import 'tracked_signal_screen.dart';

class StockDetailScreen extends StatefulWidget {
  const StockDetailScreen({super.key, required this.symbol});
  final String symbol;

  @override
  State<StockDetailScreen> createState() => _StockDetailScreenState();
}

class _StockDetailScreenState extends State<StockDetailScreen> {
  late Timeframe _tf;
  int _range = 1;

  static const _dailyRanges = [('1M', 22), ('3M', 66), ('6M', 130), ('1Y', 252)];
  static const _hourlyRanges = [('1W', 35), ('2W', 70), ('1M', 150)];

  List<(String, int)> get _ranges => _tf.intraday ? _hourlyRanges : _dailyRanges;

  @override
  void initState() {
    super.initState();
    _tf = context.read<AppState>().timeframe;
    _range = _tf.intraday ? 0 : 1;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load({bool force = false}) async {
    if (!mounted) return;
    await context.read<AppState>().ensureLoaded(widget.symbol, _tf, force: force);
  }

  void _setTf(Timeframe tf) {
    if (tf == _tf) return;
    setState(() {
      _tf = tf;
      _range = tf.intraday ? 0 : 1;
    });
    _load();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final st = s.stateOf(widget.symbol, _tf);
    final a = st.analysis;
    final inList = s.inWatchlist(widget.symbol);
    final sig = a?.currentSignal;
    final canSize = a != null && sig != null && a.status != SignalStatus.bearishAvoid;

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.symbol),
        actions: [
          IconButton(
            tooltip: inList ? 'Remove from watchlist' : 'Add to watchlist',
            icon: Icon(inList ? Icons.star_rounded : Icons.star_border_rounded,
                color: inList ? AppColors.warn : null),
            onPressed: () {
              inList ? s.removeSymbol(widget.symbol) : s.addSymbol(widget.symbol);
              ScaffoldMessenger.of(context)
                ..hideCurrentSnackBar()
                ..showSnackBar(SnackBar(
                  content: Text(inList ? '${widget.symbol} removed from watchlist' : '${widget.symbol} added to watchlist'),
                ));
            },
          ),
          IconButton(
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh_rounded),
            onPressed: st.loading ? null : () => _load(force: true),
          ),
        ],
      ),
      bottomNavigationBar: canSize
          ? SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.lg, Gap.md),
                child: FilledButton.icon(
                  onPressed: () => showPositionSizer(context, a, sig, s.profile),
                  icon: const Icon(Icons.calculate_outlined),
                  label: const Text('Calculate position size'),
                ),
              ),
            )
          : null,
      body: a == null
          ? (st.error != null && !st.loading
              ? MessageView(
                  icon: Icons.error_outline_rounded,
                  title: 'Couldn’t load ${widget.symbol}',
                  message: st.error!,
                  actionLabel: 'Retry',
                  onAction: () => _load(force: true),
                  color: AppColors.bear,
                )
              : ListView(children: const [SkeletonCard(), SkeletonCard(), SkeletonCard()]))
          : RefreshIndicator(
              onRefresh: () => _load(force: true),
              child: ListView(
                padding: const EdgeInsets.only(bottom: Gap.xl),
                children: [
                  _header(a),
                  _chartCard(a),
                  _signalSection(a),
                  _snapshot(a),
                  _keyStats(a),
                  _LiveRecordSection(symbol: widget.symbol),
                  _backtest(a),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.md, Gap.xl, 0),
                    child: TextButton(
                      onPressed: () => Navigator.push(
                          context, MaterialPageRoute(builder: (_) => const MethodologyScreen())),
                      child: const Text('How are these signals generated?'),
                    ),
                  ),
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: Gap.xl),
                    child: Text(
                      'Educational information only, not financial advice. Quotes may be delayed ~15 minutes.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: AppColors.faint, fontSize: 11.5),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  // ---------------------------------------------------------------- header
  Widget _header(Analysis a) {
    final ser = a.series;
    final intraday = _tf.intraday;
    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.xs, Gap.lg + 4, Gap.sm),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SymbolAvatar(ser.symbol, size: 44),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(ser.name, maxLines: 1, overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                    Text('${ser.exchange.isEmpty ? '' : '${ser.exchange} · '}${ser.currency}',
                        style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                  ],
                ),
              ),
              StatusBadge(a.status),
            ],
          ),
          const SizedBox(height: Gap.md),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(fmtPrice(ser.lastPrice), style: numStyle(34, weight: FontWeight.w800)),
              const SizedBox(width: Gap.sm),
              Padding(padding: const EdgeInsets.only(bottom: 7), child: ChangeText(ser.changePct, pill: true)),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            '${ser.hasLiveBar ? 'Live · ' : 'Last close · '}${fmtDate(ser.candles.last.time, withTime: intraday)}'
            ' · delayed ~15 min',
            style: const TextStyle(color: AppColors.muted, fontSize: 12),
          ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              TimeframeToggle(value: _tf, onChanged: _setTf),
              const Spacer(),
              for (var i = 0; i < _ranges.length; i++)
                Padding(
                  padding: const EdgeInsets.only(left: 4),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(8),
                    onTap: () => setState(() => _range = i),
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(
                        color: i == _range ? AppColors.surface2 : Colors.transparent,
                        borderRadius: BorderRadius.circular(8),
                      ),
                      child: Text(
                        _ranges[i].$1,
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.w700,
                          color: i == _range ? AppColors.text : AppColors.muted,
                        ),
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _chartCard(Analysis a) => AppCard(
        padding: const EdgeInsets.fromLTRB(Gap.md, Gap.xs, Gap.sm, Gap.md),
        child: CandleChart(
          candles: a.series.candles,
          indicators: a.indicators,
          signals: a.signals,
          activeTrade: a.activeTrade,
          visibleBars: _ranges[math.min(_range, _ranges.length - 1)].$2,
          intraday: _tf.intraday,
        ),
      );

  // ---------------------------------------------------------------- signal
  Widget _signalSection(Analysis a) {
    final sig = a.currentSignal;
    if (sig == null) {
      final last = a.lastSignal;
      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionHeader('Current signal'),
          AppCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.hourglass_empty_rounded, color: AppColors.muted, size: 20),
                    SizedBox(width: Gap.sm),
                    Text('No setup right now', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  ],
                ),
                const SizedBox(height: Gap.sm),
                const Text(
                  'Trend and momentum don’t line up yet. The best trade is often no trade. '
                  'Keep it on your watchlist and you’ll be alerted when a setup forms.',
                  style: TextStyle(color: AppColors.muted, height: 1.45),
                ),
                if (last != null) ...[
                  const SizedBox(height: Gap.md),
                  const Divider(),
                  const SizedBox(height: Gap.md),
                  Text(
                    'Last signal: ${last.isBuy ? 'BUY' : 'SELL'} ${fmtAgo(last.time)} at ${fmtPrice(last.price)}',
                    style: const TextStyle(fontSize: 13),
                  ),
                ],
              ],
            ),
          ),
        ],
      );
    }

    final bearishOnly = a.status == SignalStatus.bearishAvoid;
    final lv = Levels.of(a, sig);
    final dir = sig.isBuy ? AppColors.bull : (a.allowShort ? AppColors.bear : AppColors.warn);
    final t = a.activeTrade;
    final running = t != null && identical(t.signal, sig);

    final String headline;
    if (bearishOnly) {
      headline = 'Bearish setup confirmed. Avoid new buys. If you hold ${a.symbol}, consider '
          'exiting or tightening your stop.';
    } else if (running) {
      headline = 'Trade running since ${fmtDate(t.entryTime, withTime: _tf.intraday)} '
          '(${a.barsSince(sig)} bars). Manage it with the stop and targets below.';
    } else {
      headline = 'Confirmed on the latest ${_tf.intraday ? 'hourly' : 'daily'} close. '
          'Model entry is the next ${_tf.intraday ? 'hour' : 'session'}’s open near ${fmtPrice(lv.entry)}.';
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(bearishOnly ? 'Bearish warning' : (sig.isBuy ? 'Buy signal' : 'Sell signal'),
            subtitle: 'Generated ${fmtDate(sig.time, withTime: _tf.intraday)}'),
        AppCard(
          borderColor: dir.withValues(alpha: 0.45),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(headline, style: const TextStyle(height: 1.45)),
              if (!bearishOnly) ...[
                const SizedBox(height: Gap.lg),
                _levelRow(running ? 'Entry (filled)' : 'Entry', lv.entry, null, AppColors.text),
                _levelRow('Stop-loss', lv.stop, lv.pctFromEntry(lv.stop), AppColors.bear),
                _levelRow('Target 1 · partial', lv.target1, lv.pctFromEntry(lv.target1), AppColors.bull),
                _levelRow('Target 2 · final', lv.target2, lv.pctFromEntry(lv.target2), AppColors.bull),
                const SizedBox(height: Gap.sm),
                _RiskRewardBar(ratio: a.params.rewardRisk),
                if (running) ...[
                  const SizedBox(height: Gap.md),
                  _openPnl(a, lv, sig.isBuy),
                ],
              ],
              const SizedBox(height: Gap.lg),
              Row(
                children: [
                  const Text('Signal strength', style: TextStyle(color: AppColors.muted)),
                  const Spacer(),
                  StrengthMeter(sig.strength, color: dir, showLabel: true),
                ],
              ),
              const SizedBox(height: Gap.md),
              for (final r in sig.reasons)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.check_circle_rounded, size: 16, color: dir),
                      const SizedBox(width: Gap.sm),
                      Expanded(child: Text(r, style: const TextStyle(fontSize: 13.5))),
                    ],
                  ),
                ),
              if (!bearishOnly) ...[
                const SizedBox(height: Gap.sm),
                const InfoNote('Set the stop-loss order with your broker as soon as you enter. '
                    'Consider taking partial profit at Target 1 and moving the stop to entry.'),
              ],
            ],
          ),
        ),
      ],
    );
  }

  Widget _openPnl(Analysis a, Levels lv, bool long) {
    final px = a.series.lastPrice;
    final pct = (long ? px - lv.entry : lv.entry - px) / lv.entry * 100;
    final r = (long ? px - lv.entry : lv.entry - px) / (lv.entry - lv.stop).abs();
    return Container(
      padding: const EdgeInsets.all(Gap.md),
      decoration: BoxDecoration(color: AppColors.surface2, borderRadius: BorderRadius.circular(12)),
      child: Row(
        children: [
          const Text('Open result', style: TextStyle(color: AppColors.muted)),
          const Spacer(),
          Text('${fmtPct(pct)}  ·  ${fmtR(r)}', style: numStyle(14, weight: FontWeight.w700, color: AppColors.change(pct))),
        ],
      ),
    );
  }

  Widget _levelRow(String label, double v, double? pct, Color c) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 5),
        child: Row(
          children: [
            Container(width: 4, height: 18, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(2))),
            const SizedBox(width: Gap.md),
            Expanded(child: Text(label, style: const TextStyle(color: AppColors.muted))),
            Text(fmtPrice(v), style: numStyle(16, weight: FontWeight.w700, color: c)),
            SizedBox(
              width: 72,
              child: Text(
                pct == null ? '' : fmtPct(pct),
                textAlign: TextAlign.right,
                style: numStyle(12.5, weight: FontWeight.w500, color: c.withValues(alpha: 0.8)),
              ),
            ),
          ],
        ),
      );

  // ------------------------------------------------------------- snapshot
  Widget _snapshot(Analysis a) {
    final i = a.candles.length - 1;
    final ind = a.indicators;
    final close = a.candles[i].close;
    final fast = ind.emaFast[i], slow = ind.emaSlow[i], rsi = ind.rsi[i];
    final ml = ind.macd.line[i], ms = ind.macd.signal[i], atr = ind.atr[i];

    String trend = '—';
    Color trendC = AppColors.muted;
    if (fast != null && slow != null) {
      if (close > slow && fast > slow) {
        trend = 'Uptrend';
        trendC = AppColors.bull;
      } else if (close < slow && fast < slow) {
        trend = 'Downtrend';
        trendC = AppColors.bear;
      } else {
        trend = 'Mixed';
        trendC = AppColors.warn;
      }
    }
    final rsiLabel = rsi == null
        ? '—'
        : rsi >= 70
            ? 'Overbought'
            : rsi <= 30
                ? 'Oversold'
                : rsi >= 50
                    ? 'Bullish'
                    : 'Bearish';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader('Technical snapshot'),
        AppCard(
          child: StatGrid(columns: 3, children: [
            StatTile('Trend', trend, color: trendC, hint: '50 vs 200 EMA'),
            StatTile('RSI (14)', rsi == null ? '—' : rsi.toStringAsFixed(0), hint: rsiLabel),
            StatTile(
              'MACD',
              ml == null || ms == null ? '—' : (ml > ms ? 'Bullish' : 'Bearish'),
              color: ml == null || ms == null ? null : (ml > ms ? AppColors.bull : AppColors.bear),
              hint: 'Momentum',
            ),
            StatTile('Volatility', atr == null ? '—' : '${(atr / close * 100).toStringAsFixed(2)}%', hint: 'ATR per bar'),
            StatTile('vs EMA 200', slow == null ? '—' : fmtPct((close / slow - 1) * 100),
                color: slow == null ? null : AppColors.change(close - slow), hint: 'Distance'),
            StatTile('EMA 50', fast == null ? '—' : fmtPrice(fast), hint: 'Fast trend'),
          ]),
        ),
      ],
    );
  }

  // ------------------------------------------------------------ key stats
  Widget _keyStats(Analysis a) {
    final c = a.series.candles;
    final last = c.last;
    final lastDay = DateTime.utc(last.time.year, last.time.month, last.time.day);
    final today = c.where((x) => !x.time.isBefore(lastDay)).toList();
    final dayLo = today.map((x) => x.low).reduce(math.min);
    final dayHi = today.map((x) => x.high).reduce(math.max);
    final yearAgo = last.time.subtract(const Duration(days: 365));
    final year = c.where((x) => x.time.isAfter(yearAgo)).toList();
    final yLo = year.map((x) => x.low).reduce(math.min);
    final yHi = year.map((x) => x.high).reduce(math.max);
    final avgVol = a.indicators.volumeAvg.isEmpty ? null : a.indicators.volumeAvg.last;
    final prev = a.series.previousClose;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const SectionHeader('Key stats'),
        AppCard(
          child: Column(
            children: [
              _statRow('Previous close', prev == null ? '—' : fmtPrice(prev)),
              _statRow('Day range', '${fmtPrice(dayLo)} – ${fmtPrice(dayHi)}'),
              _statRow(_tf.intraday ? 'Range (data period)' : '52-week range', '${fmtPrice(yLo)} – ${fmtPrice(yHi)}'),
              _RangeBar(lo: yLo, hi: yHi, value: last.close),
              _statRow('Avg volume (20 bars)', avgVol == null ? '—' : fmtCompact(avgVol)),
              _statRow('Last bar volume', fmtCompact(last.volume)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _statRow(String k, String v) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 7),
        child: Row(
          children: [
            Text(k, style: const TextStyle(color: AppColors.muted)),
            const Spacer(),
            Text(v, style: numStyle(14, weight: FontWeight.w600)),
          ],
        ),
      );

  // ------------------------------------------------------------- backtest
  Widget _backtest(Analysis a) {
    final bt = a.backtest;
    final pf = bt.profitFactor;
    final intraday = _tf.intraday;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          'Backtest on ${a.symbol} (simulated)',
          subtitle: bt.from == null
              ? null
              : '${fmtDate(bt.from!)} – ${fmtDate(bt.to!)} · ${a.allowShort ? 'long & short' : 'long only'} · after costs',
        ),
        AppCard(
          child: bt.total == 0
              ? const InfoNote('No completed trades in the available history for this timeframe.')
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    StatGrid(columns: 3, children: [
                      StatTile('Trades', '${bt.total}'),
                      StatTile('Win rate', '${bt.winRate.toStringAsFixed(0)}%', hint: '${bt.wins}W · ${bt.losses}L'),
                      StatTile('Profit factor', pf.isInfinite ? '∞' : pf.toStringAsFixed(2),
                          color: pf >= 1 ? AppColors.bull : AppColors.bear),
                      StatTile('Avg trade', fmtR(bt.avgR), color: AppColors.change(bt.avgR)),
                      StatTile('Avg win', fmtPct(bt.avgWinPct), color: AppColors.bull),
                      StatTile('Avg loss', fmtPct(bt.avgLossPct), color: AppColors.bear),
                      StatTile('All-in return', fmtPct(bt.totalReturnPct),
                          color: AppColors.change(bt.totalReturnPct), hint: 'Full position'),
                      StatTile('Buy & hold', fmtPct(bt.buyHoldReturnPct),
                          color: AppColors.change(bt.buyHoldReturnPct), hint: 'Same period'),
                      StatTile('Max drawdown', '-${bt.maxDrawdownPct.toStringAsFixed(1)}%', color: AppColors.bear),
                    ]),
                    const SizedBox(height: Gap.md),
                    InfoNote(
                      bt.totalReturnPct > bt.buyHoldReturnPct
                          ? 'On ${a.symbol} the signals beat buy-and-hold over this period, with time out of the market.'
                          : 'On ${a.symbol}, simply buying and holding did better. Signals trade less often and aim to limit losses, not to beat every stock.',
                      icon: Icons.balance_rounded,
                    ),
                    if (bt.total < 10) ...[
                      const SizedBox(height: Gap.sm),
                      const InfoNote('Fewer than 10 trades: too few to judge reliably.',
                          icon: Icons.warning_amber_rounded, color: AppColors.warn),
                    ],
                    Theme(
                      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                      child: ExpansionTile(
                        tilePadding: EdgeInsets.zero,
                        title: Text('Trade history (${bt.total})',
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                        children: [
                          for (final t in bt.trades.reversed.take(40)) _tradeRow(t, intraday),
                        ],
                      ),
                    ),
                  ],
                ),
        ),
      ],
    );
  }

  Widget _tradeRow(Trade t, bool intraday) {
    final r = t.returnPct!;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 7),
      child: Row(
        children: [
          Container(
            width: 28,
            height: 28,
            decoration: BoxDecoration(
              color: AppColors.change(r).withValues(alpha: 0.14),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(t.isLong ? Icons.north_east_rounded : Icons.south_east_rounded,
                size: 16, color: AppColors.change(r)),
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('${fmtDate(t.entryTime, withTime: intraday)} → ${fmtDate(t.exitTime!, withTime: intraday)}',
                    style: const TextStyle(fontSize: 12.5)),
                Text('${fmtPrice(t.entryPrice)} → ${fmtPrice(t.exitPrice!)} · ${exitLabel(t)}',
                    style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              ],
            ),
          ),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(fmtPct(r), style: numStyle(14, weight: FontWeight.w700, color: AppColors.change(r))),
              Text(fmtR(t.rMultiple!), style: numStyle(11, color: AppColors.muted)),
            ],
          ),
        ],
      ),
    );
  }
}

class _RiskRewardBar extends StatelessWidget {
  const _RiskRewardBar({required this.ratio});
  final double ratio;

  @override
  Widget build(BuildContext context) => Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(4),
            child: Row(
              children: [
                Expanded(flex: 10, child: Container(height: 8, color: AppColors.bear.withValues(alpha: 0.75))),
                Expanded(flex: (ratio * 10).round(), child: Container(height: 8, color: AppColors.bull.withValues(alpha: 0.75))),
              ],
            ),
          ),
          const SizedBox(height: 6),
          Text('Reward : risk = ${ratio.toStringAsFixed(1)} : 1',
              style: const TextStyle(color: AppColors.muted, fontSize: 12)),
        ],
      );
}

class _RangeBar extends StatelessWidget {
  const _RangeBar({required this.lo, required this.hi, required this.value});
  final double lo, hi, value;

  @override
  Widget build(BuildContext context) {
    final f = hi > lo ? ((value - lo) / (hi - lo)).clamp(0.0, 1.0) : 0.5;
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: LayoutBuilder(
        builder: (context, c) => SizedBox(
          height: 14,
          child: Stack(
            children: [
              Positioned(
                left: 0,
                right: 0,
                top: 5,
                child: Container(
                  height: 4,
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(colors: [AppColors.bear, AppColors.warn, AppColors.bull]),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),
              Positioned(
                left: (c.maxWidth - 14) * f,
                child: Container(
                  width: 14,
                  height: 14,
                  decoration: BoxDecoration(
                    color: AppColors.text,
                    shape: BoxShape.circle,
                    border: Border.all(color: AppColors.bg, width: 3),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Signals the server actually issued for this stock (the verified record).
class _LiveRecordSection extends StatefulWidget {
  const _LiveRecordSection({required this.symbol});
  final String symbol;

  @override
  State<_LiveRecordSection> createState() => _LiveRecordSectionState();
}

class _LiveRecordSectionState extends State<_LiveRecordSection> {
  List<TrackedSignal>? _items;
  String? _error;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      final r = await context.read<AppState>().signalsFor(widget.symbol);
      if (mounted) setState(() => _items = r);
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = _items;
    final closed = items?.where((x) => x.isClosed).toList() ?? const <TrackedSignal>[];
    final wins = closed.where((x) => x.isWin).length;
    final totalR = closed.fold<double>(0, (a, x) => a + (x.rMultiple ?? 0));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SectionHeader(
          'Live signals on ${widget.symbol}',
          subtitle: closed.isEmpty ? 'Verified record from our server' : '${closed.length} closed · $wins won · ${fmtR(totalR)}',
        ),
        AppCard(
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.sm),
          child: items == null
              ? (_error != null
                  ? Padding(padding: const EdgeInsets.all(Gap.sm), child: InfoNote(_error!, color: AppColors.bear))
                  : const Padding(padding: EdgeInsets.all(Gap.md), child: Skeleton(height: 40)))
              : items.isEmpty
                  ? const Padding(
                      padding: EdgeInsets.all(Gap.sm),
                      child: InfoNote('No live signals recorded for this stock yet. It is scanned every hour '
                          'while it is on a watchlist.'),
                    )
                  : Column(children: [
                      for (var i = 0; i < items.length && i < 10; i++) ...[
                        if (i > 0) const Divider(height: 1),
                        TrackedRow(
                          s: items[i],
                          onTap: () => Navigator.push(context,
                              MaterialPageRoute(builder: (_) => TrackedSignalScreen(signal: items[i]))),
                        ),
                      ],
                    ]),
        ),
      ],
    );
  }
}
