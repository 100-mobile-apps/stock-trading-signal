import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/theme.dart';
import '../../models/profile.dart';
import '../../services/api_client.dart';
import '../../state/app_state.dart';
import '../../widgets/common.dart';
import '../../widgets/risk_form.dart';
import '../info_screens.dart';

class _Slide {
  final IconData icon;
  final Color color;
  final String title;
  final String body;
  final List<String> points;
  const _Slide(this.icon, this.color, this.title, this.body, this.points);
}

const _slides = [
  _Slide(
    Icons.insights_rounded,
    AppColors.primary,
    'Clear, rule-based stock signals',
    'Stock Trading Signals scans the market every hour and flags setups where trend and momentum line up.',
    ['Exact entry, stop-loss and targets', 'The reasons behind every signal', 'Daily (swing) or hourly (intraday)'],
  ),
  _Slide(
    Icons.notifications_active_outlined,
    AppColors.info,
    'Alerts the moment it happens',
    'Get a push notification and an email when a new signal appears, and again when it hits its target or stop.',
    ['Push + email alerts', 'Daily, weekly and monthly reports', 'Position size for your account'],
  ),
  _Slide(
    Icons.verified_outlined,
    AppColors.bull,
    'A verified live track record',
    'Every signal is recorded the moment it is issued and closed automatically. Nothing is deleted, and every loss is shown.',
    ['Win rate, profit and drawdown', 'Results by day, week and month', 'Public record anyone can check'],
  ),
];

/// First-launch intro slides (before sign-in).
class IntroScreen extends StatefulWidget {
  const IntroScreen({super.key, required this.onDone});
  final VoidCallback onDone;

  @override
  State<IntroScreen> createState() => _IntroScreenState();
}

class _IntroScreenState extends State<IntroScreen> {
  final _pages = PageController();
  int _page = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final last = _page == _slides.length - 1;
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.md, Gap.md, 0),
              child: Row(
                children: [
                  const BrandLogo(size: 28),
                  const Spacer(),
                  if (!last) TextButton(onPressed: widget.onDone, child: const Text('Skip')),
                ],
              ),
            ),
            Expanded(
              child: PageView(
                controller: _pages,
                onPageChanged: (i) => setState(() => _page = i),
                children: [for (final s in _slides) _SlideView(s)],
              ),
            ),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: List.generate(
                _slides.length,
                (i) => AnimatedContainer(
                  duration: const Duration(milliseconds: 250),
                  width: i == _page ? 22 : 7,
                  height: 7,
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  decoration: BoxDecoration(
                    color: i == _page ? AppColors.primary : AppColors.surface2,
                    borderRadius: BorderRadius.circular(4),
                  ),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.lg, Gap.xl, Gap.lg),
              child: FilledButton(
                onPressed: last
                    ? widget.onDone
                    : () => _pages.nextPage(duration: const Duration(milliseconds: 320), curve: Curves.easeOutCubic),
                child: Text(last ? 'Get started' : 'Continue'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// After the first sign-in: risk profile → alerts → risk acknowledgement.
class SetupScreen extends StatefulWidget {
  const SetupScreen({super.key});

  @override
  State<SetupScreen> createState() => _SetupScreenState();
}

class _SetupScreenState extends State<SetupScreen> {
  final _pages = PageController();
  int _page = 0;
  late RiskProfile _profile;
  late NotificationPrefs _notif;
  final _ack = [false, false, false];
  bool _push = true;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final s = context.read<AppState>();
    _profile = s.profile;
    _notif = s.notif;
  }

  static const _steps = 3;
  bool get _canContinue => _page < _steps - 1 || _ack.every((a) => a);

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  Future<void> _next() async {
    FocusScope.of(context).unfocus();
    if (_page < _steps - 1) {
      await _pages.nextPage(duration: const Duration(milliseconds: 320), curve: Curves.easeOutCubic);
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await context.read<AppState>().completeSetup(_profile, _notif, push: _push);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final name = context.watch<AppState>().user?.displayName ?? '';
    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.sm, Gap.sm, Gap.xl, 0),
              child: Row(
                children: [
                  if (_page > 0)
                    IconButton(
                      icon: const Icon(Icons.arrow_back_rounded),
                      onPressed: () => _pages.previousPage(duration: const Duration(milliseconds: 280), curve: Curves.easeOut),
                    )
                  else
                    const SizedBox(width: 48),
                  Expanded(
                    child: Row(
                      children: List.generate(
                        _steps,
                        (i) => Expanded(
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 250),
                            height: 4,
                            margin: const EdgeInsets.symmetric(horizontal: 2),
                            decoration: BoxDecoration(
                              color: i <= _page ? AppColors.primary : AppColors.surface2,
                              borderRadius: BorderRadius.circular(2),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: PageView(
                controller: _pages,
                physics: const NeverScrollableScrollPhysics(),
                onPageChanged: (i) => setState(() => _page = i),
                children: [_riskStep(name), _alertsStep(), _consentStep()],
              ),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
                child: InfoNote(_error!, icon: Icons.error_outline, color: AppColors.bear),
              ),
            Padding(
              padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.sm, Gap.xl, Gap.lg),
              child: FilledButton(
                onPressed: _canContinue && !_saving ? _next : null,
                child: _saving
                    ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                    : Text(_page == _steps - 1 ? 'Start using Stock Trading Signals' : 'Continue'),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _riskStep(String name) => ListView(
        padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.lg, Gap.xl, Gap.xl),
        children: [
          Text(name.isEmpty ? 'Your risk profile' : 'Welcome, $name',
              style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          const Text('Used to size every position so a losing trade stays small. You can change it anytime.',
              style: TextStyle(color: AppColors.muted, height: 1.4)),
          const SizedBox(height: Gap.xl),
          RiskProfileForm(initial: _profile, onChanged: (p) => _profile = p),
        ],
      );

  Widget _alertsStep() => ListView(
        padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.lg, Gap.xl, Gap.xl),
        children: [
          const Text('Alerts & reports', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          const Text('Choose how you want to hear about signals and their results.',
              style: TextStyle(color: AppColors.muted, height: 1.4)),
          const SizedBox(height: Gap.lg),
          NotificationPrefsForm(
            prefs: _notif,
            onChanged: (n) => setState(() => _notif = n),
            pushEnabled: _push,
            onPushEnabled: (v) => setState(() => _push = v),
          ),
        ],
      );

  Widget _consentStep() {
    const items = [
      'I understand no signal is guaranteed and I can lose money on any trade.',
      'I will use a stop-loss and only trade money I can afford to lose.',
      'Stock Trading Signals is an educational tool, not financial advice, and I make my own decisions.',
    ];
    return ListView(
      padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.lg, Gap.xl, Gap.xl),
      children: [
        const Text('Before you start', style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        const Text('Please confirm the following.', style: TextStyle(color: AppColors.muted)),
        const SizedBox(height: Gap.lg),
        for (var i = 0; i < items.length; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: Gap.sm),
            child: AppCard(
              margin: EdgeInsets.zero,
              padding: const EdgeInsets.symmetric(horizontal: Gap.sm, vertical: 4),
              borderColor: _ack[i] ? AppColors.primary : null,
              onTap: () => setState(() => _ack[i] = !_ack[i]),
              child: Row(
                children: [
                  Checkbox(
                    value: _ack[i],
                    onChanged: (v) => setState(() => _ack[i] = v ?? false),
                    activeColor: AppColors.primary,
                  ),
                  Expanded(child: Text(items[i], style: const TextStyle(height: 1.4))),
                ],
              ),
            ),
          ),
        Center(
          child: TextButton(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const LegalScreen(kind: LegalKind.disclaimer)),
            ),
            child: const Text('Read the full risk disclaimer'),
          ),
        ),
      ],
    );
  }
}

/// Push / email / report toggles, shared by setup and Settings.
class NotificationPrefsForm extends StatelessWidget {
  const NotificationPrefsForm({
    super.key,
    required this.prefs,
    required this.onChanged,
    this.pushEnabled,
    this.onPushEnabled,
  });

  final NotificationPrefs prefs;
  final ValueChanged<NotificationPrefs> onChanged;
  final bool? pushEnabled;
  final ValueChanged<bool>? onPushEnabled;

  @override
  Widget build(BuildContext context) {
    Widget sw(String t, String s, bool v, ValueChanged<bool> f) => SwitchListTile(
          contentPadding: EdgeInsets.zero,
          title: Text(t, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Text(s, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          value: v,
          onChanged: f,
        );
    Widget head(String t, IconData i) => Padding(
          padding: const EdgeInsets.only(top: Gap.md, bottom: 2),
          child: Row(children: [
            Icon(i, size: 18, color: AppColors.primary),
            const SizedBox(width: 8),
            Text(t, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
          ]),
        );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        head('Push notifications', Icons.notifications_active_outlined),
        if (pushEnabled != null && onPushEnabled != null)
          sw('Allow notifications on this phone', 'You can change this later in Settings', pushEnabled!, onPushEnabled!),
        sw('New signals', 'The moment a BUY or bearish warning appears', prefs.pushSignals,
            (v) => onChanged(prefs.copyWith(pushSignals: v))),
        sw('Results', 'Target 1, target hit, stop-loss hit or time exit', prefs.pushResults,
            (v) => onChanged(prefs.copyWith(pushResults: v))),
        head('Email', Icons.mail_outline_rounded),
        sw('New signals', 'With entry, stop, targets and reasons', prefs.emailSignals,
            (v) => onChanged(prefs.copyWith(emailSignals: v))),
        sw('Results', 'Success or failure of each signal', prefs.emailResults,
            (v) => onChanged(prefs.copyWith(emailResults: v))),
        head('Performance reports (email)', Icons.insights_outlined),
        sw('Daily', 'After the US market close, Mon–Fri', prefs.reportDaily,
            (v) => onChanged(prefs.copyWith(reportDaily: v))),
        sw('Weekly', 'Every Saturday', prefs.reportWeekly, (v) => onChanged(prefs.copyWith(reportWeekly: v))),
        sw('Monthly', 'On the 1st of each month', prefs.reportMonthly,
            (v) => onChanged(prefs.copyWith(reportMonthly: v))),
        head('Coverage', Icons.radar_rounded),
        sw('All tracked stocks', 'Get alerts for every stock the scanner follows, not only your watchlist',
            prefs.allSignals, (v) => onChanged(prefs.copyWith(allSignals: v))),
      ],
    );
  }
}

class _SlideView extends StatelessWidget {
  const _SlideView(this.s);
  final _Slide s;

  @override
  Widget build(BuildContext context) => SingleChildScrollView(
        padding: const EdgeInsets.symmetric(horizontal: Gap.xl),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const SizedBox(height: Gap.xl),
            Container(
              width: 96,
              height: 96,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [s.color.withValues(alpha: 0.35), s.color.withValues(alpha: 0.08)],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
                borderRadius: BorderRadius.circular(28),
                border: Border.all(color: s.color.withValues(alpha: 0.4)),
              ),
              child: Icon(s.icon, size: 46, color: s.color),
            ),
            const SizedBox(height: 36),
            Text(s.title,
                style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w800, height: 1.15, letterSpacing: -0.6)),
            const SizedBox(height: Gap.md),
            Text(s.body, style: const TextStyle(color: AppColors.muted, fontSize: 16, height: 1.5)),
            const SizedBox(height: Gap.xl),
            for (final p in s.points)
              Padding(
                padding: const EdgeInsets.only(bottom: Gap.md),
                child: Row(
                  children: [
                    Icon(Icons.check_circle_rounded, size: 20, color: s.color),
                    const SizedBox(width: Gap.md),
                    Expanded(child: Text(p, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w500))),
                  ],
                ),
              ),
          ],
        ),
      );
}
