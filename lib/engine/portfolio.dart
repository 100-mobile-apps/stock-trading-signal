import 'dart:math' as math;

import 'analyzer.dart';
import 'backtest.dart';

class SymbolTrade {
  final String symbol;
  final Trade trade;
  const SymbolTrade(this.symbol, this.trade);
}

class SymbolPerformance {
  final String symbol;
  final int trades;
  final double winRate;
  final double totalR;
  const SymbolPerformance(this.symbol, this.trades, this.winRate, this.totalR);
}

class EquityPoint {
  final DateTime time;
  final double equity; // 1.0 = starting balance
  const EquityPoint(this.time, this.equity);
}

/// Combines every backtested trade across the watchlist, sized with the
/// user's fixed-fractional risk (e.g. 1% of the account per trade).
class PortfolioStats {
  final List<SymbolTrade> trades;
  final double riskPct;
  final List<EquityPoint> equity;
  final List<SymbolPerformance> bySymbol;

  PortfolioStats._(this.trades, this.riskPct, this.equity, this.bySymbol);

  factory PortfolioStats.from(Iterable<Analysis> analyses, {required double riskPct}) {
    final all = <SymbolTrade>[];
    final per = <SymbolPerformance>[];
    for (final a in analyses) {
      final bt = a.backtest;
      for (final t in bt.trades) {
        all.add(SymbolTrade(a.symbol, t));
      }
      if (bt.total > 0) per.add(SymbolPerformance(a.symbol, bt.total, bt.winRate, bt.totalR));
    }
    all.sort((x, y) => x.trade.exitTime!.compareTo(y.trade.exitTime!));
    per.sort((x, y) => y.totalR.compareTo(x.totalR));

    final eq = <EquityPoint>[];
    var e = 1.0;
    if (all.isNotEmpty) eq.add(EquityPoint(all.first.trade.entryTime, e));
    for (final st in all) {
      e *= 1 + st.trade.rMultiple! * riskPct / 100;
      eq.add(EquityPoint(st.trade.exitTime!, e));
    }
    return PortfolioStats._(all, riskPct, eq, per);
  }

  int get total => trades.length;
  int get wins => trades.where((t) => t.trade.isWin).length;
  double get winRate => total == 0 ? 0 : wins / total * 100;
  double get totalR => trades.fold<double>(0, (s, t) => s + t.trade.rMultiple!);
  double get avgR => total == 0 ? 0 : totalR / total;

  double get profitFactor {
    var gp = 0.0, gl = 0.0;
    for (final t in trades) {
      final r = t.trade.rMultiple!;
      if (r > 0) {
        gp += r;
      } else {
        gl -= r;
      }
    }
    if (gl == 0) return gp > 0 ? double.infinity : 0;
    return gp / gl;
  }

  double get returnPct => equity.isEmpty ? 0 : (equity.last.equity - 1) * 100;

  double get maxDrawdownPct {
    var peak = 1.0, dd = 0.0;
    for (final p in equity) {
      peak = math.max(peak, p.equity);
      dd = math.max(dd, (peak - p.equity) / peak);
    }
    return dd * 100;
  }

  int count(ExitReason r) => trades.where((t) => t.trade.exitReason == r).length;

  /// Longest run of consecutive losing trades, useful for setting expectations.
  int get longestLosingStreak {
    var best = 0, cur = 0;
    for (final t in trades) {
      if (t.trade.isWin) {
        cur = 0;
      } else {
        cur++;
        best = math.max(best, cur);
      }
    }
    return best;
  }

  DateTime? get since => trades.isEmpty ? null : trades.map((t) => t.trade.entryTime).reduce((a, b) => a.isBefore(b) ? a : b);
}
