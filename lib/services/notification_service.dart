import 'package:flutter_local_notifications/flutter_local_notifications.dart';

/// Shows push messages while the app is open (FCM only auto-displays in the background).
class NotificationService {
  static final FlutterLocalNotificationsPlugin _plugin = FlutterLocalNotificationsPlugin();
  static bool _ready = false;
  static void Function(String? payload)? onTap;

  static Future<void> init() async {
    if (_ready) return;
    const settings = InitializationSettings(
      android: AndroidInitializationSettings('@mipmap/ic_launcher'),
      iOS: DarwinInitializationSettings(
        requestAlertPermission: false,
        requestBadgePermission: false,
        requestSoundPermission: false,
      ),
    );
    await _plugin.initialize(
      settings,
      onDidReceiveNotificationResponse: (r) => onTap?.call(r.payload),
    );
    // Same channel id the server uses for FCM ("signals").
    await _plugin
        .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(const AndroidNotificationChannel(
          'signals',
          'Trading signals',
          description: 'New signals, targets, stops and reports',
          importance: Importance.high,
        ));
    _ready = true;
  }

  static Future<void> show(int id, String title, String body, {String? payload}) async {
    await init();
    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'signals',
        'Trading signals',
        channelDescription: 'New signals, targets, stops and reports',
        importance: Importance.high,
        priority: Priority.high,
      ),
      iOS: DarwinNotificationDetails(),
    );
    await _plugin.show(id, title, body, details, payload: payload);
  }
}
