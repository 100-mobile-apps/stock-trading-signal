import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config.dart';
import '../core/format.dart';
import '../core/theme.dart';
import '../models/profile.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/support.dart';

/// Opens the paywall.
Future<void> openPremium(BuildContext context) =>
    Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const PremiumScreen()));

/// Shown instead of price levels on a live signal for free users.
class PremiumLockCard extends StatelessWidget {
  const PremiumLockCard({super.key, this.compact = false});
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final body = Row(
      children: [
        const Icon(Icons.lock_rounded, color: AppColors.warn, size: 20),
        const SizedBox(width: Gap.sm),
        const Expanded(
          child: Text('Entry, stop-loss and targets are Premium. Results stay free.',
              style: TextStyle(fontSize: 13, height: 1.35)),
        ),
        TextButton(onPressed: () => openPremium(context), child: const Text('Unlock')),
      ],
    );
    if (compact) return body;
    return AppCard(borderColor: AppColors.warn.withValues(alpha: 0.45), child: body);
  }
}

class PremiumScreen extends StatefulWidget {
  const PremiumScreen({super.key});

  @override
  State<PremiumScreen> createState() => _PremiumScreenState();
}

class _PremiumScreenState extends State<PremiumScreen> {
  bool _loading = true;
  String? _error;
  String _selected = 'yearly';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Future<void> _load() async {
    try {
      await context.read<AppState>().loadPaywall();
    } catch (e) {
      _error = e.toString();
    }
    if (mounted) setState(() => _loading = false);
  }

  @override
  Widget build(BuildContext context) {
    final app = context.watch<AppState>();
    final err = app.takePurchaseError();
    if (err != null) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(err)));
      });
    }
    final p = app.premium;
    return Scaffold(
      appBar: AppBar(title: const Text('Premium')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.only(bottom: 40),
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.md, Gap.xl, Gap.md),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Icon(Icons.diamond_outlined, color: AppColors.warn, size: 40),
                      const SizedBox(height: Gap.md),
                      Text(p.active && p.enabled ? 'You have Premium' : 'Trade every signal with exact levels',
                          style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800)),
                      const SizedBox(height: Gap.sm),
                      Text(
                        p.active && p.enabled
                            ? 'Active until ${p.until != null ? fmtDate(p.until!.toLocal()) : '—'}'
                                '${p.autoRenewing ? ' · renews automatically' : ''}.'
                            : 'Signal alerts and the full win/loss record stay free. Premium shows the entry, stop-loss and targets while a trade is live.',
                        style: const TextStyle(color: AppColors.muted, height: 1.45),
                      ),
                    ],
                  ),
                ),
                AppCard(
                  child: Column(
                    children: [
                      for (final f in app.premiumFeatures)
                        Padding(
                          padding: const EdgeInsets.symmetric(vertical: 5),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            const Icon(Icons.check_circle_rounded, color: AppColors.bull, size: 18),
                            const SizedBox(width: Gap.sm),
                            Expanded(child: Text(f, style: const TextStyle(fontSize: 14))),
                          ]),
                        ),
                    ],
                  ),
                ),
                if (_error != null)
                  Padding(padding: const EdgeInsets.all(Gap.lg), child: InfoNote(_error!, color: AppColors.bear)),
                if (!(p.active && p.enabled)) ...[
                  const SectionHeader('Choose a plan'),
                  for (final plan in app.plans) _planTile(app, plan),
                  if (!app.billing.available)
                    const Padding(
                      padding: EdgeInsets.fromLTRB(Gap.xl, Gap.sm, Gap.xl, 0),
                      child: InfoNote('Google Play billing is not available on this device.', color: AppColors.warn),
                    ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, 0),
                    child: FilledButton(
                      onPressed: app.purchaseBusy || app.plans.isEmpty
                          ? null
                          : () => app.buyPremium(app.plans.firstWhere((x) => x.code == _selected, orElse: () => app.plans.first)),
                      child: app.purchaseBusy
                          ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5))
                          : const Text('Continue with Google Play'),
                    ),
                  ),
                ],
                Padding(
                  padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.lg, 0),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      TextButton(onPressed: app.restorePurchases, child: const Text('Restore purchases')),
                      TextButton(onPressed: () => Support.open(context, AppConfig.termsUrl), child: const Text('Terms')),
                      TextButton(
                        onPressed: () => Support.open(context, 'https://play.google.com/store/account/subscriptions'),
                        child: const Text('Manage'),
                      ),
                    ],
                  ),
                ),
                const Padding(
                  padding: EdgeInsets.fromLTRB(Gap.xl, Gap.sm, Gap.xl, 0),
                  child: Text(
                    'Subscriptions renew automatically through Google Play until cancelled. Cancel anytime in Google Play → Subscriptions. '
                    'Premium is linked to your account and also works on the website. Signals are educational and not financial advice.',
                    style: TextStyle(color: AppColors.faint, fontSize: 11.5, height: 1.45),
                    textAlign: TextAlign.center,
                  ),
                ),
              ],
            ),
    );
  }

  Widget _planTile(AppState app, PremiumPlan plan) {
    final product = app.billing.products[plan.googleProductId];
    final price = product?.price ?? '\$${plan.priceUsd.toStringAsFixed(2)}';
    final on = _selected == plan.code;
    return AppCard(
      onTap: () => setState(() => _selected = plan.code),
      borderColor: on ? AppColors.primary : null,
      child: Row(
        children: [
          Icon(on ? Icons.radio_button_checked : Icons.radio_button_off, color: on ? AppColors.primary : AppColors.muted),
          const SizedBox(width: Gap.md),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(plan.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              Text(plan.months == 1 ? 'Billed monthly' : 'Billed every ${plan.months} months',
                  style: const TextStyle(color: AppColors.muted, fontSize: 12)),
            ]),
          ),
          if (plan.badge.isNotEmpty)
            Container(
              margin: const EdgeInsets.only(right: Gap.sm),
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
              decoration: BoxDecoration(color: AppColors.warn.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(99)),
              child: Text(plan.badge, style: const TextStyle(color: AppColors.warn, fontSize: 11, fontWeight: FontWeight.w700)),
            ),
          Text(price, style: numStyle(16, weight: FontWeight.w800)),
        ],
      ),
    );
  }
}
