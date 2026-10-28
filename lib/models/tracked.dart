double? _d(dynamic v) => (v as num?)?.toDouble();
DateTime? _t(dynamic v) => v is String ? DateTime.tryParse(v) : null;

/// A signal recorded by the server when it was issued, with its real outcome.
class TrackedSignal {
  final int id;
  final String symbol;
  final String tf; // '1d' | '1h'
  final bool isBuy;
  final bool isWarning; // SELL signals are published as bearish warnings
  final String status; // pending | open | won | lost | warning
  final DateTime signalTime;
  final double signalPrice;
  final double stopLoss;
  final double target1;
  final double target2;
  final int strength;
  final List<String> reasons;
  final DateTime? entryTime;
  final double? entryPrice;
  final double? liveStop;
  final double? liveTarget1;
  final double? liveTarget2;
  final DateTime? tp1HitTime;
  final DateTime? exitTime;
  final double? exitPrice;
  final String? exitReason; // target | stop | time
  final double? returnPct;
  final double? rMultiple;
  final int? barsHeld;
  final double? lastPrice;

  const TrackedSignal({
    required this.id,
    required this.symbol,
    required this.tf,
    required this.isBuy,
    required this.isWarning,
    required this.status,
    required this.signalTime,
    required this.signalPrice,
    required this.stopLoss,
    required this.target1,
    required this.target2,
    required this.strength,
    required this.reasons,
    this.entryTime,
    this.entryPrice,
    this.liveStop,
    this.liveTarget1,
    this.liveTarget2,
    this.tp1HitTime,
    this.exitTime,
    this.exitPrice,
    this.exitReason,
    this.returnPct,
    this.rMultiple,
    this.barsHeld,
    this.lastPrice,
  });

  bool get isPending => status == 'pending';
  bool get isOpen => status == 'open';
  bool get isClosed => status == 'won' || status == 'lost';
  bool get isWin => status == 'won';
  bool get intraday => tf == '1h';

  /// Levels to show: the real fill once entered, otherwise the reference levels.
  double get entry => entryPrice ?? signalPrice;
  double get stop => liveStop ?? stopLoss;
  double get t1 => liveTarget1 ?? target1;
  double get t2 => liveTarget2 ?? target2;

  /// Unrealised result for an open trade, in percent and R.
  double? get openPct => isOpen && lastPrice != null ? (lastPrice! - entry) / entry * 100 : null;
  double? get openR => isOpen && lastPrice != null ? (lastPrice! - entry) / (entry - stop).abs() : null;

  String get statusLabel => switch (status) {
        'pending' => 'Awaiting entry',
        'open' => tp1HitTime != null ? 'Open · Target 1 hit' : 'Open',
        'won' => exitReason == 'target' ? 'Target hit' : 'Closed in profit',
        'lost' => exitReason == 'stop' ? 'Stopped out' : 'Closed at a loss',
        'warning' => 'Bearish warning',
        _ => status,
      };

  factory TrackedSignal.fromApi(Map<String, dynamic> m) => TrackedSignal(
        id: (m['id'] as num).toInt(),
        symbol: m['symbol'] as String,
        tf: m['tf'] as String? ?? '1d',
        isBuy: m['direction'] == 'buy',
        isWarning: m['kind'] == 'warning',
        status: m['status'] as String? ?? 'pending',
        signalTime: _t(m['signal_time']) ?? DateTime.now(),
        signalPrice: _d(m['signal_price']) ?? 0,
        stopLoss: _d(m['stop_loss']) ?? 0,
        target1: _d(m['target1']) ?? 0,
        target2: _d(m['target2']) ?? 0,
        strength: (m['strength'] as num?)?.toInt() ?? 2,
        reasons: ((m['reasons'] as List?) ?? const []).map((e) => e.toString()).toList(),
        entryTime: _t(m['entry_time']),
        entryPrice: _d(m['entry_price']),
        liveStop: _d(m['live_stop']),
        liveTarget1: _d(m['live_target1']),
        liveTarget2: _d(m['live_target2']),
        tp1HitTime: _t(m['tp1_hit_time']),
        exitTime: _t(m['exit_time']),
        exitPrice: _d(m['exit_price']),
        exitReason: m['exit_reason'] as String?,
        returnPct: _d(m['return_pct']),
        rMultiple: _d(m['r_multiple']),
        barsHeld: (m['bars_held'] as num?)?.toInt(),
        lastPrice: _d(m['last_price']),
      );
}

class RecordStats {
  final int trades;
  final int wins;
  final int losses;
  final double winRate;
  final double totalR;
  final double avgR;
  final double? profitFactor; // null = no losses yet
  final double returnPct;
  final double maxDrawdownPct;
  final int losingStreak;
  final int targets;
  final int stops;
  final int timeExits;
  final int issued;

  const RecordStats({
    this.trades = 0,
    this.wins = 0,
    this.losses = 0,
    this.winRate = 0,
    this.totalR = 0,
    this.avgR = 0,
    this.profitFactor = 0,
    this.returnPct = 0,
    this.maxDrawdownPct = 0,
    this.losingStreak = 0,
    this.targets = 0,
    this.stops = 0,
    this.timeExits = 0,
    this.issued = 0,
  });

  factory RecordStats.fromApi(Map<String, dynamic>? m) {
    if (m == null) return const RecordStats();
    return RecordStats(
      trades: (m['trades'] as num?)?.toInt() ?? 0,
      wins: (m['wins'] as num?)?.toInt() ?? 0,
      losses: (m['losses'] as num?)?.toInt() ?? 0,
      winRate: _d(m['win_rate']) ?? 0,
      totalR: _d(m['total_r']) ?? 0,
      avgR: _d(m['avg_r']) ?? 0,
      profitFactor: _d(m['profit_factor']),
      returnPct: _d(m['return_pct']) ?? 0,
      maxDrawdownPct: _d(m['max_drawdown_pct']) ?? 0,
      losingStreak: (m['losing_streak'] as num?)?.toInt() ?? 0,
      targets: (m['targets'] as num?)?.toInt() ?? 0,
      stops: (m['stops'] as num?)?.toInt() ?? 0,
      timeExits: (m['time_exits'] as num?)?.toInt() ?? 0,
      issued: (m['issued'] as num?)?.toInt() ?? 0,
    );
  }

  String get profitFactorLabel =>
      trades == 0 ? '—' : (profitFactor == null ? '∞' : profitFactor!.toStringAsFixed(2));
}

class PeriodRow {
  final String key;
  final String label;
  final RecordStats stats;
  final int buySignals;
  final int warnings;
  const PeriodRow(this.key, this.label, this.stats, this.buySignals, this.warnings);

  factory PeriodRow.fromApi(Map<String, dynamic> m) => PeriodRow(
        m['key'] as String? ?? '',
        m['label'] as String? ?? '',
        RecordStats.fromApi(m),
        (m['buy_signals'] as num?)?.toInt() ?? 0,
        (m['warnings'] as num?)?.toInt() ?? 0,
      );
}

class SymbolResult {
  final String symbol;
  final int trades;
  final double winRate;
  final double totalR;
  const SymbolResult(this.symbol, this.trades, this.winRate, this.totalR);
}

/// The server's verified, live track record.
class TrackRecord {
  final DateTime? startedAt;
  final double riskPct;
  final int openTrades;
  final RecordStats today;
  final RecordStats week;
  final RecordStats month;
  final RecordStats all;
  final List<PeriodRow> daily;
  final List<PeriodRow> weekly;
  final List<PeriodRow> monthly;
  final List<(DateTime, double)> equity;
  final List<SymbolResult> bySymbol;

  const TrackRecord({
    required this.startedAt,
    required this.riskPct,
    required this.openTrades,
    required this.today,
    required this.week,
    required this.month,
    required this.all,
    required this.daily,
    required this.weekly,
    required this.monthly,
    required this.equity,
    required this.bySymbol,
  });

  factory TrackRecord.fromApi(Map<String, dynamic> m) {
    final s = (m['summary'] as Map?)?.cast<String, dynamic>() ?? const {};
    List<PeriodRow> rows(String k) =>
        ((m[k] as List?) ?? const []).whereType<Map>().map((e) => PeriodRow.fromApi(e.cast<String, dynamic>())).toList();
    return TrackRecord(
      startedAt: _t(m['record_started']),
      riskPct: _d(m['risk_pct']) ?? 1,
      openTrades: (m['open_trades'] as num?)?.toInt() ?? 0,
      today: RecordStats.fromApi((s['today'] as Map?)?.cast<String, dynamic>()),
      week: RecordStats.fromApi((s['week'] as Map?)?.cast<String, dynamic>()),
      month: RecordStats.fromApi((s['month'] as Map?)?.cast<String, dynamic>()),
      all: RecordStats.fromApi((s['all'] as Map?)?.cast<String, dynamic>()),
      daily: rows('daily'),
      weekly: rows('weekly'),
      monthly: rows('monthly'),
      equity: ((m['equity'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => (_t(e['t']) ?? DateTime.now(), _d(e['equity']) ?? 1.0))
          .toList(),
      bySymbol: ((m['by_symbol'] as List?) ?? const [])
          .whereType<Map>()
          .map((e) => SymbolResult(e['symbol'].toString(), (e['trades'] as num?)?.toInt() ?? 0,
              _d(e['win_rate']) ?? 0, _d(e['total_r']) ?? 0))
          .toList(),
    );
  }
}
