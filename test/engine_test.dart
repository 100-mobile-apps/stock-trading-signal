import 'dart:math' as math;

import 'package:flutter_test/flutter_test.dart';
import 'package:signal_lab/engine/analyzer.dart';
import 'package:signal_lab/engine/backtest.dart';
import 'package:signal_lab/engine/indicators.dart';
import 'package:signal_lab/engine/strategy.dart';
import 'package:signal_lab/engine/portfolio.dart';
import 'package:signal_lab/models/candle.dart';
import 'package:signal_lab/models/profile.dart';
import 'package:signal_lab/widgets/signal_widgets.dart';

/// Rising trend with a sine-wave wiggle so MACD crosses regularly.
List<Candle> synthetic({int n = 600, double drift = 0.15, double amp = 4}) {
  final out = <Candle>[];
  var prev = 100.0;
  for (var i = 0; i < n; i++) {
    final close = 100 + drift * i + amp * math.sin(i / 6);
    final open = prev;
    out.add(Candle(
      time: DateTime.utc(2020).add(Duration(days: i)),
      open: open,
      high: math.max(open, close) + 0.5,
      low: math.min(open, close) - 0.5,
      close: close,
      volume: 1000 + (i % 7) * 100,
    ));
    prev = close;
  }
  return out;
}

MarketSeries seriesOf(List<Candle> candles) => MarketSeries(
      symbol: 'TEST',
      name: 'Test',
      currency: 'USD',
      exchange: '',
      candles: candles,
      confirmedCount: candles.length,
      fetchedAt: DateTime(2024),
    );

void main() {
  group('indicators', () {
    test('EMA and SMA of a constant series equal the constant', () {
      final v = List<double>.filled(50, 7);
      expect(ema(v, 10).last, closeTo(7, 1e-9));
      expect(sma(v, 10).last, closeTo(7, 1e-9));
      expect(ema(v, 10)[8], isNull);
    });

    test('RSI is 100 for a strictly rising series and 0 for falling', () {
      final up = List<double>.generate(40, (i) => i.toDouble());
      final down = List<double>.generate(40, (i) => 40 - i.toDouble());
      expect(rsi(up).last, 100);
      expect(rsi(down).last, closeTo(0, 1e-9));
    });

    test('ATR of fixed-range bars equals the range', () {
      final c = List.generate(
        30,
        (i) => Candle(time: DateTime.utc(2020, 1, i + 1), open: 10, high: 11, low: 9, close: 10, volume: 1),
      );
      expect(atr(c).last, closeTo(2, 1e-9));
    });
  });

  group('signals + backtest', () {
    test('uptrend produces buy signals only, with sane levels', () {
      final candles = synthetic();
      const p = StrategyParams();
      final ind = Indicators.compute(candles, p);
      final sigs = const SignalEngine().generate(candles, ind);
      expect(sigs, isNotEmpty);
      for (final s in sigs) {
        expect(s.isBuy, isTrue);
        expect(s.stopLoss, lessThan(s.price));
        expect(s.takeProfit1, greaterThan(s.price));
        expect(s.takeProfit2, greaterThan(s.takeProfit1));
        expect(s.strength, inInclusiveRange(2, 4));
        expect(s.index, greaterThanOrEqualTo(ind.firstReadyIndex));
      }
    });

    test('backtest never overlaps trades and enters after the signal bar', () {
      final candles = synthetic();
      final ind = Indicators.compute(candles, const StrategyParams());
      final sigs = const SignalEngine().generate(candles, ind);
      final bt = const Backtester().run(candles, sigs, startIndex: ind.firstReadyIndex);
      expect(bt.total, greaterThan(0));
      var lastExit = -1;
      for (final t in bt.trades) {
        expect(t.entryIndex, t.signal.index + 1);
        expect(t.entryIndex, greaterThan(lastExit));
        expect(t.exitIndex!, greaterThanOrEqualTo(t.entryIndex));
        expect(t.exitIndex! - t.entryIndex, lessThan(const StrategyParams().maxHoldBars));
        lastExit = t.exitIndex!;
      }
      expect(bt.winRate, inInclusiveRange(0, 100));
      expect(bt.maxDrawdownPct, greaterThanOrEqualTo(0));
    });

    test('stop-loss wins ties when both levels are hit in one bar', () {
      final candles = <Candle>[
        Candle(time: DateTime.utc(2020, 1, 1), open: 100, high: 100, low: 100, close: 100, volume: 1),
        // Huge range bar touching both SL (95) and TP (110).
        Candle(time: DateTime.utc(2020, 1, 2), open: 100, high: 120, low: 80, close: 100, volume: 1),
      ];
      final sig = Signal(
        index: 0,
        time: candles[0].time,
        type: SignalType.buy,
        price: 100,
        stopLoss: 95,
        takeProfit1: 105,
        takeProfit2: 110,
        strength: 2,
        reasons: const [],
      );
      final bt = const Backtester().run(candles, [sig], startIndex: 0);
      expect(bt.trades.single.exitReason, ExitReason.stopLoss);
      expect(bt.trades.single.returnPct!, lessThan(0));
    });

    test('gap through take-profit fills at the open', () {
      final candles = <Candle>[
        Candle(time: DateTime.utc(2020, 1, 1), open: 100, high: 100, low: 100, close: 100, volume: 1),
        Candle(time: DateTime.utc(2020, 1, 2), open: 100, high: 101, low: 99, close: 100, volume: 1),
        Candle(time: DateTime.utc(2020, 1, 3), open: 115, high: 116, low: 114, close: 115, volume: 1),
      ];
      final sig = Signal(
        index: 0,
        time: candles[0].time,
        type: SignalType.buy,
        price: 100,
        stopLoss: 95,
        takeProfit1: 105,
        takeProfit2: 110,
        strength: 2,
        reasons: const [],
      );
      final t = const Backtester().run(candles, [sig], startIndex: 0).trades.single;
      expect(t.exitReason, ExitReason.takeProfit);
      expect(t.exitPrice, 115);
    });

    test('short signals are ignored unless allowed', () {
      final candles = synthetic(drift: -0.15).map((c) => c).toList();
      final series = seriesOf(candles);
      final longOnly = Analyzer.analyze(series);
      final withShorts = Analyzer.analyze(series, allowShort: true);
      expect(longOnly.signals.every((s) => !s.isBuy), isTrue);
      expect(longOnly.backtest.total, 0);
      expect(withShorts.backtest.total, greaterThan(0));
    });
  });

  group('app logic', () {
    test('only confirmed bars are analysed (no repainting)', () {
      final candles = synthetic();
      final live = MarketSeries(
        symbol: 'T', name: 'T', currency: 'USD', exchange: '',
        candles: candles, confirmedCount: candles.length - 1, fetchedAt: DateTime(2024),
      );
      final a = Analyzer.analyze(live);
      expect(a.candles.length, candles.length - 1);
      expect(a.signals.every((s) => s.index < candles.length - 1), isTrue);
    });

    test('R-multiples: stop-loss is about -1R, target about +2R', () {
      final a = Analyzer.analyze(seriesOf(synthetic()));
      for (final t in a.backtest.trades) {
        if (t.exitReason == ExitReason.stopLoss) expect(t.rMultiple!, lessThan(-0.9));
        if (t.exitReason == ExitReason.takeProfit) expect(t.rMultiple!, greaterThan(1.8));
      }
    });

    test('position size risks exactly the chosen amount', () {
      const p = RiskProfile(accountSize: 10000, riskPct: 1, fractionalShares: false);
      const lv = Levels(100, 95, 107.5, 115, false);
      final plan = PositionPlan.compute(p, lv);
      expect(plan.shares, 20); // $100 risk / $5 per share
      expect(plan.lossAtStop, closeTo(100, 1e-9));
      expect(plan.profitT2, closeTo(300, 1e-9));
      expect(plan.cappedByAccount, isFalse);
    });

    test('position size is capped by account size (no leverage)', () {
      const p = RiskProfile(accountSize: 1000, riskPct: 3);
      const lv = Levels(100, 99.5, 101, 102, false);
      final plan = PositionPlan.compute(p, lv);
      expect(plan.capital, closeTo(1000, 1e-6));
      expect(plan.cappedByAccount, isTrue);
    });

    test('portfolio stats combine all symbols', () {
      final a = Analyzer.analyze(seriesOf(synthetic()));
      final b = Analyzer.analyze(seriesOf(synthetic(amp: 6)));
      final pf = PortfolioStats.from([a, b], riskPct: 1);
      expect(pf.total, a.backtest.total + b.backtest.total);
      expect(pf.equity.length, pf.total + 1);
      expect(pf.maxDrawdownPct, greaterThanOrEqualTo(0));
    });
  });
}
