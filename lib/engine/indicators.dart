import '../models/candle.dart';

/// All indicator functions return a list aligned with the input,
/// with `null` where there is not yet enough data.

List<double?> sma(List<double> values, int period) {
  final out = List<double?>.filled(values.length, null);
  if (period <= 0 || values.length < period) return out;
  var sum = 0.0;
  for (var i = 0; i < values.length; i++) {
    sum += values[i];
    if (i >= period) sum -= values[i - period];
    if (i >= period - 1) out[i] = sum / period;
  }
  return out;
}

List<double?> ema(List<double> values, int period) {
  final out = List<double?>.filled(values.length, null);
  if (period <= 0 || values.length < period) return out;
  var sum = 0.0;
  for (var i = 0; i < period; i++) {
    sum += values[i];
  }
  var prev = sum / period; // seed with SMA
  out[period - 1] = prev;
  final k = 2 / (period + 1);
  for (var i = period; i < values.length; i++) {
    prev = values[i] * k + prev * (1 - k);
    out[i] = prev;
  }
  return out;
}

/// Wilder's RSI.
List<double?> rsi(List<double> closes, [int period = 14]) {
  final out = List<double?>.filled(closes.length, null);
  if (closes.length <= period) return out;
  var gain = 0.0, loss = 0.0;
  for (var i = 1; i <= period; i++) {
    final ch = closes[i] - closes[i - 1];
    if (ch > 0) {
      gain += ch;
    } else {
      loss -= ch;
    }
  }
  var avgGain = gain / period;
  var avgLoss = loss / period;
  out[period] = _rsiValue(avgGain, avgLoss);
  for (var i = period + 1; i < closes.length; i++) {
    final ch = closes[i] - closes[i - 1];
    final g = ch > 0 ? ch : 0.0;
    final l = ch < 0 ? -ch : 0.0;
    avgGain = (avgGain * (period - 1) + g) / period;
    avgLoss = (avgLoss * (period - 1) + l) / period;
    out[i] = _rsiValue(avgGain, avgLoss);
  }
  return out;
}

double _rsiValue(double avgGain, double avgLoss) {
  if (avgLoss == 0) return avgGain == 0 ? 50 : 100;
  final rs = avgGain / avgLoss;
  return 100 - 100 / (1 + rs);
}

class MacdResult {
  final List<double?> line;
  final List<double?> signal;
  final List<double?> histogram;
  const MacdResult(this.line, this.signal, this.histogram);
}

MacdResult macd(List<double> closes, {int fast = 12, int slow = 26, int signalPeriod = 9}) {
  final n = closes.length;
  final ef = ema(closes, fast);
  final es = ema(closes, slow);
  final line = List<double?>.filled(n, null);
  for (var i = 0; i < n; i++) {
    final a = ef[i], b = es[i];
    if (a != null && b != null) line[i] = a - b;
  }
  final signal = List<double?>.filled(n, null);
  final hist = List<double?>.filled(n, null);
  final start = line.indexWhere((x) => x != null);
  if (start >= 0) {
    final sub = line.sublist(start).map((x) => x!).toList();
    final sig = ema(sub, signalPeriod);
    for (var j = 0; j < sig.length; j++) {
      final s = sig[j];
      if (s == null) continue;
      signal[start + j] = s;
      hist[start + j] = line[start + j]! - s;
    }
  }
  return MacdResult(line, signal, hist);
}

/// Wilder's Average True Range.
List<double?> atr(List<Candle> candles, [int period = 14]) {
  final n = candles.length;
  final out = List<double?>.filled(n, null);
  if (n < period) return out;
  final tr = List<double>.filled(n, 0);
  for (var i = 0; i < n; i++) {
    final c = candles[i];
    if (i == 0) {
      tr[i] = c.high - c.low;
    } else {
      final pc = candles[i - 1].close;
      final a = c.high - c.low;
      final b = (c.high - pc).abs();
      final d = (c.low - pc).abs();
      tr[i] = a > b ? (a > d ? a : d) : (b > d ? b : d);
    }
  }
  var sum = 0.0;
  for (var i = 0; i < period; i++) {
    sum += tr[i];
  }
  var prev = sum / period;
  out[period - 1] = prev;
  for (var i = period; i < n; i++) {
    prev = (prev * (period - 1) + tr[i]) / period;
    out[i] = prev;
  }
  return out;
}
