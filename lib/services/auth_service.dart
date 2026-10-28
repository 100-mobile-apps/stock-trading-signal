import 'package:google_sign_in/google_sign_in.dart';

import '../config.dart';
import 'api_client.dart';

/// Sign-up, sign-in (email or Google), password reset and sign-out.
/// Each successful call returns the session map to pass to AppState.signedIn.
class AuthService {
  AuthService(this.api);
  final ApiClient api;

  GoogleSignIn? _google;
  GoogleSignIn get _g => _google ??= GoogleSignIn(
        scopes: const ['email', 'profile'],
        serverClientId: AppConfig.googleServerClientId.isEmpty ? null : AppConfig.googleServerClientId,
      );

  Future<Map<String, dynamic>> register(String name, String email, String password) async =>
      ((await api.post('/auth/register', {'name': name, 'email': email, 'password': password}, false)) as Map)
          .cast<String, dynamic>();

  Future<Map<String, dynamic>> login(String email, String password) async =>
      ((await api.post('/auth/login', {'email': email, 'password': password}, false)) as Map).cast<String, dynamic>();

  /// Returns null if the user closed the Google dialog.
  Future<Map<String, dynamic>?> google() async {
    final GoogleSignInAccount? account;
    try {
      await _g.signOut(); // always show the account picker
      account = await _g.signIn();
    } catch (e) {
      throw ApiException(0, 'google_failed', 'Google sign-in is not available. Please use email instead.');
    }
    if (account == null) return null;
    final idToken = (await account.authentication).idToken;
    if (idToken == null) {
      throw ApiException(0, 'google_failed', 'Google did not return an ID token. Check GOOGLE_SERVER_CLIENT_ID.');
    }
    return ((await api.post('/auth/google', {'id_token': idToken}, false)) as Map).cast<String, dynamic>();
  }

  Future<String> forgot(String email) async {
    final r = (await api.post('/auth/forgot', {'email': email}, false) as Map);
    return r['message']?.toString() ?? 'Check your email for a reset link.';
  }

  Future<void> changePassword(String current, String next) =>
      api.post('/auth/change-password', {'current_password': current, 'new_password': next});

  Future<void> logout(String? refreshToken, String? fcmToken) async {
    try {
      await _google?.signOut();
    } catch (_) {}
    if (refreshToken == null) return;
    await api.post('/auth/logout', {'refresh_token': refreshToken, if (fcmToken != null) 'fcm_token': fcmToken}, false);
  }
}
