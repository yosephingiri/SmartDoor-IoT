// Grafik SVG ringan tanpa library (bar, step-line + strip state, heatmap) dengan tooltip.
'use strict';
const Charts = (() => {
  const NS = 'http://www.w3.org/2000/svg';
  const el = (tag, attrs = {}, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    if (parent) parent.appendChild(n);
    return n;
  };
  const tip = () => document.getElementById('tip');
  function showTip(evt, html) {
    const t = tip(); t.innerHTML = html; t.classList.add('show');
    const w = t.offsetWidth, h = t.offsetHeight;
    let x = evt.clientX + 14, y = evt.clientY - h - 10;
    if (x + w > innerWidth - 8) x = evt.clientX - w - 14;
    if (y < 8) y = evt.clientY + 16;
    t.style.left = x + 'px'; t.style.top = y + 'px';
  }
  const hideTip = () => tip().classList.remove('show');
  const nice = (max) => {
    if (max <= 4) return { top: Math.max(1, max), step: 1 };
    const raw = max / 4, mag = 10 ** Math.floor(Math.log10(raw));
    const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= raw);
    return { top: Math.ceil(max / step) * step, step };
  };
  // path bar dengan ujung data membulat 4px dan dasar rata
  const barPath = (x, y, w, h, r = 4) => {
    if (h <= 0) return '';
    r = Math.min(r, w / 2, h);
    return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
  };
  const legend = (target, series) => {
    if (!target) return;
    target.innerHTML = series.map((s) => `<span><i style="background:${s.color}"></i>${s.name}</span>`).join('');
  };

  // categories: label sumbu-x; series: [{name, color, values[]}]; opts.stacked
  function bar(container, { categories, series, stacked = false, height = 240, labelEvery = 1, tipTitle }) {
    container.innerHTML = '';
    const W = Math.max(container.clientWidth, 320), H = height;
    const m = { t: 10, r: 8, b: 26, l: 34 };
    const iw = W - m.l - m.r, ih = H - m.t - m.b;
    const totals = categories.map((_, i) => series.reduce((s, se) => s + (se.values[i] || 0), 0));
    const max = stacked ? Math.max(0, ...totals) : Math.max(0, ...series.flatMap((s) => s.values));
    const { top, step } = nice(max);
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img' }, container);
    const g = el('g', { transform: `translate(${m.l},${m.t})` }, svg);
    const grid = el('g', { class: 'grid' }, g);
    for (let v = 0; v <= top + 1e-9; v += step) {
      const y = ih - (v / top) * ih;
      if (v > 0) el('line', { x1: 0, x2: iw, y1: y, y2: y }, grid);
      el('text', { x: -8, y: y + 4, 'text-anchor': 'end' }, g).textContent = Math.round(v * 10) / 10;
    }
    const band = iw / categories.length;
    const groupW = Math.min(band * (stacked ? 0.72 : 0.84), stacked ? 40 : 18 * series.length + 2 * (series.length - 1));
    categories.forEach((c, i) => {
      const x0 = i * band + (band - groupW) / 2;
      if (stacked) {
        let acc = 0;
        series.forEach((s) => {
          const v = s.values[i] || 0; if (!v) return;
          const h = (v / top) * ih, y = ih - ((acc + v) / top) * ih;
          // gap 2px antar segmen
          el('path', { d: barPath(x0, y + 1, groupW, Math.max(0, h - 2), acc + v === totals[i] ? 4 : 0), style: `fill:${s.color}` }, g);
          acc += v;
        });
      } else {
        const bw = (groupW - 2 * (series.length - 1)) / series.length;
        series.forEach((s, j) => {
          const v = s.values[i] || 0; if (!v) return;
          const h = (v / top) * ih;
          el('path', { d: barPath(x0 + j * (bw + 2), ih - h, bw, h), style: `fill:${s.color}` }, g);
        });
      }
      if (i % labelEvery === 0) el('text', { x: i * band + band / 2, y: ih + 17, 'text-anchor': 'middle' }, g).textContent = c;
      // area hover lebih besar dari mark
      const hit = el('rect', { x: i * band, y: 0, width: band, height: ih, fill: 'transparent' }, g);
      hit.addEventListener('mousemove', (e) => {
        hit.setAttribute('fill', 'color-mix(in srgb, currentColor 6%, transparent)');
        showTip(e, `<b>${tipTitle ? tipTitle(c, i) : c}</b><br>` + series.map((s) => `<span class="sw" style="background:${s.color}"></span>${s.name}: <b>${s.values[i] || 0}</b>`).join('<br>') + (stacked ? `<br>Total: <b>${totals[i]}</b>` : ''));
      });
      hit.addEventListener('mouseleave', () => { hit.setAttribute('fill', 'transparent'); hideTip(); });
    });
    el('line', { x1: 0, x2: iw, y1: ih, y2: ih, class: 'base' }, g);
  }

  // Timeline state perangkat: step-line jumlah orang + strip pintu terbuka/terkunci/alarm.
  function timeline(container, { rows, from, to, fmt }) {
    container.innerHTML = '';
    if (!rows.length) { container.innerHTML = '<div class="empty">Belum ada telemetri pada rentang ini.</div>'; return; }
    const W = Math.max(container.clientWidth, 320);
    const strips = [
      { key: 'door_open', label: 'Pintu terbuka', color: 'var(--s-keluar)' },
      { key: 'relay', label: 'Solenoid aktif', color: 'var(--s-masuk)' },
      { key: 'alarm', label: 'Alarm', color: 'var(--s-ditolak)' },
    ];
    const lineH = 150, stripH = 14, gap = 8;
    const m = { t: 10, r: 10, b: 24, l: 96 };
    const H = m.t + lineH + 16 + strips.length * (stripH + gap) + m.b;
    const iw = W - m.l - m.r;
    const sx = (t) => ((t - from) / (to - from)) * iw;
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}` }, container);
    const g = el('g', { transform: `translate(${m.l},${m.t})` }, svg);
    const maxOcc = Math.max(1, ...rows.map((r) => r.occupancy || 0));
    const { top, step } = nice(maxOcc);
    const grid = el('g', { class: 'grid' }, g);
    for (let v = 0; v <= top; v += step) {
      const y = lineH - (v / top) * lineH;
      if (v > 0) el('line', { x1: 0, x2: iw, y1: y, y2: y }, grid);
      el('text', { x: -8, y: y + 4, 'text-anchor': 'end' }, g).textContent = v;
    }
    el('text', { x: -m.l + 4, y: 12 }, g).textContent = 'Orang di dalam';
    let d = '', prevY = null;
    rows.forEach((r, i) => {
      const x = sx(r.ts), y = lineH - ((r.occupancy || 0) / top) * lineH;
      d += i === 0 ? `M${x},${y}` : `H${x}V${y}`;
      prevY = y;
    });
    d += `H${sx(to)}`;
    el('path', { d, fill: 'none', style: 'stroke:var(--s-masuk)', 'stroke-width': 2 }, g);
    el('line', { x1: 0, x2: iw, y1: lineH, y2: lineH, class: 'base' }, g);
    strips.forEach((s, k) => {
      const y0 = lineH + 16 + k * (stripH + gap);
      el('rect', { x: 0, y: y0, width: iw, height: stripH, rx: 3, style: 'fill:var(--surface-2)' }, g);
      el('text', { x: -8, y: y0 + 11, 'text-anchor': 'end' }, g).textContent = s.label;
      let start = null;
      rows.forEach((r, i) => {
        const on = !!r[s.key];
        if (on && start === null) start = r.ts;
        const next = rows[i + 1];
        if (start !== null && (!on || !next)) {
          const end = on ? to : r.ts;
          el('rect', { x: sx(start), y: y0, width: Math.max(2, sx(end) - sx(start)), height: stripH, rx: 2, style: `fill:${s.color}` }, g);
          start = null;
        }
      });
    });
    const ticks = 6;
    for (let i = 0; i <= ticks; i++) {
      const t = from + ((to - from) * i) / ticks;
      el('text', { x: sx(t), y: H - m.t - 6, 'text-anchor': i === 0 ? 'start' : i === ticks ? 'end' : 'middle' }, g).textContent = fmt(t);
    }
    // crosshair
    const cross = el('line', { y1: 0, y2: H - m.t - m.b, style: 'stroke:var(--text-3)', 'stroke-dasharray': '3 3', visibility: 'hidden' }, g);
    const hit = el('rect', { x: 0, y: 0, width: iw, height: H - m.t - m.b, fill: 'transparent' }, g);
    hit.addEventListener('mousemove', (e) => {
      const rect = svg.getBoundingClientRect();
      const px = ((e.clientX - rect.left) / rect.width) * W - m.l;
      const t = from + (px / iw) * (to - from);
      let r = rows[0];
      for (const x of rows) { if (x.ts <= t) r = x; else break; }
      cross.setAttribute('x1', px); cross.setAttribute('x2', px); cross.setAttribute('visibility', 'visible');
      showTip(e, `<b>${fmt(t, true)}</b><br>Orang di dalam: <b>${r.occupancy ?? '-'}</b><br>Pintu: <b>${r.door_open ? 'Terbuka' : 'Tertutup'}</b><br>Kunci: <b>${r.lock_state ? 'Terkunci' : 'Terbuka'}</b><br>Alarm: <b>${r.alarm ? (r.alarm_type || 'Aktif') : 'Tidak'}</b><br>RSSI: ${r.rssi ?? '-'} dBm`);
    });
    hit.addEventListener('mouseleave', () => { cross.setAttribute('visibility', 'hidden'); hideTip(); });
  }

  // Heatmap 7x24 dengan skala sekuensial satu hue
  function heatmap(container, { matrix, rowLabels }) {
    container.innerHTML = '';
    const W = Math.max(container.clientWidth, 320);
    const m = { t: 4, r: 4, b: 22, l: 46 };
    const cw = (W - m.l - m.r) / 24, ch = Math.min(26, cw * 1.1);
    const H = m.t + ch * 7 + m.b;
    const max = Math.max(1, ...matrix.flat());
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}` }, container);
    const g = el('g', { transform: `translate(${m.l},${m.t})` }, svg);
    const order = [1, 2, 3, 4, 5, 6, 0]; // Senin..Minggu
    order.forEach((dw, row) => {
      el('text', { x: -8, y: row * ch + ch / 2 + 4, 'text-anchor': 'end' }, g).textContent = rowLabels[dw];
      for (let h = 0; h < 24; h++) {
        const v = matrix[dw][h];
        const lvl = v === 0 ? 0 : Math.min(6, 1 + Math.floor((v / max) * 5.999));
        const r = el('rect', { x: h * cw + 1, y: row * ch + 1, width: cw - 2, height: ch - 2, rx: 3, style: `fill:var(--seq-${lvl})` }, g);
        r.addEventListener('mousemove', (e) => showTip(e, `<b>${rowLabels[dw]}, ${String(h).padStart(2, '0')}:00–${String(h).padStart(2, '0')}:59</b><br>MASUK: <b>${v}</b>`));
        r.addEventListener('mouseleave', hideTip);
      }
    });
    for (let h = 0; h < 24; h += 3) el('text', { x: h * cw + cw / 2, y: ch * 7 + 15, 'text-anchor': 'middle' }, g).textContent = String(h).padStart(2, '0');
  }

  return { bar, timeline, heatmap, legend };
})();
