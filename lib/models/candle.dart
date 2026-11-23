class Candle {
  final DateTime time;
  final double open;
  final double high;
  final double low;
  final double close;
  final double volume;

  const Candle({
    required this.time,
    required this.open,
    required this.high,
    required this.low,
    required this.close,
    required this.volume,
  });
}

class MarketSeries {
  final String symbol;
  final String name;
  final String currency;
  final String exchange;

  /// Every bar returned by the provider, including a bar that may still be forming.
  final List<Candle> candles;

  /// Number of leading bars that are fully closed. Signals are computed only
  /// on these, so a signal can never appear and then vanish mid-bar.
  final int confirmedCount;

  final DateTime fetchedAt;

  const MarketSeries({
    required this.symbol,
    required this.name,
    required this.currency,
    required this.exchange,
    required this.candles,
    required this.confirmedCount,
    required this.fetchedAt,
  });

  List<Candle> get confirmed =>
      confirmedCount >= candles.length ? candles : candles.sublist(0, confirmedCount);

  bool get hasLiveBar => confirmedCount < candles.length;

  double get lastPrice => candles.last.close;

  /// Change versus the previous session's close.
  double get changePct {
    final prev = previousClose;
    return prev == null || prev == 0 ? 0 : (lastPrice - prev) / prev * 100;
  }

  double? get previousClose {
    if (candles.length < 2) return null;
    final lastDay = _day(candles.last.time);
    for (var i = candles.length - 2; i >= 0; i--) {
      if (_day(candles[i].time) != lastDay) return candles[i].close;
    }
    return null;
  }

  static int _day(DateTime t) => t.year * 10000 + t.month * 100 + t.day;
}
