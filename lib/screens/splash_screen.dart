import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../core/theme.dart';
import '../state/app_state.dart';
import '../widgets/common.dart';
import 'auth/auth_screen.dart';
import 'onboarding/onboarding_screen.dart';
import 'shell.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _c =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..forward();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      final state = context.read<AppState>();
      // Restore the session while the logo shows (at least 1.2 s so it doesn't flash).
      await Future.wait([state.bootstrap(), Future.delayed(const Duration(milliseconds: 1200))]);
      if (!mounted) return;
      Navigator.of(context).pushReplacement(PageRouteBuilder(
        transitionDuration: const Duration(milliseconds: 450),
        pageBuilder: (_, __, ___) => const RootGate(),
        transitionsBuilder: (_, anim, __, child) => FadeTransition(opacity: anim, child: child),
      ));
    });
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SplashView(animation: _c);
}

class SplashView extends StatelessWidget {
  const SplashView({super.key, required this.animation});
  final AnimationController animation;

  @override
  Widget build(BuildContext context) {
    final curve = CurvedAnimation(parent: animation, curve: Curves.easeOutBack);
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: RadialGradient(center: Alignment(0, -0.3), radius: 1.1, colors: [Color(0xFF0F2A2E), AppColors.bg]),
        ),
        child: Column(
          children: [
            const Spacer(),
            ScaleTransition(
              scale: Tween(begin: 0.6, end: 1.0).animate(curve),
              child: FadeTransition(opacity: animation, child: const BrandMark(size: 88)),
            ),
            const SizedBox(height: Gap.xl),
            FadeTransition(
              opacity: animation,
              child: const Column(
                children: [
                  Text('Stock Trading Signals', style: TextStyle(fontSize: 30, fontWeight: FontWeight.w800, letterSpacing: -0.8)),
                  SizedBox(height: 6),
                  Text('Stock signals with a verified track record', style: TextStyle(color: AppColors.muted, fontSize: 15)),
                ],
              ),
            ),
            const Spacer(),
            const Padding(
              padding: EdgeInsets.only(bottom: 36),
              child: Text('Free · Every signal tracked · Losses included',
                  style: TextStyle(color: AppColors.faint, fontSize: 12)),
            ),
          ],
        ),
      ),
    );
  }
}

/// Shows the right place in the flow for the current session:
/// intro → sign in / sign up → setup → app. Signing out anywhere lands back here.
class RootGate extends StatefulWidget {
  const RootGate({super.key});

  @override
  State<RootGate> createState() => _RootGateState();
}

class _RootGateState extends State<RootGate> {
  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final Widget child = switch (s.stage) {
      AuthStage.unknown => const Scaffold(body: Center(child: CircularProgressIndicator())),
      AuthStage.signedOut => s.prefs.introSeen
          ? const AuthScreen()
          : IntroScreen(onDone: () async {
              await s.prefs.setIntroSeen();
              if (mounted) setState(() {});
            }),
      AuthStage.needsSetup => const SetupScreen(),
      AuthStage.ready => const Shell(),
    };
    return AnimatedSwitcher(duration: const Duration(milliseconds: 350), child: KeyedSubtree(key: ValueKey(child.runtimeType), child: child));
  }
}
