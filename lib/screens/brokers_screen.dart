import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../config.dart';
import '../core/theme.dart';
import '../models/profile.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/support.dart';

/// Partner brokers (affiliate links, clearly disclosed) and the affiliate program.
class BrokersScreen extends StatefulWidget {
  const BrokersScreen({super.key});

  @override
  State<BrokersScreen> createState() => _BrokersScreenState();
}

class _BrokersScreenState extends State<BrokersScreen> {
  late Future<List<Broker>> _f;

  @override
  void initState() {
    super.initState();
    _f = context.read<AppState>().brokers();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Brokers')),
      body: FutureBuilder<List<Broker>>(
        future: _f,
        builder: (context, snap) {
          if (snap.connectionState != ConnectionState.done) return const Center(child: CircularProgressIndicator());
          if (snap.hasError) {
            return MessageView(
              icon: Icons.cloud_off_rounded,
              title: 'Couldn’t load brokers',
              message: snap.error.toString(),
              actionLabel: 'Retry',
              onAction: () => setState(() => _f = context.read<AppState>().brokers()),
            );
          }
          final items = snap.data ?? const [];
          return ListView(
            padding: const EdgeInsets.only(bottom: 40),
            children: [
              const Padding(
                padding: EdgeInsets.fromLTRB(Gap.lg + 4, Gap.md, Gap.lg, Gap.sm),
                child: InfoNote(AppState.brokerDisclosure, icon: Icons.info_outline, color: AppColors.muted),
              ),
              if (items.isEmpty)
                const Padding(
                  padding: EdgeInsets.all(Gap.xl),
                  child: Text('No partner brokers listed yet.', style: TextStyle(color: AppColors.muted)),
                ),
              for (final b in items)
                AppCard(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(children: [
                        SymbolAvatar(b.name, size: 40),
                        const SizedBox(width: Gap.md),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(b.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                            if (b.tagline.isNotEmpty)
                              Text(b.tagline, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                          ]),
                        ),
                      ]),
                      if (b.description.isNotEmpty) ...[
                        const SizedBox(height: Gap.md),
                        Text(b.description, style: const TextStyle(fontSize: 13.5, height: 1.45)),
                      ],
                      const SizedBox(height: Gap.sm),
                      Wrap(spacing: 14, children: [
                        if (b.minDeposit.isNotEmpty) Text('Min deposit: ${b.minDeposit}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                        if (b.regions.isNotEmpty) Text('Regions: ${b.regions}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                      ]),
                      const SizedBox(height: Gap.md),
                      SizedBox(
                        width: double.infinity,
                        child: OutlinedButton.icon(
                          onPressed: () => Support.open(context, b.link),
                          icon: const Icon(Icons.open_in_new_rounded, size: 18),
                          label: Text('Open ${b.name}'),
                        ),
                      ),
                    ],
                  ),
                ),
              const AffiliateCard(),
            ],
          );
        },
      ),
    );
  }
}

/// "Earn with us": shows the user's referral code; the full affiliate dashboard is on the website.
class AffiliateCard extends StatelessWidget {
  const AffiliateCard({super.key});

  @override
  Widget build(BuildContext context) {
    final code = context.select<AppState, String>((a) => a.user?.referralCode ?? '');
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Row(children: [
            Icon(Icons.handshake_outlined, color: AppColors.primary),
            SizedBox(width: Gap.sm),
            Text('Earn as an affiliate', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          ]),
          const SizedBox(height: Gap.sm),
          const Text('Invite traders with your code. Approved affiliates earn commission on Premium plans and broker sign-ups.',
              style: TextStyle(color: AppColors.muted, fontSize: 13, height: 1.4)),
          if (code.isNotEmpty) ...[
            const SizedBox(height: Gap.md),
            SelectableText('Your code: $code', style: const TextStyle(fontWeight: FontWeight.w800, letterSpacing: 1.2)),
          ],
          const SizedBox(height: Gap.md),
          SizedBox(
            width: double.infinity,
            child: FilledButton.tonal(
              onPressed: () => Support.open(context, AppConfig.affiliateUrl),
              child: const Text('Open affiliate dashboard'),
            ),
          ),
        ],
      ),
    );
  }
}
