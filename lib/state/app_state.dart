import 'package:flutter/foundation.dart';

import '../engine/analyzer.dart';
import '../engine/portfolio.dart';
import '../models/candle.dart';
import '../models/profile.dart';
import '../models/tracked.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/market_data.dart';
import '../services/prefs_store.dart';
import '../services/push_service.dart';

class SymbolState {
  Analysis? analysis;
  String? error;
  bool loading = false;
}

enum AuthStage { unknown, signedOut, needsSetup, ready }

/// App-wide state. The server is the source of truth for the account, settings,
/// watchlist, tracked signals, track record and alerts. The local engine powers
/// charts and per-stock backtests.
class AppState extends ChangeNotifier {
  AppState(this.prefs, this.market, this.api) : auth = AuthService(api) {
    api.onSignedOut = _onSessionLost;
  }

  final PrefsStore prefs;
  final MarketDataService market;
  final ApiClient api;
  final AuthService auth;

  AuthStage _stage = AuthStage.unknown;
  AppUser? _user;
  RiskProfile _profile = const RiskProfile();
  NotificationPrefs _notif = const NotificationPrefs();
  List<String> _watchlist = List.of(PrefsStore.defaultWatchlist);
  Timeframe _timeframe = Timeframe.daily;

  final Map<String, SymbolState> _states = {};
  final Map<String, MarketSeries> _indexes = {};
  List<TrackedSignal> _live = [];
  List<TrackedSignal> _closed = [];
  TrackRecord? _record;
  List<AppNotification> _inbox = [];
  int _unread = 0;
  String? _serverError;
  DateTime? _lastUpdated;
  bool _refreshing = false;
  int _generation = 0;
  PortfolioStats? _backtestCache;

  // ---------------------------------------------------------------- getters
  AuthStage get stage => _stage;
  AppUser? get user => _user;
  RiskProfile get profile => _profile;
  NotificationPrefs get notif => _notif;
  List<String> get watchlist => List.unmodifiable(_watchlist);
  Timeframe get timeframe => _timeframe;
  String get tfCode => _timeframe == Timeframe.daily ? '1d' : '1h';
  List<TrackedSignal> get liveSignals => _live;
  List<TrackedSignal> get closedSignals => _closed;
  TrackRecord? get record => _record;
  List<AppNotification> get inbox => _inbox;
  int get unreadAlerts => _unread;
  String? get serverError => _serverError;
  DateTime? get lastUpdated => _lastUpdated;
  bool get refreshing => _refreshing;
  bool get pushAvailable => PushService.available;
  Map<String, MarketSeries> get indexes => Map.unmodifiable(_indexes);

  List<TrackedSignal> get newSignals => _live
      .where((s) => DateTime.now().difference(s.signalTime) < Duration(hours: _timeframe.intraday ? 3 : 48))
      .toList();

  String _key(String sym, Timeframe tf) => '$sym|${tf.name}';
  SymbolState stateOf(String sym, [Timeframe? tf]) =>
      _states.putIfAbsent(_key(sym, tf ?? _timeframe), SymbolState.new);
  Analysis? analysisOf(String sym, [Timeframe? tf]) => _states[_key(sym, tf ?? _timeframe)]?.analysis;
  bool inWatchlist(String sym) => _watchlist.contains(sym);
  List<Analysis> get analyses => _watchlist.map(analysisOf).whereType<Analysis>().toList();
  bool get hasAnyData => analyses.isNotEmpty || _live.isNotEmpty || _record != null;

  /// Simulated history of the rules on the current watchlist (clearly labelled in the UI).
  PortfolioStats get backtest => _backtestCache ??= PortfolioStats.from(analyses, riskPct: _profile.riskPct);

  // ---------------------------------------------------------------- startup & auth
  Future<void> bootstrap() async {
    await api.loadSession();
    if (!api.hasSession) {
      _stage = AuthStage.signedOut;
      notifyListeners();
      return;
    }
    try {
      await _loadAccount();
    } on ApiException catch (e) {
      if (e.isAuth) {
        _stage = AuthStage.signedOut;
        notifyListeners();
        return;
      }
      // Offline: keep the session, show cached/empty data with a banner.
      _serverError = e.message;
      _stage = AuthStage.ready;
    }
    notifyListeners();
    if (_stage == AuthStage.ready) _afterSignIn();
  }

  Future<void> _loadAccount() async {
    final me = (await api.get('/me') as Map).cast<String, dynamic>();
    _applyMe(me);
    _stage = prefs.setupDone(_user!.id) ? AuthStage.ready : AuthStage.needsSetup;
  }

  void _applyMe(Map<String, dynamic> me) {
    _user = AppUser.fromApi((me['user'] as Map).cast<String, dynamic>());
    final s = (me['settings'] as Map).cast<String, dynamic>();
    _profile = RiskProfile.fromApi(s);
    _notif = NotificationPrefs.fromApi(s);
    _timeframe = _profile.style;
    final wl = ((me['watchlist'] as List?) ?? const []).map((e) => e.toString()).toList();
    if (wl.isNotEmpty) _watchlist = wl;
  }

  /// Call after register/login/google sign-in.
  Future<void> signedIn(Map<String, dynamic> session) async {
    await api.saveSession(session);
    await _loadAccount();
    notifyListeners();
    if (_stage == AuthStage.ready) _afterSignIn();
  }

  Future<void> completeSetup(RiskProfile p, NotificationPrefs n, {required bool push}) async {
    _profile = p;
    _notif = n;
    _timeframe = p.style;
    await api.put('/me/settings', {...p.toApi(), ...n.toApi()});
    await prefs.setSetupDone(_user!.id);
    _stage = AuthStage.ready;
    notifyListeners();
    if (push) await PushService.register(api);
    _afterSignIn();
  }

  void _afterSignIn() {
    if (_notif.pushSignals || _notif.pushResults) {
      PushService.register(api).catchError((_) => false);
    }
    refresh();
  }

  Future<void> signOut() async {
    final token = await PushService.currentToken();
    try {
      await auth.logout(api.refreshToken, token);
    } catch (_) {}
    await PushService.unregister();
    await api.clearSession();
    _resetLocal();
  }

  Future<void> deleteAccount() async {
    await api.delete('/me');
    await PushService.unregister();
    await api.clearSession();
    _resetLocal();
  }

  void _onSessionLost() {
    if (_stage == AuthStage.signedOut) return;
    _resetLocal();
  }

  void _resetLocal() {
    _generation++;
    _stage = AuthStage.signedOut;
    _user = null;
    _live = [];
    _closed = [];
    _record = null;
    _inbox = [];
    _unread = 0;
    _refreshing = false;
    _backtestCache = null;
    notifyListeners();
  }

  // ---------------------------------------------------------------- loading
  Future<void> refresh({bool force = false}) async {
    if (_stage != AuthStage.ready) return;
    final gen = ++_generation;
    _refreshing = true;
    for (final s in _watchlist) {
      stateOf(s).loading = true;
    }
    notifyListeners();

    final server = refreshServer();
    final idx = _loadIndexes(force);
    const batch = 4;
    for (var i = 0; i < _watchlist.length; i += batch) {
      final chunk = _watchlist.skip(i).take(batch).toList();
      await Future.wait(chunk.map((s) => _load(s, _timeframe, force: force, notify: false)));
      if (gen != _generation) return;
      _backtestCache = null;
      notifyListeners();
    }
    await Future.wait([server, idx]);
    if (gen != _generation) return;
    _refreshing = false;
    _lastUpdated = DateTime.now();
    notifyListeners();
  }

  /// Tracked signals, track record and inbox from the server.
  Future<void> refreshServer() async {
    try {
      final results = await Future.wait([
        api.get('/signals', query: {'status': 'live', 'tf': tfCode, 'scope': _notif.allSignals ? 'all' : 'watchlist', 'limit': '100'}),
        api.get('/signals', query: {'status': 'closed', 'tf': tfCode, 'scope': 'all', 'limit': '150'}),
        api.get('/performance', query: {'tf': tfCode, 'scope': 'all'}),
        api.get('/notifications', query: {'limit': '80'}),
      ]);
      _live = (results[0] as List).whereType<Map>().map((m) => TrackedSignal.fromApi(m.cast<String, dynamic>())).toList();
      _closed = (results[1] as List).whereType<Map>().map((m) => TrackedSignal.fromApi(m.cast<String, dynamic>())).toList();
      _record = TrackRecord.fromApi((results[2] as Map).cast<String, dynamic>());
      final n = (results[3] as Map).cast<String, dynamic>();
      _inbox = ((n['items'] as List?) ?? const []).whereType<Map>().map((m) => AppNotification.fromApi(m.cast<String, dynamic>())).toList();
      _unread = (n['unread'] as num?)?.toInt() ?? 0;
      _serverError = null;
    } on ApiException catch (e) {
      _serverError = e.message;
    }
    notifyListeners();
  }

  Future<List<TrackedSignal>> signalsFor(String symbol) async {
    final data = await api.get('/signals', query: {'symbol': symbol, 'status': 'all', 'limit': '30'});
    return (data as List).whereType<Map>().map((m) => TrackedSignal.fromApi(m.cast<String, dynamic>())).toList();
  }

  Future<TrackedSignal> signalById(int id) async =>
      TrackedSignal.fromApi(((await api.get('/signals/$id')) as Map).cast<String, dynamic>());

  Future<void> _loadIndexes(bool force) async {
    await Future.wait(PrefsStore.marketIndexes.map((s) async {
      try {
        _indexes[s] = await market.fetch(s, Timeframe.daily, force: force);
      } catch (_) {}
    }));
  }

  Future<void> _load(String sym, Timeframe tf, {bool force = false, bool notify = true}) async {
    final st = stateOf(sym, tf);
    st.loading = true;
    if (notify) notifyListeners();
    try {
      final series = await market.fetch(sym, tf, force: force);
      st.analysis = Analyzer.analyze(series, allowShort: _profile.allowShort);
      st.error = null;
    } catch (e) {
      st.error = e.toString();
    } finally {
      st.loading = false;
      _backtestCache = null;
      if (notify) notifyListeners();
    }
  }

  Future<void> ensureLoaded(String sym, Timeframe tf, {bool force = false}) async {
    final st = stateOf(sym, tf);
    if (!force && st.analysis != null) return;
    await _load(sym, tf, force: force);
  }

  Future<void> setTimeframe(Timeframe tf) async {
    if (tf == _timeframe) return;
    _timeframe = tf;
    _backtestCache = null;
    notifyListeners();
    await refresh();
  }

  // ---------------------------------------------------------------- watchlist (synced)
  Future<void> _syncWatchlist() async {
    try {
      final data = await api.put('/watchlist', {'symbols': _watchlist});
      _watchlist = (data as List).map((e) => e.toString()).toList();
      notifyListeners();
      refreshServer();
    } on ApiException catch (e) {
      _serverError = e.message;
      notifyListeners();
    }
  }

  Future<void> addSymbol(String sym) async {
    final s = sym.trim().toUpperCase();
    if (s.isEmpty || _watchlist.contains(s)) return;
    _watchlist = [..._watchlist, s];
    notifyListeners();
    await _syncWatchlist();
    await _load(s, _timeframe);
  }

  Future<int> removeSymbol(String sym) async {
    final i = _watchlist.indexOf(sym);
    if (i < 0) return -1;
    _watchlist = [..._watchlist]..removeAt(i);
    _backtestCache = null;
    notifyListeners(); // synchronously, so a swiped row leaves the tree at once
    await _syncWatchlist();
    return i;
  }

  Future<void> insertSymbol(int index, String sym) async {
    if (_watchlist.contains(sym)) return;
    _watchlist = [..._watchlist]..insert(index.clamp(0, _watchlist.length), sym);
    _backtestCache = null;
    notifyListeners();
    await _syncWatchlist();
    if (analysisOf(sym) == null) await _load(sym, _timeframe);
  }

  Future<void> resetWatchlist() async {
    _watchlist = List.of(PrefsStore.defaultWatchlist);
    await _syncWatchlist();
    await refresh();
  }

  // ---------------------------------------------------------------- settings (synced)
  Future<void> updateProfile(RiskProfile p) async {
    final old = _profile;
    _profile = p;
    _backtestCache = null;
    if (old.allowShort != p.allowShort) {
      for (final st in _states.values) {
        final a = st.analysis;
        if (a != null) st.analysis = Analyzer.analyze(a.series, allowShort: p.allowShort);
      }
    }
    notifyListeners();
    await api.put('/me/settings', p.toApi());
    if (old.style != p.style) setTimeframe(p.style);
  }

  Future<void> updateNotif(NotificationPrefs n) async {
    final old = _notif;
    _notif = n;
    notifyListeners();
    try {
      await api.put('/me/settings', n.toApi());
    } catch (e) {
      _notif = old;
      notifyListeners();
      rethrow;
    }
    if ((n.pushSignals || n.pushResults) && !(old.pushSignals || old.pushResults)) {
      await PushService.register(api);
    }
    if (n.allSignals != old.allSignals) refreshServer();
  }

  Future<bool> enablePush() => PushService.register(api);

  Future<bool> sendTestPush() async {
    final r = (await api.post('/devices/test') as Map).cast<String, dynamic>();
    return r['delivered'] == true;
  }

  Future<void> resendVerification() => api.post('/auth/resend-verification');

  Future<void> reloadUser() async {
    final me = (await api.get('/me') as Map).cast<String, dynamic>();
    _applyMe(me);
    notifyListeners();
  }

  Future<void> markAlertsRead() async {
    if (_unread == 0) return;
    _unread = 0;
    notifyListeners();
    try {
      await api.post('/notifications/read');
    } catch (_) {}
  }
}
