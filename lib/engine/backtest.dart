import 'dart:math' as math;

import '../models/candle.dart';
import 'strategy.dart';

enum ExitReason { takeProfit, stopLoss, timeLimit }

extension ExitReasonX on ExitReason {
  String get label => switch (this) {
        ExitReason.takeProfit => 'Take-profit',
        ExitReason.stopLoss => 'Stop-loss',
        ExitReason.timeLimit => 'Time exit',
      };
}

class Trade {
  final Signal signal;
  final int entryIndex;
  final DateTime entryTime;
  final double entryPrice;
  final double stopLoss;
  final double takeProfit;

  final int? exitIndex;
  final DateTime? exitTime;
  final double? exitPrice;
  final ExitReason? exitReason;

  /// Net return after costs, in percent. Null while the trade is open.
  final double? returnPct;

  /// Result in units of initial risk (1R = the stop-loss distance), after costs.
  final double? rMultiple;

  const Trade({
    required this.signal,
    required this.entryIndex,
    required this.entryTime,
    required this.entryPrice,
    required this.stopLoss,
    required this.takeProfit,
    this.exitIndex,
    this.exitTime,
    this.exitPrice,
    this.exitReason,
    this.returnPct,
    this.rMultiple,
  });

  bool get isOpen => exitIndex == null;
  bool get isWin => (returnPct ?? 0) > 0;
  bool get isLong => signal.isBuy;
}

class BacktestResult {
  final List<Trade> trades; // closed trades only
  final Trade? openTrade;
  final int barsTested;
  final DateTime? from;
  final DateTime? to;
  final double buyHoldReturnPct;

  const BacktestResult({
    required this.trades,
    required this.openTrade,
    required this.barsTested,
    required this.from,
    required this.to,
    required this.buyHoldReturnPct,
  });

  int get total => trades.length;
  double get totalR => trades.fold<double>(0, (s, t) => s + t.rMultiple!);
  double get avgR => total == 0 ? 0 : totalR / total;
  int get wins => trades.where((t) => t.isWin).length;
  int get losses => total - wins;
  double get winRate => total == 0 ? 0 : wins / total * 100;

  double get avgReturnPct =>
      total == 0 ? 0 : trades.fold<double>(0, (s, t) => s + t.returnPct!) / total;

  double get avgWinPct {
    final w = trades.where((t) => t.isWin).toList();
    return w.isEmpty ? 0 : w.fold<double>(0, (s, t) => s + t.returnPct!) / w.length;
  }

  double get avgLossPct {
    final l = trades.where((t) => !t.isWin).toList();
    return l.isEmpty ? 0 : l.fold<double>(0, (s, t) => s + t.returnPct!) / l.length;
  }

  /// Gross profit / gross loss. `double.infinity` if there were no losses.
  double get profitFactor {
    var gp = 0.0, gl = 0.0;
    for (final t in trades) {
      final r = t.returnPct!;
      if (r > 0) {
        gp += r;
      } else {
        gl -= r;
      }
    }
    if (gl == 0) return gp > 0 ? double.infinity : 0;
    return gp / gl;
  }

  /// Compounded return if every trade used the full account.
  double get totalReturnPct {
    var eq = 1.0;
    for (final t in trades) {
      eq *= 1 + t.returnPct! / 100;
    }
    return (eq - 1) * 100;
  }

  /// Largest peak-to-trough fall of the trade-by-trade equity curve.
  double get maxDrawdownPct {
    var eq = 1.0, peak = 1.0, maxDd = 0.0;
    for (final t in trades) {
      eq *= 1 + t.returnPct! / 100;
      peak = math.max(peak, eq);
      maxDd = math.max(maxDd, (peak - eq) / peak);
    }
    return maxDd * 100;
  }
}

/// Simulates the signals bar by bar with no look-ahead:
///  * Entry at the OPEN of the bar after the signal.
///  * One position at a time.
///  * If stop-loss and take-profit are both touched in the same bar, we
///    assume the stop-loss hit first (conservative).
///  * Price gaps through a level fill at the open, not at the level.
///  * Trading costs are subtracted from every trade.
class Backtester {
  final StrategyParams params;
  const Backtester([this.params = const StrategyParams()]);

  BacktestResult run(
    List<Candle> candles,
    List<Signal> signals, {
    required int startIndex,
    bool allowShort = false,
  }) {
    final trades = <Trade>[];
    Trade? open;
    var nextFree = 0;
    final last = candles.length - 1;

    for (final s in signals) {
      if (!s.isBuy && !allowShort) continue;
      final e = s.index + 1;
      if (e > last) break; // signal on the latest bar: not tradable yet
      if (e < nextFree) continue; // already in a position

      final entry = candles[e].open;
      final long = s.isBuy;
      final riskDist = (s.price - s.stopLoss).abs();
      final rewardDist = (s.takeProfit2 - s.price).abs();
      final sl = long ? entry - riskDist : entry + riskDist;
      final tp = long ? entry + rewardDist : entry - rewardDist;

      int? exitIdx;
      double? exitPx;
      ExitReason? reason;
      final limit = e + params.maxHoldBars - 1;

      for (var j = e; j <= last && j <= limit; j++) {
        final b = candles[j];
        if (long) {
          if (b.open <= sl) {
            (exitIdx, exitPx, reason) = (j, b.open, ExitReason.stopLoss);
          } else if (b.open >= tp) {
            (exitIdx, exitPx, reason) = (j, b.open, ExitReason.takeProfit);
          } else if (b.low <= sl) {
            (exitIdx, exitPx, reason) = (j, sl, ExitReason.stopLoss);
          } else if (b.high >= tp) {
            (exitIdx, exitPx, reason) = (j, tp, ExitReason.takeProfit);
          }
        } else {
          if (b.open >= sl) {
            (exitIdx, exitPx, reason) = (j, b.open, ExitReason.stopLoss);
          } else if (b.open <= tp) {
            (exitIdx, exitPx, reason) = (j, b.open, ExitReason.takeProfit);
          } else if (b.high >= sl) {
            (exitIdx, exitPx, reason) = (j, sl, ExitReason.stopLoss);
          } else if (b.low <= tp) {
            (exitIdx, exitPx, reason) = (j, tp, ExitReason.takeProfit);
          }
        }
        if (exitIdx != null) break;
        if (j == limit) {
          (exitIdx, exitPx, reason) = (j, b.close, ExitReason.timeLimit);
        }
      }

      if (exitIdx == null) {
        // Ran out of data: the trade is still live.
        open = Trade(
          signal: s,
          entryIndex: e,
          entryTime: candles[e].time,
          entryPrice: entry,
          stopLoss: sl,
          takeProfit: tp,
        );
        break;
      }

      final px = exitPx!;
      final gross = long ? (px - entry) / entry : (entry - px) / entry;
      final net = gross * 100 - params.costPct;
      final move = long ? px - entry : entry - px;
      final rMult = riskDist > 0 ? (move - entry * params.costPct / 100) / riskDist : 0.0;
      trades.add(Trade(
        signal: s,
        entryIndex: e,
        entryTime: candles[e].time,
        entryPrice: entry,
        stopLoss: sl,
        takeProfit: tp,
        exitIndex: exitIdx,
        exitTime: candles[exitIdx].time,
        exitPrice: exitPx,
        exitReason: reason,
        returnPct: net,
        rMultiple: rMult,
      ));
      nextFree = exitIdx + 1;
    }

    final hasRange = startIndex <= last && last >= 0;
    final bh = hasRange && candles[startIndex].close > 0
        ? (candles[last].close / candles[startIndex].close - 1) * 100
        : 0.0;

    return BacktestResult(
      trades: trades,
      openTrade: open,
      barsTested: hasRange ? last - startIndex + 1 : 0,
      from: hasRange ? candles[startIndex].time : null,
      to: hasRange ? candles[last].time : null,
      buyHoldReturnPct: bh,
    );
  }
}
