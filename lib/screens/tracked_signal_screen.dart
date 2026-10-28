import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/tracked.dart';
import '../services/api_client.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/signal_widgets.dart';
import '../widgets/tracked_widgets.dart';
import 'stock_detail_screen.dart';

/// Full history of one tracked signal: issued → entered → target 1 → closed.
class TrackedSignalScreen extends StatefulWidget {
  const TrackedSignalScreen({super.key, this.signal, this.signalId}) : assert(signal != null || signalId != null);
  final TrackedSignal? signal;
  final int? signalId;

  @override
  State<TrackedSignalScreen> createState() => _TrackedSignalScreenState();
}

class _TrackedSignalScreenState extends State<TrackedSignalScreen> {
  TrackedSignal? _s;
  String? _error;

  @override
  void initState() {
    super.initState();
    _s = widget.signal;
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    final id = widget.signal?.id ?? widget.signalId!;
    try {
      final s = await context.read<AppState>().signalById(id);
      if (mounted) setState(() => _s = s);
    } on ApiException catch (e) {
      if (mounted && _s == null) setState(() => _error = e.message);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    return Scaffold(
      appBar: AppBar(title: Text(s == null ? 'Signal' : '${s.symbol} signal #${s.id}')),
      body: s == null
          ? (_error != null
              ? MessageView(icon: Icons.error_outline, title: 'Couldn’t load signal', message: _error!, color: AppColors.bear)
              : const Center(child: CircularProgressIndicator()))
          : RefreshIndicator(onRefresh: _load, child: _body(s)),
      bottomNavigationBar: s == null
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.lg, Gap.md),
                child: OutlinedButton.icon(
                  onPressed: () => Navigator.push(
                      context, MaterialPageRoute(builder: (_) => StockDetailScreen(symbol: s.symbol))),
                  icon: const Icon(Icons.candlestick_chart_outlined),
                  label: Text('Open ${s.symbol} chart'),
                ),
              ),
            ),
    );
  }

  Widget _body(TrackedSignal s) {
    final c = trackedColor(s);
    final events = <(IconData, Color, String, String, DateTime?)>[
      (Icons.bolt_rounded, s.isWarning ? AppColors.warn : AppColors.primary,
          s.isWarning ? 'Bearish warning issued' : 'BUY signal issued', 'Reference price ${fmtPrice(s.signalPrice)}', s.signalTime),
      if (s.entryTime != null)
        (Icons.login_rounded, AppColors.info, 'Entered at next open', 'Filled at ${fmtPrice(s.entryPrice!)}', s.entryTime),
      if (s.tp1HitTime != null)
        (Icons.flag_outlined, AppColors.bull, 'Target 1 reached', 'Partial-profit level ${fmtPrice(s.t1)}', s.tp1HitTime),
      if (s.isClosed)
        (
          s.isWin ? Icons.emoji_events_outlined : Icons.block_rounded,
          c,
          switch (s.exitReason) { 'target' => 'Target hit', 'stop' => 'Stop-loss hit', _ => 'Time exit' },
          'Closed at ${fmtPrice(s.exitPrice!)} · ${fmtPct(s.returnPct!)} · ${fmtR(s.rMultiple!)}',
          s.exitTime
        ),
      if (s.isOpen || s.isPending)
        (Icons.more_horiz_rounded, AppColors.muted, s.isPending ? 'Waiting for the next bar to open' : 'Trade running',
            s.lastPrice == null ? '' : 'Last price ${fmtPrice(s.lastPrice!)}', null),
    ];

    return ListView(
      padding: const EdgeInsets.only(bottom: Gap.xl),
      children: [
        AppCard(
          borderColor: c.withValues(alpha: 0.45),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  SymbolAvatar(s.symbol, size: 44),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(s.symbol, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                        Text('${s.intraday ? 'Hourly' : 'Daily'} · strength ${s.strength}/4',
                            style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                      ],
                    ),
                  ),
                  TrackedBadge(s),
                ],
              ),
              if (s.isClosed) ...[
                const SizedBox(height: Gap.lg),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(fmtR(s.rMultiple!), style: numStyle(34, weight: FontWeight.w800, color: c)),
                    const SizedBox(width: Gap.sm),
                    Padding(
                      padding: const EdgeInsets.only(bottom: 7),
                      child: Text('${fmtPct(s.returnPct!)} · held ${s.barsHeld ?? '-'} bars',
                          style: const TextStyle(color: AppColors.muted)),
                    ),
                  ],
                ),
              ] else if (s.openPct != null) ...[
                const SizedBox(height: Gap.lg),
                Text('Open result ${fmtPct(s.openPct!)} · ${fmtR(s.openR!)}',
                    style: numStyle(16, weight: FontWeight.w700, color: AppColors.change(s.openPct!))),
              ],
            ],
          ),
        ),
        if (!s.isWarning) ...[
          const SectionHeader('Levels', subtitle: 'Re-based to the actual fill once entered'),
          AppCard(
            child: Column(
              children: [
                _lv(s.entryPrice != null ? 'Entry (filled)' : 'Entry (reference)', s.entry, AppColors.text, null),
                _lv('Stop-loss', s.stop, AppColors.bear, (s.stop - s.entry) / s.entry * 100),
                _lv('Target 1 · partial', s.t1, AppColors.bull, (s.t1 - s.entry) / s.entry * 100),
                _lv('Target 2 · final', s.t2, AppColors.bull, (s.t2 - s.entry) / s.entry * 100),
              ],
            ),
          ),
        ],
        const SectionHeader('Timeline', subtitle: 'Recorded by the server as it happened'),
        AppCard(
          child: Column(
            children: [
              for (var i = 0; i < events.length; i++)
                _TimelineRow(event: events[i], last: i == events.length - 1, intraday: s.intraday),
            ],
          ),
        ),
        const SectionHeader('Why this signal'),
        AppCard(
          child: Column(
            children: [
              for (final r in s.reasons)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.check_circle_rounded, size: 16, color: s.isWarning ? AppColors.warn : AppColors.bull),
                      const SizedBox(width: Gap.sm),
                      Expanded(child: Text(r, style: const TextStyle(fontSize: 13.5))),
                    ],
                  ),
                ),
            ],
          ),
        ),
        if (!s.isWarning && !s.isClosed)
          Padding(
            padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, 0),
            child: FilledButton.icon(
              onPressed: () {
                final p = context.read<AppState>().profile;
                final plan = PositionPlan.compute(p, Levels(s.entry, s.stop, s.t1, s.t2, s.isOpen));
                showModalBottomSheet<void>(
                  context: context,
                  builder: (ctx) => SafeArea(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(Gap.xl, 0, Gap.xl, Gap.xl),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('Position size', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                          const SizedBox(height: Gap.md),
                          Text('${fmtShares(plan.shares, fractional: p.fractionalShares)} shares',
                              style: numStyle(30, weight: FontWeight.w800)),
                          Text('≈ ${fmtMoney(plan.capital)} · risk ${fmtMoney(plan.lossAtStop)} if stopped out',
                              style: const TextStyle(color: AppColors.muted)),
                          const SizedBox(height: Gap.lg),
                          FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Got it')),
                        ],
                      ),
                    ),
                  ),
                );
              },
              icon: const Icon(Icons.calculate_outlined),
              label: const Text('Position size for my account'),
            ),
          ),
      ],
    );
  }

  Widget _lv(String k, double v, Color c, double? pct) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Container(width: 4, height: 18, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(2))),
            const SizedBox(width: Gap.md),
            Expanded(child: Text(k, style: const TextStyle(color: AppColors.muted))),
            Text(fmtPrice(v), style: numStyle(16, weight: FontWeight.w700, color: c)),
            SizedBox(
              width: 70,
              child: Text(pct == null ? '' : fmtPct(pct), textAlign: TextAlign.right, style: numStyle(12.5, color: c)),
            ),
          ],
        ),
      );
}

class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.event, required this.last, required this.intraday});
  final (IconData, Color, String, String, DateTime?) event;
  final bool last;
  final bool intraday;

  @override
  Widget build(BuildContext context) {
    final (icon, color, title, sub, time) = event;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Column(
            children: [
              Container(
                width: 30,
                height: 30,
                decoration: BoxDecoration(color: color.withValues(alpha: 0.15), shape: BoxShape.circle),
                child: Icon(icon, size: 16, color: color),
              ),
              if (!last) Expanded(child: Container(width: 2, color: AppColors.border)),
            ],
          ),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: last ? 0 : Gap.lg, top: 4),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
                  if (sub.isNotEmpty) Text(sub, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                  if (time != null)
                    Text(fmtDate(time, withTime: true), style: const TextStyle(color: AppColors.faint, fontSize: 11.5)),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
