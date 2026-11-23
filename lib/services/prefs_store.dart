import 'package:shared_preferences/shared_preferences.dart';

/// Small device-only flags. Account data (profile, watchlist, alerts) lives on the server.
class PrefsStore {
  PrefsStore._(this._p);
  final SharedPreferences _p;

  static Future<PrefsStore> open() async => PrefsStore._(await SharedPreferences.getInstance());

  static const defaultWatchlist = ['AAPL', 'MSFT', 'NVDA', 'AMZN', 'GOOGL', 'META', 'TSLA', 'AMD', 'SPY', 'QQQ'];
  static const marketIndexes = ['SPY', 'QQQ', 'DIA'];

  /// Intro slides seen on this device.
  bool get introSeen => _p.getBool('intro_seen') ?? false;
  Future<void> setIntroSeen() => _p.setBool('intro_seen', true);

  /// The signed-in user finished setup (risk profile + risk acknowledgement) on this device.
  bool setupDone(int userId) => _p.getBool('setup_done_$userId') ?? false;
  Future<void> setSetupDone(int userId) => _p.setBool('setup_done_$userId', true);

  Future<void> clear() => _p.clear();
}
