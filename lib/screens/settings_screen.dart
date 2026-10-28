import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../models/profile.dart';
import '../services/api_client.dart';
import '../services/market_data.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import '../widgets/risk_form.dart';
import '../widgets/support.dart';
import 'alerts_screen.dart';
import 'info_screens.dart';
import 'onboarding/onboarding_screen.dart';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  void _snack(BuildContext context, String msg) =>
      ScaffoldMessenger.of(context)..hideCurrentSnackBar()..showSnackBar(SnackBar(content: Text(msg)));

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final p = s.profile;
    final u = s.user;
    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        padding: const EdgeInsets.only(bottom: 40),
        children: [
          // ------------------------------------------------ account
          if (u != null)
            AppCard(
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 24,
                    backgroundColor: AppColors.primary.withValues(alpha: 0.2),
                    child: Text(u.displayName.isEmpty ? '?' : u.displayName[0].toUpperCase(),
                        style: const TextStyle(color: AppColors.primary, fontWeight: FontWeight.w800, fontSize: 20)),
                  ),
                  const SizedBox(width: Gap.md),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(u.displayName, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                        Text(u.email, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                        const SizedBox(height: 4),
                        Row(children: [
                          Icon(u.emailVerified ? Icons.verified_rounded : Icons.error_outline_rounded,
                              size: 14, color: u.emailVerified ? AppColors.bull : AppColors.warn),
                          const SizedBox(width: 4),
                          Text(u.emailVerified ? 'Email verified' : 'Email not verified',
                              style: TextStyle(fontSize: 12, color: u.emailVerified ? AppColors.bull : AppColors.warn)),
                          if (u.googleLinked) ...[
                            const SizedBox(width: 8),
                            const Text('· Google', style: TextStyle(fontSize: 12, color: AppColors.muted)),
                          ],
                        ]),
                      ],
                    ),
                  ),
                  if (!u.emailVerified)
                    TextButton(
                      onPressed: () async {
                        try {
                          await s.resendVerification();
                          if (context.mounted) _snack(context, 'Verification email sent to ${u.email}');
                        } on ApiException catch (e) {
                          if (context.mounted) _snack(context, e.message);
                        }
                      },
                      child: const Text('Verify'),
                    ),
                ],
              ),
            ),
          AppCard(
            onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const RiskProfileScreen())),
            child: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: BoxDecoration(gradient: AppColors.brandGradient, borderRadius: BorderRadius.circular(13)),
                  child: const Icon(Icons.shield_outlined, color: Color(0xFF042F2E)),
                ),
                const SizedBox(width: Gap.md),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Risk profile', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                      const SizedBox(height: 2),
                      Text(
                        '${fmtMoney(p.accountSize, digits: 0)} · ${p.riskPct.toStringAsFixed(2)}% risk '
                        '(${fmtMoney(p.riskAmount)}) · ${p.style == Timeframe.daily ? 'Swing' : 'Intraday'}',
                        style: const TextStyle(color: AppColors.muted, fontSize: 12.5),
                      ),
                    ],
                  ),
                ),
                const Icon(Icons.chevron_right_rounded, color: AppColors.muted),
              ],
            ),
          ),

          // ------------------------------------------------ notifications
          const _Group('Notifications'),
          _Section(children: [
            _nav(context, 'Alerts & email reports', Icons.notifications_active_outlined, const NotificationsScreen(),
                subtitle: _notifSummary(s.notif)),
            ListTile(
              leading: const Icon(Icons.send_to_mobile_outlined, color: AppColors.muted),
              title: const Text('Send a test notification'),
              subtitle: Text(s.pushAvailable ? 'Checks push delivery to this phone' : 'Push is not set up in this build',
                  style: const TextStyle(fontSize: 12)),
              onTap: !s.pushAvailable
                  ? null
                  : () async {
                      try {
                        await s.enablePush();
                        final ok = await s.sendTestPush();
                        if (context.mounted) {
                          _snack(context, ok ? 'Sent! It should arrive in a few seconds.' : 'Could not deliver. Check notification permission.');
                        }
                      } on ApiException catch (e) {
                        if (context.mounted) _snack(context, e.message);
                      }
                    },
            ),
            _nav(context, 'Alert history', Icons.history_rounded, const AlertsScreen()),
          ]),

          // ------------------------------------------------ trading
          const _Group('Trading'),
          _Section(children: [
            SwitchListTile(
              title: const Text('Short selling'),
              subtitle: const Text('Show SELL signals as trades in backtests instead of warnings', style: TextStyle(fontSize: 12)),
              value: p.allowShort,
              onChanged: (v) => s.updateProfile(p.copyWith(allowShort: v)),
            ),
            SwitchListTile(
              title: const Text('Fractional shares'),
              subtitle: const Text('Position sizes can include part of a share', style: TextStyle(fontSize: 12)),
              value: p.fractionalShares,
              onChanged: (v) => s.updateProfile(p.copyWith(fractionalShares: v)),
            ),
            ListTile(
              title: const Text('Signal timeframe'),
              subtitle: Text('${p.style.longLabel} · alerts follow this', style: const TextStyle(fontSize: 12)),
              trailing: const Icon(Icons.chevron_right_rounded),
              onTap: () => _pickTimeframe(context, s),
            ),
          ]),

          // ------------------------------------------------ data
          const _Group('Data'),
          _Section(children: [
            ListTile(
              title: const Text('Refresh now'),
              subtitle: Text(s.lastUpdated == null ? 'Not updated yet' : 'Updated ${fmtAgo(s.lastUpdated!)}',
                  style: const TextStyle(fontSize: 12)),
              trailing: s.refreshing
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.refresh_rounded),
              onTap: s.refreshing ? null : () => s.refresh(force: true),
            ),
            ListTile(
              title: const Text('Reset watchlist'),
              subtitle: const Text('Restore the default stocks', style: TextStyle(fontSize: 12)),
              trailing: const Icon(Icons.restart_alt_rounded),
              onTap: () async {
                if (await _confirm(context, 'Reset watchlist?', 'Your watchlist will be replaced with the default stocks.')) {
                  await s.resetWatchlist();
                }
              },
            ),
          ]),

          // ------------------------------------------------ support
          const _Group('Support the developer'),
          const _Section(children: [SupportCard()]),

          // ------------------------------------------------ about
          const _Group('About'),
          _Section(children: [
            _nav(context, 'How signals work', Icons.menu_book_outlined, const MethodologyScreen()),
            _nav(context, 'Risk disclaimer', Icons.gavel_rounded, const LegalScreen(kind: LegalKind.disclaimer)),
            _nav(context, 'Privacy policy', Icons.lock_outline_rounded, const LegalScreen(kind: LegalKind.privacy)),
          ]),

          // ------------------------------------------------ account actions
          const _Group('Account'),
          _Section(children: [
            if (u?.hasPassword ?? false)
              ListTile(
                leading: const Icon(Icons.password_rounded, color: AppColors.muted),
                title: const Text('Change password'),
                onTap: () => _changePassword(context, s),
              ),
            ListTile(
              leading: const Icon(Icons.logout_rounded, color: AppColors.muted),
              title: const Text('Sign out'),
              onTap: () async {
                if (!await _confirm(context, 'Sign out?', 'You will stop receiving push alerts on this phone.')) return;
                if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
                await s.signOut();
              },
            ),
            ListTile(
              leading: const Icon(Icons.delete_forever_outlined, color: AppColors.bear),
              title: const Text('Delete account', style: TextStyle(color: AppColors.bear)),
              subtitle: const Text('Permanently erase your account, settings and alerts', style: TextStyle(fontSize: 12)),
              onTap: () async {
                if (!await _confirm(context, 'Delete your account?',
                    'This permanently deletes your account and all its data from our server. This cannot be undone.')) {
                  return;
                }
                try {
                  if (context.mounted) Navigator.of(context).popUntil((r) => r.isFirst);
                  await s.deleteAccount();
                } on ApiException catch (e) {
                  if (context.mounted) _snack(context, e.message);
                }
              },
            ),
          ]),
          const SizedBox(height: Gap.xl),
          const AboutCard(),
          const SizedBox(height: Gap.md),
          const Center(
            child: Text('Free · No ads · Not financial advice', style: TextStyle(color: AppColors.faint, fontSize: 12)),
          ),
        ],
      ),
    );
  }

  static String _notifSummary(NotificationPrefs n) {
    final push = n.pushSignals || n.pushResults;
    final email = n.emailSignals || n.emailResults;
    final reports = [if (n.reportDaily) 'daily', if (n.reportWeekly) 'weekly', if (n.reportMonthly) 'monthly'];
    return [
      push ? 'Push on' : 'Push off',
      email ? 'Email on' : 'Email off',
      reports.isEmpty ? 'no reports' : '${reports.join('/')} reports',
    ].join(' · ');
  }

  Widget _nav(BuildContext context, String title, IconData icon, Widget page, {String? subtitle}) => ListTile(
        leading: Icon(icon, color: AppColors.muted),
        title: Text(title),
        subtitle: subtitle == null ? null : Text(subtitle, style: const TextStyle(fontSize: 12)),
        trailing: const Icon(Icons.chevron_right_rounded),
        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => page)),
      );

  Future<void> _pickTimeframe(BuildContext context, AppState s) async {
    final tf = await showModalBottomSheet<Timeframe>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (final t in Timeframe.values)
              ListTile(
                leading: Icon(t == s.profile.style ? Icons.radio_button_checked : Icons.radio_button_off,
                    color: t == s.profile.style ? AppColors.primary : AppColors.muted),
                title: Text(t.longLabel),
                subtitle: Text(t == Timeframe.daily
                    ? 'Fewer, higher-quality signals. Check once a day.'
                    : 'More signals; best if you can act during market hours.'),
                onTap: () => Navigator.pop(ctx, t),
              ),
            const SizedBox(height: Gap.md),
          ],
        ),
      ),
    );
    if (tf != null) await s.updateProfile(s.profile.copyWith(style: tf));
  }

  Future<void> _changePassword(BuildContext context, AppState s) async {
    final cur = TextEditingController();
    final next = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Change password'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(controller: cur, obscureText: true, decoration: const InputDecoration(hintText: 'Current password')),
            const SizedBox(height: Gap.md),
            TextField(controller: next, obscureText: true, decoration: const InputDecoration(hintText: 'New password (8+, letters & numbers)')),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Save')),
        ],
      ),
    );
    if (ok == true) {
      try {
        await s.auth.changePassword(cur.text, next.text);
        if (context.mounted) _snack(context, 'Password changed');
      } on ApiException catch (e) {
        if (context.mounted) _snack(context, e.message);
      }
    }
    cur.dispose();
    next.dispose();
  }
}

Future<bool> _confirm(BuildContext context, String title, String body) async {
  final ok = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      title: Text(title),
      content: Text(body),
      actions: [
        TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
        TextButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Confirm')),
      ],
    ),
  );
  return ok == true;
}

class _Group extends StatelessWidget {
  const _Group(this.title);
  final String title;
  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.xl, Gap.xl, Gap.sm),
        child: Text(title.toUpperCase(),
            style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w700, letterSpacing: 0.8)),
      );
}

class _Section extends StatelessWidget {
  const _Section({required this.children});
  final List<Widget> children;

  @override
  Widget build(BuildContext context) => AppCard(
        padding: EdgeInsets.zero,
        margin: const EdgeInsets.symmetric(horizontal: Gap.lg),
        child: Column(
          children: [
            for (var i = 0; i < children.length; i++) ...[
              if (i > 0) const Divider(indent: Gap.lg),
              children[i],
            ],
          ],
        ),
      );
}

/// Push, email and report preferences (synced to the server).
class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    return Scaffold(
      appBar: AppBar(title: const Text('Alerts & reports')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(Gap.xl, 0, Gap.xl, Gap.xl),
        children: [
          if (s.user != null && !s.user!.emailVerified)
            const Padding(
              padding: EdgeInsets.only(top: Gap.md),
              child: InfoNote('Emails are sent only after you verify your email address.',
                  icon: Icons.mark_email_unread_outlined, color: AppColors.warn),
            ),
          NotificationPrefsForm(
            prefs: s.notif,
            onChanged: (n) async {
              try {
                await s.updateNotif(n);
              } on ApiException catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                }
              }
            },
          ),
          const SizedBox(height: Gap.lg),
          const InfoNote('Alerts are sent by our server within about an hour of each signal, even when the app is closed. '
              'Reports summarise every signal for the period, wins and losses.'),
        ],
      ),
    );
  }
}

class RiskProfileScreen extends StatefulWidget {
  const RiskProfileScreen({super.key});

  @override
  State<RiskProfileScreen> createState() => _RiskProfileScreenState();
}

class _RiskProfileScreenState extends State<RiskProfileScreen> {
  late RiskProfile _draft;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _draft = context.read<AppState>().profile;
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Risk profile')),
        body: ListView(
          padding: const EdgeInsets.all(Gap.xl),
          children: [RiskProfileForm(initial: _draft, onChanged: (p) => _draft = p)],
        ),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(Gap.lg, Gap.sm, Gap.lg, Gap.md),
            child: FilledButton(
              onPressed: _saving
                  ? null
                  : () async {
                      setState(() => _saving = true);
                      try {
                        await context.read<AppState>().updateProfile(_draft);
                        if (context.mounted) Navigator.pop(context);
                      } on ApiException catch (e) {
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
                        }
                      } finally {
                        if (mounted) setState(() => _saving = false);
                      }
                    },
              child: const Text('Save'),
            ),
          ),
        ),
      );
}
