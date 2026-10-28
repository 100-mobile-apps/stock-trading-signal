import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../core/format.dart';
import '../core/theme.dart';
import '../engine/backtest.dart';
import '../engine/portfolio.dart';
import '../engine/strategy.dart';
import '../models/candle.dart';

// ------------------------------------------------------------------ Sparkline

class Sparkline extends StatelessWidget {
  const Sparkline(this.values, {super.key, this.width = 72, this.height = 30, this.color});
  final List<double> values;
  final double width;
  final double height;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ??
        (values.length >= 2 ? AppColors.change(values.last - values.first) : AppColors.muted);
    return SizedBox(
      width: width,
      height: height,
      child: CustomPaint(painter: _SparkPainter(values, c)),
    );
  }
}

class _SparkPainter extends CustomPainter {
  _SparkPainter(this.v, this.c);
  final List<double> v;
  final Color c;

  @override
  void paint(Canvas canvas, Size size) {
    if (v.length < 2) return;
    final lo = v.reduce(math.min), hi = v.reduce(math.max);
    final span = hi - lo == 0 ? 1 : hi - lo;
    final path = Path();
    for (var i = 0; i < v.length; i++) {
      final x = i / (v.length - 1) * size.width;
      final y = size.height - (v[i] - lo) / span * (size.height - 2) - 1;
      i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
    }
    final fill = Path.from(path)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(
      fill,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [c.withValues(alpha: 0.28), c.withValues(alpha: 0)],
        ).createShader(Offset.zero & size),
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = c
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.6
        ..strokeJoin = StrokeJoin.round,
    );
  }

  @override
  bool shouldRepaint(covariant _SparkPainter o) => o.v != v || o.c != c;
}

// --------------------------------------------------------------- Candle chart

class CandleChart extends StatefulWidget {
  const CandleChart({
    super.key,
    required this.candles,
    required this.indicators,
    required this.signals,
    this.activeTrade,
    this.visibleBars = 90,
    this.intraday = false,
    this.height = 300,
  });

  /// All bars (the last one may still be forming).
  final List<Candle> candles;
  final Indicators indicators;
  final List<Signal> signals;
  final Trade? activeTrade;
  final int visibleBars;
  final bool intraday;
  final double height;

  @override
  State<CandleChart> createState() => _CandleChartState();
}

class _CandleChartState extends State<CandleChart> {
  int? _cursor; // absolute candle index under the finger

  void _updateCursor(Offset local, double width) {
    final n = math.min(widget.visibleBars, widget.candles.length);
    final start = widget.candles.length - n;
    final plotW = width - _CandlePainter.rightAxis;
    final i = (local.dx / plotW * n).floor().clamp(0, n - 1);
    setState(() => _cursor = start + i);
  }

  @override
  Widget build(BuildContext context) {
    final candles = widget.candles;
    final cur = _cursor != null && _cursor! < candles.length ? candles[_cursor!] : null;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        SizedBox(
          height: 34,
          child: cur == null
              ? const Align(
                  alignment: Alignment.centerLeft,
                  child: Text('Press and drag on the chart to inspect prices',
                      style: TextStyle(color: AppColors.faint, fontSize: 11.5)),
                )
              : _OhlcRow(candle: cur, intraday: widget.intraday),
        ),
        LayoutBuilder(
          builder: (context, c) => GestureDetector(
            behavior: HitTestBehavior.opaque,
            onLongPressStart: (d) => _updateCursor(d.localPosition, c.maxWidth),
            onLongPressMoveUpdate: (d) => _updateCursor(d.localPosition, c.maxWidth),
            onLongPressEnd: (_) => setState(() => _cursor = null),
            onHorizontalDragStart: (d) => _updateCursor(d.localPosition, c.maxWidth),
            onHorizontalDragUpdate: (d) => _updateCursor(d.localPosition, c.maxWidth),
            onHorizontalDragEnd: (_) => setState(() => _cursor = null),
            child: SizedBox(
              height: widget.height,
              width: c.maxWidth,
              child: CustomPaint(
                painter: _CandlePainter(
                  candles: candles,
                  ind: widget.indicators,
                  signals: widget.signals,
                  trade: widget.activeTrade,
                  bars: widget.visibleBars,
                  cursor: _cursor,
                  intraday: widget.intraday,
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: Gap.sm),
        const Wrap(
          spacing: 14,
          runSpacing: 4,
          children: [
            _Legend(AppColors.emaFast, 'EMA 50'),
            _Legend(AppColors.emaSlow, 'EMA 200'),
            _Legend(AppColors.bull, 'Buy signal', marker: true),
            _Legend(AppColors.bear, 'Sell signal', marker: true),
          ],
        ),
      ],
    );
  }
}

class _OhlcRow extends StatelessWidget {
  const _OhlcRow({required this.candle, required this.intraday});
  final Candle candle;
  final bool intraday;

  @override
  Widget build(BuildContext context) {
    final up = candle.close >= candle.open;
    final c = up ? AppColors.bull : AppColors.bear;
    Widget kv(String k, double v) => Padding(
          padding: const EdgeInsets.only(right: 10),
          child: RichText(
            text: TextSpan(children: [
              TextSpan(text: '$k ', style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
              TextSpan(text: fmtPrice(v), style: numStyle(11.5, color: c)),
            ]),
          ),
        );
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(
        children: [
          Text(fmtDate(candle.time, withTime: intraday),
              style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w600)),
          const SizedBox(width: 10),
          kv('O', candle.open),
          kv('H', candle.high),
          kv('L', candle.low),
          kv('C', candle.close),
          Text('Vol ${fmtCompact(candle.volume)}', style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
        ],
      ),
    );
  }
}

class _Legend extends StatelessWidget {
  const _Legend(this.color, this.label, {this.marker = false});
  final Color color;
  final String label;
  final bool marker;

  @override
  Widget build(BuildContext context) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          marker
              ? Icon(Icons.change_history, size: 11, color: color)
              : Container(width: 12, height: 3, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(2))),
          const SizedBox(width: 5),
          Text(label, style: const TextStyle(fontSize: 11, color: AppColors.muted)),
        ],
      );
}

class _CandlePainter extends CustomPainter {
  _CandlePainter({
    required this.candles,
    required this.ind,
    required this.signals,
    required this.trade,
    required this.bars,
    required this.cursor,
    required this.intraday,
  });

  static const rightAxis = 54.0;

  final List<Candle> candles;
  final Indicators ind;
  final List<Signal> signals;
  final Trade? trade;
  final int bars;
  final int? cursor;
  final bool intraday;

  @override
  void paint(Canvas canvas, Size size) {
    if (candles.length < 2) return;
    final n = math.min(bars, candles.length);
    final start = candles.length - n;
    final w = size.width - rightAxis;
    final volH = size.height * 0.16;
    final priceH = size.height - volH - 18; // leave room for date labels
    final slot = w / n;
    final body = math.max(1.0, slot * 0.62);

    double? at(List<double?> xs, int i) => i < xs.length ? xs[i] : null;

    var lo = double.infinity, hi = -double.infinity, maxVol = 0.0;
    void inc(double? v) {
      if (v == null) return;
      lo = math.min(lo, v);
      hi = math.max(hi, v);
    }

    for (var i = start; i < candles.length; i++) {
      inc(candles[i].low);
      inc(candles[i].high);
      inc(at(ind.emaFast, i));
      inc(at(ind.emaSlow, i));
      maxVol = math.max(maxVol, candles[i].volume);
    }
    final t = trade;
    if (t != null) {
      inc(t.stopLoss);
      inc(t.takeProfit);
    }
    if (hi <= lo) hi = lo + 1;
    final pad = (hi - lo) * 0.06;
    lo -= pad;
    hi += pad;

    double x(int i) => (i - start) * slot + slot / 2;
    double y(double v) => priceH - (v - lo) / (hi - lo) * priceH;

    // Grid + price axis
    final grid = Paint()..color = AppColors.border.withValues(alpha: 0.55);
    for (var k = 0; k <= 4; k++) {
      final v = lo + (hi - lo) * k / 4;
      final yy = y(v);
      _dashed(canvas, Offset(0, yy), w, grid, dash: 3);
      _label(canvas, fmtPrice(v), Offset(w + 6, yy - 7), AppColors.faint);
    }

    // Date labels
    for (final k in [0, n ~/ 2, n - 1]) {
      final i = start + k;
      final label = fmtDate(candles[i].time, withYear: !intraday);
      final tp = _tp(label, AppColors.faint);
      final lx = (x(i) - tp.width / 2).clamp(0.0, w - tp.width);
      tp.paint(canvas, Offset(lx, size.height - 14));
    }

    // Volume
    if (maxVol > 0) {
      for (var i = start; i < candles.length; i++) {
        final c = candles[i];
        final h = c.volume / maxVol * (volH - 4);
        final col = (c.close >= c.open ? AppColors.bull : AppColors.bear).withValues(alpha: 0.28);
        canvas.drawRect(
          Rect.fromLTWH(x(i) - body / 2, priceH + volH - h, body, h),
          Paint()..color = col,
        );
      }
    }

    // EMAs
    void line(List<double?> vals, Color col) {
      final p = Path();
      var started = false;
      for (var i = start; i < candles.length; i++) {
        final v = at(vals, i);
        if (v == null) continue;
        if (!started) {
          p.moveTo(x(i), y(v));
          started = true;
        } else {
          p.lineTo(x(i), y(v));
        }
      }
      canvas.drawPath(
        p,
        Paint()
          ..color = col
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.4,
      );
    }

    line(ind.emaSlow, AppColors.emaSlow);
    line(ind.emaFast, AppColors.emaFast);

    // Candles
    for (var i = start; i < candles.length; i++) {
      final c = candles[i];
      final up = c.close >= c.open;
      final col = up ? AppColors.bull : AppColors.bear;
      final live = i == candles.length - 1 && i >= ind.emaFast.length;
      final paint = Paint()..color = live ? col.withValues(alpha: 0.5) : col;
      canvas.drawLine(Offset(x(i), y(c.high)), Offset(x(i), y(c.low)), paint..strokeWidth = 1);
      final top = y(math.max(c.open, c.close));
      final bot = y(math.min(c.open, c.close));
      canvas.drawRect(
        Rect.fromLTRB(x(i) - body / 2, top, x(i) + body / 2, math.max(bot, top + 1)),
        paint,
      );
    }

    // Trade levels
    if (t != null) {
      for (final (v, col, name) in [
        (t.entryPrice, AppColors.text, 'Entry'),
        (t.stopLoss, AppColors.bear, 'Stop'),
        (t.takeProfit, AppColors.bull, 'Target'),
      ]) {
        final yy = y(v);
        _dashed(canvas, Offset(0, yy), w, Paint()..color = col.withValues(alpha: 0.8)..strokeWidth = 1);
        final tp = _tp(' $name ${fmtPrice(v)} ', const Color(0xFF0A0F1C), bold: true);
        final r = RRect.fromRectAndRadius(Rect.fromLTWH(w - tp.width - 2, yy - 8, tp.width, 16), const Radius.circular(4));
        canvas.drawRRect(r, Paint()..color = col);
        tp.paint(canvas, Offset(w - tp.width - 2, yy - 7));
      }
    }

    // Signal markers
    for (final s in signals) {
      if (s.index < start) continue;
      final c = candles[s.index];
      final px = x(s.index);
      final path = Path();
      if (s.isBuy) {
        final py = y(c.low) + 5;
        path
          ..moveTo(px, py)
          ..lineTo(px - 5, py + 9)
          ..lineTo(px + 5, py + 9)
          ..close();
      } else {
        final py = y(c.high) - 5;
        path
          ..moveTo(px, py)
          ..lineTo(px - 5, py - 9)
          ..lineTo(px + 5, py - 9)
          ..close();
      }
      canvas.drawPath(path, Paint()..color = s.isBuy ? AppColors.bull : AppColors.bear);
    }

    // Crosshair
    final cu = cursor;
    if (cu != null && cu >= start && cu < candles.length) {
      final cp = Paint()
        ..color = AppColors.text.withValues(alpha: 0.5)
        ..strokeWidth = 1;
      canvas.drawLine(Offset(x(cu), 0), Offset(x(cu), priceH + volH), cp);
      final yy = y(candles[cu].close);
      _dashed(canvas, Offset(0, yy), w, cp, dash: 4);
      final tp = _tp(fmtPrice(candles[cu].close), const Color(0xFF0A0F1C), bold: true);
      canvas.drawRRect(
        RRect.fromRectAndRadius(Rect.fromLTWH(w + 2, yy - 9, tp.width + 8, 18), const Radius.circular(4)),
        Paint()..color = AppColors.text,
      );
      tp.paint(canvas, Offset(w + 6, yy - 7));
    }
  }

  void _dashed(Canvas canvas, Offset from, double width, Paint p, {double dash = 5}) {
    for (var xx = from.dx; xx < width; xx += dash * 2) {
      canvas.drawLine(Offset(xx, from.dy), Offset(math.min(xx + dash, width), from.dy), p);
    }
  }

  TextPainter _tp(String s, Color c, {bool bold = false}) => TextPainter(
        text: TextSpan(
          text: s,
          style: TextStyle(
            color: c,
            fontSize: 10,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
            fontFeatures: tabular,
          ),
        ),
        textDirection: TextDirection.ltr,
      )..layout();

  void _label(Canvas canvas, String s, Offset o, Color c) => _tp(s, c).paint(canvas, o);

  @override
  bool shouldRepaint(covariant _CandlePainter o) =>
      o.candles != candles || o.bars != bars || o.cursor != cursor || o.trade != trade || o.ind != ind;
}

// --------------------------------------------------------------- Equity chart

class EquityChart extends StatelessWidget {
  const EquityChart(this.points, {super.key, this.height = 180});
  final List<EquityPoint> points;
  final double height;

  @override
  Widget build(BuildContext context) => SizedBox(
        height: height,
        width: double.infinity,
        child: CustomPaint(painter: _EquityPainter(points)),
      );
}

class _EquityPainter extends CustomPainter {
  _EquityPainter(this.pts);
  final List<EquityPoint> pts;

  @override
  void paint(Canvas canvas, Size size) {
    if (pts.length < 2) return;
    const axis = 46.0;
    final w = size.width - axis;
    final h = size.height - 16;
    var lo = pts.map((p) => p.equity).reduce(math.min);
    var hi = pts.map((p) => p.equity).reduce(math.max);
    lo = math.min(lo, 1.0);
    hi = math.max(hi, 1.0);
    final pad = (hi - lo) * 0.1 + 1e-6;
    lo -= pad;
    hi += pad;
    final t0 = pts.first.time.millisecondsSinceEpoch.toDouble();
    final t1 = math.max(pts.last.time.millisecondsSinceEpoch.toDouble(), t0 + 1);
    double x(DateTime t) => (t.millisecondsSinceEpoch - t0) / (t1 - t0) * w;
    double y(double v) => h - (v - lo) / (hi - lo) * h;

    // baseline (starting balance)
    final by = y(1.0);
    for (var xx = 0.0; xx < w; xx += 8) {
      canvas.drawLine(Offset(xx, by), Offset(math.min(xx + 4, w), by),
          Paint()..color = AppColors.muted.withValues(alpha: 0.5));
    }

    for (final v in [hi - pad, 1.0, lo + pad]) {
      final tp = TextPainter(
        text: TextSpan(
          text: fmtPct((v - 1) * 100, digits: 0),
          style: const TextStyle(color: AppColors.faint, fontSize: 10, fontFeatures: tabular),
        ),
        textDirection: TextDirection.ltr,
      )..layout();
      tp.paint(canvas, Offset(w + 6, y(v) - 6));
    }

    final path = Path()..moveTo(x(pts.first.time), y(pts.first.equity));
    for (final p in pts.skip(1)) {
      path.lineTo(x(p.time), y(p.equity));
    }
    final up = pts.last.equity >= 1;
    final col = up ? AppColors.bull : AppColors.bear;
    final fill = Path.from(path)
      ..lineTo(x(pts.last.time), h)
      ..lineTo(x(pts.first.time), h)
      ..close();
    canvas.drawPath(
      fill,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: [col.withValues(alpha: 0.25), col.withValues(alpha: 0)],
        ).createShader(Rect.fromLTWH(0, 0, w, h)),
    );
    canvas.drawPath(
      path,
      Paint()
        ..color = col
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round,
    );

    for (final (t, align) in [(pts.first.time, 0.0), (pts.last.time, 1.0)]) {
      final tp = TextPainter(
        text: TextSpan(text: fmtDate(t), style: const TextStyle(color: AppColors.faint, fontSize: 10)),
        textDirection: TextDirection.ltr,
      )..layout();
      tp.paint(canvas, Offset((w - tp.width) * align, size.height - 12));
    }
  }

  @override
  bool shouldRepaint(covariant _EquityPainter o) => o.pts != pts;
}
