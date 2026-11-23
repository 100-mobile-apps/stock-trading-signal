import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../engine/portfolio.dart';
import '../models/tracked.dart';
import '../state/app_state.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import '../widgets/tracked_widgets.dart';
import 'performance_screen.dart';
import 'signals_screen.dart';

/// The verified live track record: results by day, week and month.
class ResultsScreen extends StatefulWidget {
  const ResultsScreen({super.key});

  @override
  State<ResultsScreen> createState() => _ResultsScreenState();
}

class _ResultsScreenState extends State<ResultsScreen> {
  String _range = 'week'; // today | week | month | all
  String _period = 'weekly'; // daily | weekly | monthly

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final r = s.record;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Results'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: Gap.md),
            child: TimeframeToggle(value: s.timeframe, onChanged: s.setTimeframe),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: s.refreshServer,
        child: r == null
            ? ListView(children: [
                if (s.serverError != null) ServerBanner(message: s.serverError!, onRetry: s.refreshServer),
                if (s.refreshing) ...List.generate(3, (_) => const SkeletonCard()),
              ])
            : _body(s, r),
      ),
    );
  }

  Widget _body(AppState s, TrackRecord r) {
    final stats = switch (_range) { 'today' => r.today, 'month' => r.month, 'all' => r.all, _ => r.week };
    final rows = switch (_period) { 'daily' => r.daily, 'monthly' => r.monthly, _ => r.weekly };
    return ListView(
      padding: const EdgeInsets.only(bottom: Gap.xl),
      children: [
        if (s.serverError != null) ServerBanner(message: s.serverError!, onRetry: s.refreshServer),
        Padding(
          padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.xs, Gap.lg + 4, Gap.sm),
          child: Row(
            children: [
              const Icon(Icons.verified_rounded, color: AppColors.bull, size: 18),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  'Verified live record${r.startedAt == null ? '' : ' since ${fmtDate(r.startedAt!)}'}. '
                  'Every signal is saved when issued; losses included.',
                  style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.4),
                ),
              ),
            ],
          ),
        ),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
          child: Wrap(
            spacing: 6,
            runSpacing: 6,
            children: [
              for (final (k, l) in const [('today', 'Today'), ('week', 'This week'), ('month', 'This month'), ('all', 'All time')])
                ChoiceChip(label: Text(l), selected: _range == k, onSelected: (_) => setState(() => _range = k)),
            ],
          ),
        ),
        _hero(stats, r),
        const SectionHeader('Results by period'),
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
          child: SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'daily', label: Text('Daily')),
              ButtonSegment(value: 'weekly', label: Text('Weekly')),
              ButtonSegment(value: 'monthly', label: Text('Monthly')),
            ],
            selected: {_period},
            showSelectedIcon: false,
            onSelectionChanged: (v) => setState(() => _period = v.first),
          ),
        ),
        AppCard(
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.sm),
          child: rows.isEmpty
              ? const Padding(padding: EdgeInsets.all(Gap.md), child: InfoNote('No periods yet.'))
              : Column(children: [
                  for (var i = 0; i < rows.length; i++) ...[
                    if (i > 0) const Divider(height: 1),
                    _PeriodTile(row: rows[i], maxAbsR: rows.fold<double>(0.5, (m, x) => x.stats.totalR.abs() > m ? x.stats.totalR.abs() : m)),
                  ],
                ]),
        ),
        if (r.equity.length >= 2) ...[
          SectionHeader('Account growth at ${r.riskPct.toStringAsFixed(2)}% risk', subtitle: 'All closed signals, in order'),
          AppCard(child: EquityChart(r.equity.map((e) => EquityPoint(e.$1, e.$2)).toList())),
        ],
        if (r.all.trades > 0) ...[
          const SectionHeader('How trades ended', subtitle: 'All time'),
          AppCard(child: _Outcomes(stats: r.all)),
        ],
        if (r.bySymbol.isNotEmpty) ...[
          const SectionHeader('By stock', subtitle: 'Total result in R (1R = amount risked)'),
          AppCard(
            padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.sm),
            child: Column(children: [for (final x in r.bySymbol.take(15)) _SymbolTile(x)]),
          ),
        ],
        const SectionHeader('Transparency'),
        AppCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const InfoNote('Signals are recorded by the server at the moment they are issued and closed automatically '
                  'at the stop, the target or after 20 bars. Entries use the next bar’s open; 0.1% costs are deducted.'),
              const SizedBox(height: Gap.md),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () {
                        Clipboard.setData(ClipboardData(text: AppConfig.publicRecordUrl));
                        ScaffoldMessenger.of(context)
                            .showSnackBar(const SnackBar(content: Text('Public track-record link copied')));
                      },
                      icon: const Icon(Icons.link_rounded, size: 18),
                      label: const Text('Public record'),
                    ),
                  ),
                  const SizedBox(width: Gap.sm),
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () =>
                          Navigator.push(context, MaterialPageRoute(builder: (_) => const PerformanceScreen())),
                      icon: const Icon(Icons.science_outlined, size: 18),
                      label: const Text('Backtest'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _hero(RecordStats st, TrackRecord r) {
    final col = AppColors.change(st.totalR);
    return AppCard(
      borderColor: st.trades == 0 ? null : col.withValues(alpha: 0.4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(st.trades == 0 ? '0.00R' : fmtR(st.totalR), style: numStyle(34, weight: FontWeight.w800, color: col)),
              const SizedBox(width: Gap.sm),
              Padding(
                padding: const EdgeInsets.only(bottom: 7),
                child: Text('${fmtPct(st.returnPct)} at ${r.riskPct.toStringAsFixed(2)}% risk',
                    style: const TextStyle(color: AppColors.muted)),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          StatGrid(columns: 3, children: [
            StatTile('Closed', '${st.trades}', hint: '${st.wins}W · ${st.losses}L'),
            StatTile('Win rate', st.trades == 0 ? '—' : '${st.winRate.toStringAsFixed(1)}%'),
            StatTile('Profit factor', st.profitFactorLabel,
                color: st.trades == 0 ? null : ((st.profitFactor ?? 99) >= 1 ? AppColors.bull : AppColors.bear)),
            StatTile('New signals', '${st.issued}'),
            StatTile('Max drawdown', '-${st.maxDrawdownPct.toStringAsFixed(1)}%', color: AppColors.bear),
            StatTile('Open now', '${r.openTrades}', hint: 'Still running'),
          ]),
        ],
      ),
    );
  }
}

class _PeriodTile extends StatelessWidget {
  const _PeriodTile({required this.row, required this.maxAbsR});
  final PeriodRow row;
  final double maxAbsR;

  @override
  Widget build(BuildContext context) {
    final st = row.stats;
    final c = AppColors.change(st.totalR);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          SizedBox(
            width: 108,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(row.label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
                Text('${row.buySignals} signals${row.warnings > 0 ? ' · ${row.warnings} warn' : ''}',
                    style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              ],
            ),
          ),
          Expanded(
            child: st.trades == 0
                ? const Text('No closed trades', style: TextStyle(color: AppColors.faint, fontSize: 12))
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${st.trades} closed · ${st.wins}W ${st.losses}L · ${st.winRate.toStringAsFixed(0)}%',
                          style: const TextStyle(fontSize: 12)),
                      const SizedBox(height: 4),
                      LayoutBuilder(
                        builder: (context, cons) => Container(
                          height: 6,
                          width: cons.maxWidth * (st.totalR.abs() / maxAbsR).clamp(0.03, 1.0),
                          decoration: BoxDecoration(color: c.withValues(alpha: 0.75), borderRadius: BorderRadius.circular(3)),
                        ),
                      ),
                    ],
                  ),
          ),
          SizedBox(
            width: 70,
            child: Text(st.trades == 0 ? '—' : fmtR(st.totalR),
                textAlign: TextAlign.right, style: numStyle(14, weight: FontWeight.w800, color: st.trades == 0 ? AppColors.faint : c)),
          ),
        ],
      ),
    );
  }
}

class _Outcomes extends StatelessWidget {
  const _Outcomes({required this.stats});
  final RecordStats stats;

  @override
  Widget build(BuildContext context) {
    final items = [
      ('Target hit', stats.targets, AppColors.bull),
      ('Time exit', stats.timeExits, AppColors.info),
      ('Stopped out', stats.stops, AppColors.bear),
    ];
    final total = items.fold<int>(0, (a, b) => a + b.$2).clamp(1, 1 << 30);
    return Column(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: Row(children: [
            for (final (_, n, c) in items)
              if (n > 0) Expanded(flex: n, child: Container(height: 12, color: c)),
          ]),
        ),
        const SizedBox(height: Gap.md),
        Row(children: [
          for (final (l, n, c) in items)
            Expanded(
              child: Row(children: [
                Container(width: 10, height: 10, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(3))),
                const SizedBox(width: 6),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(l, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
                    Text('$n · ${(n / total * 100).toStringAsFixed(0)}%', style: numStyle(13, weight: FontWeight.w700)),
                  ]),
                ),
              ]),
            ),
        ]),
      ],
    );
  }
}

class _SymbolTile extends StatelessWidget {
  const _SymbolTile(this.x);
  final SymbolResult x;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 8),
        child: Row(
          children: [
            SymbolAvatar(x.symbol, size: 32),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(x.symbol, style: const TextStyle(fontWeight: FontWeight.w700)),
                Text('${x.trades} trades · ${x.winRate.toStringAsFixed(0)}% win',
                    style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              ]),
            ),
            Text(fmtR(x.totalR), style: numStyle(14, weight: FontWeight.w800, color: AppColors.change(x.totalR))),
          ],
        ),
      );
}
