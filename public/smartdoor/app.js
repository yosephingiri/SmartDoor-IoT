// Smart Door Cloud — aplikasi web pengelola (vanilla JS).
'use strict';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const S = { devices: new Map(), presence: [], view: 'overview', tz: 420, logPage: 0, anDays: 7, anData: null, feedIds: new Set() };
const DAYS = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
const SERIES = {
  MASUK: { name: 'Masuk', color: 'var(--s-masuk)' },
  KELUAR: { name: 'Keluar', color: 'var(--s-keluar)' },
  DITOLAK: { name: 'Ditolak', color: 'var(--s-ditolak)' },
  REMOTE: { name: 'Remote', color: 'var(--s-remote)' },
  ALARM: { name: 'Alarm', color: 'var(--s-alarm)' },
};

// ---------------------------------------------------------------- waktu (WIB)
const shift = (ts) => new Date(ts + S.tz * 60000);
const pad = (n) => String(n).padStart(2, '0');
const fTime = (ts) => { const d = shift(ts); return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())}`; };
const fDate = (ts) => { const d = shift(ts); return `${pad(d.getUTCDate())}/${pad(d.getUTCMonth() + 1)}/${d.getUTCFullYear()}`; };
const fDT = (ts) => (ts ? `${fDate(ts)} ${fTime(ts)}` : '-');
const ago = (ts) => {
  if (!ts) return 'belum pernah';
  const s = Math.round((Date.now() - ts) / 1000);
  if (s < 5) return 'baru saja'; if (s < 60) return `${s} dtk lalu`;
  if (s < 3600) return `${Math.floor(s / 60)} mnt lalu`; if (s < 86400) return `${Math.floor(s / 3600)} jam lalu`;
  return `${Math.floor(s / 86400)} hari lalu`;
};
const dayStartLocal = (ts = Date.now()) => { const t = ts + S.tz * 60000; return t - (t % 86400000) - S.tz * 60000; };
const dateInputToTs = (v, endOfDay) => { if (!v) return ''; const t = Date.parse(v + 'T00:00:00Z') - S.tz * 60000; return endOfDay ? t + 86400000 - 1 : t; };

// ---------------------------------------------------------------- API
const BASE = (window.SMARTDOOR?.base || '').replace(/\/$/, '');
const CSRF = document.querySelector('meta[name=csrf-token]')?.content;
async function api(path, opts = {}) {
  const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF };
  if (opts.body) headers['Content-Type'] = 'application/json';
  const res = await fetch(BASE + path, { method: opts.method || 'GET', headers, body: opts.body ? JSON.stringify(opts.body) : undefined, credentials: 'same-origin' });
  if (res.status === 401 || res.status === 419) { location.href = BASE + '/login'; throw new Error('Sesi berakhir'); }
  const data = res.headers.get('content-type')?.includes('json') ? await res.json() : await res.text();
  if (!res.ok) throw new Error(data.error || data.message || res.statusText);
  return data;
}

function toast(title, body = '', kind = '') {
  const t = document.createElement('div');
  t.className = 'toast ' + kind;
  t.innerHTML = `<b>${esc(title)}</b>${esc(body)}`;
  const box = $('#toasts'); box.appendChild(t);
  while (box.children.length > 4) box.firstChild.remove();
  setTimeout(() => t.remove(), kind === 'crit' ? 9000 : 4500);
}

// ---------------------------------------------------------------- deskripsi kejadian
function describe(e) {
  const who = e.card_name || (e.uid ? `UID ${e.uid}` : '');
  switch (e.type) {
    case 'ACCESS':
      return {
        MASUK: { b: 'b-good', l: 'MASUK', t: `${who} masuk` },
        KELUAR: { b: 'b-info', l: 'KELUAR', t: `${who} keluar` },
        DITOLAK: { b: 'b-crit', l: 'DITOLAK', t: `Akses ditolak${e.direction ? ' (' + e.direction.toLowerCase() + ')' : ''} — ${e.card_name ? who + ' (nonaktif/tidak berhak)' : 'kartu tidak terdaftar'}` },
        REMOTE: { b: 'b-info', l: 'REMOTE', t: `Pintu dibuka jarak jauh${e.detail ? ' oleh ' + e.detail : ''}` },
        BATAL: { b: 'b-idle', l: 'BATAL', t: `Akses ${who} batal — pintu tidak dibuka dalam batas waktu` },
      }[e.status] || { b: 'b-idle', l: e.status, t: who };
    case 'ALARM':
      return {
        BRUTE_FORCE: { b: 'b-crit', l: 'ALARM', t: 'Kartu ditolak berturut-turut — reader diblokir sementara' },
        FORCED_OPEN: { b: 'b-crit', l: 'ALARM', t: 'Pintu dibuka paksa tanpa akses sah' },
        DOOR_LEFT_OPEN: { b: 'b-warn', l: 'ALARM', t: 'Pintu lupa ditutup' },
      }[e.status] || { b: 'b-crit', l: 'ALARM', t: e.status };
    case 'ALARM_RESET': return { b: 'b-idle', l: 'RESET', t: `Alarm dihentikan (${(e.status || '').toLowerCase()})${e.detail ? ' — ' + e.detail : ''}` };
    case 'DOOR': return e.status === 'OPEN'
      ? { b: 'b-idle', l: 'PINTU', t: 'Pintu dibuka' }
      : { b: 'b-idle', l: 'PINTU', t: `Pintu ditutup${e.duration_ms ? ` setelah ${(e.duration_ms / 1000).toFixed(1)} dtk` : ''}` };
    case 'SYSTEM': return { b: 'b-idle', l: 'SISTEM', t: `${e.status || ''}${e.detail ? ' — ' + e.detail : ''}` };
  }
  return { b: 'b-idle', l: e.type, t: e.detail || '' };
}
const devName = (id) => S.devices.get(id)?.name || id;

// ---------------------------------------------------------------- navigasi
function go(view) {
  S.view = view;
  $$('#nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === view));
  $$('.view').forEach((v) => v.classList.toggle('hidden', v.id !== 'view-' + view));
  location.hash = view;
  ({ overview: loadOverview, logs: loadLogs, analytics: loadAnalytics, cards: loadCards, devices: loadDevices })[view]();
}
$('#nav').addEventListener('click', (e) => { const a = e.target.closest('a'); if (a) go(a.dataset.view); });

// ================================================================ DASHBOARD
async function loadOverview() {
  const [ov, pres] = await Promise.all([api('/panel/overview'), api('/panel/presence')]);
  S.tz = ov.tzOffsetMin;
  S.devices = new Map(ov.devices.map((d) => [d.id, d]));
  S.today = ov.today; S.alarmsToday = ov.alarmsToday; S.presence = pres;
  if (S.lastId === undefined) S.lastId = ov.lastId;
  fillDeviceSelects();
  renderKpis(); renderDevices(); renderPeople();
  $('#feed').innerHTML = ''; S.feedIds.clear();
  ov.recent.slice().reverse().forEach((e) => addFeed(e, false));
}
function renderKpis() {
  const devs = [...S.devices.values()];
  const online = devs.filter((d) => d.online).length;
  const inside = devs.reduce((s, d) => s + (d.state?.occupancy || 0), 0);
  const alarms = devs.filter((d) => d.state?.alarm && d.online).length;
  const t = S.today || {};
  $('#kpis').innerHTML = [
    ['Perangkat online', `${online}<span class="muted" style="font-size:16px"> / ${devs.length}</span>`, devs.length - online ? `${devs.length - online} offline` : 'semua terhubung'],
    ['Orang di dalam ruangan', inside, `di ${devs.length} pintu`],
    ['Akses hari ini', (t.MASUK || 0) + (t.KELUAR || 0), `${t.MASUK || 0} masuk · ${t.KELUAR || 0} keluar · ${t.REMOTE || 0} remote`],
    ['Ditolak hari ini', t.DITOLAK || 0, `${S.alarmsToday || 0} alarm hari ini`],
    ['Alarm aktif', alarms, alarms ? 'perlu ditindaklanjuti' : 'aman'],
  ].map(([k, v, s]) => `<div class="kpi"><div class="k">${k}</div><div class="v">${v}</div><div class="s">${s}</div></div>`).join('');
}
const ALARM_TEXT = { BRUTE_FORCE: 'Percobaan berulang', FORCED_OPEN: 'Dibuka paksa', DOOR_LEFT_OPEN: 'Lupa ditutup' };
function renderDevices() {
  const g = $('#devgrid');
  const devs = [...S.devices.values()];
  if (!devs.length) { g.innerHTML = '<div class="empty">Belum ada perangkat. Tambahkan di menu <b>Perangkat</b>.</div>'; return; }
  g.innerHTML = devs.map((d) => {
    const s = d.state || {};
    const has = !!d.state;
    const alarm = d.online && s.alarm;
    return `<div class="dev ${alarm ? 'alarm' : ''} ${d.online ? '' : 'offline'}" data-id="${esc(d.id)}">
      <div class="dev-h"><div><b>${esc(d.name)}</b><div class="loc">${esc(d.location || d.id)}</div></div>
        ${d.online ? '<span class="badge b-good">Online</span>' : '<span class="badge b-idle">Offline</span>'}</div>
      <div class="states">
        <div class="st"><div class="k">Kunci (solenoid)</div><div class="v">${!has ? '-' : s.locked ? '<span class="badge b-good">Terkunci</span>' : '<span class="badge b-warn">Terbuka</span>'}</div></div>
        <div class="st"><div class="k">Pintu (reed switch)</div><div class="v">${!has ? '-' : s.doorOpen ? '<span class="badge b-warn">Terbuka</span>' : '<span class="badge b-good">Tertutup</span>'}</div></div>
        <div class="st"><div class="k">Orang di dalam</div><div class="v" style="font-size:18px">${has ? s.occupancy ?? 0 : '-'}</div></div>
        <div class="st"><div class="k">Alarm</div><div class="v">${!has ? '-' : s.alarm ? `<span class="badge b-crit">${esc(ALARM_TEXT[s.alarmType] || 'Aktif')}</span>` : s.readersBlocked ? '<span class="badge b-warn">Reader diblokir</span>' : '<span class="badge b-idle">Tidak ada</span>'}</div></div>
      </div>
      <div class="dev-meta">
        <span>Reader luar: ${has ? (s.readerInOk === false ? '⚠ error' : 'OK') : '-'}</span>
        <span>Reader dalam: ${has ? (s.readerOutOk === false ? '⚠ error' : 'OK') : '-'}</span>
        <span>Wi-Fi: ${s.rssi ?? '-'} dBm</span>
        <span>${d.online ? 'aktif' : 'terakhir'} ${ago(d.lastSeen)}</span>
      </div>
      <div class="dev-actions">
        <button class="btn primary sm" data-cmd="UNLOCK" ${d.online ? '' : 'disabled'}>Buka pintu</button>
        <button class="btn sm" data-cmd="RESET_ALARM" ${d.online && (s.alarm || s.readersBlocked) ? '' : 'disabled'}>Reset alarm</button>
        <button class="btn ghost sm" data-detail>Detail sensor</button>
      </div></div>`;
  }).join('');
}
$('#devgrid').addEventListener('click', async (e) => {
  const card = e.target.closest('.dev'); if (!card) return;
  const id = card.dataset.id;
  const b = e.target.closest('button'); if (!b) return;
  if (b.dataset.detail !== undefined) return openDeviceDetail(id);
  if (b.dataset.cmd) sendCommand(id, b.dataset.cmd, b);
});
async function sendCommand(id, type, btn) {
  if (type === 'UNLOCK' && !confirm(`Buka pintu "${devName(id)}" dari jarak jauh?`)) return;
  if (btn) btn.disabled = true;
  try {
    const c = await api(`/panel/devices/${id}/commands`, { method: 'POST', body: { type } });
    toast('Perintah dikirim', ` ${type === 'UNLOCK' ? 'Buka pintu' : type === 'RESET_ALARM' ? 'Reset alarm' : type} → ${devName(id)}. Menunggu konfirmasi perangkat…`);
    const check = async (n) => {
      const list = await api(`/panel/commands?device=${encodeURIComponent(id)}`);
      const r = list.find((x) => x.id === c.id);
      if (r && r.status === 'done') toast('Perangkat mengonfirmasi', ` ${devName(id)}: ${r.result || 'berhasil'}`);
      else if (r && (r.status === 'failed' || r.status === 'expired')) toast('Perintah gagal', ` ${devName(id)}: ${r.result || r.status}`, 'crit');
      else if (n < 8) setTimeout(() => check(n + 1), 1000);
    };
    setTimeout(() => check(0), 800);
  } catch (err) { toast('Gagal mengirim perintah', ' ' + err.message, 'crit'); }
  finally { if (btn) setTimeout(() => { btn.disabled = false; }, 1500); }
}
function renderPeople() {
  const p = S.presence;
  $('#people').innerHTML = p.length ? p.map((x) => `<li><span><b>${esc(x.name || x.uid)}</b><br><span class="muted sm">${esc(devName(x.deviceId))}</span></span><span class="muted sm">sejak ${fTime(x.since)}<br>${fDate(x.since)}</span></li>`).join('')
    : '<li class="muted">Tidak ada yang tercatat di dalam ruangan.</li>';
}
function addFeed(e, animate = true) {
  if (S.feedIds.has(e.id)) return; S.feedIds.add(e.id);
  if (e.type === 'SYSTEM' && e.status === 'HEARTBEAT') return;
  const d = describe(e);
  const li = document.createElement('li');
  if (animate) li.className = 'new';
  li.innerHTML = `<span class="t">${fTime(e.ts)}</span><span class="d">${esc(d.t)}<small>${esc(devName(e.device_id || e.deviceId))}</small></span><span class="badge ${d.b}">${esc(d.l)}</span>`;
  const feed = $('#feed'); feed.prepend(li);
  while (feed.children.length > 60) feed.lastChild.remove();
}

// ---- detail perangkat: seluruh state sensor & aktuator dari telemetri
async function openDeviceDetail(id, hours = 24) {
  const d = S.devices.get(id);
  const to = Date.now(), from = to - hours * 3600000;
  const [rows, cmds] = await Promise.all([
    api(`/panel/telemetry?device=${encodeURIComponent(id)}&from=${from}&to=${to}`),
    api(`/panel/commands?device=${encodeURIComponent(id)}`),
  ]);
  $('#modalBody').innerHTML = `
    <h2 style="margin-top:0;color:var(--text)">${esc(d.name)} <span class="muted sm">${esc(id)}</span></h2>
    <div class="filters"><div class="seg" id="tlRange">${[1, 6, 24, 168].map((h) => `<button data-h="${h}" class="${h === hours ? 'on' : ''}">${h < 24 ? h + ' jam' : h === 24 ? '24 jam' : '7 hari'}</button>`).join('')}</div>
      <span class="muted sm">${rows.length} sampel telemetri tersimpan</span></div>
    <div class="chart" id="tl" style="min-height:260px"></div>
    <h3 style="margin:18px 0 8px">Snapshot state terakhir</h3>
    <pre class="code">${esc(JSON.stringify(d.state, null, 2))}</pre>
    <h3 style="margin:18px 0 8px">Riwayat perintah jarak jauh</h3>
    <table class="tbl"><thead><tr><th>Waktu</th><th>Perintah</th><th>Oleh</th><th>Status</th><th>Hasil</th></tr></thead>
    <tbody>${cmds.slice(0, 10).map((c) => `<tr><td>${fDT(c.created_at)}</td><td>${c.type}</td><td>${esc(c.issued_by)}</td><td><span class="badge ${c.status === 'done' ? 'b-good' : c.status === 'pending' || c.status === 'sent' ? 'b-info' : 'b-crit'}">${c.status}</span></td><td>${esc(c.result || '')}</td></tr>`).join('') || '<tr><td colspan="5" class="muted">Belum ada perintah</td></tr>'}</tbody></table>`;
  const m = $('#modal'); if (!m.open) m.showModal();
  Charts.timeline($('#tl'), { rows, from, to, fmt: (t, full) => (full ? fDT(t) : hours > 24 ? fDate(t).slice(0, 5) : fTime(t).slice(0, 5)) });
  $('#tlRange').addEventListener('click', (e) => { const b = e.target.closest('button'); if (b) openDeviceDetail(id, Number(b.dataset.h)); });
}

// ================================================================ LOG
function fillDeviceSelects() {
  const opts = [...S.devices.values()].map((d) => `<option value="${esc(d.id)}">${esc(d.name)}</option>`).join('');
  for (const sel of [$('#logFilter [name=device]'), $('#anDevice')]) {
    const v = sel.value; sel.innerHTML = '<option value="">Semua pintu</option>' + opts; sel.value = v;
  }
}
function logQuery() {
  const f = new FormData($('#logFilter'));
  const q = new URLSearchParams();
  for (const k of ['device', 'type', 'status', 'q']) if (f.get(k)) q.set(k, f.get(k));
  if (f.get('from')) q.set('from', dateInputToTs(f.get('from')));
  if (f.get('to')) q.set('to', dateInputToTs(f.get('to'), true));
  return q;
}
async function loadLogs() {
  if (!S.devices.size) { S.devices = new Map((await api('/panel/devices')).map((d) => [d.id, d])); fillDeviceSelects(); }
  const q = logQuery(); q.set('limit', 50); q.set('offset', S.logPage * 50);
  const { total, rows } = await api('/panel/events?' + q);
  $('#logTable tbody').innerHTML = rows.map((e) => {
    const d = describe(e);
    return `<tr><td class="mono">${fDT(e.ts)}</td><td>${esc(devName(e.device_id))}</td><td><span class="badge ${d.b}">${esc(e.type === 'ALARM' ? e.status : d.l)}</span></td>
      <td>${esc(e.card_name || '')}</td><td class="mono">${esc(e.uid || '')}</td><td>${esc(d.t)}</td></tr>`;
  }).join('') || '<tr><td colspan="6" class="muted">Tidak ada data</td></tr>';
  $('#logInfo').textContent = total ? `${S.logPage * 50 + 1}–${Math.min(total, (S.logPage + 1) * 50)} dari ${total} kejadian` : '';
  $('#prevPg').disabled = S.logPage === 0;
  $('#nextPg').disabled = (S.logPage + 1) * 50 >= total;
}
$('#logFilter').addEventListener('submit', (e) => { e.preventDefault(); S.logPage = 0; loadLogs(); });
$('#prevPg').onclick = () => { S.logPage--; loadLogs(); };
$('#nextPg').onclick = () => { S.logPage++; loadLogs(); };
$('#csvBtn').onclick = () => { location.href = BASE + '/panel/events-csv?' + logQuery(); };

// ================================================================ ANALYTICS
async function loadAnalytics() {
  if (!S.devices.size) { S.devices = new Map((await api('/panel/devices')).map((d) => [d.id, d])); fillDeviceSelects(); }
  const to = Date.now();
  const from = S.anDays === 1 ? dayStartLocal() : dayStartLocal(to - (S.anDays - 1) * 86400000);
  const q = new URLSearchParams({ from, to });
  if ($('#anDevice').value) q.set('device', $('#anDevice').value);
  S.anData = await api('/panel/analytics?' + q);
  renderAnalytics();
}
function renderAnalytics() {
  const a = S.anData; if (!a) return;
  const k = a.kpi;
  $('#anKpis').innerHTML = [
    ['Total masuk', k.masuk, `${k.uniqueUsers} pengguna unik`],
    ['Total keluar', k.keluar, `${k.remote} dibuka remote`],
    ['Akses ditolak', k.ditolak, `${(k.denialRate * 100).toFixed(1)}% dari seluruh tap`],
    ['Alarm', k.alarms, `${k.batal} akses batal`],
    ['Rata-rata pintu terbuka', `${k.avgDoorOpenS.toFixed(1)}<span class="muted" style="font-size:16px"> dtk</span>`, `terlama ${k.maxDoorOpenS.toFixed(0)} dtk`],
    ['Jam tersibuk', k.peakHour === null ? '-' : `${pad(k.peakHour)}:00`, 'berdasarkan masuk + keluar'],
  ].map(([kk, v, s]) => `<div class="kpi"><div class="k">${kk}</div><div class="v">${v}</div><div class="s">${s}</div></div>`).join('');

  const dailyS = ['MASUK', 'KELUAR', 'DITOLAK'].map((x) => ({ ...SERIES[x], values: a.daily.map((d) => d[x]) }));
  Charts.legend($('#lgDaily'), dailyS);
  Charts.bar($('#chDaily'), {
    categories: a.daily.map((d) => d.day.slice(8, 10) + '/' + d.day.slice(5, 7)), series: dailyS, stacked: true,
    labelEvery: Math.ceil(a.daily.length / 15), tipTitle: (c, i) => { const dt = new Date(a.daily[i].day + 'T00:00:00Z'); return `${DAYS[dt.getUTCDay()]}, ${c}/${a.daily[i].day.slice(0, 4)}`; },
  });
  const hourlyS = ['MASUK', 'KELUAR', 'DITOLAK'].map((x) => ({ ...SERIES[x], values: a.hourly.map((h) => h[x]) }));
  Charts.legend($('#lgHourly'), hourlyS);
  Charts.bar($('#chHourly'), { categories: a.hourly.map((h) => pad(h.hour)), series: hourlyS, labelEvery: 3, tipTitle: (c) => `${c}:00–${c}:59` });
  Charts.heatmap($('#chHeat'), { matrix: a.heat, rowLabels: DAYS });

  const hlist = (items, label, val, sub) => {
    if (!items.length) return '<p class="muted">Belum ada data.</p>';
    const max = Math.max(...items.map(val));
    return items.map((x) => `<div class="hbar"><span>${esc(label(x))}${sub ? `<br><span class="muted sm">${esc(sub(x))}</span>` : ''}</span><span class="n">${val(x)}</span><div class="bar"><i style="width:${(val(x) / max) * 100}%"></i></div></div>`).join('');
  };
  $('#topUsers').innerHTML = hlist(a.topUsers, (x) => x.name, (x) => x.masuk + x.keluar, (x) => `${x.masuk} masuk · ${x.keluar} keluar`);
  $('#deniedList').innerHTML = hlist(a.deniedUids, (x) => x.name, (x) => x.n, (x) => `UID ${x.uid}`);
  $('#alarmList').innerHTML = hlist(a.alarms, (x) => ALARM_TEXT[x.type] || x.type, (x) => x.n);
  $('#deniedList').querySelectorAll('.bar i').forEach((i) => { i.style.background = 'var(--s-ditolak)'; });
  $('#alarmList').querySelectorAll('.bar i').forEach((i) => { i.style.background = 'var(--s-alarm)'; });

  const av = new Map(a.availability.map((x) => [x.deviceId, x.pct]));
  $('#byDevTable tbody').innerHTML = a.byDevice.map((r) => `<tr><td>${esc(devName(r.deviceId))}</td><td class="r">${r.masuk}</td><td class="r">${r.keluar}</td><td class="r">${r.ditolak}</td><td class="r">${r.remote}</td><td class="r">${r.alarm}</td>
    <td class="r">${r.avgOpenMs ? (r.avgOpenMs / 1000).toFixed(1) + ' dtk' : '-'}</td><td class="r">${av.has(r.deviceId) ? av.get(r.deviceId).toFixed(1) + '%' : '-'}</td></tr>`).join('') || '<tr><td colspan="8" class="muted">Belum ada data</td></tr>';
  $('#anTable tbody').innerHTML = a.daily.map((d) => `<tr><td>${d.day}</td><td class="r">${d.MASUK}</td><td class="r">${d.KELUAR}</td><td class="r">${d.DITOLAK}</td><td class="r">${d.REMOTE}</td><td class="r">${d.ALARM}</td></tr>`).join('');
}
$('#anRange').addEventListener('click', (e) => {
  const b = e.target.closest('button'); if (!b) return;
  $$('#anRange button').forEach((x) => x.classList.toggle('on', x === b));
  S.anDays = Number(b.dataset.d); loadAnalytics();
});
$('#anDevice').addEventListener('change', loadAnalytics);
$('#anTableBtn').onclick = () => { const w = $('#anTableWrap'); w.hidden = !w.hidden; $('#anTableBtn').textContent = w.hidden ? 'Lihat sebagai tabel' : 'Sembunyikan tabel'; };
let rz; addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => { if (S.view === 'analytics') renderAnalytics(); }, 200); });

// ================================================================ KARTU
async function loadCards() {
  const [cards, unknown, devs] = await Promise.all([api('/panel/cards'), api('/panel/cards/unknown'), api('/panel/devices')]);
  S.devices = new Map(devs.map((d) => [d.id, d]));
  S.cards = cards;
  $('#unknownPanel').hidden = !unknown.length;
  $('#unknownList').innerHTML = unknown.map((u) => `<li><span><span class="mono">${esc(u.uid)}</span> <span class="muted sm">· ${u.attempts}× ditolak · terakhir ${ago(u.lastTs)} di ${esc(devName(u.deviceId))}</span></span><button class="btn sm primary" data-reg="${esc(u.uid)}">Daftarkan</button></li>`).join('');
  $('#cardTable tbody').innerHTML = cards.map((c) => `<tr>
    <td><b>${esc(c.name)}</b></td><td class="mono">${esc(c.uid)}</td><td>${esc(c.role)}</td>
    <td>${c.allDevices ? 'Semua pintu' : esc(c.devices.map(devName).join(', ') || '— tidak ada —')}</td>
    <td>${c.active ? '<span class="badge b-good">Aktif</span>' : '<span class="badge b-idle">Nonaktif</span>'}</td>
    <td>${c.lastAccess ? `${ago(c.lastAccess.ts)} <span class="muted sm">(${esc(c.lastAccess.status)})</span>` : '<span class="muted">-</span>'}</td>
    <td class="actions"><button class="btn sm" data-edit="${esc(c.uid)}">Ubah</button> <button class="btn sm danger" data-del="${esc(c.uid)}">Hapus</button></td></tr>`).join('')
    || '<tr><td colspan="7" class="muted">Belum ada kartu terdaftar.</td></tr>';
}
function cardForm(c = {}) {
  const devs = [...S.devices.values()];
  const isNew = !c.uid || c._new;
  $('#modalBody').innerHTML = `<h2 style="margin-top:0;color:var(--text)">${isNew ? 'Daftarkan kartu RFID' : 'Ubah kartu'}</h2>
  <form class="form" id="cardForm">
    <label>UID kartu (hex)<input name="uid" value="${esc(c.uid || '')}" ${isNew ? '' : 'readonly'} required placeholder="contoh: 93A1F40C" class="mono"></label>
    <label>Nama pemilik<input name="name" value="${esc(c.name || '')}" required></label>
    <label>Peran / keterangan<input name="role" value="${esc(c.role || '')}" placeholder="mis. Mahasiswa, Asisten Lab"></label>
    <label>Status<select name="active"><option value="1">Aktif</option><option value="0" ${c.active === false ? 'selected' : ''}>Nonaktif</option></select></label>
    <div class="full"><label>Hak akses pintu</label>
      <div class="checks"><label><input type="checkbox" name="all" ${c.allDevices !== false ? 'checked' : ''}> Semua pintu</label>
      ${devs.map((d) => `<label><input type="checkbox" name="dev" value="${esc(d.id)}" ${c.devices?.includes(d.id) ? 'checked' : ''}> ${esc(d.name)}</label>`).join('')}</div></div>
    <p class="muted sm full">Tip: tempelkan kartu baru di reader mana pun — UID-nya akan muncul di daftar “kartu tidak dikenal”.</p>
    <div class="row"><button type="button" class="btn" onclick="modal.close()">Batal</button><button class="btn primary">Simpan</button></div>
    <p class="err full" id="cardErr"></p>
  </form>`;
  $('#modal').showModal();
  $('#cardForm').onsubmit = async (e) => {
    e.preventDefault();
    const f = new FormData(e.target);
    const body = { uid: f.get('uid'), name: f.get('name'), role: f.get('role'), active: f.get('active') === '1', allDevices: !!f.get('all'), devices: f.getAll('dev') };
    try {
      if (isNew) await api('/panel/cards', { method: 'POST', body }); else await api('/panel/cards/' + c.uid, { method: 'PATCH', body });
      $('#modal').close(); toast('Kartu disimpan', ' Whitelist akan tersinkron otomatis ke perangkat.'); loadCards();
    } catch (err) { $('#cardErr').textContent = err.message; }
  };
}
$('#addCardBtn').onclick = () => cardForm();
$('#view-cards').addEventListener('click', async (e) => {
  const b = e.target.closest('button'); if (!b) return;
  if (b.dataset.reg) cardForm({ uid: b.dataset.reg, _new: true });
  if (b.dataset.edit) cardForm(S.cards.find((c) => c.uid === b.dataset.edit));
  if (b.dataset.del && confirm('Hapus kartu ini? Kartu tidak akan bisa membuka pintu lagi.')) { await api('/panel/cards/' + b.dataset.del, { method: 'DELETE' }); loadCards(); }
});

// ================================================================ PERANGKAT
async function loadDevices() {
  const devs = await api('/panel/devices');
  S.devices = new Map(devs.map((d) => [d.id, d]));
  $('#devTable tbody').innerHTML = devs.map((d) => `<tr><td class="mono">${esc(d.id)}</td><td><b>${esc(d.name)}</b></td><td>${esc(d.location)}</td>
    <td>${d.online ? '<span class="badge b-good">Online</span>' : '<span class="badge b-idle">Offline</span>'}</td>
    <td class="mono">${esc(d.fwVersion || '-')}</td><td class="mono">${esc(d.ip || '-')}</td><td>${ago(d.lastSeen)}</td>
    <td class="actions"><button class="btn sm" data-edit="${esc(d.id)}">Pengaturan</button> <button class="btn sm" data-key="${esc(d.id)}">API key</button> <button class="btn sm danger" data-del="${esc(d.id)}">Hapus</button></td></tr>`).join('')
    || '<tr><td colspan="8" class="muted">Belum ada perangkat.</td></tr>';
}
function firmwareSnippet(id, key) {
  return `// firmware/smart_door_esp32/config.h
#define WIFI_SSID     "NamaWiFi"
#define WIFI_PASS     "PasswordWiFi"
#define SERVER_URL    "${BASE}"
#define DEVICE_ID     "${id}"
#define DEVICE_KEY    "${key}"`;
}
function devForm(d) {
  const isNew = !d; const c = d?.config || { unlockMs: 5000, leftOpenAlarmS: 30, maxFail: 3, blockS: 30, telemetryIntervalS: 10 };
  $('#modalBody').innerHTML = `<h2 style="margin-top:0;color:var(--text)">${isNew ? 'Tambah perangkat (node pintu)' : 'Pengaturan ' + esc(d.name)}</h2>
  <form class="form" id="devForm">
    <label>ID perangkat<input name="id" value="${esc(d?.id || '')}" ${isNew ? '' : 'readonly'} required placeholder="door-lab-01" class="mono"></label>
    <label>Nama<input name="name" value="${esc(d?.name || '')}" required placeholder="Pintu Lab IoT"></label>
    <label class="full">Lokasi<input name="location" value="${esc(d?.location || '')}" placeholder="Gedung A Lt. 2"></label>
    <label>Batas waktu buka kunci (ms)<input type="number" name="unlockMs" value="${c.unlockMs}" min="1000" max="9000"></label>
    <label>Alarm pintu lupa ditutup (detik)<input type="number" name="leftOpenAlarmS" value="${c.leftOpenAlarmS}" min="5"></label>
    <label>Maks. kartu ditolak berturut-turut<input type="number" name="maxFail" value="${c.maxFail}" min="1"></label>
    <label>Lama blokir reader (detik)<input type="number" name="blockS" value="${c.blockS}" min="5"></label>
    <label>Interval telemetri periodik (detik)<input type="number" name="telemetryIntervalS" value="${c.telemetryIntervalS}" min="2"></label>
    <p class="muted sm full">Parameter dikirim ke ESP32 saat sinkronisasi; tidak perlu upload ulang firmware. Solenoid tidak boleh aktif &gt; 10 detik.</p>
    <div class="row"><button type="button" class="btn" onclick="modal.close()">Batal</button><button class="btn primary">Simpan</button></div>
    <p class="err full" id="devErr"></p>
  </form>`;
  $('#modal').showModal();
  $('#devForm').onsubmit = async (e) => {
    e.preventDefault();
    const f = Object.fromEntries(new FormData(e.target));
    const config = Object.fromEntries(['unlockMs', 'leftOpenAlarmS', 'maxFail', 'blockS', 'telemetryIntervalS'].map((k) => [k, Number(f[k])]));
    try {
      if (isNew) {
        const r = await api('/panel/devices', { method: 'POST', body: { id: f.id, name: f.name, location: f.location, config } });
        showKey(r.id, r.apiKey, true);
      } else {
        await api('/panel/devices/' + d.id, { method: 'PATCH', body: { name: f.name, location: f.location, config } });
        $('#modal').close(); toast('Pengaturan disimpan', ' Perangkat akan menyinkronkan konfigurasi baru.');
      }
      loadDevices();
    } catch (err) { $('#devErr').textContent = err.message; }
  };
}
function showKey(id, key, fresh) {
  $('#modalBody').innerHTML = `<h2 style="margin-top:0;color:var(--text)">${fresh ? 'Perangkat ditambahkan' : 'API key ' + esc(id)}</h2>
    <p class="muted">Salin konfigurasi ini ke <code>config.h</code> pada firmware ESP32. Simpan API key secara rahasia.</p>
    <pre class="code">${esc(firmwareSnippet(id, key))}</pre>
    <div class="row" style="display:flex;gap:8px;justify-content:flex-end"><button class="btn danger" id="rotateBtn">Buat ulang key</button><button class="btn primary" onclick="modal.close()">Selesai</button></div>`;
  if (!$('#modal').open) $('#modal').showModal();
  $('#rotateBtn').onclick = async () => {
    if (!confirm('Key lama akan langsung tidak berlaku. Lanjutkan?')) return;
    const r = await api(`/panel/devices/${id}/rotate-key`, { method: 'POST' }); showKey(id, r.apiKey);
  };
}
$('#addDevBtn').onclick = () => devForm();
$('#devTable').addEventListener('click', async (e) => {
  const b = e.target.closest('button'); if (!b) return;
  if (b.dataset.edit) devForm(S.devices.get(b.dataset.edit));
  if (b.dataset.key) { const r = await api(`/panel/devices/${b.dataset.key}/key`); showKey(b.dataset.key, r.apiKey); }
  if (b.dataset.del && confirm('Hapus perangkat? Log historisnya tetap tersimpan.')) { await api('/panel/devices/' + b.dataset.del, { method: 'DELETE' }); loadDevices(); }
});

// ================================================================ REALTIME (polling ringan, cocok untuk hosting PHP biasa)
let ovTimer, polling = false, failCount = 0;
const refreshOverviewSoon = () => { clearTimeout(ovTimer); ovTimer = setTimeout(async () => {
  if (S.view !== 'overview') return;
  const [ov, pres] = await Promise.all([api('/panel/overview'), api('/panel/presence')]);
  S.today = ov.today; S.alarmsToday = ov.alarmsToday; S.presence = pres;
  renderKpis(); renderPeople();
}, 600); };
function setLive(ok) {
  $('#liveDot').classList.toggle('off', !ok);
  $('#liveDot span').textContent = ok ? 'Realtime aktif' : 'Menyambung ulang…';
}
async function poll() {
  if (polling || document.hidden) return;
  polling = true;
  try {
    const r = await api('/panel/live?after=' + (S.lastId || 0));
    setLive(true); failCount = 0;
    const first = S.lastId === undefined;
    S.lastId = r.lastId;
    for (const d of r.devices) S.devices.set(d.id, d);
    if (S.view === 'overview') { renderDevices(); renderKpis(); }
    if (!first) for (const e of r.events) {
      addFeed(e);
      if (e.type === 'ALARM') {
        const d = describe(e);
        toast(`⚠ ${devName(e.device_id)}`, ' ' + d.t, 'crit');
        if (window.Notification?.permission === 'granted') new Notification(`Alarm: ${devName(e.device_id)}`, { body: d.t, tag: 'alarm-' + e.device_id });
      }
      if (e.type === 'ACCESS' && e.status === 'DITOLAK') toast(`Akses ditolak — ${devName(e.device_id)}`, ` UID ${e.uid || '-'}`);
    }
    if (r.events.length) refreshOverviewSoon();
  } catch (err) { if (++failCount > 1) setLive(false); }
  finally { polling = false; }
}
function connectStream() { poll(); setInterval(poll, 2000); }
setInterval(() => { $('#clock').textContent = fDT(Date.now()) + ' WIB'; }, 1000);
setInterval(() => { if (S.view === 'overview') renderDevices(); }, 15000); // segarkan label "x detik lalu"

// ---- notifikasi browser
$('#notifBtn').onclick = async () => {
  if (!('Notification' in window)) return toast('Browser tidak mendukung notifikasi');
  const p = await Notification.requestPermission();
  toast(p === 'granted' ? 'Notifikasi aktif' : 'Notifikasi ditolak', p === 'granted' ? ' Alarm akan muncul walau tab tidak dibuka.' : '');
  $('#notifBtn').hidden = p === 'granted';
};
if (window.Notification?.permission === 'granted') $('#notifBtn').hidden = true;

// ================================================================ MULAI
(function start() {
  connectStream();
  const v = location.hash.slice(1);
  go(['overview', 'logs', 'analytics', 'cards', 'devices'].includes(v) ? v : 'overview');
})();
