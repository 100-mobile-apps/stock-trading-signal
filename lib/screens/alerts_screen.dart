import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/profile.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import 'results_screen.dart';
import 'tracked_signal_screen.dart';

/// Every alert the server sent this account, including where it was delivered.
class AlertsScreen extends StatefulWidget {
  const AlertsScreen({super.key});

  @override
  State<AlertsScreen> createState() => _AlertsScreenState();
}

class _AlertsScreenState extends State<AlertsScreen> {
  late final Set<int> _unreadOnOpen;

  @override
  void initState() {
    super.initState();
    final s = context.read<AppState>();
    _unreadOnOpen = s.inbox.where((a) => !a.read).map((a) => a.id).toSet();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<AppState>().markAlertsRead();
    });
  }

  IconData _icon(String k) => switch (k) {
        'new_buy' => Icons.trending_up_rounded,
        'bearish' => Icons.warning_amber_rounded,
        'target1' => Icons.outlined_flag_rounded,
        'target' => Icons.emoji_events_outlined,
        'stop' => Icons.block_rounded,
        'time' => Icons.timer_outlined,
        'report' => Icons.insights_rounded,
        _ => Icons.notifications_none_rounded,
      };

  void _open(AppNotification a) {
    final Widget page = a.signalId != null ? TrackedSignalScreen(signalId: a.signalId) : const ResultsScreen();
    Navigator.push(context, MaterialPageRoute(builder: (_) => page));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final items = s.inbox;
    return Scaffold(
      appBar: AppBar(title: const Text('Alerts')),
      body: RefreshIndicator(
        onRefresh: s.refreshServer,
        child: ListView(
          padding: const EdgeInsets.only(bottom: Gap.xl),
          children: [
            if (!s.notif.pushSignals && !s.notif.pushResults)
              const AppCard(
                child: InfoNote('Push notifications are off. Turn them on in Settings → Notifications.',
                    icon: Icons.notifications_off_outlined, color: AppColors.primary),
              ),
            if (items.isEmpty)
              const MessageView(
                icon: Icons.notifications_none_rounded,
                title: 'No alerts yet',
                message: 'New signals, target and stop hits and your reports will appear here, '
                    'and on your phone and email according to your settings.',
              )
            else
              for (final a in items)
                AppCard(
                  onTap: () => _open(a),
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        width: 38,
                        height: 38,
                        decoration: BoxDecoration(
                          color: AppColors.alert(a.kind).withValues(alpha: 0.14),
                          borderRadius: BorderRadius.circular(11),
                        ),
                        child: Icon(_icon(a.kind), color: AppColors.alert(a.kind), size: 20),
                      ),
                      const SizedBox(width: Gap.md),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Expanded(child: Text(a.title, style: const TextStyle(fontWeight: FontWeight.w700))),
                                if (_unreadOnOpen.contains(a.id))
                                  Container(
                                    width: 8,
                                    height: 8,
                                    decoration: const BoxDecoration(color: AppColors.primary, shape: BoxShape.circle),
                                  ),
                              ],
                            ),
                            const SizedBox(height: 3),
                            Text(a.body, style: const TextStyle(color: AppColors.muted, fontSize: 13, height: 1.35)),
                            const SizedBox(height: 6),
                            Row(
                              children: [
                                Text(fmtAgo(a.time.toLocal()), style: const TextStyle(color: AppColors.faint, fontSize: 11.5)),
                                if (a.pushed) ...[
                                  const SizedBox(width: 8),
                                  const Icon(Icons.phone_iphone_rounded, size: 12, color: AppColors.faint),
                                ],
                                if (a.emailed) ...[
                                  const SizedBox(width: 6),
                                  const Icon(Icons.mail_outline_rounded, size: 12, color: AppColors.faint),
                                ],
                              ],
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
          ],
        ),
      ),
    );
  }
}
