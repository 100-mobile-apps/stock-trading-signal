import 'package:flutter/material.dart';
import 'package:in_app_review/in_app_review.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

import '../config.dart';
import '../core/theme.dart';

/// Rate the app, chat with the developer, buy a coffee, hire the developer.
class Support {
  static Future<void> open(BuildContext context, String url) async {
    final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not open the link.')));
    }
  }

  /// Native Google Play / App Store rating dialog, falling back to the store page.
  static Future<void> rate(BuildContext context) async {
    final review = InAppReview.instance;
    try {
      if (await review.isAvailable()) {
        await review.requestReview();
        await _markRated();
        return;
      }
    } catch (_) {}
    if (context.mounted) await open(context, AppConfig.playStoreUrl);
    await _markRated();
  }

  static Future<void> chat(BuildContext context) => open(context, AppConfig.chatUrl);
  static Future<void> coffee(BuildContext context) => open(context, AppConfig.coffeeUrl);
  static Future<void> hire(BuildContext context) => open(context, AppConfig.servicesUrl);
  static Future<void> moreApps(BuildContext context) => open(context, AppConfig.moreAppsUrl);

  // ------------------------------------------------------------ smart popup
  static const _kOpens = 'support_opens';
  static const _kLast = 'support_last_shown';
  static const _kRated = 'support_rated';

  static Future<void> _markRated() async => (await SharedPreferences.getInstance()).setBool(_kRated, true);

  /// Counts app opens and shows the popup on the 3rd open, then at most every
  /// 5 days, never twice in one session, and never while onboarding.
  static Future<void> maybeShowPopup(BuildContext context) async {
    final p = await SharedPreferences.getInstance();
    final opens = (p.getInt(_kOpens) ?? 0) + 1;
    await p.setInt(_kOpens, opens);
    final last = p.getInt(_kLast);
    final now = DateTime.now().millisecondsSinceEpoch;
    final due = opens >= 3 && (last == null || now - last > const Duration(days: 5).inMilliseconds);
    if (!due || !context.mounted) return;
    await p.setInt(_kLast, now);
    await Future.delayed(const Duration(seconds: 2));
    if (!context.mounted) return;
    await showDialog<void>(
      context: context,
      builder: (_) => SupportDialog(rated: p.getBool(_kRated) ?? false),
    );
  }
}

class SupportDialog extends StatelessWidget {
  const SupportDialog({super.key, this.rated = false});
  final bool rated;

  @override
  Widget build(BuildContext context) {
    Widget action(IconData icon, Color color, String title, String sub, Future<void> Function(BuildContext) onTap) =>
        Material(
          color: AppColors.surface2,
          borderRadius: BorderRadius.circular(14),
          child: InkWell(
            borderRadius: BorderRadius.circular(14),
            onTap: () {
              Navigator.pop(context);
              onTap(context);
            },
            child: Padding(
              padding: const EdgeInsets.all(Gap.md),
              child: Row(
                children: [
                  Container(
                    width: 40,
                    height: 40,
                    decoration: BoxDecoration(color: color.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(12)),
                    child: Icon(icon, color: color, size: 22),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text(sub, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                    ]),
                  ),
                  const Icon(Icons.chevron_right_rounded, color: AppColors.muted),
                ],
              ),
            ),
          ),
        );

    return Dialog(
      insetPadding: const EdgeInsets.symmetric(horizontal: 20, vertical: 24),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.lg, Gap.lg, Gap.md),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Text('❤️', style: TextStyle(fontSize: 26)),
                const Spacer(),
                IconButton(
                  onPressed: () => Navigator.pop(context),
                  icon: const Icon(Icons.close_rounded, color: AppColors.muted),
                ),
              ],
            ),
            const Text('Enjoying Stock Trading Signals?', style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(
              'Stock Trading Signals is free, built by ${AppConfig.developerShort}. A rating or a coffee keeps it running.',
              style: TextStyle(color: AppColors.muted, height: 1.4),
            ),
            const SizedBox(height: Gap.lg),
            if (!rated) ...[
              action(Icons.star_rounded, AppColors.warn, 'Rate 5 stars', 'Takes 5 seconds on Google Play', Support.rate),
              const SizedBox(height: Gap.sm),
            ],
            action(Icons.local_cafe_rounded, const Color(0xFFF59E0B), 'Buy me a coffee', 'Support free updates', Support.coffee),
            const SizedBox(height: Gap.sm),
            action(Icons.chat_rounded, AppColors.bull, 'Chat with the developer', 'Feedback, ideas or help', Support.chat),
            const SizedBox(height: Gap.sm),
            action(Icons.rocket_launch_rounded, AppColors.info, 'Need an app or website?',
                'Hire ${AppConfig.developerShort} to build yours', Support.hire),
            const SizedBox(height: Gap.sm),
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('Maybe later')),
          ],
        ),
      ),
    );
  }
}

/// Floating click-to-chat button.
class ChatFab extends StatelessWidget {
  const ChatFab({super.key});

  @override
  Widget build(BuildContext context) => FloatingActionButton.small(
        heroTag: 'chat-dev',
        tooltip: 'Chat with the developer',
        backgroundColor: const Color(0xFF25D366),
        foregroundColor: Colors.white,
        onPressed: () => Support.chat(context),
        child: const Icon(Icons.chat_rounded),
      );
}

/// Settings card listing all support actions.
class SupportCard extends StatelessWidget {
  const SupportCard({super.key});

  @override
  Widget build(BuildContext context) {
    Widget tile(IconData i, Color c, String t, String s, Future<void> Function(BuildContext) f) => ListTile(
          leading: Icon(i, color: c),
          title: Text(t),
          subtitle: Text(s, style: const TextStyle(fontSize: 12)),
          trailing: const Icon(Icons.open_in_new_rounded, size: 18, color: AppColors.muted),
          onTap: () => f(context),
        );
    return Column(children: [
      tile(Icons.star_rounded, AppColors.warn, 'Rate Stock Trading Signals', 'Help other traders find the app', Support.rate),
      const Divider(indent: Gap.lg),
      tile(Icons.local_cafe_rounded, const Color(0xFFF59E0B), 'Buy the developer a coffee', 'Keeps the app free', Support.coffee),
      const Divider(indent: Gap.lg),
      tile(Icons.chat_rounded, const Color(0xFF25D366), 'Chat with the developer', 'Questions, feedback, bug reports', Support.chat),
      const Divider(indent: Gap.lg),
      tile(Icons.rocket_launch_rounded, AppColors.info, 'Hire ${AppConfig.developerShort}', 'Custom mobile apps, websites & trading tools', Support.hire),
      if (AppConfig.moreAppsUrl.isNotEmpty) ...[
        const Divider(indent: Gap.lg),
        tile(Icons.apps_rounded, AppColors.primary, 'More apps', 'Other apps by ${AppConfig.developerShort}', Support.moreApps),
      ],
    ]);
  }
}
