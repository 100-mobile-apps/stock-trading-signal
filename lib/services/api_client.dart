import 'dart:async';
import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:http/http.dart' as http;

import '../config.dart';

class ApiException implements Exception {
  final int status;
  final String code;
  final String message;
  ApiException(this.status, this.code, this.message);
  bool get isAuth => status == 401;
  @override
  String toString() => message;
}

/// Talks to the SignalLab backend. Keeps tokens in the phone's secure storage
/// (Keychain / Keystore) and refreshes the access token automatically.
class ApiClient {
  ApiClient({http.Client? client, FlutterSecureStorage? storage, String? baseUrl})
      : _http = client ?? http.Client(),
        _store = storage ?? const FlutterSecureStorage(),
        _base = (baseUrl ?? AppConfig.apiBaseUrl).replaceAll(RegExp(r'/+$'), '');

  final http.Client _http;
  final FlutterSecureStorage _store;
  final String _base;

  String? _access;
  String? _refresh;
  Future<bool>? _refreshing;

  /// Called when the session can't be recovered (user must sign in again).
  void Function()? onSignedOut;

  bool get hasSession => _refresh != null;

  Future<void> loadSession() async {
    try {
      _access = await _store.read(key: 'access_token');
      _refresh = await _store.read(key: 'refresh_token');
    } catch (_) {
      _access = _refresh = null;
    }
  }

  Future<void> saveSession(Map<String, dynamic> data) async {
    _access = data['access_token'] as String?;
    _refresh = data['refresh_token'] as String?;
    await _store.write(key: 'access_token', value: _access);
    await _store.write(key: 'refresh_token', value: _refresh);
  }

  Future<void> clearSession() async {
    _access = _refresh = null;
    await _store.delete(key: 'access_token');
    await _store.delete(key: 'refresh_token');
  }

  String? get refreshToken => _refresh;

  // ------------------------------------------------------------------ verbs
  Future<dynamic> get(String path, {Map<String, String>? query, bool auth = true}) =>
      _send('GET', path, query: query, auth: auth);
  Future<dynamic> post(String path, [Map<String, dynamic>? body, bool auth = true]) =>
      _send('POST', path, body: body, auth: auth);
  Future<dynamic> put(String path, Map<String, dynamic> body) => _send('PUT', path, body: body);
  Future<dynamic> patch(String path, Map<String, dynamic> body) => _send('PATCH', path, body: body);
  Future<dynamic> delete(String path, [Map<String, dynamic>? body]) => _send('DELETE', path, body: body);

  Future<dynamic> _send(
    String method,
    String path, {
    Map<String, String>? query,
    Map<String, dynamic>? body,
    bool auth = true,
    bool retried = false,
  }) async {
    final uri = Uri.parse('$_base$path').replace(queryParameters: query);
    final req = http.Request(method, uri)
      ..headers['Accept'] = 'application/json'
      ..headers['Content-Type'] = 'application/json';
    if (auth && _access != null) req.headers['Authorization'] = 'Bearer $_access';
    if (body != null) req.body = jsonEncode(body);

    final http.Response res;
    try {
      res = await http.Response.fromStream(await _http.send(req).timeout(const Duration(seconds: 25)));
    } on TimeoutException {
      throw ApiException(0, 'timeout', 'The server took too long to respond. Please try again.');
    } catch (_) {
      throw ApiException(0, 'network', 'Can’t reach Stock Trading Signals. Check your internet connection.');
    }

    if (res.statusCode == 401 && auth && !retried && _refresh != null) {
      if (await _refreshTokens()) {
        return _send(method, path, query: query, body: body, auth: auth, retried: true);
      }
      await clearSession();
      onSignedOut?.call();
    }

    Map<String, dynamic>? json;
    try {
      json = jsonDecode(res.body) as Map<String, dynamic>;
    } catch (_) {
      json = null;
    }
    if (res.statusCode >= 200 && res.statusCode < 300 && json?['ok'] == true) return json!['data'];
    final err = (json?['error'] as Map?) ?? const {};
    throw ApiException(
      res.statusCode,
      err['code']?.toString() ?? 'error',
      err['message']?.toString() ?? 'Unexpected server response (${res.statusCode}).',
    );
  }

  Future<bool> _refreshTokens() {
    return _refreshing ??= () async {
      try {
        final data = await _send('POST', '/auth/refresh', body: {'refresh_token': _refresh}, auth: false, retried: true);
        await saveSession((data as Map).cast<String, dynamic>());
        return true;
      } catch (_) {
        return false;
      } finally {
        _refreshing = null;
      }
    }();
  }
}
