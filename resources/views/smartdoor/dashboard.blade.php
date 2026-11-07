<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Smart Door Cloud — Monitoring Akses Pintu</title>
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="stylesheet" href="{{ asset('smartdoor/style.css') }}">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect x='7' y='3' width='18' height='26' rx='2' fill='%232a78d6'/><circle cx='20' cy='17' r='2' fill='white'/></svg>">
</head>
<body>
<div id="app" class="app">
  <aside class="side">
    <div class="brand"><span class="logo"></span>Smart Door Cloud</div>
    <nav id="nav">
      <a data-view="overview" class="active">Dashboard</a>
      <a data-view="logs">Riwayat Akses</a>
      <a data-view="analytics">Analitik</a>
      <a data-view="cards">Kartu RFID</a>
      <a data-view="devices">Perangkat</a>
    </nav>
    <div class="side-foot">
      <div id="liveDot" class="live off"><i></i><span>Menghubungkan…</span></div>
      <button id="notifBtn" class="btn ghost sm">Aktifkan notifikasi</button>
      <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn ghost sm" style="width:100%">Keluar ({{ $user->name }})</button></form>
    </div>
  </aside>

  <main>
    <!-- ================= DASHBOARD ================= -->
    <section id="view-overview" class="view">
      <header class="vh"><h1>Dashboard</h1><span class="muted" id="clock"></span></header>
      <div class="kpis" id="kpis"></div>
      <h2>Pintu</h2>
      <div class="devgrid" id="devgrid"></div>
      <div class="cols">
        <div class="panel">
          <div class="ph"><h3>Aktivitas terbaru</h3><span class="muted sm">langsung dari perangkat</span></div>
          <ul class="feed" id="feed"></ul>
        </div>
        <div class="panel">
          <div class="ph"><h3>Sedang di dalam ruangan</h3></div>
          <ul class="people" id="people"></ul>
        </div>
      </div>
    </section>

    <!-- ================= LOG ================= -->
    <section id="view-logs" class="view hidden">
      <header class="vh"><h1>Riwayat Akses</h1><button class="btn" id="csvBtn">Unduh CSV</button></header>
      <form class="filters" id="logFilter">
        <select name="device"><option value="">Semua pintu</option></select>
        <select name="type">
          <option value="">Semua tipe</option><option value="ACCESS">Akses</option><option value="ALARM">Alarm</option>
          <option value="DOOR">Pintu buka/tutup</option><option value="ALARM_RESET">Reset alarm</option><option value="SYSTEM">Sistem</option>
        </select>
        <select name="status">
          <option value="">Semua status</option><option>MASUK</option><option>KELUAR</option><option>DITOLAK</option>
          <option>REMOTE</option><option>BATAL</option><option>FORCED_OPEN</option><option>DOOR_LEFT_OPEN</option><option>BRUTE_FORCE</option>
        </select>
        <input type="date" name="from"><input type="date" name="to">
        <input type="search" name="q" placeholder="Cari nama / UID">
        <button class="btn primary">Terapkan</button>
      </form>
      <div class="panel flush">
        <table class="tbl" id="logTable">
          <thead><tr><th>Waktu</th><th>Pintu</th><th>Kejadian</th><th>Nama</th><th>UID</th><th>Keterangan</th></tr></thead>
          <tbody></tbody>
        </table>
        <div class="pager"><span id="logInfo" class="muted sm"></span><span><button class="btn sm" id="prevPg">‹ Sebelumnya</button> <button class="btn sm" id="nextPg">Berikutnya ›</button></span></div>
      </div>
    </section>

    <!-- ================= ANALYTICS ================= -->
    <section id="view-analytics" class="view hidden">
      <header class="vh"><h1>Analitik</h1></header>
      <div class="filters" id="anFilter">
        <select id="anDevice"><option value="">Semua pintu</option></select>
        <div class="seg" id="anRange">
          <button data-d="1">Hari ini</button><button data-d="7" class="on">7 hari</button><button data-d="30">30 hari</button><button data-d="90">90 hari</button>
        </div>
        <button class="btn sm" id="anTableBtn">Lihat sebagai tabel</button>
      </div>
      <div class="kpis" id="anKpis"></div>
      <div class="panel">
        <div class="ph"><h3>Tren akses harian</h3><div class="legend" id="lgDaily"></div></div>
        <div class="chart" id="chDaily"></div>
      </div>
      <div class="cols">
        <div class="panel">
          <div class="ph"><h3>Akses per jam</h3><div class="legend" id="lgHourly"></div></div>
          <div class="chart" id="chHourly"></div>
        </div>
        <div class="panel">
          <div class="ph"><h3>Pola kedatangan (MASUK) hari × jam</h3></div>
          <div class="chart" id="chHeat"></div>
        </div>
      </div>
      <div class="cols three">
        <div class="panel"><div class="ph"><h3>Pengguna paling aktif</h3></div><div id="topUsers"></div></div>
        <div class="panel"><div class="ph"><h3>Kartu paling sering ditolak</h3></div><div id="deniedList"></div></div>
        <div class="panel"><div class="ph"><h3>Alarm berdasarkan jenis</h3></div><div id="alarmList"></div></div>
      </div>
      <div class="panel flush">
        <div class="ph pad"><h3>Perbandingan per pintu</h3></div>
        <table class="tbl" id="byDevTable"><thead><tr><th>Pintu</th><th class="r">Masuk</th><th class="r">Keluar</th><th class="r">Ditolak</th><th class="r">Remote</th><th class="r">Alarm</th><th class="r">Rata-rata pintu terbuka</th><th class="r">Ketersediaan</th></tr></thead><tbody></tbody></table>
      </div>
      <div class="panel" id="anTableWrap" hidden>
        <div class="ph"><h3>Data tren harian</h3></div>
        <table class="tbl" id="anTable"><thead><tr><th>Tanggal</th><th class="r">Masuk</th><th class="r">Keluar</th><th class="r">Ditolak</th><th class="r">Remote</th><th class="r">Alarm</th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <!-- ================= CARDS ================= -->
    <section id="view-cards" class="view hidden">
      <header class="vh"><h1>Kartu RFID</h1><button class="btn primary" id="addCardBtn">+ Daftarkan kartu</button></header>
      <div class="panel" id="unknownPanel" hidden>
        <div class="ph"><h3>Kartu tidak dikenal yang baru ditempelkan</h3><span class="muted sm">klik “Daftarkan” untuk memberi akses</span></div>
        <ul class="unknown" id="unknownList"></ul>
      </div>
      <div class="panel flush">
        <table class="tbl" id="cardTable"><thead><tr><th>Nama</th><th>UID</th><th>Peran</th><th>Akses pintu</th><th>Status</th><th>Akses terakhir</th><th></th></tr></thead><tbody></tbody></table>
      </div>
      <p class="muted sm">Whitelist disinkronkan otomatis ke setiap ESP32 dan disimpan di memori flash, sehingga pintu tetap bisa dibuka dengan kartu saat internet terputus.</p>
    </section>

    <!-- ================= DEVICES ================= -->
    <section id="view-devices" class="view hidden">
      <header class="vh"><h1>Perangkat</h1><button class="btn primary" id="addDevBtn">+ Tambah perangkat</button></header>
      <div class="panel flush">
        <table class="tbl" id="devTable"><thead><tr><th>ID</th><th>Nama</th><th>Lokasi</th><th>Status</th><th>Firmware</th><th>IP</th><th>Terakhir terlihat</th><th></th></tr></thead><tbody></tbody></table>
      </div>
    </section>
  </main>
</div>

<!-- Modal generik -->
<dialog id="modal"><form method="dialog" class="modal-x"><button aria-label="Tutup">×</button></form><div id="modalBody"></div></dialog>
<div id="toasts"></div>
<div id="tip" class="tip"></div>

<script>window.SMARTDOOR = { base: @json(url('/')), user: @json($user->name) };</script>
<script src="{{ asset('smartdoor/charts.js') }}"></script>
<script src="{{ asset('smartdoor/app.js') }}"></script>
</body>
</html>
