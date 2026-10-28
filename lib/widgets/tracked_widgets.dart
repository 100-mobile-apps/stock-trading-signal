import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/tracked.dart';
import 'common.dart';

Color trackedColor(TrackedSignal s) => switch (s.status) {
      'won' => AppColors.bull,
      'lost' => AppColors.bear,
      'open' => AppColors.info,
      'pending' => AppColors.primary,
      'warning' => AppColors.warn,
      _ => AppColors.muted,
    };

class TrackedBadge extends StatelessWidget {
  const TrackedBadge(this.s, {super.key, this.small = false});
  final TrackedSignal s;
  final bool small;

  @override
  Widget build(BuildContext context) {
    final c = trackedColor(s);
    final label = switch (s.status) {
      'pending' => 'NEW BUY',
      'open' => 'BUY ACTIVE',
      'won' => 'WON',
      'lost' => 'LOST',
      'warning' => 'BEARISH',
      _ => s.status.toUpperCase(),
    };
    return Container(
      padding: EdgeInsets.symmetric(horizontal: small ? 6 : 8, vertical: small ? 3 : 4),
      decoration: BoxDecoration(color: c.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(8)),
      child: Text(label,
          style: TextStyle(color: c, fontWeight: FontWeight.w800, fontSize: small ? 10 : 11, letterSpacing: 0.4)),
    );
  }
}

/// Card for a live (pending/open) tracked signal or a recent bearish warning.
class TrackedSignalCard extends StatelessWidget {
  const TrackedSignalCard({super.key, required this.s, required this.onTap, this.margin});
  final TrackedSignal s;
  final VoidCallback onTap;
  final EdgeInsets? margin;

  @override
  Widget build(BuildContext context) {
    final dir = s.isWarning ? AppColors.warn : AppColors.bull;
    return AppCard(
      onTap: onTap,
      margin: margin ?? const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              SymbolAvatar(s.symbol, size: 38),
              const SizedBox(width: Gap.md),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(s.symbol, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                    Text('Issued ${fmtAgo(s.signalTime.toLocal())} · ${s.intraday ? '1H' : '1D'} · #${s.id}',
                        style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                  ],
                ),
              ),
              TrackedBadge(s),
            ],
          ),
          const SizedBox(height: Gap.md),
          if (s.isWarning)
            const InfoNote('Downtrend momentum confirmed. Avoid new buys; review any open position.',
                icon: Icons.warning_amber_rounded, color: AppColors.warn)
          else
            Row(
              children: [
                _Lv(s.isOpen ? 'Entry (filled)' : 'Entry ≈', s.entry, AppColors.text),
                _Lv('Stop', s.stop, AppColors.bear),
                _Lv('Target', s.t2, AppColors.bull),
              ],
            ),
          const SizedBox(height: Gap.md),
          Row(
            children: [
              StrengthMeter(s.strength, color: dir, showLabel: true),
              const Spacer(),
              if (s.openPct != null)
                Text('${fmtPct(s.openPct!)} · ${fmtR(s.openR!)}',
                    style: numStyle(12.5, weight: FontWeight.w700, color: AppColors.change(s.openPct!)))
              else
                Text(s.statusLabel, style: TextStyle(color: trackedColor(s), fontSize: 12, fontWeight: FontWeight.w600)),
            ],
          ),
        ],
      ),
    );
  }
}

class _Lv extends StatelessWidget {
  const _Lv(this.label, this.value, this.color);
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

/// One line in the history list: stock, dates, result.
class TrackedRow extends StatelessWidget {
  const TrackedRow({super.key, required this.s, required this.onTap});
  final TrackedSignal s;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final c = trackedColor(s);
    return InkWell(
      onTap: onTap,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 11),
        child: Row(
          children: [
            Container(
              width: 36,
              height: 36,
              decoration: BoxDecoration(color: c.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(10)),
              child: Icon(
                s.isWin
                    ? Icons.check_rounded
                    : s.status == 'lost'
                        ? Icons.close_rounded
                        : s.isWarning
                            ? Icons.warning_amber_rounded
                            : Icons.schedule_rounded,
                color: c,
                size: 20,
              ),
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(s.symbol, style: const TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 2),
                  Text(
                    s.isClosed
                        ? '${fmtDate(s.signalTime, withYear: false)} → ${fmtDate(s.exitTime!, withYear: false)} · '
                            '${fmtPrice(s.entry)} → ${fmtPrice(s.exitPrice!)}'
                        : '${fmtDate(s.signalTime, withTime: s.intraday)} · @ ${fmtPrice(s.signalPrice)}',
                    style: const TextStyle(color: AppColors.muted, fontSize: 12),
                  ),
                ],
              ),
            ),
            Column(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                if (s.rMultiple != null)
                  Text(fmtR(s.rMultiple!), style: numStyle(14, weight: FontWeight.w800, color: AppColors.change(s.rMultiple!))),
                if (s.returnPct != null)
                  Text(fmtPct(s.returnPct!), style: numStyle(11.5, color: AppColors.change(s.returnPct!))),
                if (s.rMultiple == null) Text(s.statusLabel, style: TextStyle(color: c, fontSize: 11.5)),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// Banner shown when the server can't be reached.
class ServerBanner extends StatelessWidget {
  const ServerBanner({super.key, required this.message, this.onRetry});
  final String message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) => AppCard(
        color: AppColors.bear.withValues(alpha: 0.08),
        borderColor: AppColors.bear.withValues(alpha: 0.4),
        padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.sm, Gap.sm),
        child: Row(
          children: [
            const Icon(Icons.cloud_off_rounded, color: AppColors.bear, size: 20),
            const SizedBox(width: Gap.md),
            Expanded(child: Text(message, style: const TextStyle(fontSize: 12.5))),
            if (onRetry != null) TextButton(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      );
}
