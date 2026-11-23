import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/analyzer.dart';

class AppCard extends StatelessWidget {
  const AppCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(Gap.lg),
    this.margin = const EdgeInsets.symmetric(horizontal: Gap.lg, vertical: 6),
    this.onTap,
    this.color,
    this.borderColor,
  });

  final Widget child;
  final EdgeInsets padding;
  final EdgeInsets margin;
  final VoidCallback? onTap;
  final Color? color;
  final Color? borderColor;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(Gap.radius);
    return Padding(
      padding: margin,
      child: Material(
        color: color ?? AppColors.surface,
        shape: RoundedRectangleBorder(
          borderRadius: radius,
          side: BorderSide(color: borderColor ?? AppColors.border),
        ),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

/// Brand mark: gradient tile with a rising signal line.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 40});
  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        gradient: AppColors.brandGradient,
        borderRadius: BorderRadius.circular(size * 0.28),
        boxShadow: [
          BoxShadow(color: AppColors.primary.withValues(alpha: 0.35), blurRadius: size * 0.4),
        ],
      ),
      child: CustomPaint(painter: _MarkPainter()),
    );
  }
}

class _MarkPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size s) {
    final p = Paint()
      ..color = const Color(0xFF042F2E)
      ..style = PaintingStyle.stroke
      ..strokeWidth = s.width * 0.09
      ..strokeCap = StrokeCap.round
      ..strokeJoin = StrokeJoin.round;
    final path = Path()
      ..moveTo(s.width * 0.2, s.height * 0.68)
      ..lineTo(s.width * 0.4, s.height * 0.48)
      ..lineTo(s.width * 0.55, s.height * 0.6)
      ..lineTo(s.width * 0.8, s.height * 0.3);
    canvas.drawPath(path, p);
    canvas.drawCircle(
      Offset(s.width * 0.8, s.height * 0.3),
      s.width * 0.08,
      Paint()..color = const Color(0xFF042F2E),
    );
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 32});
  final double size;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          BrandMark(size: size),
          SizedBox(width: size * 0.3),
          Text(
            'Stock Trading Signals',
            style: TextStyle(fontSize: size * 0.62, fontWeight: FontWeight.w800, letterSpacing: -0.5),
          ),
        ],
      );
}

class StatusBadge extends StatelessWidget {
  const StatusBadge(this.status, {super.key, this.compact = false});
  final SignalStatus status;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.status(status);
    return Container(
      padding: EdgeInsets.symmetric(horizontal: compact ? 6 : 8, vertical: compact ? 3 : 4),
      decoration: BoxDecoration(
        color: c.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (status.isNew) ...[
            _PulseDot(color: c),
            const SizedBox(width: 5),
          ],
          Text(
            status.label,
            style: TextStyle(
              color: c,
              fontWeight: FontWeight.w800,
              fontSize: compact ? 10 : 11,
              letterSpacing: 0.4,
            ),
          ),
        ],
      ),
    );
  }
}

class _PulseDot extends StatefulWidget {
  const _PulseDot({required this.color});
  final Color color;

  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 1100))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => FadeTransition(
        opacity: Tween(begin: 0.35, end: 1.0).animate(_c),
        child: Container(
          width: 6,
          height: 6,
          decoration: BoxDecoration(color: widget.color, shape: BoxShape.circle),
        ),
      );
}

class StrengthMeter extends StatelessWidget {
  const StrengthMeter(this.strength, {super.key, this.color = AppColors.bull, this.showLabel = false});
  final int strength;
  final Color color;
  final bool showLabel;

  static String labelFor(int s) => switch (s) { >= 4 => 'Strong', 3 => 'Good', _ => 'Moderate' };

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          for (var i = 0; i < 4; i++)
            Container(
              width: 14,
              height: 5,
              margin: const EdgeInsets.only(right: 3),
              decoration: BoxDecoration(
                color: i < strength ? color : color.withValues(alpha: 0.18),
                borderRadius: BorderRadius.circular(3),
              ),
            ),
          if (showLabel) ...[
            const SizedBox(width: 4),
            Text(labelFor(strength), style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w600)),
          ],
        ],
      );
}

class SectionHeader extends StatelessWidget {
  const SectionHeader(this.title, {super.key, this.action, this.onAction, this.subtitle});
  final String title;
  final String? subtitle;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(Gap.lg + 4, Gap.lg, Gap.md, Gap.xs),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                  if (subtitle != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(subtitle!, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                    ),
                ],
              ),
            ),
            if (action != null)
              TextButton(
                onPressed: onAction,
                child: Text(action!, style: const TextStyle(fontWeight: FontWeight.w600)),
              ),
          ],
        ),
      );
}

class StatTile extends StatelessWidget {
  const StatTile(this.label, this.value, {super.key, this.color, this.hint});
  final String label;
  final String value;
  final Color? color;
  final String? hint;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(Gap.md),
        decoration: BoxDecoration(
          color: AppColors.surface2,
          borderRadius: BorderRadius.circular(12),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
            const SizedBox(height: 4),
            FittedBox(
              fit: BoxFit.scaleDown,
              alignment: Alignment.centerLeft,
              child: Text(value, style: numStyle(17, weight: FontWeight.w700, color: color)),
            ),
            if (hint != null)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text(hint!, style: const TextStyle(color: AppColors.faint, fontSize: 10.5)),
              ),
          ],
        ),
      );
}

/// Lays tiles out in an even grid that adapts to phone width.
class StatGrid extends StatelessWidget {
  const StatGrid({super.key, required this.children, this.columns = 3});
  final List<Widget> children;
  final int columns;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
        builder: (context, c) {
          const spacing = 8.0;
          final w = (c.maxWidth - spacing * (columns - 1)) / columns;
          return Wrap(
            spacing: spacing,
            runSpacing: spacing,
            children: [for (final ch in children) SizedBox(width: w, child: ch)],
          );
        },
      );
}

class ChangeText extends StatelessWidget {
  const ChangeText(this.pct, {super.key, this.size = 13, this.pill = false});
  final double pct;
  final double size;
  final bool pill;

  @override
  Widget build(BuildContext context) {
    final c = AppColors.change(pct);
    final t = Text(fmtPct(pct, digits: 2), style: numStyle(size, color: c));
    if (!pill) return t;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(color: c.withValues(alpha: 0.14), borderRadius: BorderRadius.circular(8)),
      child: t,
    );
  }
}

class SymbolAvatar extends StatelessWidget {
  const SymbolAvatar(this.symbol, {super.key, this.size = 40});
  final String symbol;
  final double size;

  static const _palette = [
    Color(0xFF0EA5E9), Color(0xFF8B5CF6), Color(0xFFF97316), Color(0xFF14B8A6),
    Color(0xFFEC4899), Color(0xFF84CC16), Color(0xFFEAB308), Color(0xFF6366F1),
  ];

  @override
  Widget build(BuildContext context) {
    final c = _palette[symbol.codeUnits.fold<int>(0, (a, b) => a + b) % _palette.length];
    final label = symbol.length <= 4 ? symbol : symbol.substring(0, 4);
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: c.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(size * 0.3),
      ),
      child: Text(
        label,
        style: TextStyle(color: c, fontWeight: FontWeight.w800, fontSize: size * (label.length > 3 ? 0.24 : 0.3)),
      ),
    );
  }
}

class Skeleton extends StatefulWidget {
  const Skeleton({super.key, this.width, this.height = 14, this.radius = 6});
  final double? width;
  final double height;
  final double radius;

  @override
  State<Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<Skeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _c,
        builder: (_, __) => Container(
          width: widget.width,
          height: widget.height,
          decoration: BoxDecoration(
            color: Color.lerp(AppColors.surface2, AppColors.border, _c.value),
            borderRadius: BorderRadius.circular(widget.radius),
          ),
        ),
      );
}

class SkeletonCard extends StatelessWidget {
  const SkeletonCard({super.key});

  @override
  Widget build(BuildContext context) => const AppCard(
        child: Row(
          children: [
            Skeleton(width: 40, height: 40, radius: 12),
            SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Skeleton(width: 70),
                  SizedBox(height: 8),
                  Skeleton(width: 140, height: 10),
                ],
              ),
            ),
            Skeleton(width: 60, height: 22),
          ],
        ),
      );
}

class MessageView extends StatelessWidget {
  const MessageView({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.color = AppColors.muted,
  });

  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final Color color;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 40),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: 64,
              height: 64,
              decoration: BoxDecoration(color: color.withValues(alpha: 0.12), shape: BoxShape.circle),
              child: Icon(icon, color: color, size: 30),
            ),
            const SizedBox(height: Gap.lg),
            Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(message, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.4)),
            if (actionLabel != null) ...[
              const SizedBox(height: Gap.lg),
              SizedBox(
                width: 200,
                child: OutlinedButton(onPressed: onAction, child: Text(actionLabel!)),
              ),
            ],
          ],
        ),
      );
}

class InfoNote extends StatelessWidget {
  const InfoNote(this.text, {super.key, this.icon = Icons.info_outline, this.color = AppColors.muted});
  final String text;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) => Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 15, color: color),
          const SizedBox(width: 6),
          Expanded(child: Text(text, style: TextStyle(color: color, fontSize: 12, height: 1.4))),
        ],
      );
}

/// "Updated 2m ago · Delayed ~15 min" freshness line used across screens.
class FreshnessLine extends StatelessWidget {
  const FreshnessLine({super.key, required this.updated, this.refreshing = false});
  final DateTime? updated;
  final bool refreshing;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (refreshing)
            const SizedBox(width: 10, height: 10, child: CircularProgressIndicator(strokeWidth: 1.5))
          else
            Container(
              width: 7,
              height: 7,
              decoration: const BoxDecoration(color: AppColors.bull, shape: BoxShape.circle),
            ),
          const SizedBox(width: 6),
          Text(
            refreshing
                ? 'Updating…'
                : updated == null
                    ? 'Not updated yet'
                    : 'Updated ${fmtAgo(updated!)} · quotes delayed ~15 min',
            style: const TextStyle(color: AppColors.muted, fontSize: 11.5),
          ),
        ],
      );
}
