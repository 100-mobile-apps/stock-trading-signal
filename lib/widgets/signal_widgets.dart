import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/analyzer.dart';
import '../engine/backtest.dart';
import '../engine/strategy.dart';
import '../models/profile.dart';
import 'charts.dart';
import 'common.dart';

/// Resolved trade levels for the current signal (uses the real fill once a trade is running).
class Levels {
  final double entry, stop, target1, target2;
  final bool live; // true once the trade has been entered
  const Levels(this.entry, this.stop, this.target1, this.target2, this.live);

  factory Levels.of(Analysis a, Signal s) {
    final t = a.activeTrade;
    if (t != null && identical(t.signal, s)) {
      return Levels(t.entryPrice, t.stopLoss, t.entryPrice + (s.takeProfit1 - s.price), t.takeProfit, true);
    }
    return Levels(s.price, s.stopLoss, s.takeProfit1, s.takeProfit2, false);
  }

  double pctFromEntry(double v) => (v - entry) / entry * 100;
}

class SignalCard extends StatelessWidget {
  const SignalCard({super.key, required this.analysis, required this.onTap, this.compact = false});
  final Analysis analysis;
  final VoidCallback onTap;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final a = analysis;
    final s = a.currentSignal!;
    final lv = Levels.of(a, s);
    final bearishOnly = a.status == SignalStatus.bearishAvoid;
    final dir = s.isBuy ? AppColors.bull : (a.allowShort ? AppColors.bear : AppColors.warn);
    return AppCard(
      onTap: onTap,
      margin: compact ? EdgeInsets.zero : const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SymbolAvatar(a.symbol, size: 38),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(a.symbol, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                    Text(
                      '${a.status.isNew ? 'Signal' : 'Since'} ${fmtAgo(s.time)} · ${fmtPrice(a.series.lastPrice)}',
                      style: const TextStyle(color: AppColors.muted, fontSize: 12),
                    ),
                  ],
                ),
              ),
              StatusBadge(a.status),
            ],
          ),
          const SizedBox(height: Gap.md),
          if (bearishOnly)
            const InfoNote(
              'Downtrend momentum confirmed. Avoid new buys; review any open position.',
              icon: Icons.warning_amber_rounded,
              color: AppColors.warn,
            )
          else
            Row(
              children: [
                _Level('Entry', lv.entry, AppColors.text),
                _Level('Stop', lv.stop, AppColors.bear),
                _Level('Target', lv.target2, AppColors.bull),
              ],
            ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              StrengthMeter(s.strength, color: dir, showLabel: true),
              const Spacer(),
              Text(
                a.backtest.total == 0
                    ? 'No history'
                    : 'History ${a.backtest.winRate.toStringAsFixed(0)}% win · ${a.backtest.total} trades',
                style: const TextStyle(color: AppColors.muted, fontSize: 11.5),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

class _Level extends StatelessWidget {
  const _Level(this.label, this.value, this.color);
  final String label;
  final double value;
  final Color color;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 11)),
            const SizedBox(height: 2),
            Text(fmtPrice(value), style: numStyle(15, weight: FontWeight.w700, color: color)),
          ],
        ),
      );
}

class WatchRow extends StatelessWidget {
  const WatchRow({super.key, required this.symbol, this.analysis, this.error, this.loading = false, this.onTap});
  final String symbol;
  final Analysis? analysis;
  final String? error;
  final bool loading;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final a = analysis;
    if (a == null) {
      return AppCard(
        onTap: onTap,
        child: Row(
          children: [
            SymbolAvatar(symbol),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(symbol, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  const SizedBox(height: 4),
                  error != null && !loading
                      ? Text(error!, style: const TextStyle(color: AppColors.bear, fontSize: 12))
                      : const Skeleton(width: 120, height: 10),
                ],
              ),
            ),
          ],
        ),
      );
    }
    final s = a.series;
    final closes = s.candles.map((c) => c.close).toList();
    final spark = closes.sublist(math.max(0, closes.length - 40));
    return AppCard(
      onTap: onTap,
      padding: const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 14),
      child: Row(
        children: [
          SymbolAvatar(symbol),
          const SizedBox(width: Gap.md),
          Expanded(
            flex: 5,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Text(symbol, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                    if (loading) ...[
                      const SizedBox(width: 6),
                      const SizedBox(width: 10, height: 10, child: CircularProgressIndicator(strokeWidth: 1.5)),
                    ],
                  ],
                ),
                Text(s.name, maxLines: 1, overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                const SizedBox(height: 6),
                StatusBadge(a.status, compact: true),
              ],
            ),
          ),
          Sparkline(spark, width: 64, height: 32),
          const SizedBox(width: Gap.md),
          Column(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(fmtPrice(s.lastPrice), style: numStyle(15, weight: FontWeight.w700)),
              const SizedBox(height: 4),
              ChangeText(s.changePct, pill: true, size: 12),
            ],
          ),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------- Position sizing

class PositionPlan {
  final double shares;
  final double capital;
  final double riskAmount;
  final double lossAtStop;
  final double profitT1;
  final double profitT2;
  final bool cappedByAccount;
  const PositionPlan(this.shares, this.capital, this.riskAmount, this.lossAtStop, this.profitT1,
      this.profitT2, this.cappedByAccount);

  /// Fixed-fractional sizing: risk a set % of the account between entry and stop.
  factory PositionPlan.compute(RiskProfile p, Levels lv) {
    final riskAmount = p.riskAmount;
    final perShare = (lv.entry - lv.stop).abs();
    if (perShare <= 0 || lv.entry <= 0) return const PositionPlan(0, 0, 0, 0, 0, 0, false);
    var shares = riskAmount / perShare;
    var capped = false;
    if (shares * lv.entry > p.accountSize) {
      shares = p.accountSize / lv.entry;
      capped = true;
    }
    if (!p.fractionalShares) shares = shares.floorToDouble();
    return PositionPlan(
      shares,
      shares * lv.entry,
      riskAmount,
      shares * perShare,
      shares * (lv.target1 - lv.entry).abs(),
      shares * (lv.target2 - lv.entry).abs(),
      capped,
    );
  }
}

Future<void> showPositionSizer(BuildContext context, Analysis a, Signal s, RiskProfile p) {
  final lv = Levels.of(a, s);
  final plan = PositionPlan.compute(p, lv);
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (ctx) => SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(Gap.xl, 0, Gap.xl, Gap.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Position size', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            Text(
              'Account ${fmtMoney(p.accountSize, digits: 0)} · risk ${p.riskPct.toStringAsFixed(2)}% '
              '(${fmtMoney(p.riskAmount)}) per trade',
              style: const TextStyle(color: AppColors.muted),
            ),
            const SizedBox(height: Gap.lg),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(Gap.lg),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [
                  AppColors.primary.withValues(alpha: 0.22),
                  AppColors.bull.withValues(alpha: 0.08),
                ]),
                borderRadius: BorderRadius.circular(Gap.radius),
                border: Border.all(color: AppColors.primary.withValues(alpha: 0.4)),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('${s.isBuy ? 'Buy' : 'Sell short'} ${a.symbol}',
                      style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 4),
                  Text(
                    '${fmtShares(plan.shares, fractional: p.fractionalShares)} shares',
                    style: numStyle(30, weight: FontWeight.w800),
                  ),
                  Text('≈ ${fmtMoney(plan.capital)} at ${fmtPrice(lv.entry)}',
                      style: const TextStyle(color: AppColors.muted)),
                ],
              ),
            ),
            const SizedBox(height: Gap.md),
            StatGrid(columns: 3, children: [
              StatTile('If stopped out', fmtMoney(-plan.lossAtStop), color: AppColors.bear),
              StatTile('At target 1', fmtMoney(plan.profitT1), color: AppColors.bull),
              StatTile('At target 2', fmtMoney(plan.profitT2), color: AppColors.bull),
            ]),
            const SizedBox(height: Gap.md),
            if (plan.shares == 0)
              const InfoNote(
                'Your risk amount is smaller than the risk of one share. Enable fractional shares '
                'or increase account size/risk in Settings.',
                color: AppColors.warn,
                icon: Icons.warning_amber_rounded,
              )
            else if (plan.cappedByAccount)
              const InfoNote(
                'Position capped at your account size. The full risk budget would need leverage, '
                'which this app never assumes.',
                color: AppColors.warn,
                icon: Icons.warning_amber_rounded,
              )
            else
              const InfoNote(
                'Size is set so that hitting the stop-loss costs exactly your chosen risk. '
                'Place the stop order with your broker immediately after entering.',
              ),
            const SizedBox(height: Gap.lg),
            FilledButton(onPressed: () => Navigator.pop(ctx), child: const Text('Got it')),
          ],
        ),
      ),
    ),
  );
}

String exitLabel(Trade t) => t.exitReason?.label ?? 'Open';
