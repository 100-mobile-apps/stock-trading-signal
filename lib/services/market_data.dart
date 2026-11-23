import 'dart:convert';

import 'package:http/http.dart' as http;

import '../models/candle.dart';

enum Timeframe { daily, hourly }

extension TimeframeX on Timeframe {
  String get label => this == Timeframe.daily ? '1D' : '1H';
  String get longLabel => this == Timeframe.daily ? 'Daily · Swing' : 'Hourly · Intraday';
  String get interval => this == Timeframe.daily ? '1d' : '1h';

  /// Enough history for a 200-period EMA plus a meaningful backtest.
  String get range => this == Timeframe.daily ? '5y' : '1y';
  bool get intraday => this == Timeframe.hourly;
}

class MarketDataException implements Exception {
  final String message;
  MarketDataException(this.message);
  @override
  String toString() => message;
}

class SymbolSearchResult {
  final String symbol;
  final String name;
  final String exchange;
  final String type;
  const SymbolSearchResult(this.symbol, this.name, this.exchange, this.type);
}

/// Free, keyless market data from Yahoo Finance's public endpoints.
/// Quotes are usually delayed ~15 minutes.
class MarketDataService {
  MarketDataService({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;
  final Map<String, MarketSeries> _cache = {};
  static const _cacheTtl = Duration(minutes: 3);

  static const _headers = {
    'User-Agent': 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 '
        '(KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
    'Accept': 'application/json',
  };

  Future<MarketSeries> fetch(String symbol, Timeframe tf, {bool force = false}) async {
    final sym = symbol.trim().toUpperCase();
    final key = '$sym|${tf.name}';
    final cached = _cache[key];
    if (!force && cached != null && DateTime.now().difference(cached.fetchedAt) < _cacheTtl) {
      return cached;
    }

    final uri = Uri.https('query1.finance.yahoo.com', '/v8/finance/chart/$sym', {
      'range': tf.range,
      'interval': tf.interval,
      'includePrePost': 'false',
    });
    final body = await _get(uri, sym);
    final series = _parseChart(sym, body, tf);
    _cache[key] = series;
    return series;
  }

  Future<List<SymbolSearchResult>> search(String query) async {
    final q = query.trim();
    if (q.isEmpty) return const [];
    final uri = Uri.https('query1.finance.yahoo.com', '/v1/finance/search', {
      'q': q,
      'quotesCount': '12',
      'newsCount': '0',
      'listsCount': '0',
    });
    final body = await _get(uri, q);
    try {
      final json = jsonDecode(body) as Map<String, dynamic>;
      final quotes = (json['quotes'] as List?) ?? const [];
      return quotes
          .whereType<Map>()
          .where((m) => m['symbol'] != null && (m['quoteType'] == 'EQUITY' || m['quoteType'] == 'ETF'))
          .map((m) => SymbolSearchResult(
                m['symbol'].toString(),
                (m['shortname'] ?? m['longname'] ?? m['symbol']).toString(),
                (m['exchDisp'] ?? m['exchange'] ?? '').toString(),
                m['quoteType'].toString(),
              ))
          .toList();
    } catch (_) {
      return const [];
    }
  }

  Future<String> _get(Uri uri, String what) async {
    final http.Response res;
    try {
      res = await _client.get(uri, headers: _headers).timeout(const Duration(seconds: 20));
    } catch (_) {
      throw MarketDataException('Network error. Check your connection and try again.');
    }
    if (res.statusCode == 404) throw MarketDataException('"$what" was not found.');
    if (res.statusCode == 429) {
      throw MarketDataException('Too many requests. Please wait a minute and retry.');
    }
    if (res.statusCode != 200) {
      throw MarketDataException('Market data unavailable (HTTP ${res.statusCode}).');
    }
    return res.body;
  }

  MarketSeries _parseChart(String sym, String body, Timeframe tf) {
    final Map<String, dynamic> json;
    try {
      json = jsonDecode(body) as Map<String, dynamic>;
    } catch (_) {
      throw MarketDataException('Unexpected response from data provider.');
    }
    final chart = json['chart'] as Map<String, dynamic>?;
    final error = chart?['error'];
    if (error is Map && error['description'] != null) {
      throw MarketDataException(error['description'].toString());
    }
    final results = chart?['result'] as List?;
    if (results == null || results.isEmpty) throw MarketDataException('No data for "$sym".');
    final r = results.first as Map<String, dynamic>;
    final meta = (r['meta'] as Map?) ?? const {};
    final timestamps = (r['timestamp'] as List?) ?? const [];
    final quotes = ((r['indicators'] as Map?)?['quote'] as List?) ?? const [];
    if (timestamps.isEmpty || quotes.isEmpty) {
      throw MarketDataException('No price history for "$sym".');
    }
    final q = quotes.first as Map;
    final o = (q['open'] as List?) ?? const [];
    final h = (q['high'] as List?) ?? const [];
    final l = (q['low'] as List?) ?? const [];
    final c = (q['close'] as List?) ?? const [];
    final v = (q['volume'] as List?) ?? const [];

    final candles = <Candle>[];
    for (var i = 0; i < timestamps.length; i++) {
      if (i >= o.length || i >= h.length || i >= l.length || i >= c.length) break;
      final oi = o[i], hi = h[i], li = l[i], ci = c[i];
      if (oi == null || hi == null || li == null || ci == null) continue;
      final vi = i < v.length ? v[i] : null;
      candles.add(Candle(
        time: DateTime.fromMillisecondsSinceEpoch((timestamps[i] as num).toInt() * 1000, isUtc: true),
        open: (oi as num).toDouble(),
        high: (hi as num).toDouble(),
        low: (li as num).toDouble(),
        close: (ci as num).toDouble(),
        volume: (vi as num?)?.toDouble() ?? 0,
      ));
    }
    if (candles.isEmpty) throw MarketDataException('No valid prices for "$sym".');

    final now = DateTime.now().toUtc();
    final confirmed = _lastBarComplete(candles.last, meta, tf, now) ? candles.length : candles.length - 1;

    return MarketSeries(
      symbol: sym,
      name: (meta['longName'] ?? meta['shortName'] ?? sym).toString(),
      currency: (meta['currency'] ?? 'USD').toString(),
      exchange: (meta['fullExchangeName'] ?? meta['exchangeName'] ?? '').toString(),
      candles: candles,
      confirmedCount: confirmed,
      fetchedAt: DateTime.now(),
    );
  }

  /// A bar is complete once its session (daily) or its hour (hourly) is over.
  bool _lastBarComplete(Candle last, Map meta, Timeframe tf, DateTime now) {
    final regular = (meta['currentTradingPeriod'] as Map?)?['regular'] as Map?;
    final startS = regular?['start'] as num?;
    final endS = regular?['end'] as num?;
    if (startS == null || endS == null) {
      // Unknown session: be conservative for intraday, lenient for daily.
      return tf == Timeframe.daily || last.time.add(const Duration(hours: 1)).isBefore(now);
    }
    final start = DateTime.fromMillisecondsSinceEpoch(startS.toInt() * 1000, isUtc: true);
    final end = DateTime.fromMillisecondsSinceEpoch(endS.toInt() * 1000, isUtc: true);
    final sessionOpen = !now.isBefore(start) && now.isBefore(end);
    if (tf == Timeframe.daily) {
      return !(sessionOpen && !last.time.isBefore(start));
    }
    final barEnd = last.time.add(const Duration(hours: 1));
    final effectiveEnd = barEnd.isBefore(end) ? barEnd : end;
    return !now.isBefore(effectiveEnd);
  }
}
