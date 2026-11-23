String fmtPrice(double v) {
  final a = v.abs();
  if (a >= 1000) return _group(v.toStringAsFixed(2));
  if (a >= 1) return v.toStringAsFixed(2);
  return v.toStringAsFixed(4);
}

String fmtPct(double v, {bool sign = true, int digits = 1}) {
  if (v.isInfinite) return '∞';
  if (v.isNaN) return '—';
  final s = v.toStringAsFixed(digits);
  return sign && v > 0 ? '+$s%' : '$s%';
}

String fmtR(double v) => '${v > 0 ? '+' : ''}${v.toStringAsFixed(2)}R';

String fmtMoney(double v, {int digits = 2}) {
  final neg = v < 0;
  final s = _group(v.abs().toStringAsFixed(digits));
  return '${neg ? '-' : ''}\$$s';
}

String fmtCompact(double v) {
  final a = v.abs();
  if (a >= 1e12) return '${(v / 1e12).toStringAsFixed(2)}T';
  if (a >= 1e9) return '${(v / 1e9).toStringAsFixed(2)}B';
  if (a >= 1e6) return '${(v / 1e6).toStringAsFixed(2)}M';
  if (a >= 1e3) return '${(v / 1e3).toStringAsFixed(1)}K';
  return v.toStringAsFixed(0);
}

String fmtShares(double v, {required bool fractional}) =>
    fractional ? v.toStringAsFixed(v >= 100 ? 1 : 3) : v.toStringAsFixed(0);

String _group(String fixed) {
  final parts = fixed.split('.');
  final intPart = parts[0];
  final buf = StringBuffer();
  for (var i = 0; i < intPart.length; i++) {
    if (i > 0 && (intPart.length - i) % 3 == 0) buf.write(',');
    buf.write(intPart[i]);
  }
  return parts.length > 1 ? '$buf.${parts[1]}' : buf.toString();
}

const _months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

String fmtDate(DateTime t, {bool withTime = false, bool withYear = true}) {
  final l = t.toLocal();
  final d = withYear ? '${l.day} ${_months[l.month - 1]} ${l.year}' : '${l.day} ${_months[l.month - 1]}';
  if (!withTime) return d;
  return '$d, ${fmtClock(l)}';
}

String fmtClock(DateTime t) {
  final l = t.toLocal();
  return '${l.hour.toString().padLeft(2, '0')}:${l.minute.toString().padLeft(2, '0')}';
}

String fmtAgo(DateTime t, {DateTime? now}) {
  final d = (now ?? DateTime.now()).difference(t);
  if (d.inSeconds < 60) return 'just now';
  if (d.inMinutes < 60) return '${d.inMinutes}m ago';
  if (d.inHours < 24) return '${d.inHours}h ago';
  if (d.inDays < 30) return '${d.inDays}d ago';
  return fmtDate(t);
}

String greeting([DateTime? now]) {
  final h = (now ?? DateTime.now()).hour;
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}
