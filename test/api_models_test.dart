// Parses REAL responses captured from the PHP backend (test/fixtures), so the
// app and server can never silently disagree about the JSON format.
import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:signal_lab/models/profile.dart';
import 'package:signal_lab/models/tracked.dart';
import 'package:signal_lab/services/market_data.dart';

Map<String, dynamic> fixture(String name) =>
    jsonDecode(File('test/fixtures/$name.json').readAsStringSync()) as Map<String, dynamic>;

void main() {
  test('track record parses with daily/weekly/monthly buckets', () {
    final r = TrackRecord.fromApi((fixture('performance')['data'] as Map).cast<String, dynamic>());
    expect(r.all.trades, greaterThan(0));
    expect(r.all.wins + r.all.losses, r.all.trades);
    expect(r.daily, isNotEmpty);
    expect(r.weekly, isNotEmpty);
    expect(r.monthly, isNotEmpty);
    expect(r.equity.length, r.all.trades);
    expect(r.bySymbol.fold<int>(0, (a, b) => a + b.trades), r.all.trades);
    expect(r.all.targets + r.all.stops + r.all.timeExits, r.all.trades);
  });

  test('tracked signals parse in every status', () {
    final list = (fixture('signals')['data'] as List)
        .map((e) => TrackedSignal.fromApi((e as Map).cast<String, dynamic>()))
        .toList();
    expect(list, isNotEmpty);
    final statuses = list.map((s) => s.status).toSet();
    expect(statuses, containsAll(['won', 'lost']));
    for (final s in list) {
      if (s.isClosed) {
        expect(s.exitPrice, isNotNull);
        expect(s.rMultiple, isNotNull);
        expect(s.isWin, s.returnPct! > 0);
      }
      if (!s.isWarning) {
        expect(s.stop, lessThan(s.entry));
        expect(s.t2, greaterThan(s.t1));
      }
      expect(s.statusLabel, isNotEmpty);
    }
  });

  test('inbox and account parse', () {
    final n = (fixture('notifications')['data'] as Map).cast<String, dynamic>();
    final items = (n['items'] as List).map((e) => AppNotification.fromApi((e as Map).cast<String, dynamic>())).toList();
    expect(items, isNotEmpty);
    expect(items.first.title, isNotEmpty);

    final me = (fixture('me')['data'] as Map).cast<String, dynamic>();
    final user = AppUser.fromApi((me['user'] as Map).cast<String, dynamic>());
    expect(user.email, contains('@'));
    final settings = (me['settings'] as Map).cast<String, dynamic>();
    final p = RiskProfile.fromApi(settings);
    expect(p.style, Timeframe.daily);
    expect(p.riskPct, greaterThan(0));
    final prefs = NotificationPrefs.fromApi(settings);
    // round-trip
    expect(NotificationPrefs.fromApi(prefs.toApi()).reportWeekly, prefs.reportWeekly);
    expect(RiskProfile.fromApi(p.toApi()).accountSize, p.accountSize);
  });
}
