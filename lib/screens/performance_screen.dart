import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/backtest.dart';
import '../engine/portfolio.dart';
import '../state/app_state.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import 'info_screens.dart';
import 'shell.dart';
import 'signals_screen.dart';

class PerformanceScreen extends StatelessWidget {
  const PerformanceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final pf = s.backtest;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Backtest (simulated)'),
        actions: [
          Padding(
            padding: const EdgeInsets.only(right: Gap.md),
            child: TimeframeToggle(value: s.timeframe, onChanged: s.setTimeframe),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => s.refresh(force: true),
        child: pf.total == 0
            ? ListView(children: [
                if (s.refreshing)
                  ...List.generate(3, (_) => const SkeletonCard())
                else
                  const MessageView(
                    icon: Icons.query_stats_rounded,
                    title: 'No backtest yet',
                    message: 'Once your watchlist loads, every past signal is simulated here, including every loss.',
                  ),
              ])
            : ListView(
                padding: const EdgeInsets.only(bottom: Gap.xl),
                children: [
                  const Padding(
                    padding: EdgeInsets.fromLTRB(Gap.lg, 0, Gap.lg, Gap.sm),
                    child: InfoNote(
                      'Hypothetical: the same rules replayed over past prices on your watchlist. '
                      'For real results, see the verified live record in the Results tab.',
                      icon: Icons.science_outlined,
                      color: AppColors.warn,
                    ),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.xs, Gap.lg + 4, Gap.sm),
                    child: Text(
                      'Every signal on your ${s.watchlist.length}-stock watchlist since ${fmtDate(pf.since!)}, '
                      'sized at your ${s.profile.riskPct.toStringAsFixed(2)}% risk per trade, after costs.',
                      style: const TextStyle(color: AppColors.muted, height: 1.4),
                    ),
                  ),
                  _Hero(pf: pf),
                  const SectionHeader('Key numbers'),
                  AppCard(
                    child: StatGrid(columns: 3, children: [
                      StatTile('Trades', '${pf.total}'),
                      StatTile('Win rate', '${pf.winRate.toStringAsFixed(1)}%', hint: '${pf.wins} wins'),
                      StatTile('Profit factor',
                          pf.profitFactor.isInfinite ? '∞' : pf.profitFactor.toStringAsFixed(2),
                          color: pf.profitFactor >= 1 ? AppColors.bull : AppColors.bear,
                          hint: '> 1 = profitable'),
                      StatTile('Avg trade', fmtR(pf.avgR), color: AppColors.change(pf.avgR), hint: 'In risk units'),
                      StatTile('Max drawdown', '-${pf.maxDrawdownPct.toStringAsFixed(1)}%',
                          color: AppColors.bear, hint: 'Worst dip'),
                      StatTile('Losing streak', '${pf.longestLosingStreak}', hint: 'Longest in a row'),
                    ]),
                  ),
                  const SectionHeader('How trades ended'),
                  AppCard(child: _Outcomes(pf: pf)),
                  const SectionHeader('By stock', subtitle: 'Total result in risk units (R)'),
                  AppCard(
                    padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: Gap.sm),
                    child: Column(
                      children: [
                        for (final p in pf.bySymbol) _SymbolRow(p: p, maxAbs: _maxAbs(pf)),
                      ],
                    ),
                  ),
                  const SectionHeader('Reading these numbers'),
                  const AppCard(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        InfoNote('1R = the amount you risk per trade. +2R means you made twice your risk.'),
                        SizedBox(height: Gap.sm),
                        InfoNote('A win rate near 40% can still be profitable because winners are ~2× the size of losers.'),
                        SizedBox(height: Gap.sm),
                        InfoNote('These are simulated (backtested) results, not real trades. Future results will differ.',
                            icon: Icons.warning_amber_rounded, color: AppColors.warn),
                      ],
                    ),
                  ),
                  Center(
                    child: TextButton(
                      onPressed: () =>
                          Navigator.push(context, MaterialPageRoute(builder: (_) => const MethodologyScreen())),
                      child: const Text('How the track record is calculated'),
                    ),
                  ),
                ],
              ),
      ),
    );
  }

  static double _maxAbs(PortfolioStats pf) =>
      pf.bySymbol.fold<double>(0.1, (m, p) => p.totalR.abs() > m ? p.totalR.abs() : m);
}

class _Hero extends StatelessWidget {
  const _Hero({required this.pf});
  final PortfolioStats pf;

  @override
  Widget build(BuildContext context) {
    final up = pf.returnPct >= 0;
    return AppCard(
      borderColor: (up ? AppColors.bull : AppColors.bear).withValues(alpha: 0.35),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('Simulated account growth', style: TextStyle(color: AppColors.muted)),
          const SizedBox(height: 4),
          Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(fmtPct(pf.returnPct), style: numStyle(34, weight: FontWeight.w800, color: AppColors.change(pf.returnPct))),
              const SizedBox(width: Gap.sm),
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text('${fmtR(pf.totalR)} total', style: const TextStyle(color: AppColors.muted)),
              ),
            ],
          ),
          const SizedBox(height: Gap.md),
          EquityChart(pf.equity),
        ],
      ),
    );
  }
}

class _Outcomes extends StatelessWidget {
  const _Outcomes({required this.pf});
  final PortfolioStats pf;

  @override
  Widget build(BuildContext context) {
    final tp = pf.count(ExitReason.takeProfit);
    final sl = pf.count(ExitReason.stopLoss);
    final te = pf.count(ExitReason.timeLimit);
    final total = (tp + sl + te).clamp(1, 1 << 30);
    Widget seg(int n, Color c) => n == 0 ? const SizedBox.shrink() : Expanded(flex: n, child: Container(height: 12, color: c));
    Widget legend(String label, int n, Color c) => Expanded(
          child: Row(
            children: [
              Container(width: 10, height: 10, decoration: BoxDecoration(color: c, borderRadius: BorderRadius.circular(3))),
              const SizedBox(width: 6),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
                    Text('$n · ${(n / total * 100).toStringAsFixed(0)}%', style: numStyle(13, weight: FontWeight.w700)),
                  ],
                ),
              ),
            ],
          ),
        );
    return Column(
      children: [
        ClipRRect(
          borderRadius: BorderRadius.circular(6),
          child: Row(children: [seg(tp, AppColors.bull), seg(te, AppColors.info), seg(sl, AppColors.bear)]),
        ),
        const SizedBox(height: Gap.md),
        Row(children: [
          legend('Target hit', tp, AppColors.bull),
          legend('Time exit', te, AppColors.info),
          legend('Stopped out', sl, AppColors.bear),
        ]),
      ],
    );
  }
}

class _SymbolRow extends StatelessWidget {
  const _SymbolRow({required this.p, required this.maxAbs});
  final SymbolPerformance p;
  final double maxAbs;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.change(p.totalR);
    return InkWell(
      onTap: () => openStock(context, p.symbol),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 9),
        child: Row(
          children: [
            SymbolAvatar(p.symbol, size: 32),
            const SizedBox(width: Gap.md),
            SizedBox(
              width: 88,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(p.symbol, style: const TextStyle(fontWeight: FontWeight.w700)),
                  Text('${p.trades} trades · ${p.winRate.toStringAsFixed(0)}%',
                      style: const TextStyle(color: AppColors.muted, fontSize: 11)),
                ],
              ),
            ),
            Expanded(
              child: LayoutBuilder(
                builder: (context, cons) => Align(
                  alignment: Alignment.centerLeft,
                  child: Container(
                    height: 8,
                    width: cons.maxWidth * (p.totalR.abs() / maxAbs).clamp(0.02, 1.0),
                    decoration: BoxDecoration(color: c.withValues(alpha: 0.7), borderRadius: BorderRadius.circular(4)),
                  ),
                ),
              ),
            ),
            SizedBox(
              width: 64,
              child: Text(fmtR(p.totalR), textAlign: TextAlign.right, style: numStyle(13, weight: FontWeight.w700, color: c)),
            ),
          ],
        ),
      ),
    );
  }
}
