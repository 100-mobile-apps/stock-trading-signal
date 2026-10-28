import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../../core/theme.dart';
import '../../services/api_client.dart';
import '../../state/app_state.dart';
import '../../widgets/common.dart';
import '../info_screens.dart';

/// Sign in / create account with email + password, or continue with Google.
class AuthScreen extends StatefulWidget {
  const AuthScreen({super.key});

  @override
  State<AuthScreen> createState() => _AuthScreenState();
}

class _AuthScreenState extends State<AuthScreen> {
  bool _signUp = true;
  bool _busy = false;
  bool _showPw = false;
  String? _error;
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _pw = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    _email.dispose();
    _pw.dispose();
    super.dispose();
  }

  Future<void> _run(Future<Map<String, dynamic>?> Function() action) async {
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    final state = context.read<AppState>();
    try {
      final session = await action();
      if (session != null) await state.signedIn(session);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } catch (_) {
      if (mounted) setState(() => _error = 'Something went wrong. Please try again.');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _submit() {
    if (!_form.currentState!.validate()) return;
    final auth = context.read<AppState>().auth;
    _run(() => _signUp
        ? auth.register(_name.text.trim(), _email.text.trim(), _pw.text)
        : auth.login(_email.text.trim(), _pw.text));
  }

  Future<void> _forgot() async {
    final ctrl = TextEditingController(text: _email.text.trim());
    final auth = context.read<AppState>().auth;
    final messenger = ScaffoldMessenger.of(context);
    final email = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.fromLTRB(Gap.xl, 0, Gap.xl, MediaQuery.of(ctx).viewInsets.bottom + Gap.xl),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('Reset password', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            const Text('We’ll email you a secure link to choose a new password.',
                style: TextStyle(color: AppColors.muted)),
            const SizedBox(height: Gap.lg),
            TextField(
              controller: ctrl,
              autofocus: true,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(hintText: 'you@example.com', prefixIcon: Icon(Icons.mail_outline)),
            ),
            const SizedBox(height: Gap.lg),
            FilledButton(onPressed: () => Navigator.pop(ctx, ctrl.text.trim()), child: const Text('Send reset link')),
          ],
        ),
      ),
    );
    ctrl.dispose();
    if (email == null || email.isEmpty) return;
    try {
      final msg = await auth.forgot(email);
      messenger.showSnackBar(SnackBar(content: Text(msg)));
    } on ApiException catch (e) {
      messenger.showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(Gap.xl, Gap.xl, Gap.xl, Gap.xl),
          children: [
            const Center(child: BrandMark(size: 64)),
            const SizedBox(height: Gap.lg),
            Text(
              _signUp ? 'Create your free account' : 'Welcome back',
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w800, letterSpacing: -0.5),
            ),
            const SizedBox(height: 6),
            Text(
              _signUp
                  ? 'Get signal alerts, results and reports by push and email.'
                  : 'Sign in to see your signals, alerts and track record.',
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.muted, height: 1.4),
            ),
            const SizedBox(height: Gap.xl),
            _SegmentedAuth(signUp: _signUp, onChanged: (v) => setState(() {
                  _signUp = v;
                  _error = null;
                })),
            const SizedBox(height: Gap.lg),
            OutlinedButton.icon(
              onPressed: _busy ? null : () => _run(() => context.read<AppState>().auth.google()),
              icon: const _GoogleG(),
              label: const Text('Continue with Google'),
            ),
            const SizedBox(height: Gap.lg),
            const Row(children: [
              Expanded(child: Divider()),
              Padding(padding: EdgeInsets.symmetric(horizontal: 12), child: Text('or', style: TextStyle(color: AppColors.muted))),
              Expanded(child: Divider()),
            ]),
            const SizedBox(height: Gap.lg),
            Form(
              key: _form,
              child: Column(
                children: [
                  if (_signUp) ...[
                    TextFormField(
                      controller: _name,
                      textCapitalization: TextCapitalization.words,
                      autofillHints: const [AutofillHints.name],
                      decoration: const InputDecoration(hintText: 'Your name', prefixIcon: Icon(Icons.person_outline)),
                    ),
                    const SizedBox(height: Gap.md),
                  ],
                  TextFormField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    autofillHints: const [AutofillHints.email],
                    decoration: const InputDecoration(hintText: 'Email', prefixIcon: Icon(Icons.mail_outline)),
                    validator: (v) => (v == null || !RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(v.trim()))
                        ? 'Enter a valid email'
                        : null,
                  ),
                  const SizedBox(height: Gap.md),
                  TextFormField(
                    controller: _pw,
                    obscureText: !_showPw,
                    autofillHints: [_signUp ? AutofillHints.newPassword : AutofillHints.password],
                    onFieldSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      hintText: 'Password',
                      prefixIcon: const Icon(Icons.lock_outline),
                      suffixIcon: IconButton(
                        icon: Icon(_showPw ? Icons.visibility_off_outlined : Icons.visibility_outlined),
                        onPressed: () => setState(() => _showPw = !_showPw),
                      ),
                    ),
                    validator: (v) {
                      if (v == null || v.isEmpty) return 'Enter your password';
                      if (_signUp && (v.length < 8 || !RegExp(r'[A-Za-z]').hasMatch(v) || !RegExp(r'\d').hasMatch(v))) {
                        return 'At least 8 characters, with letters and numbers';
                      }
                      return null;
                    },
                  ),
                ],
              ),
            ),
            if (!_signUp)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(onPressed: _busy ? null : _forgot, child: const Text('Forgot password?')),
              ),
            if (_error != null) ...[
              const SizedBox(height: Gap.sm),
              Container(
                padding: const EdgeInsets.all(Gap.md),
                decoration: BoxDecoration(
                  color: AppColors.bear.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: InfoNote(_error!, icon: Icons.error_outline_rounded, color: AppColors.bear),
              ),
            ],
            const SizedBox(height: Gap.lg),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy
                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                  : Text(_signUp ? 'Create account' : 'Sign in'),
            ),
            const SizedBox(height: Gap.lg),
            Wrap(
              alignment: WrapAlignment.center,
              children: [
                const Text('By continuing you agree to the ', style: TextStyle(color: AppColors.faint, fontSize: 12)),
                GestureDetector(
                  onTap: () => Navigator.push(context,
                      MaterialPageRoute(builder: (_) => const LegalScreen(kind: LegalKind.disclaimer))),
                  child: const Text('risk disclaimer',
                      style: TextStyle(color: AppColors.primary, fontSize: 12, fontWeight: FontWeight.w600)),
                ),
                const Text(' and ', style: TextStyle(color: AppColors.faint, fontSize: 12)),
                GestureDetector(
                  onTap: () => Navigator.push(context,
                      MaterialPageRoute(builder: (_) => const LegalScreen(kind: LegalKind.privacy))),
                  child: const Text('privacy policy',
                      style: TextStyle(color: AppColors.primary, fontSize: 12, fontWeight: FontWeight.w600)),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _SegmentedAuth extends StatelessWidget {
  const _SegmentedAuth({required this.signUp, required this.onChanged});
  final bool signUp;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(4),
        decoration: BoxDecoration(color: AppColors.surface2, borderRadius: BorderRadius.circular(14)),
        child: Row(
          children: [
            for (final (v, label) in [(true, 'Create account'), (false, 'Sign in')])
              Expanded(
                child: GestureDetector(
                  onTap: () => onChanged(v),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 180),
                    padding: const EdgeInsets.symmetric(vertical: 11),
                    decoration: BoxDecoration(
                      color: signUp == v ? AppColors.surface : Colors.transparent,
                      borderRadius: BorderRadius.circular(10),
                      border: signUp == v ? Border.all(color: AppColors.border) : null,
                    ),
                    child: Text(
                      label,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        fontWeight: FontWeight.w700,
                        color: signUp == v ? AppColors.text : AppColors.muted,
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      );
}

/// Simple multicolour "G" mark (drawn, no image asset needed).
class _GoogleG extends StatelessWidget {
  const _GoogleG();

  @override
  Widget build(BuildContext context) => SizedBox(width: 20, height: 20, child: CustomPaint(painter: _GPainter()));
}

class _GPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size s) {
    final r = Rect.fromLTWH(2, 2, s.width - 4, s.height - 4);
    final p = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = s.width * 0.18;
    const segs = [
      (Color(0xFFEA4335), -2.6, 1.35),
      (Color(0xFFFBBC05), -3.95, 1.35),
      (Color(0xFF34A853), 0.75, 1.55),
      (Color(0xFF4285F4), -0.15, 0.9),
    ];
    for (final (c, start, sweep) in segs) {
      canvas.drawArc(r, start, sweep, false, p..color = c);
    }
    canvas.drawLine(Offset(s.width / 2, s.height / 2), Offset(s.width - 2, s.height / 2), p..color = const Color(0xFF4285F4));
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}
