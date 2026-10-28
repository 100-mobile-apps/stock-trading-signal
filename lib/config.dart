/// App configuration. Override at build time without editing code:
///   flutter run --dart-define=API_BASE_URL=https://api.yourdomain.com \
///               --dart-define=GOOGLE_SERVER_CLIENT_ID=xxxx.apps.googleusercontent.com
class AppConfig {
  /// Your cPanel API (the server/public folder), no trailing slash.
  static const apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'https://api.yourdomain.com');

  /// Google OAuth *Web* client ID. The server verifies ID tokens issued for it.
  static const googleServerClientId = String.fromEnvironment('GOOGLE_SERVER_CLIENT_ID', defaultValue: '');

  static String get publicRecordUrl => '$apiBaseUrl/record';

  // ------------------------------------------------------------ developer & support
  // Replace these with your own links (or pass them with --dart-define).

  /// Android package name (Play Store listing + in-app rating).
  static const androidPackage = String.fromEnvironment('ANDROID_PACKAGE', defaultValue: 'com.swiftmynd.signal_lab');

  /// Click-to-chat (WhatsApp +234 701 140 1387).
  static const chatUrl = String.fromEnvironment('CHAT_URL',
      defaultValue: 'https://wa.me/2347011401387?text=Hi%2C%20I%27m%20using%20Stock%20Trading%20Signals%20and%20');

  /// Tip jar.
  static const coffeeUrl = String.fromEnvironment('COFFEE_URL', defaultValue: 'https://buymeacoffee.com/swalafblogM');

  /// "Hire the developer": WhatsApp with a prefilled message until a portfolio page exists.
  static const servicesUrl = String.fromEnvironment('SERVICES_URL',
      defaultValue: 'https://wa.me/2347011401387?text=Hi%2C%20I%20saw%20Stock%20Trading%20Signals%20and%20I%27d%20like%20you%20to%20build%20an%20app%2Fwebsite%20for%20me.');

  /// Your Google Play developer page for "More apps" (hidden while empty).
  static const moreAppsUrl = String.fromEnvironment('MORE_APPS_URL', defaultValue: '');

  static const developerName = String.fromEnvironment('DEVELOPER_NAME', defaultValue: 'Swiftmynd Technologies Ltd');
  static const developerShort = 'Swiftmynd Technologies';

  static String get playStoreUrl => 'https://play.google.com/store/apps/details?id=$androidPackage';
}
