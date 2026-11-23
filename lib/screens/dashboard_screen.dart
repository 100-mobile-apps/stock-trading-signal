import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/portfolio.dart';
import '../models/candle.dart';
import '../models/tracked.dart';
import '../services/market_data.dart';
import '../state/app_state.dart';
import '../widgets/charts.dart';
import '../widgets/common.dart';
import '../widgets/support.dart';
import '../widgets/tracked_widgets.dart';
import 'alerts_screen.dart';
import 'shell.dart';
import 'tracked_signal_screen.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key, required this.onNavigate});
  final ValueChanged<int> onNavigate;

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final live = s.liveSignals;
    final user = s.user;
    return Scaffold(
      floatingActionButton: const ChatFab(),
      body: SafeArea(
        child: RefreshIndicator(
          color: AppColors.primary,
          onRefresh: () => s.refresh(force: true),
          child: ListView(
            padding: const EdgeInsets.only(bottom: Gap.xl),
            children: [
              _TopBar(unread: s.unreadAlerts),
              Padding(
                padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.sm, Gap.lg, 0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('${greeting()}${user == null ? '' : ', ${user.displayName}'}',
                        style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, letterSpacing: -0.5)),
                    const SizedBox(height: 4),
                    FreshnessLine(updated: s.lastUpdated, refreshing: s.refreshing),
                  ],
                ),
              ),
              if (s.serverError != null) ServerBanner(message: s.serverError!, onRetry: () => s.refresh(force: true)),
              if (user != null && !user.emailVerified) const _VerifyBanner(),
              const SizedBox(height: Gap.sm),
              _MarketPulse(indexes: s.indexes),
              _SummaryCard(live: live, watchCount: s.watchlist.length, onTap: () => onNavigate(1)),
              SectionHeader('Latest signals', subtitle: s.timeframe.longLabel,
                  action: live.isEmpty ? null : 'See all', onAction: () => onNavigate(1)),
              if (s.refreshing && live.isEmpty && s.record == null)
                ...List.generate(2, (_) => const SkeletonCard())
              else if (live.isEmpty)
                const AppCard(
                  child: InfoNote(
                    'No live setups right now. That’s normal: the scanner waits for trend and momentum to agree, '
                    'and you’ll get a push and email the moment one appears.',
                  ),
                )
              else
                for (final x in live.take(3))
                  TrackedSignalCard(
                    s: x,
                    onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => TrackedSignalScreen(signal: x))),
                  ),
              SectionHeader('Results', action: 'Details', onAction: () => onNavigate(3)),
              _ResultsCard(record: s.record, riskPct: s.profile.riskPct, onTap: () => onNavigate(3)),
              const SectionHeader('Your risk plan'),
              _RiskPlanCard(onEdit: () => onNavigate(4)),
              const Padding(
                padding: EdgeInsets.fromLTRB(Gap.xl, Gap.lg, Gap.xl, 0),
                child: Text(
                  'Signals are generated automatically for education and are not financial advice. '
                  'Trading involves risk of loss.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: AppColors.faint, fontSize: 11.5, height: 1.4),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _TopBar extends StatelessWidget {
  const _TopBar({required this.unread});
  final int unread;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.md, Gap.sm, 0),
        child: Row(
          children: [
            const BrandLogo(size: 30),
            const Spacer(),
            IconButton(
              tooltip: 'Alerts',
              onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const AlertsScreen())),
              icon: Badge(
                isLabelVisible: unread > 0,
                label: Text(unread > 99 ? '99+' : '$unread'),
                child: const Icon(Icons.notifications_none_rounded, size: 26),
              ),
            ),
          ],
        ),
      );
}

class _VerifyBanner extends StatefulWidget {
  const _VerifyBanner();
  @override
  State<_VerifyBanner> createState() => _VerifyBannerState();
}

class _VerifyBannerState extends State<_VerifyBanner> {
  bool _sent = false;

  @override
  Widget build(BuildContext context) {
    final s = context.read<AppState>();
    return AppCard(
      color: AppColors.warn.withValues(alpha: 0.08),
      borderColor: AppColors.warn.withValues(alpha: 0.4),
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.sm, Gap.sm),
      child: Row(
        children: [
          const Icon(Icons.mark_email_unread_outlined, color: AppColors.warn, size: 20),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Text(
              _sent
                  ? 'Link sent. Check your inbox (and spam), then pull to refresh.'
                  : 'Confirm your email to receive signal emails and reports.',
              style: const TextStyle(fontSize: 12.5),
            ),
          ),
          TextButton(
            onPressed: () async {
              if (_sent) {
                await s.reloadUser();
                return;
              }
              try {
                await s.resendVerification();
                if (mounted) setState(() => _sent = true);
              } catch (_) {}
            },
            child: Text(_sent ? 'Done' : 'Resend'),
          ),
        ],
      ),
    );
  }
}

class _MarketPulse extends StatelessWidget {
  const _MarketPulse({required this.indexes});
  final Map<String, MarketSeries> indexes;

  static const _names = {'SPY': 'S&P 500', 'QQQ': 'Nasdaq 100', 'DIA': 'Dow 30'};

  @override
  Widget build(BuildContext context) => SizedBox(
        height: 104,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: Gap.lg),
          children: [
            for (final sym in _names.keys)
              Padding(
                padding: const EdgeInsets.only(right: Gap.sm),
                child: _IndexTile(sym: sym, name: _names[sym]!, series: indexes[sym]),
              ),
          ],
        ),
      );
}

class _IndexTile extends StatelessWidget {
  const _IndexTile({required this.sym, required this.name, this.series});
  final String sym;
  final String name;
  final MarketSeries? series;

  @override
  Widget build(BuildContext context) {
    final s = series;
    return AppCard(
      margin: EdgeInsets.zero,
      padding: const EdgeInsets.all(Gap.md),
      onTap: s == null ? null : () => openStock(context, sym),
      child: SizedBox(
        width: 136,
        child: s == null
            ? const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [Skeleton(width: 60), SizedBox(height: 10), Skeleton(width: 90, height: 18)],
              )
            : Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Text(name, style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w600)),
                      const Spacer(),
                      ChangeText(s.changePct, size: 11.5),
                    ],
                  ),
                  const SizedBox(height: 6),
                  Text(fmtPrice(s.lastPrice), style: numStyle(17, weight: FontWeight.w700)),
                  const Spacer(),
                  Sparkline(_tail(s.candles), width: 136, height: 22),
                ],
              ),
      ),
    );
  }

  List<double> _tail(List<Candle> c) => c.sublist(math.max(0, c.length - 30)).map((e) => e.close).toList();
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.live, required this.watchCount, required this.onTap});
  final List<TrackedSignal> live;
  final int watchCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final newOnes = live.where((x) => x.isPending).length;
    final open = live.where((x) => x.isOpen).length;
    final bearish = live.where((x) => x.isWarning).length;
    return Padding(
      padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.md, Gap.lg, 0),
      child: Material(
        borderRadius: BorderRadius.circular(Gap.radius),
        clipBehavior: Clip.antiAlias,
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          child: Ink(
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                colors: [Color(0xFF0F3B3A), Color(0xFF12263A)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(Gap.radius),
              border: Border.all(color: AppColors.primary.withValues(alpha: 0.35)),
            ),
            padding: const EdgeInsets.all(Gap.lg),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    const Icon(Icons.radar_rounded, color: AppColors.primary, size: 20),
                    const SizedBox(width: 6),
                    Text('Scanning $watchCount stocks every hour',
                        style: const TextStyle(color: Color(0xFFB7F5EC), fontWeight: FontWeight.w600)),
                    const Spacer(),
                    const Icon(Icons.chevron_right_rounded, color: AppColors.muted),
                  ],
                ),
                const SizedBox(height: Gap.md),
                Row(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text('${newOnes + open}', style: numStyle(40, weight: FontWeight.w800)),
                    const SizedBox(width: Gap.sm),
                    const Padding(
                      padding: EdgeInsets.only(bottom: 8),
                      child: Text('live signals', style: TextStyle(color: AppColors.muted, fontSize: 15)),
                    ),
                  ],
                ),
                const SizedBox(height: Gap.md),
                Row(
                  children: [
                    _Pill('$newOnes new', AppColors.bull),
                    const SizedBox(width: Gap.sm),
                    _Pill('$open running', AppColors.info),
                    const SizedBox(width: Gap.sm),
                    _Pill('$bearish bearish', AppColors.warn),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill(this.text, this.color);
  final String text;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700)),
      );
}

class _ResultsCard extends StatelessWidget {
  const _ResultsCard({required this.record, required this.riskPct, required this.onTap});
  final TrackRecord? record;
  final double riskPct;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final r = record;
    if (r == null) return const AppCard(child: Skeleton(height: 70));
    Widget col(String title, RecordStats st) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              const SizedBox(height: 4),
              Text(st.trades == 0 ? '—' : fmtR(st.totalR),
                  style: numStyle(17, weight: FontWeight.w800, color: st.trades == 0 ? AppColors.faint : AppColors.change(st.totalR))),
              Text(st.trades == 0 ? 'no closed trades' : '${st.wins}W · ${st.losses}L',
                  style: const TextStyle(color: AppColors.faint, fontSize: 11)),
            ],
          ),
        );
    return AppCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [col('Today', r.today), col('This week', r.week), col('This month', r.month)]),
          const SizedBox(height: Gap.md),
          const Divider(),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Row(children: [
                      Icon(Icons.verified_rounded, size: 14, color: AppColors.bull),
                      SizedBox(width: 4),
                      Text('All-time live record', style: TextStyle(color: AppColors.muted, fontSize: 12)),
                    ]),
                    const SizedBox(height: 4),
                    Text(
                      r.all.trades == 0
                          ? 'Starting…'
                          : '${fmtR(r.all.totalR)} · ${r.all.winRate.toStringAsFixed(0)}% win · ${r.all.trades} trades',
                      style: numStyle(14, weight: FontWeight.w700),
                    ),
                  ],
                ),
              ),
              if (r.equity.length >= 2)
                SizedBox(width: 110, child: EquityChart(r.equity.map((e) => EquityPoint(e.$1, e.$2)).toList(), height: 48)),
            ],
          ),
        ],
      ),
    );
  }
}

class _RiskPlanCard extends StatelessWidget {
  const _RiskPlanCard({required this.onEdit});
  final VoidCallback onEdit;

  @override
  Widget build(BuildContext context) {
    final p = context.watch<AppState>().profile;
    Widget kv(String k, String v, {Color? color}) => Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(k, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              const SizedBox(height: 4),
              Text(v, style: numStyle(15, weight: FontWeight.w700, color: color)),
            ],
          ),
        );
    return AppCard(
      onTap: onEdit,
      child: Row(children: [
        kv('Account', fmtMoney(p.accountSize, digits: 0)),
        kv('Risk / trade', '${p.riskPct.toStringAsFixed(2)}%'),
        kv('Max loss / trade', fmtMoney(p.riskAmount), color: AppColors.bear),
      ]),
    );
  }
}
