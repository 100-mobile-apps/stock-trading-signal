import '../models/candle.dart';
import 'backtest.dart';
import 'strategy.dart';

enum SignalStatus { newBuy, newSell, activeBuy, activeSell, bearishAvoid, noSignal }

extension SignalStatusX on SignalStatus {
  String get label => switch (this) {
        SignalStatus.newBuy => 'NEW BUY',
        SignalStatus.newSell => 'NEW SELL',
        SignalStatus.activeBuy => 'BUY ACTIVE',
        SignalStatus.activeSell => 'SELL ACTIVE',
        SignalStatus.bearishAvoid => 'BEARISH',
        SignalStatus.noSignal => 'NO SETUP',
      };

  /// Lower = more important (used for sorting).
  int get priority => switch (this) {
        SignalStatus.newBuy => 0,
        SignalStatus.newSell => 1,
        SignalStatus.activeBuy => 2,
        SignalStatus.activeSell => 3,
        SignalStatus.bearishAvoid => 4,
        SignalStatus.noSignal => 5,
      };

  bool get isLive => this != SignalStatus.noSignal;
  bool get isNew => this == SignalStatus.newBuy || this == SignalStatus.newSell;
}

enum OutcomeKind { win, loss, open, skipped, notTraded }

class SignalOutcome {
  final Signal signal;
  final OutcomeKind kind;
  final Trade? trade;
  const SignalOutcome(this.signal, this.kind, this.trade);

  String get label => switch (kind) {
        OutcomeKind.win => trade?.exitReason == ExitReason.takeProfit ? 'Target hit' : 'Closed in profit',
        OutcomeKind.loss => trade?.exitReason == ExitReason.stopLoss ? 'Stopped out' : 'Closed at a loss',
        OutcomeKind.open => trade == null ? 'Awaiting entry' : 'In progress',
        OutcomeKind.skipped => 'Skipped (already in a trade)',
        OutcomeKind.notTraded => 'Bearish warning (shorts off)',
      };
}

class Analysis {
  final MarketSeries series;

  /// The closed bars the analysis was computed on (indices below refer to these).
  final List<Candle> candles;
  final Indicators indicators;
  final List<Signal> signals;
  final BacktestResult backtest;
  final SignalStatus status;
  final Signal? currentSignal;
  final Trade? activeTrade;
  final Signal? lastSignal;
  final StrategyParams params;
  final bool allowShort;

  const Analysis({
    required this.series,
    required this.candles,
    required this.indicators,
    required this.signals,
    required this.backtest,
    required this.status,
    required this.currentSignal,
    required this.activeTrade,
    required this.lastSignal,
    required this.params,
    required this.allowShort,
  });

  String get symbol => series.symbol;
  int barsSince(Signal s) => candles.length - 1 - s.index;

  /// Stable id used for alerts, so the same signal is never announced twice.
  String keyOf(Signal s) =>
      '${series.symbol}|${s.isBuy ? 'B' : 'S'}|${s.time.millisecondsSinceEpoch}';

  List<SignalOutcome> get outcomes {
    final byIndex = {for (final t in backtest.trades) t.signal.index: t};
    final open = backtest.openTrade;
    return signals.map((s) {
      final t = byIndex[s.index];
      if (t != null) return SignalOutcome(s, t.isWin ? OutcomeKind.win : OutcomeKind.loss, t);
      if (open != null && open.signal.index == s.index) return SignalOutcome(s, OutcomeKind.open, open);
      if (!s.isBuy && !allowShort) return SignalOutcome(s, OutcomeKind.notTraded, null);
      if (s.index == candles.length - 1) return SignalOutcome(s, OutcomeKind.open, null);
      return SignalOutcome(s, OutcomeKind.skipped, null);
    }).toList();
  }
}

class Analyzer {
  static Analysis analyze(
    MarketSeries series, {
    StrategyParams params = const StrategyParams(),
    bool allowShort = false,
  }) {
    final candles = series.confirmed;
    final ind = Indicators.compute(candles, params);
    final signals = SignalEngine(params).generate(candles, ind);
    final bt = Backtester(params).run(
      candles,
      signals,
      startIndex: ind.firstReadyIndex,
      allowShort: allowShort,
    );

    final last = candles.length - 1;
    final lastSignal = signals.isEmpty ? null : signals.last;
    final open = bt.openTrade;
    final fresh = lastSignal != null && lastSignal.index == last ? lastSignal : null;
    // A fresh signal matters unless it repeats the direction of a running trade.
    final freshMatters = fresh != null && (open == null || open.signal.isBuy != fresh.isBuy);

    var status = SignalStatus.noSignal;
    Signal? current;
    if (freshMatters) {
      current = fresh;
      if (current.isBuy) {
        status = SignalStatus.newBuy;
      } else {
        status = allowShort ? SignalStatus.newSell : SignalStatus.bearishAvoid;
      }
    } else if (open != null) {
      current = open.signal;
      status = current.isBuy ? SignalStatus.activeBuy : SignalStatus.activeSell;
    } else if (lastSignal != null && !lastSignal.isBuy && !allowShort && last - lastSignal.index <= 5) {
      status = SignalStatus.bearishAvoid;
      current = lastSignal;
    }

    return Analysis(
      series: series,
      candles: candles,
      indicators: ind,
      signals: signals,
      backtest: bt,
      status: status,
      currentSignal: current,
      activeTrade: open,
      lastSignal: lastSignal,
      params: params,
      allowShort: allowShort,
    );
  }
}
