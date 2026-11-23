import 'dart:async';
import 'dart:io';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';

import 'api_client.dart';
import 'notification_service.dart';

/// Must be a top-level function: runs when a push arrives while the app is closed.
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {
  // Notification messages are displayed by the OS automatically. Nothing to do.
}

/// Firebase Cloud Messaging: registers this device with the backend so the
/// server can push new signals and results, even when the app is closed.
class PushService {
  static bool _available = false;
  static StreamSubscription<String>? _tokenSub;

  /// Called with the signal id / symbol when the user taps a notification.
  static void Function(Map<String, dynamic> data)? onOpen;

  static bool get available => _available;

  static Future<void> init() async {
    try {
      await Firebase.initializeApp();
      FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
      _available = true;
    } catch (_) {
      // google-services.json / GoogleService-Info.plist not added yet: push disabled.
      _available = false;
      return;
    }
    await NotificationService.init();

    // Foreground messages: show them as local notifications.
    FirebaseMessaging.onMessage.listen((m) {
      final n = m.notification;
      if (n == null) return;
      NotificationService.show(m.hashCode & 0x7fffffff, n.title ?? 'Stock Trading Signals', n.body ?? '',
          payload: m.data['symbol'] as String?);
    });
    // Tapped while in background.
    FirebaseMessaging.onMessageOpenedApp.listen((m) => onOpen?.call(m.data));
    // Tapped while the app was terminated.
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) {
      Future.delayed(const Duration(milliseconds: 800), () => onOpen?.call(initial.data));
    }
  }

  /// Asks for permission and registers the token with the API. Returns false if denied.
  static Future<bool> register(ApiClient api) async {
    if (!_available) return false;
    final settings = await FirebaseMessaging.instance.requestPermission(alert: true, badge: true, sound: true);
    if (settings.authorizationStatus == AuthorizationStatus.denied) return false;
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) return false;
    await api.post('/devices', {'token': token, 'platform': Platform.isIOS ? 'ios' : 'android'});
    await _tokenSub?.cancel();
    _tokenSub = FirebaseMessaging.instance.onTokenRefresh.listen((t) {
      api.post('/devices', {'token': t, 'platform': Platform.isIOS ? 'ios' : 'android'}).catchError((_) => null);
    });
    return true;
  }

  static Future<String?> currentToken() async {
    if (!_available) return null;
    try {
      return await FirebaseMessaging.instance.getToken();
    } catch (_) {
      return null;
    }
  }

  static Future<void> unregister() async {
    await _tokenSub?.cancel();
    _tokenSub = null;
    if (!_available) return;
    try {
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {}
  }
}
