import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/profile.dart';
import '../services/market_data.dart';
import 'common.dart';

/// Shared by onboarding and Settings → Risk profile.
class RiskProfileForm extends StatefulWidget {
  const RiskProfileForm({super.key, required this.initial, required this.onChanged});
  final RiskProfile initial;
  final ValueChanged<RiskProfile> onChanged;

  @override
  State<RiskProfileForm> createState() => _RiskProfileFormState();
}

class _RiskProfileFormState extends State<RiskProfileForm> {
  late RiskProfile _p = widget.initial;
  late final TextEditingController _account =
      TextEditingController(text: widget.initial.accountSize.toStringAsFixed(0));

  @override
  void dispose() {
    _account.dispose();
    super.dispose();
  }

  void _set(RiskProfile p) {
    setState(() => _p = p);
    widget.onChanged(p);
  }

  @override
  Widget build(BuildContext context) {
    final riskColor = _p.riskPct <= 1
        ? AppColors.bull
        : _p.riskPct <= 2
            ? AppColors.warn
            : AppColors.bear;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const _Label('Account size (USD)'),
        TextField(
          controller: _account,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
          style: numStyle(18, weight: FontWeight.w700),
          decoration: const InputDecoration(prefixText: '\$ ', hintText: '1000'),
          onChanged: (v) {
            final n = double.tryParse(v);
            if (n != null && n > 0) _set(_p.copyWith(accountSize: n));
          },
        ),
        const SizedBox(height: 6),
        const Text('Only the money you use for trading. Used to size positions; never leaves your phone.',
            style: TextStyle(color: AppColors.faint, fontSize: 12)),
        const SizedBox(height: Gap.xl),
        Row(
          children: [
            const Expanded(child: _Label('Risk per trade')),
            Text('${_p.riskPct.toStringAsFixed(2)}%', style: numStyle(18, weight: FontWeight.w800, color: riskColor)),
          ],
        ),
        Slider(
          value: _p.riskPct,
          min: 0.25,
          max: 3,
          divisions: 11,
          activeColor: riskColor,
          thumbColor: riskColor,
          onChanged: (v) => _set(_p.copyWith(riskPct: v)),
        ),
        InfoNote(
          'You would lose about ${fmtMoney(_p.riskAmount)} if a trade hits its stop. '
          '${_p.riskPct <= 1 ? 'Professionals typically risk 0.5–1%.' : _p.riskPct <= 2 ? 'Moderate. Losing streaks will hurt more.' : 'Aggressive. A normal losing streak could cut your account hard.'}',
          color: riskColor,
          icon: Icons.shield_outlined,
        ),
        const SizedBox(height: Gap.xl),
        const _Label('Trading style'),
        Row(
          children: [
            Expanded(
              child: _Choice(
                selected: _p.style == Timeframe.daily,
                icon: Icons.calendar_month_outlined,
                title: 'Swing',
                subtitle: 'Daily charts\nHold days–weeks',
                onTap: () => _set(_p.copyWith(style: Timeframe.daily)),
              ),
            ),
            const SizedBox(width: Gap.md),
            Expanded(
              child: _Choice(
                selected: _p.style == Timeframe.hourly,
                icon: Icons.schedule,
                title: 'Intraday',
                subtitle: 'Hourly charts\nHold hours–days',
                onTap: () => _set(_p.copyWith(style: Timeframe.hourly)),
              ),
            ),
          ],
        ),
        const SizedBox(height: Gap.lg),
        _SwitchRow(
          title: 'Fractional shares',
          subtitle: 'My broker lets me buy part of a share',
          value: _p.fractionalShares,
          onChanged: (v) => _set(_p.copyWith(fractionalShares: v)),
        ),
        _SwitchRow(
          title: 'Short selling',
          subtitle: 'Also trade SELL signals (most beginners should leave this off)',
          value: _p.allowShort,
          onChanged: (v) => _set(_p.copyWith(allowShort: v)),
        ),
      ],
    );
  }
}

class _Label extends StatelessWidget {
  const _Label(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: Gap.sm),
        child: Text(text, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
      );
}

class _Choice extends StatelessWidget {
  const _Choice({
    required this.selected,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });
  final bool selected;
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => AnimatedContainer(
        duration: const Duration(milliseconds: 180),
        decoration: BoxDecoration(
          color: selected ? AppColors.primary.withValues(alpha: 0.12) : AppColors.surface2,
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: selected ? AppColors.primary : AppColors.border, width: selected ? 1.5 : 1),
        ),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(Gap.md),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Icon(icon, size: 20, color: selected ? AppColors.primary : AppColors.muted),
                    const Spacer(),
                    if (selected) const Icon(Icons.check_circle, size: 18, color: AppColors.primary),
                  ],
                ),
                const SizedBox(height: Gap.sm),
                Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: 2),
                Text(subtitle, style: const TextStyle(color: AppColors.muted, fontSize: 12, height: 1.35)),
              ],
            ),
          ),
        ),
      );
}

class _SwitchRow extends StatelessWidget {
  const _SwitchRow({required this.title, required this.subtitle, required this.value, required this.onChanged});
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(subtitle, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                ],
              ),
            ),
            Switch(value: value, onChanged: onChanged),
          ],
        ),
      );
}
