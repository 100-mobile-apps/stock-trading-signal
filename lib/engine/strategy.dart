import '../models/candle.dart';
import 'indicators.dart' as calc;

enum SignalType { buy, sell }

class StrategyParams {
  final int fastEma;
  final int slowEma;
  final int rsiPeriod;
  final int atrPeriod;

  /// Stop-loss distance in ATR multiples.
  final double stopAtr;

  /// First and final take-profit distances in ATR multiples.
  final double tp1Atr;
  final double tp2Atr;

  /// Close a trade at market after this many bars if neither SL nor TP hit.
  final int maxHoldBars;

  /// Round-trip trading cost (commission + slippage), in percent.
  final double costPct;

  const StrategyParams({
    this.fastEma = 50,
    this.slowEma = 200,
    this.rsiPeriod = 14,
    this.atrPeriod = 14,
    this.stopAtr = 1.5,
    this.tp1Atr = 1.5,
    this.tp2Atr = 3.0,
    this.maxHoldBars = 20,
    this.costPct = 0.1,
  });

  double get rewardRisk => tp2Atr / stopAtr;
}

class Indicators {
  final List<double?> emaFast;
  final List<double?> emaSlow;
  final List<double?> rsi;
  final calc.MacdResult macd;
  final List<double?> atr;
  final List<double?> volumeAvg;

  const Indicators({
    required this.emaFast,
    required this.emaSlow,
    required this.rsi,
    required this.macd,
    required this.atr,
    required this.volumeAvg,
  });

  factory Indicators.compute(List<Candle> candles, StrategyParams p) {
    final closes = candles.map((c) => c.close).toList();
    final vols = candles.map((c) => c.volume).toList();
    return Indicators(
      emaFast: calc.ema(closes, p.fastEma),
      emaSlow: calc.ema(closes, p.slowEma),
      rsi: calc.rsi(closes, p.rsiPeriod),
      macd: calc.macd(closes),
      atr: calc.atr(candles, p.atrPeriod),
      volumeAvg: calc.sma(vols, 20),
    );
  }

  /// First bar where every indicator is available.
  int get firstReadyIndex {
    for (var i = 1; i < emaSlow.length; i++) {
      if (_ready(i) && _ready(i - 1)) return i;
    }
    return emaSlow.length;
  }

  bool _ready(int i) =>
      emaFast[i] != null &&
      emaSlow[i] != null &&
      rsi[i] != null &&
      macd.line[i] != null &&
      macd.signal[i] != null &&
      atr[i] != null;
}

class Signal {
  final int index;
  final DateTime time;
  final SignalType type;

  /// Reference price: the close of the signal bar.
  final double price;
  final double stopLoss;
  final double takeProfit1;
  final double takeProfit2;

  /// 2..4 — how many confirmations lined up.
  final int strength;
  final List<String> reasons;

  const Signal({
    required this.index,
    required this.time,
    required this.type,
    required this.price,
    required this.stopLoss,
    required this.takeProfit1,
    required this.takeProfit2,
    required this.strength,
    required this.reasons,
  });

  bool get isBuy => type == SignalType.buy;
  double get risk => (price - stopLoss).abs();
}

/// Trend-following momentum strategy:
///  * Trend filter: price and 50-EMA on the same side of the 200-EMA.
///  * Trigger: MACD line crosses its signal line in the trend direction.
///  * Filter: RSI not overextended.
///  * Levels: ATR-based stop-loss and take-profits (2:1 reward/risk).
class SignalEngine {
  final StrategyParams params;
  const SignalEngine([this.params = const StrategyParams()]);

  List<Signal> generate(List<Candle> candles, Indicators ind) {
    final out = <Signal>[];
    final start = ind.firstReadyIndex;
    for (var i = start; i < candles.length; i++) {
      final ml = ind.macd.line[i]!, ms = ind.macd.signal[i]!;
      final pl = ind.macd.line[i - 1]!, ps = ind.macd.signal[i - 1]!;
      final crossUp = pl <= ps && ml > ms;
      final crossDown = pl >= ps && ml < ms;
      if (!crossUp && !crossDown) continue;

      final c = candles[i];
      final fast = ind.emaFast[i]!, slow = ind.emaSlow[i]!;
      final r = ind.rsi[i]!;
      final a = ind.atr[i]!;
      if (a <= 0) continue;
      final va = ind.volumeAvg[i];
      final volumeUp = va != null && va > 0 && c.volume > va;

      if (crossUp && c.close > slow && fast > slow && r >= 40 && r <= 70) {
        final reasons = <String>[
          'Uptrend: price and 50-EMA above 200-EMA',
          'MACD crossed above its signal line',
          'RSI ${r.toStringAsFixed(0)} (not overbought)',
        ];
        var strength = 2;
        if (volumeUp) {
          strength++;
          reasons.add('Volume above 20-bar average');
        }
        if (r >= 50 && r <= 65) {
          strength++;
          reasons.add('RSI in the healthy momentum zone (50–65)');
        }
        out.add(Signal(
          index: i,
          time: c.time,
          type: SignalType.buy,
          price: c.close,
          stopLoss: c.close - params.stopAtr * a,
          takeProfit1: c.close + params.tp1Atr * a,
          takeProfit2: c.close + params.tp2Atr * a,
          strength: strength,
          reasons: reasons,
        ));
      } else if (crossDown && c.close < slow && fast < slow && r >= 30 && r <= 60) {
        final reasons = <String>[
          'Downtrend: price and 50-EMA below 200-EMA',
          'MACD crossed below its signal line',
          'RSI ${r.toStringAsFixed(0)} (not oversold)',
        ];
        var strength = 2;
        if (volumeUp) {
          strength++;
          reasons.add('Volume above 20-bar average');
        }
        if (r >= 35 && r <= 50) {
          strength++;
          reasons.add('RSI in the bearish momentum zone (35–50)');
        }
        out.add(Signal(
          index: i,
          time: c.time,
          type: SignalType.sell,
          price: c.close,
          stopLoss: c.close + params.stopAtr * a,
          takeProfit1: c.close - params.tp1Atr * a,
          takeProfit2: c.close - params.tp2Atr * a,
          strength: strength,
          reasons: reasons,
        ));
      }
    }
    return out;
  }
}
