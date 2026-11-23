import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import 'core/theme.dart';
import 'screens/splash_screen.dart';
import 'screens/tracked_signal_screen.dart';
import 'services/api_client.dart';
import 'services/market_data.dart';
import 'services/notification_service.dart';
import 'services/prefs_store.dart';
import 'services/push_service.dart';
import 'state/app_state.dart';

/// Lets push-notification taps open screens from anywhere.
final navigatorKey = GlobalKey<NavigatorState>();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await SystemChrome.setPreferredOrientations([DeviceOrientation.portraitUp]);
  final prefs = await PrefsStore.open();
  try {
    await NotificationService.init();
    await PushService.init();
  } catch (_) {
    // Push is optional; the app works without it.
  }
  final state = AppState(prefs, MarketDataService(), ApiClient());

  void openFromPush(Map<String, dynamic> data) {
    final id = int.tryParse('${data['signal_id'] ?? ''}');
    final nav = navigatorKey.currentState;
    if (id == null || nav == null || state.stage != AuthStage.ready) return;
    nav.push(MaterialPageRoute(builder: (_) => TrackedSignalScreen(signalId: id)));
  }

  PushService.onOpen = openFromPush;
  runApp(ChangeNotifierProvider<AppState>.value(value: state, child: const SignalLabApp()));
}

class SignalLabApp extends StatelessWidget {
  const SignalLabApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Stock Trading Signals',
      navigatorKey: navigatorKey,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark(),
      darkTheme: AppTheme.dark(),
      themeMode: ThemeMode.dark,
      home: const SplashScreen(),
    );
  }
}
