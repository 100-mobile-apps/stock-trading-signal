import '../services/market_data.dart';

/// Risk settings (synced with the server's user_settings).
class RiskProfile {
  /// Account size in USD (US-listed stocks are priced in USD).
  final double accountSize;

  /// Percent of the account risked per trade (distance to stop-loss).
  final double riskPct;
  final Timeframe style;
  final bool allowShort;
  final bool fractionalShares;

  const RiskProfile({
    this.accountSize = 1000,
    this.riskPct = 1.0,
    this.style = Timeframe.daily,
    this.allowShort = false,
    this.fractionalShares = true,
  });

  double get riskAmount => accountSize * riskPct / 100;

  RiskProfile copyWith({
    double? accountSize,
    double? riskPct,
    Timeframe? style,
    bool? allowShort,
    bool? fractionalShares,
  }) =>
      RiskProfile(
        accountSize: accountSize ?? this.accountSize,
        riskPct: riskPct ?? this.riskPct,
        style: style ?? this.style,
        allowShort: allowShort ?? this.allowShort,
        fractionalShares: fractionalShares ?? this.fractionalShares,
      );

  Map<String, dynamic> toApi() => {
        'account_size': accountSize,
        'risk_pct': riskPct,
        'style': style == Timeframe.daily ? '1d' : '1h',
        'allow_short': allowShort,
        'fractional': fractionalShares,
      };

  factory RiskProfile.fromApi(Map<String, dynamic> m) => RiskProfile(
        accountSize: (m['account_size'] as num?)?.toDouble() ?? 1000,
        riskPct: (m['risk_pct'] as num?)?.toDouble() ?? 1.0,
        style: m['style'] == '1h' ? Timeframe.hourly : Timeframe.daily,
        allowShort: m['allow_short'] == true,
        fractionalShares: m['fractional'] != false,
      );
}

/// Which alerts and reports the user wants, and where.
class NotificationPrefs {
  final bool pushSignals;
  final bool pushResults;
  final bool emailSignals;
  final bool emailResults;
  final bool reportDaily;
  final bool reportWeekly;
  final bool reportMonthly;
  final bool allSignals;

  const NotificationPrefs({
    this.pushSignals = true,
    this.pushResults = true,
    this.emailSignals = true,
    this.emailResults = true,
    this.reportDaily = false,
    this.reportWeekly = true,
    this.reportMonthly = true,
    this.allSignals = false,
  });

  NotificationPrefs copyWith({
    bool? pushSignals,
    bool? pushResults,
    bool? emailSignals,
    bool? emailResults,
    bool? reportDaily,
    bool? reportWeekly,
    bool? reportMonthly,
    bool? allSignals,
  }) =>
      NotificationPrefs(
        pushSignals: pushSignals ?? this.pushSignals,
        pushResults: pushResults ?? this.pushResults,
        emailSignals: emailSignals ?? this.emailSignals,
        emailResults: emailResults ?? this.emailResults,
        reportDaily: reportDaily ?? this.reportDaily,
        reportWeekly: reportWeekly ?? this.reportWeekly,
        reportMonthly: reportMonthly ?? this.reportMonthly,
        allSignals: allSignals ?? this.allSignals,
      );

  Map<String, dynamic> toApi() => {
        'push_signals': pushSignals,
        'push_results': pushResults,
        'email_signals': emailSignals,
        'email_results': emailResults,
        'report_daily': reportDaily,
        'report_weekly': reportWeekly,
        'report_monthly': reportMonthly,
        'all_signals': allSignals,
      };

  factory NotificationPrefs.fromApi(Map<String, dynamic> m) => NotificationPrefs(
        pushSignals: m['push_signals'] != false,
        pushResults: m['push_results'] != false,
        emailSignals: m['email_signals'] != false,
        emailResults: m['email_results'] != false,
        reportDaily: m['report_daily'] == true,
        reportWeekly: m['report_weekly'] != false,
        reportMonthly: m['report_monthly'] != false,
        allSignals: m['all_signals'] == true,
      );
}

class AppUser {
  final int id;
  final String email;
  final String name;
  final bool emailVerified;
  final bool hasPassword;
  final bool googleLinked;

  const AppUser({
    required this.id,
    required this.email,
    required this.name,
    required this.emailVerified,
    required this.hasPassword,
    required this.googleLinked,
  });

  String get displayName => name.isNotEmpty ? name : email.split('@').first;

  factory AppUser.fromApi(Map<String, dynamic> m) => AppUser(
        id: (m['id'] as num).toInt(),
        email: m['email'] as String? ?? '',
        name: m['name'] as String? ?? '',
        emailVerified: m['email_verified'] == true,
        hasPassword: m['has_password'] == true,
        googleLinked: m['google_linked'] == true,
      );
}

/// An entry in the in-app alert inbox (mirrors what was pushed/emailed).
class AppNotification {
  final int id;
  final int? signalId;
  final String kind; // new_buy, bearish, target1, target, stop, time, report, system
  final String symbol;
  final String title;
  final String body;
  final DateTime time;
  final bool read;
  final bool pushed;
  final bool emailed;

  const AppNotification({
    required this.id,
    required this.signalId,
    required this.kind,
    required this.symbol,
    required this.title,
    required this.body,
    required this.time,
    required this.read,
    required this.pushed,
    required this.emailed,
  });

  factory AppNotification.fromApi(Map<String, dynamic> m) => AppNotification(
        id: (m['id'] as num).toInt(),
        signalId: (m['signal_id'] as num?)?.toInt(),
        kind: m['kind'] as String? ?? 'system',
        symbol: m['symbol'] as String? ?? '',
        title: m['title'] as String? ?? '',
        body: m['body'] as String? ?? '',
        time: DateTime.tryParse(m['created_at'] as String? ?? '') ?? DateTime.now(),
        read: m['read'] == true,
        pushed: m['pushed'] == true,
        emailed: m['emailed'] == true,
      );
}
