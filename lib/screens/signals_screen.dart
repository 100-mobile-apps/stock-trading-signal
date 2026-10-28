import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../models/tracked.dart';
import '../services/market_data.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/tracked_widgets.dart';
import 'tracked_signal_screen.dart';

enum _Filter { all, buys, warnings }

/// Live signals and full history, straight from the server's tracked record.
class SignalsScreen extends StatefulWidget {
  const SignalsScreen({super.key});

  @override
  State<SignalsScreen> createState() => _SignalsScreenState();
}

class _SignalsScreenState extends State<SignalsScreen> {
  _Filter _filter = _Filter.all;
  String _outcome = 'all'; // all | won | lost

  void _open(TrackedSignal s) =>
      Navigator.push(context, MaterialPageRoute(builder: (_) => TrackedSignalScreen(signal: s)));

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Signals'),
          actions: [
            Padding(
              padding: const EdgeInsets.only(right: Gap.md),
              child: TimeframeToggle(value: s.timeframe, onChanged: s.setTimeframe),
            ),
          ],
          bottom: const TabBar(tabs: [Tab(text: 'Live'), Tab(text: 'History')]),
        ),
        body: TabBarView(children: [_live(s), _history(s)]),
      ),
    );
  }

  Widget _live(AppState s) {
    final list = s.liveSignals.where((x) => switch (_filter) {
          _Filter.all => true,
          _Filter.buys => !x.isWarning,
          _Filter.warnings => x.isWarning,
        }).toList();
    return RefreshIndicator(
      onRefresh: s.refreshServer,
      child: ListView(
        padding: const EdgeInsets.only(bottom: Gap.xl),
        children: [
          if (s.serverError != null) ServerBanner(message: s.serverError!, onRetry: s.refreshServer),
          Padding(
            padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.xs),
            child: Row(
              children: [
                for (final (f, label) in const [(_Filter.all, 'All'), (_Filter.buys, 'Buy'), (_Filter.warnings, 'Bearish')])
                  Padding(
                    padding: const EdgeInsets.only(right: Gap.sm),
                    child: ChoiceChip(
                      label: Text(label),
                      selected: _filter == f,
                      onSelected: (_) => setState(() => _filter = f),
                    ),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: Gap.lg + 4, vertical: Gap.xs),
            child: FreshnessLine(updated: s.lastUpdated, refreshing: s.refreshing),
          ),
          if (s.refreshing && s.liveSignals.isEmpty && s.record == null)
            ...List.generate(3, (_) => const SkeletonCard())
          else if (list.isEmpty)
            MessageView(
              icon: Icons.hourglass_empty_rounded,
              title: 'No live signals',
              message: s.notif.allSignals
                  ? 'Nothing meets every rule right now. Waiting is part of the strategy; you’ll be alerted the moment a setup forms.'
                  : 'Nothing on your watchlist meets every rule right now. Add more stocks or turn on “All tracked stocks” in Settings.',
            )
          else
            for (final x in list) TrackedSignalCard(s: x, onTap: () => _open(x)),
        ],
      ),
    );
  }

  Widget _history(AppState s) {
    final rows = s.closedSignals.where((x) => _outcome == 'all' || x.status == _outcome).toList();
    final all = s.record?.all;
    return RefreshIndicator(
      onRefresh: s.refreshServer,
      child: ListView(
        padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, Gap.xl),
        children: [
          if (all != null && all.trades > 0)
            AppCard(
              margin: const EdgeInsets.only(bottom: Gap.md),
              child: Row(
                children: [
                  Expanded(child: _kv('Closed', '${all.trades}')),
                  Expanded(child: _kv('Won', '${all.wins}', AppColors.bull)),
                  Expanded(child: _kv('Lost', '${all.losses}', AppColors.bear)),
                  Expanded(child: _kv('Win rate', '${all.winRate.toStringAsFixed(0)}%')),
                ],
              ),
            ),
          Row(
            children: [
              for (final (k, label) in const [('all', 'All'), ('won', 'Won'), ('lost', 'Lost')])
                Padding(
                  padding: const EdgeInsets.only(right: Gap.sm),
                  child: ChoiceChip(
                    label: Text(label),
                    selected: _outcome == k,
                    onSelected: (_) => setState(() => _outcome = k),
                  ),
                ),
            ],
          ),
          const SizedBox(height: Gap.sm),
          const InfoNote('Every signal the server issued and closed. Nothing is removed, and losses are included.'),
          const SizedBox(height: Gap.sm),
          if (rows.isEmpty)
            const MessageView(
              icon: Icons.history_rounded,
              title: 'No closed signals yet',
              message: 'The live record fills in as trades reach their target, stop or time limit.',
            )
          else
            for (var i = 0; i < rows.length; i++) ...[
              if (i > 0) const Divider(height: 1),
              TrackedRow(s: rows[i], onTap: () => _open(rows[i])),
            ],
        ],
      ),
    );
  }

  Widget _kv(String k, String v, [Color? c]) => Column(
        children: [
          Text(v, style: numStyle(18, weight: FontWeight.w800, color: c)),
          Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
        ],
      );
}

class TimeframeToggle extends StatelessWidget {
  const TimeframeToggle({super.key, required this.value, required this.onChanged});
  final Timeframe value;
  final ValueChanged<Timeframe> onChanged;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(3),
        decoration: BoxDecoration(color: AppColors.surface2, borderRadius: BorderRadius.circular(10)),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final tf in Timeframe.values)
              GestureDetector(
                onTap: () => onChanged(tf),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 160),
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
                  decoration: BoxDecoration(
                    color: tf == value ? AppColors.primary : Colors.transparent,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: Text(
                    tf.label,
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      fontSize: 13,
                      color: tf == value ? const Color(0xFF042F2E) : AppColors.muted,
                    ),
                  ),
                ),
              ),
          ],
        ),
      );
}
