/* Stock Trading Signals — small, dependency-free helpers */
(function () {
  'use strict';
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-menu]');
    if (t) { document.querySelector('.nav').classList.toggle('open'); return; }
    var c = e.target.closest('[data-copy]');
    if (c) {
      var inp = document.getElementById(c.getAttribute('data-copy'));
      if (inp) {
        inp.select();
        (navigator.clipboard ? navigator.clipboard.writeText(inp.value) : Promise.resolve(document.execCommand('copy')))
          .then(function () { var o = c.textContent; c.textContent = 'Copied ✓'; setTimeout(function () { c.textContent = o; }, 1500); });
      }
    }
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    var b = f.querySelector('button[type=submit]:not([data-keep])');
    if (b) setTimeout(function () { b.disabled = true; }, 0);
  });

  // ------------------------------------------------------------ charts
  var css = getComputedStyle(document.documentElement);
  var C = function (n) { return css.getPropertyValue(n).trim(); };

  function setup(canvas) {
    var dpr = window.devicePixelRatio || 1, w = canvas.clientWidth, h = canvas.clientHeight;
    canvas.width = w * dpr; canvas.height = h * dpr;
    var ctx = canvas.getContext('2d'); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.font = '11px Inter, system-ui, sans-serif';
    return { ctx: ctx, w: w, h: h };
  }
  function fmt(v) { var a = Math.abs(v); return a >= 1000 ? v.toFixed(0) : a >= 1 ? v.toFixed(2) : v.toFixed(4); }

  function candleChart(canvas, d) {
    var s = setup(canvas), ctx = s.ctx, W = s.w, H = s.h, padR = 62, padT = 14, padB = 24;
    var cs = d.candles; if (!cs.length) return;
    var lo = Infinity, hi = -Infinity;
    cs.forEach(function (k) { lo = Math.min(lo, k.l); hi = Math.max(hi, k.h); });
    (d.levels || []).forEach(function (l) { if (l.v) { lo = Math.min(lo, l.v); hi = Math.max(hi, l.v); } });
    var pad = (hi - lo) * 0.06; lo -= pad; hi += pad;
    var cw = (W - padR) / cs.length, y = function (v) { return padT + (hi - v) / (hi - lo) * (H - padT - padB); };
    ctx.strokeStyle = C('--b'); ctx.fillStyle = C('--m2'); ctx.lineWidth = 1;
    for (var g = 0; g <= 5; g++) {
      var v = lo + (hi - lo) * g / 5, yy = Math.round(y(v)) + .5;
      ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(W - padR, yy); ctx.stroke(); ctx.fillText(fmt(v), W - padR + 6, yy + 4);
    }
    var step = Math.max(1, Math.round(cs.length / 6));
    for (var i = 0; i < cs.length; i += step) {
      var dt = new Date(cs[i].t * 1000);
      ctx.fillText(d.tf === '1h' ? (dt.getUTCMonth() + 1) + '/' + dt.getUTCDate() + ' ' + dt.getUTCHours() + 'h' : dt.toISOString().slice(0, 10), i * cw + 2, H - 7);
    }
    function line(arr, color) {
      ctx.strokeStyle = color; ctx.lineWidth = 1.6; ctx.beginPath(); var on = false;
      arr.forEach(function (v, i) { if (v == null) return; var X = i * cw + cw / 2, Y = y(v); on ? ctx.lineTo(X, Y) : ctx.moveTo(X, Y); on = true; });
      ctx.stroke();
    }
    if (d.ema50) line(d.ema50, '#F59E0B');
    if (d.ema200) line(d.ema200, '#8B5CF6');
    cs.forEach(function (k, i) {
      var X = i * cw + cw / 2, up = k.c >= k.o, col = up ? C('--g') : C('--r');
      ctx.strokeStyle = col; ctx.fillStyle = col; ctx.lineWidth = 1;
      ctx.beginPath(); ctx.moveTo(X, y(k.h)); ctx.lineTo(X, y(k.l)); ctx.stroke();
      var bw = Math.max(1, cw * 0.62), t = y(Math.max(k.o, k.c)), b = y(Math.min(k.o, k.c));
      ctx.fillRect(X - bw / 2, t, bw, Math.max(1, b - t));
    });
    (d.levels || []).forEach(function (l) {
      if (!l.v) return; var yy = Math.round(y(l.v)) + .5;
      ctx.setLineDash([5, 4]); ctx.strokeStyle = l.c; ctx.lineWidth = 1.2;
      ctx.beginPath(); ctx.moveTo(0, yy); ctx.lineTo(W - padR, yy); ctx.stroke(); ctx.setLineDash([]);
      ctx.fillStyle = l.c; ctx.fillRect(W - padR + 2, yy - 9, padR - 4, 18);
      ctx.fillStyle = '#0A0F1C'; ctx.fillText(fmt(l.v), W - padR + 6, yy + 4);
      ctx.fillStyle = l.c; ctx.fillText(l.n, 6, yy - 5);
    });
    var idx = {}; cs.forEach(function (k, i) { idx[k.t] = i; });
    (d.markers || []).forEach(function (m) {
      var i = idx[m.t]; if (i == null) return;
      var X = i * cw + cw / 2, k = cs[i], buy = m.buy, Y = buy ? y(k.l) + 12 : y(k.h) - 12;
      ctx.fillStyle = buy ? C('--g') : C('--r'); ctx.beginPath();
      if (buy) { ctx.moveTo(X, Y - 7); ctx.lineTo(X - 6, Y + 4); ctx.lineTo(X + 6, Y + 4); }
      else { ctx.moveTo(X, Y + 7); ctx.lineTo(X - 6, Y - 4); ctx.lineTo(X + 6, Y - 4); }
      ctx.fill();
    });
  }

  function lineChart(canvas, pts) {
    var s = setup(canvas), ctx = s.ctx, W = s.w, H = s.h, pad = 10;
    if (pts.length < 2) { ctx.fillStyle = C('--m'); ctx.fillText('The curve appears after the first closed trades.', 12, H / 2); return; }
    var lo = Math.min.apply(null, pts), hi = Math.max.apply(null, pts); if (hi === lo) { hi += 1; lo -= 1; }
    var X = function (i) { return pad + i / (pts.length - 1) * (W - 2 * pad); }, Y = function (v) { return pad + (hi - v) / (hi - lo) * (H - 2 * pad); };
    var up = pts[pts.length - 1] >= pts[0], col = up ? C('--g') : C('--r');
    var grad = ctx.createLinearGradient(0, 0, 0, H); grad.addColorStop(0, up ? 'rgba(34,197,94,.25)' : 'rgba(244,63,94,.25)'); grad.addColorStop(1, 'rgba(0,0,0,0)');
    ctx.beginPath(); pts.forEach(function (v, i) { i ? ctx.lineTo(X(i), Y(v)) : ctx.moveTo(X(i), Y(v)); });
    ctx.lineTo(X(pts.length - 1), H); ctx.lineTo(X(0), H); ctx.closePath(); ctx.fillStyle = grad; ctx.fill();
    ctx.beginPath(); pts.forEach(function (v, i) { i ? ctx.lineTo(X(i), Y(v)) : ctx.moveTo(X(i), Y(v)); });
    ctx.strokeStyle = col; ctx.lineWidth = 2; ctx.stroke();
  }

  function render() {
    document.querySelectorAll('canvas[data-chart]').forEach(function (cv) {
      var src = cv.getAttribute('data-chart');
      if (cv._data) return candleChart(cv, cv._data);
      fetch(src, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (!j.ok) throw new Error(j.error && j.error.message);
        cv._data = j.data; candleChart(cv, j.data);
        var n = document.getElementById(cv.id + '-name'); if (n && j.data.name) n.textContent = j.data.name;
      }).catch(function (e) {
        var s = setup(cv); s.ctx.fillStyle = C('--m'); s.ctx.fillText('Chart unavailable: ' + (e.message || 'network error'), 14, 24);
      });
    });
    document.querySelectorAll('canvas[data-line]').forEach(function (cv) {
      lineChart(cv, JSON.parse(cv.getAttribute('data-line') || '[]'));
    });
  }
  window.addEventListener('load', render);
  var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(render, 150); });
})();
