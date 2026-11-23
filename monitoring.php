<?php
/**
 * ============================================================
 *  monitoring.php
 *  TUJUAN: Dashboard lengkap — kartu realtime, grafik tren,
 *  statistik periode, tabel riwayat, dan tombol unduh laporan.
 *
 *  ESP32 mengirim data via HTTP POST setiap 20 detik ke
 *  api/inputsuhu.php — halaman ini lah yang MEMBACA data itu
 *  dan menampilkannya, dengan cara polling AJAX setiap 20 detik
 *  juga (supaya selalu sinkron dengan jadwal kirim ESP32).
 *
 *  File ini punya 2 "wajah" sekaligus:
 *  - Kalau dipanggil dengan ?ajax=... → balas JSON saja (dipakai JS)
 *  - Kalau dipanggil biasa             → tampilkan halaman HTML lengkap
 * ============================================================
 */
/**
 * ============================================================
 *  monitoring.php
 *  TUJUAN: Dashboard lengkap — kartu realtime, grafik tren,
 *  statistik periode, tabel riwayat, dan tombol unduh laporan.
 *
 *  ESP32 mengirim data via HTTP POST setiap 20 detik ke
 *  api/inputsuhu.php — halaman ini lah yang MEMBACA data itu
 *  dan menampilkannya, dengan cara polling AJAX setiap 20 detik
 *  juga (supaya selalu sinkron dengan jadwal kirim ESP32).
 *
 *  File ini punya 2 "wajah" sekaligus:
 *  - Kalau dipanggil dengan ?ajax=... → balas JSON saja (dipakai JS)
 *  - Kalau dipanggil biasa             → tampilkan halaman HTML lengkap
 * ============================================================
 */
require __DIR__ . '/config/koneksi.php';

// ── Fungsi bantu: query yang tidak akan bikin halaman crash ──
// kalau tabelnya kosong atau query gagal (dipakai berkali-kali
// di bawah, daripada menulis pengecekan error berulang-ulang).
function safeRow($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    return ($r && $r !== true) ? mysqli_fetch_assoc($r) : null;
}
function safeRes($conn, $sql) {
    $r = mysqli_query($conn, $sql);
    return ($r && $r !== true) ? $r : false;
}

// ── Lokasi ruangan yang sedang diminta/ditampilkan ──────────
// Dipakai oleh KEDUA "wajah" file ini (AJAX & halaman HTML) supaya
// switcher Ruang Atas/Ruang Bawah di toolbar dan semua endpoint AJAX
// selalu konsisten melihat ruangan yang sama. Default 'atas' kalau
// parameter tidak dikirim (lihat validasiLokasi() di koneksi.php).
$lokasiRaw = $_GET['lokasi'] ?? null;
$lokasi    = validasiLokasi(is_string($lokasiRaw) ? $lokasiRaw : null);

// ════════════════════════════════════════════════════════════
//  WAJAH 1: AJAX ENDPOINTS
//  Dipanggil oleh JavaScript di halaman ini sendiri (lihat
//  bagian <script> di paling bawah file), BUKAN oleh ESP32.
// ════════════════════════════════════════════════════════════
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    switch ($_GET['ajax']) {

        // ?ajax=latest → angka suhu/kelembaban TERBARU untuk kartu atas
        case 'latest':
            $lokEsc = mysqli_real_escape_string($conn, $lokasi);
            $row = safeRow($conn,
                "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
                 FROM monitoring_suhu WHERE lokasi = '$lokEsc' ORDER BY created_at DESC LIMIT 1");
            echo json_encode($row ?? null);
            break;

        // ?ajax=chart → data untuk menggambar grafik tren garis
        case 'chart':
            $fs   = mysqli_real_escape_string($conn, $_GET['start'] ?? date('Y-m-d'));
            $fe   = mysqli_real_escape_string($conn, $_GET['end']   ?? date('Y-m-d'));
            $lokEsc = mysqli_real_escape_string($conn, $lokasi);
            $diff = max(0, (strtotime($fe) - strtotime($fs)) / 86400);
            $gfmt = $diff >= 2 ? '%Y-%m-%d %H:00' : '%Y-%m-%d %H:%i';
            $cr   = safeRes($conn,
                "SELECT DATE_FORMAT(created_at,'$gfmt') lbl,
                        ROUND(AVG(suhu),1) s, ROUND(AVG(kelembaban),1) h
                 FROM monitoring_suhu
                 WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$fs' AND '$fe'
                 GROUP BY lbl ORDER BY MIN(created_at) ASC LIMIT 500");
            $out = ['labels'=>[],'suhu'=>[],'humid'=>[]];
            if ($cr) while ($row = mysqli_fetch_assoc($cr)) {
                $out['labels'][] = $row['lbl'];
                $out['suhu'][]   = (float)$row['s'];
                $out['humid'][]  = (float)$row['h'];
            }
            echo json_encode($out);
            break;

        // ?ajax=table → tabel HTML 25 data terbaru (auto-refresh tanpa reload halaman)
        case 'table':
            $s = date('Y-m-d');
            $lokEsc = mysqli_real_escape_string($conn, $lokasi);
            $rR = safeRes($conn,
                "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
                 FROM monitoring_suhu WHERE lokasi = '$lokEsc' ORDER BY created_at DESC LIMIT 25");
            $html = '';
            $n = 1;
            if ($rR && mysqli_num_rows($rR) > 0) {
                while ($r = mysqli_fetch_assoc($rR)) {
                    $ss = $r['status_suhu']==='bahaya' ? 'err' : ($r['status_suhu']==='waspada' ? 'warn' : 'ok');
                    $sh = $r['status_humid']==='bahaya' ? 'err' : ($r['status_humid']==='waspada' ? 'warn' : 'ok');
                    $date = date('d M Y', strtotime($r['created_at']));
                    $time = date('H:i:s', strtotime($r['created_at']));
                    
                    $html .= "<tr>
                        <td>{$n}</td>
                        <td>{$date}</td>
                        <td>{$time}</td>
                        <td><strong>{$r['suhu']}°C</strong></td>
                        <td><span class='badge badge-{$ss}'>" . strtoupper($r['status_suhu']) . "</span></td>
                        <td><strong>{$r['kelembaban']}%</strong></td>
                        <td><span class='badge badge-{$sh}'>" . strtoupper($r['status_humid']) . "</span></td>
                    </tr>";
                    $n++;
                }
            } else {
                $html = '<tr><td colspan="7"><div class="no-data">Belum ada data</div></td></tr>';
            }
            echo $html;
            break;

        // ?ajax=pdfdata → JSON semua data periode untuk dirender jsPDF client-side
        case 'pdfdata':
            $fs = mysqli_real_escape_string($conn, $_GET['start'] ?? date('Y-m-d'));
            $fe = mysqli_real_escape_string($conn, $_GET['end']   ?? date('Y-m-d'));
            $lokEsc = mysqli_real_escape_string($conn, $lokasi);

            $stPdf = safeRow($conn,
                "SELECT ROUND(AVG(suhu),1) avg_s, MAX(suhu) max_s, MIN(suhu) min_s,
                        ROUND(AVG(kelembaban),1) avg_h, MAX(kelembaban) max_h, MIN(kelembaban) min_h,
                        COUNT(*) total,
                        SUM(status_suhu='bahaya') al_s, SUM(status_humid='bahaya') al_h
                 FROM monitoring_suhu
                 WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$fs' AND '$fe'")
                ?: [];

            $rPdf  = safeRes($conn,
                "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
                 FROM monitoring_suhu
                 WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$fs' AND '$fe'
                 ORDER BY created_at ASC LIMIT 500");

            $tableRows = [];
            $n = 1;
            if ($rPdf) while ($r = mysqli_fetch_assoc($rPdf)) {
                $tableRows[] = [
                    $n++,
                    date('d M Y', strtotime($r['created_at'])),
                    date('H:i:s', strtotime($r['created_at'])),
                    number_format((float)$r['suhu'],       1) . '°C',
                    strtoupper($r['status_suhu']),
                    number_format((float)$r['kelembaban'], 1) . '%',
                    strtoupper($r['status_humid']),
                ];
            }

            echo json_encode([
                'lokasi'      => labelLokasi($lokasi),
                'stats'       => $stPdf,
                'alert_suhu'  => (int)($stPdf['al_s']  ?? 0),
                'alert_humid' => (int)($stPdf['al_h']  ?? 0),
                'total'       => (int)($stPdf['total'] ?? 0),
                'rows'        => $tableRows,
            ]);
            break;

        default:
            echo json_encode(['error'=>'Unknown action']);
    }
    exit;
}


// ════════════════════════════════════════════════════════════
//  WAJAH 2: HALAMAN HTML LENGKAP
//  Kode di bawah ini hanya jalan kalau halaman dibuka TANPA
//  parameter ?ajax= — yaitu saat dashboard pertama kali dimuat.
// ════════════════════════════════════════════════════════════

// ── Tentukan rentang tanggal sesuai filter yang dipilih user ──
$filter = in_array($_GET['filter'] ?? '', ['today','week','month','custom'])
          ? $_GET['filter'] : 'today';
$cs = mysqli_real_escape_string($conn, $_GET['start'] ?? '');
$ce = mysqli_real_escape_string($conn, $_GET['end']   ?? '');

switch ($filter) {
    case 'week':
        $s = date('Y-m-d', strtotime('monday this week'));
        $e = date('Y-m-d', strtotime('sunday this week'));
        $lbl_period = 'Minggu Ini — ' . date('d M', strtotime($s)) . ' s/d ' . date('d M Y', strtotime($e));
        break;
    case 'month':
        $s = date('Y-m-01'); $e = date('Y-m-t');
        $lbl_period = 'Bulan Ini — ' . date('F Y');
        break;
    case 'custom':
        $s = $cs ?: date('Y-m-d'); $e = $ce ?: date('Y-m-d');
        $lbl_period = date('d M Y', strtotime($s)) . ' s/d ' . date('d M Y', strtotime($e));
        break;
    default:
        $s = $e = date('Y-m-d');
        $lbl_period = 'Hari Ini, ' . date('d F Y');
}

// ── Escape lokasi sekali di sini, dipakai di semua query WAJAH 2 ──
$lokEsc = mysqli_real_escape_string($conn, $lokasi);

// ── Ambil data untuk menggambar grafik (load pertama kali, sebelum AJAX jalan) ──
$diff_days = max(0, (strtotime($e) - strtotime($s)) / 86400);
$grp = $diff_days >= 2 ? '%Y-%m-%d %H:00' : '%Y-%m-%d %H:%i';
$cL = $cS = $cH = [];
$cr = safeRes($conn,
    "SELECT DATE_FORMAT(created_at,'$grp') lbl,
            ROUND(AVG(suhu),1) s, ROUND(AVG(kelembaban),1) h
     FROM monitoring_suhu
     WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$s' AND '$e'
     GROUP BY lbl ORDER BY MIN(created_at) ASC LIMIT 500");
if ($cr) while ($r = mysqli_fetch_assoc($cr)) {
    $cL[] = $r['lbl']; $cS[] = (float)$r['s']; $cH[] = (float)$r['h'];
}

// ── Hitung rata-rata, maksimum, minimum, dan total alert periode ini ──
$st = safeRow($conn,
    "SELECT COUNT(*) total,
            ROUND(AVG(suhu),1) avg_s, MAX(suhu) max_s, MIN(suhu) min_s,
            ROUND(AVG(kelembaban),1) avg_h, MAX(kelembaban) max_h, MIN(kelembaban) min_h,
            SUM(status_suhu='bahaya') al_s, SUM(status_humid='bahaya') al_h
     FROM monitoring_suhu
     WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$s' AND '$e'")
?: ['total'=>0,'avg_s'=>'-','max_s'=>'-','min_s'=>'-',
    'avg_h'=>'-','max_h'=>'-','min_h'=>'-','al_s'=>0,'al_h'=>0];

// ── Ambil 1 data paling baru — untuk mengisi kartu sebelum AJAX jalan ──
$lt = safeRow($conn,
    "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
     FROM monitoring_suhu WHERE lokasi = '$lokEsc' ORDER BY created_at DESC LIMIT 1") ?: [];

// ── Ambil 25 baris terbaru untuk tabel riwayat di bagian bawah halaman ──
$rR = safeRes($conn,
    "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
     FROM monitoring_suhu
     WHERE lokasi = '$lokEsc' AND DATE(created_at) BETWEEN '$s' AND '$e'
     ORDER BY created_at DESC LIMIT 25");
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitoring Suhu & Kelembaban — <?=htmlspecialchars(labelLokasi($lokasi))?> | KIPMAIN</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="icon" type="image/x-icon" href="assets/favicon.ico">
<!-- Chart.js — TIDAK ada MQTT CDN, diganti HTTP polling -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<!-- jsPDF + AutoTable — untuk ekspor laporan ke PDF client-side -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<style>
:root {
    --primary:#0f172a; --accent:#0284c7; --green:#10b981;
    --yellow:#f59e0b;  --red:#ef4444;    --border:#cbd5e1;
    --text:#1e293b;    --muted:#64748b;  --bg:#f8fafc;
}
*,*::before,*::after { box-sizing: border-box }
body { font-family:'Inter',-apple-system,sans-serif; background:var(--bg);
       color:var(--text); margin:0; padding:20px;
       -webkit-print-color-adjust:exact; print-color-adjust:exact }
.container { max-width:1080px; margin:0 auto; background:#fff; padding:28px;
             border-radius:10px; box-shadow:0 4px 20px rgba(0,0,0,.06) }

/* ── Header ── */
.rpt-header { display:flex; align-items:center; justify-content:space-between;
              border-bottom:3px solid var(--primary); padding-bottom:12px; margin-bottom:18px }
.rpt-header h1 { margin:0; font-size:20px; font-weight:800; color:var(--primary) }
.rpt-sub { margin:3px 0 0; font-size:12px; font-weight:700; color:var(--accent) }
.logo { height:42px }

/* ── Toolbar ── */
.toolbar { display:flex; justify-content:space-between; align-items:center;
           flex-wrap:wrap; gap:8px; margin-bottom:14px }
.btn-group { display:flex; gap:6px; flex-wrap:wrap; align-items:center }
.btn { display:inline-flex; align-items:center; gap:6px; padding:7px 13px;
       border-radius:6px; font-size:12px; font-weight:600; border:1px solid var(--border);
       background:#fff; color:var(--text); text-decoration:none; cursor:pointer;
       transition:.2s; white-space:nowrap }
.btn:hover { background:#f1f5f9; border-color:var(--accent) }
.btn-accent { background:var(--accent); color:#fff; border-color:var(--accent) }
.btn-green  { background:var(--green);  color:#fff; border-color:var(--green) }
.btn-dark   { background:var(--primary);color:#fff; border-color:var(--primary) }

/* ── Pills ── */
.filter-pills { display:flex; background:#f1f5f9; border-radius:8px; padding:3px; gap:2px }
.pill { padding:5px 12px; border-radius:6px; font-size:11px; font-weight:700;
        text-decoration:none; color:var(--muted); background:transparent;
        border:none; cursor:pointer; transition:.15s }
.pill.active { background:#fff; color:var(--primary); box-shadow:0 1px 4px rgba(0,0,0,.1) }

/* ── Room Switch (Ruang Atas / Ruang Bawah) ── */
.room-switch { display:flex; gap:8px; margin-bottom:14px }
.room-pill { flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
             padding:10px; border-radius:8px; border:1.5px solid var(--border);
             background:#fff; color:var(--muted); font-size:12.5px; font-weight:800;
             text-decoration:none; transition:.15s }
.room-pill:hover { border-color:var(--accent); color:var(--accent) }
.room-pill.active { border-color:var(--accent); background:#f0f9ff; color:var(--accent);
                     box-shadow:0 1px 4px rgba(2,132,199,.15) }

/* ── Date Bar ── */
.date-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding:10px 14px;
            background:#f0f9ff; border:1px solid var(--accent); border-radius:8px;
            margin-bottom:12px; font-size:12px; font-weight:600 }
.date-bar input[type="date"] { padding:5px 10px; border:1px solid var(--border);
    border-radius:6px; font:12px 'Inter',sans-serif; color:var(--text); cursor:pointer }

/* ── Period Banner ── */
.period-banner { text-align:center; padding:7px; border-radius:6px; background:#f0f9ff;
                 font-size:13px; font-weight:800; color:var(--accent); margin-bottom:14px }

/* ── Connection Status Bar ── */
.conn-bar { display:flex; align-items:center; gap:8px; padding:9px 14px; border-radius:6px;
            margin-bottom:14px; font-size:12px; font-weight:600; background:#fef9c3;
            border:1px solid var(--yellow); color:#713f12; transition:all .3s }
.conn-bar.ok  { background:#dcfce7; border-color:var(--green); color:#14532d }
.conn-bar.err { background:#fee2e2; border-color:var(--red);   color:#7f1d1d }
.conn-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0;
            background:var(--yellow); animation:blink 1.2s ease-in-out infinite }
.conn-dot.ok  { background:var(--green); animation:none }
.conn-dot.err { background:var(--red);   animation:none }
.conn-timer { margin-left:auto; font-size:10px; font-weight:700;
              background:rgba(0,0,0,.08); padding:2px 7px;
              border-radius:20px; white-space:nowrap }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

/* ── Cards ── */
.card-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin-bottom:14px }
.card { border:1.5px solid var(--border); border-radius:10px; padding:14px 12px;
        background:#fff; display:flex; flex-direction:column; gap:5px;
        transition:border-color .3s,background .3s,box-shadow .3s }
.card.ok   { border-color:var(--green); background:#f0fdf4 }
.card.warn { border-color:var(--yellow);background:#fffbeb }
.card.err  { border-color:var(--red);   background:#fff1f2; box-shadow:0 0 0 3px rgba(239,68,68,.12) }
.card-lbl  { font-size:10px; font-weight:700; text-transform:uppercase;
             letter-spacing:.5px; color:var(--muted) }
.card-val  { font-size:36px; font-weight:900; line-height:1;
             color:var(--primary); transition:color .3s }
.card-val.warn { color:var(--yellow) }
.card-val.err  { color:var(--red) }
.card-unit { font-size:11px; font-weight:600; color:var(--muted); margin-top:-2px }
.card-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 9px;
              border-radius:20px; font-size:10px; font-weight:800; text-transform:uppercase;
              background:#dcfce7; color:#14532d; width:fit-content; letter-spacing:.3px }
.card-badge.warn { background:#fef3c7; color:#78350f }
.card-badge.err  { background:#fee2e2; color:#991b1b }
.prog { height:4px; background:#e2e8f0; border-radius:2px; overflow:hidden; margin-top:2px }
.prog-bar { height:100%; background:var(--green); border-radius:2px;
            transition:width .6s ease, background .3s }

/* ── Stat Grid ── */
.stat-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:10px; margin-bottom:14px }
.stat { border:1px solid var(--border); border-radius:8px; padding:10px 6px;
        text-align:center; background:var(--bg) }
.stat-val { font-size:22px; font-weight:900; line-height:1.1 }
.stat-lbl { font-size:9px; text-transform:uppercase; font-weight:700;
            color:#94a3b8; letter-spacing:.5px; margin-top:3px }

/* ── Section Header ── */
.sec { display:flex; align-items:center; justify-content:space-between;
       margin:20px 0 10px; padding-bottom:8px; border-bottom:1.5px solid var(--border) }
.sec-title { font-size:13px; font-weight:800; color:var(--primary) }

/* ── Chart ── */
.chart-wrap { position:relative; height:280px }

/* ── Table ── */
.tbl-wrap { overflow-x:auto; margin-top:2px }
table { width:100%; border-collapse:collapse; font-size:12px }
thead th { background:var(--primary); color:#fff; padding:8px 10px; text-align:left;
           font-size:10px; text-transform:uppercase; font-weight:700; white-space:nowrap }
tbody td { padding:7px 10px; border-bottom:1px solid #f1f5f9 }
tbody tr:hover td { background:#f8fafc }
.badge { display:inline-block; padding:2px 8px; border-radius:20px;
         font-size:10px; font-weight:700; text-transform:uppercase }
.badge-ok   { background:#dcfce7; color:#166534 }
.badge-warn { background:#fef3c7; color:#78350f }
.badge-err  { background:#fee2e2; color:#991b1b }

/* ── Alert Flash ── */
.alert-flash { display:none; position:fixed; top:16px; right:16px; z-index:999;
               padding:12px 18px; border-radius:10px; background:var(--red); color:#fff;
               font-size:13px; font-weight:700; box-shadow:0 4px 20px rgba(239,68,68,.4);
               animation:slideIn .4s ease; align-items:center; gap:10px }
@keyframes slideIn { from{transform:translateX(120%);opacity:0} to{transform:none;opacity:1} }

/* ── No Data Banner ── */
.no-data { text-align:center; padding:40px 20px; color:var(--muted) }
.no-data i { font-size:40px; margin-bottom:12px; display:block }

/* ── Print ── */
@media print {
    @page { size:A4 portrait; margin:1cm }
    body { background:#fff; padding:0 }
    .container { box-shadow:none }
    .toolbar,.conn-bar,.date-bar,.alert-flash,.filter-pills { display:none!important }
    .chart-wrap { height:220px }
}

/* ── Download Modal ─────────────────────────────────────────── */
.dl-overlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.55); z-index:800;
    align-items:center; justify-content:center;
    backdrop-filter:blur(2px);
}
.dl-overlay.open { display:flex }
.dl-modal {
    background:#fff; border-radius:16px; padding:28px 28px 22px;
    width:480px; max-width:95vw; max-height:92vh; overflow-y:auto;
    box-shadow:0 24px 60px rgba(0,0,0,.22);
    animation:dlIn .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes dlIn { from{transform:translateY(-18px) scale(.97);opacity:0} to{transform:none;opacity:1} }
.dl-modal h2 { margin:0 0 3px; font-size:17px; font-weight:800; color:var(--primary) }
.dl-sub      { margin:0 0 20px; font-size:12px; color:var(--muted) }
.dl-label    { font-size:10px; font-weight:800; text-transform:uppercase;
               letter-spacing:.8px; color:var(--muted); margin-bottom:8px; display:block }

/* Period pills */
.dl-pills    { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px }
.dl-pill     { padding:6px 14px; border-radius:7px; font-size:12px; font-weight:600;
               border:1.5px solid var(--border); background:var(--bg); color:var(--muted);
               cursor:pointer; transition:.15s; user-select:none }
.dl-pill:hover    { border-color:var(--accent); background:#f0f9ff }
.dl-pill.active   { border-color:var(--accent); background:#e0f2fe; color:var(--accent) }

/* Custom range row */
.dl-range    { display:none; align-items:center; gap:8px; flex-wrap:wrap;
               margin-bottom:16px; font-size:12px; font-weight:600; color:var(--text) }
.dl-range.show { display:flex }
.dl-range input { padding:6px 10px; border:1.5px solid var(--border); border-radius:6px;
                  font:12px 'Inter',sans-serif; color:var(--text); cursor:pointer }

/* Format cards */
.dl-fmt-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-bottom:18px }
.dl-fmt-card {
    border:2px solid var(--border); border-radius:12px; padding:16px 10px 14px;
    text-align:center; cursor:pointer; transition:.2s; background:var(--bg); position:relative
}
.dl-fmt-card:hover  { border-color:var(--accent); background:#f0f9ff }
.dl-fmt-card.active { border-color:var(--accent); background:#e0f2fe }
.dl-fmt-card i      { font-size:26px; display:block; margin-bottom:8px }
.dl-fmt-card .fmt-name  { font-size:12px; font-weight:800; display:block; color:var(--primary) }
.dl-fmt-card .fmt-desc  { font-size:10px; color:var(--muted); display:block; margin-top:3px; line-height:1.4 }
.fmt-csv   { color:#16a34a }
.fmt-excel { color:#15803d }
.fmt-pdf   { color:#dc2626 }
.dl-fmt-card.active .fmt-name { color:var(--accent) }

/* Note box */
.dl-note {
    font-size:11px; color:#1e40af; background:#eff6ff;
    border:1px solid #bfdbfe; border-radius:8px;
    padding:9px 12px; margin-bottom:18px; display:none
}
.dl-note.show { display:block }
.dl-note i    { margin-right:5px }

/* Actions */
.dl-actions { display:flex; gap:8px; justify-content:flex-end }
.dl-spinner { display:none; width:16px; height:16px; border:2.5px solid #fff;
              border-top-color:transparent; border-radius:50%;
              animation:spin .6s linear infinite; flex-shrink:0 }
@keyframes spin { to{transform:rotate(360deg)} }
/* ── Responsive ── */
@media(max-width:880px) {
    .card-grid { grid-template-columns:repeat(2,1fr) }
    .stat-grid { grid-template-columns:repeat(3,1fr) }
}
@media(max-width:560px) {
    body { padding:10px } .container { padding:14px }
    .rpt-header { flex-direction:column; text-align:center; gap:8px }
    .card-grid { grid-template-columns:1fr 1fr }
    .stat-grid { grid-template-columns:repeat(2,1fr) }
    .card-val  { font-size:28px }
}
</style>
</head>
<body>

<!-- Alert Flash -->
<div class="alert-flash" id="alertFlash">
    <i class="fas fa-triangle-exclamation"></i>
    <span id="alertMsg"></span>
</div>

<div class="container">

<!-- ══ HEADER ═══════════════════════════════════════════════════ -->
<div class="rpt-header">
    <img src="assets/logo.png" alt="KIPMAIN" class="logo"
         onerror="this.style.display='none'">
    <div style="text-align:right">
        <h1><i class="fas fa-temperature-half" style="color:var(--accent)"></i>
            Monitoring Suhu &amp; Kelembaban</h1>
        <p class="rpt-sub">
            <?=htmlspecialchars(labelLokasi($lokasi))?> &nbsp;|&nbsp; IoT System — ESP32 + DHT22 &nbsp;|&nbsp; HTTP Polling Real-time
        </p>
    </div>
</div>

<!-- ══ SWITCHER RUANGAN ═════════════════════════════════════════ -->
<!-- Kapasitor bank punya 2 ruangan terpisah, masing-masing dengan
     unit ESP32 sendiri. Pindah ruangan = reload halaman dengan
     ?lokasi=... baru; filter tanggal yang sedang aktif tetap
     dipertahankan supaya tidak balik ke "Hari Ini" tiap ganti ruang. -->
<div class="room-switch">
    <a class="room-pill <?=$lokasi==='atas'  ?'active':''?>"
       href="?lokasi=atas&filter=<?=urlencode($filter)?><?=$filter==='custom' ? '&start='.urlencode($s).'&end='.urlencode($e) : ''?>">
        <i class="fas fa-arrow-up"></i> Ruang Atas
    </a>
    <a class="room-pill <?=$lokasi==='bawah' ?'active':''?>"
       href="?lokasi=bawah&filter=<?=urlencode($filter)?><?=$filter==='custom' ? '&start='.urlencode($s).'&end='.urlencode($e) : ''?>">
        <i class="fas fa-arrow-down"></i> Ruang Bawah
    </a>
</div>

<!-- ══ TOOLBAR ═══════════════════════════════════════════════════ -->
<div class="toolbar">
    <div class="btn-group">
        <a href="/index.php" class="btn"><i class="fas fa-home"></i> Portal Utama</a>
        <div class="filter-pills">
            <a href="?filter=today"  class="pill <?= $filter==='today'  ?'active':''?>">Hari Ini</a>
            <a href="?filter=week"   class="pill <?= $filter==='week'   ?'active':''?>">Mingguan</a>
            <a href="?filter=month"  class="pill <?= $filter==='month'  ?'active':''?>">Bulanan</a>
            <a href="?filter=custom" class="pill <?= $filter==='custom' ?'active':''?>">Custom</a>
        </div>
    </div>
    <div class="btn-group">
        <button onclick="openDlModal()" class="btn btn-green">
            <i class="fas fa-download"></i> Unduh Laporan
        </button>
        <button onclick="window.print()" class="btn">
            <i class="fas fa-print"></i> Cetak
        </button>
    </div>
</div>

<!-- Custom Date Range -->
<?php if ($filter==='custom'): ?>
<div class="date-bar">
    <i class="fas fa-calendar-range" style="color:var(--accent)"></i>
    <label><strong>Dari:</strong></label>
    <input type="date" id="inS" value="<?=htmlspecialchars($s)?>">
    <label><strong>s/d:</strong></label>
    <input type="date" id="inE" value="<?=htmlspecialchars($e)?>">
    <button class="btn btn-accent" onclick="goCust()">
        <i class="fas fa-magnifying-glass"></i> Tampilkan
    </button>
</div>
<?php endif; ?>

<div class="period-banner">
    <i class="fas fa-calendar-check"></i> <?=htmlspecialchars($lbl_period)?>
</div>

<!-- ══ STATUS KONEKSI (HTTP Polling) ════════════════════════════ -->
<div class="conn-bar" id="connBar">
    <div class="conn-dot" id="connDot"></div>
    <span id="connTxt">
        <i class="fas fa-rotate fa-spin"></i> Memulai koneksi polling data dari server…
    </span>
    <span class="conn-timer" id="connTimer">⟳ --s</span>
</div>

<!-- ══ CARDS ═════════════════════════════════════════════════════ -->
<div class="card-grid">
    <!-- Suhu -->
    <div class="card" id="cSuhu">
        <div class="card-lbl"><i class="fas fa-thermometer-half"></i> Suhu</div>
        <div style="display:flex;align-items:baseline;gap:4px">
            <div class="card-val" id="vSuhu"><?=isset($lt['suhu'])?(float)$lt['suhu']:'–'?></div>
            <div class="card-unit">°C</div>
        </div>
        <div class="prog"><div class="prog-bar" id="bSuhu"
            style="width:<?=isset($lt['suhu'])?min((float)$lt['suhu']/60*100,100):0?>%"></div></div>
        <div class="card-badge" id="bdSuhu">
            <i class="fas fa-circle" style="font-size:6px"></i>
            <span id="bdSuhuTxt">AMAN</span>
        </div>
    </div>

    <!-- Kelembaban -->
    <div class="card" id="cHumid">
        <div class="card-lbl"><i class="fas fa-droplet"></i> Kelembaban</div>
        <div style="display:flex;align-items:baseline;gap:4px">
            <div class="card-val" id="vHumid"><?=isset($lt['kelembaban'])?(float)$lt['kelembaban']:'–'?></div>
            <div class="card-unit">% RH</div>
        </div>
        <div class="prog"><div class="prog-bar" id="bHumid"
            style="width:<?=isset($lt['kelembaban'])?min((float)$lt['kelembaban'],100):0?>%;background:#0284c7"></div></div>
        <div class="card-badge" id="bdHumid">
            <i class="fas fa-circle" style="font-size:6px"></i>
            <span id="bdHumidTxt">AMAN</span>
        </div>
    </div>

    <!-- Kondisi -->
    <div class="card" id="cCond">
        <div class="card-lbl"><i class="fas fa-shield-halved"></i> Kondisi Ruangan</div>
        <div class="card-val" id="vCond" style="font-size:22px;margin-top:4px">AMAN</div>
        <div class="card-unit" id="vCondDesc">Suhu &amp; Kelembaban Normal</div>
        <div style="margin-top:auto;padding-top:8px">
            <i id="condIcon" class="fas fa-circle-check fa-xl" style="color:var(--green)"></i>
        </div>
    </div>

    <!-- Last Update -->
    <div class="card">
        <div class="card-lbl"><i class="fas fa-clock"></i> Update Terakhir</div>
        <div class="card-val" id="vTime" style="font-size:24px;margin-top:4px">
            <?=!empty($lt['created_at'])?date('H:i:s',strtotime($lt['created_at'])):'--:--:--'?>
        </div>
        <div class="card-unit">WIB</div>
        <div id="vDate" style="font-size:11px;color:var(--muted);margin-top:3px">
            <?=!empty($lt['created_at'])?date('d M Y',strtotime($lt['created_at'])):'–'?>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:5px">
            <strong><?=number_format((int)($st['total']??0))?></strong> record periode ini
        </div>
    </div>
</div>

<!-- ══ STATISTIK ══════════════════════════════════════════════════ -->
<div class="sec">
    <div class="sec-title"><i class="fas fa-chart-bar"></i> Statistik Periode</div>
    <small style="color:var(--muted);font-size:11px"><?=htmlspecialchars($lbl_period)?></small>
</div>
<div class="stat-grid">
    <div class="stat">
        <div class="stat-val" style="color:var(--red)"><?=$st['avg_s']??'-'?>°</div>
        <div class="stat-lbl">Rata² Suhu</div>
    </div>
    <div class="stat">
        <div class="stat-val" style="color:#dc2626"><?=$st['max_s']??'-'?>°</div>
        <div class="stat-lbl">Maks. Suhu</div>
    </div>
    <div class="stat">
        <div class="stat-val" style="color:var(--green)"><?=$st['min_s']??'-'?>°</div>
        <div class="stat-lbl">Min. Suhu</div>
    </div>
    <div class="stat">
        <div class="stat-val" style="color:var(--accent)"><?=$st['avg_h']??'-'?>%</div>
        <div class="stat-lbl">Rata² Humid</div>
    </div>
    <div class="stat">
        <div class="stat-val" style="color:#0369a1"><?=$st['max_h']??'-'?>%</div>
        <div class="stat-lbl">Maks. Humid</div>
    </div>
    <div class="stat">
        <div class="stat-val" style="color:var(--red)">
            <?=((int)($st['al_s']??0))+((int)($st['al_h']??0))?>
        </div>
        <div class="stat-lbl">Total Alert</div>
    </div>
</div>

<!-- ══ GRAFIK ═════════════════════════════════════════════════════ -->
<div class="sec">
    <div class="sec-title"><i class="fas fa-chart-line"></i> Grafik Tren Sensor</div>
    <div class="btn-group">
        <button class="btn" style="font-size:11px" onclick="toggleDS(0)">
            <span style="color:#ef4444">●</span> Suhu
        </button>
        <button class="btn" style="font-size:11px" onclick="toggleDS(1)">
            <span style="color:#0284c7">●</span> Kelembaban
        </button>
        <button class="btn" style="font-size:11px" onclick="refreshChart()">
            <i class="fas fa-rotate-right"></i> Refresh Chart
        </button>
    </div>
</div>
<div class="chart-wrap"><canvas id="chart"></canvas></div>

<!-- ══ TABEL ══════════════════════════════════════════════════════ -->
<div class="sec" style="margin-top:24px">
    <div class="sec-title">
        <i class="fas fa-table-list"></i> Data Terbaru (25 Record)
    </div>
    <button onclick="openDlModal()" class="btn btn-green" style="font-size:11px">
        <i class="fas fa-download"></i> Unduh Data Ini
    </button>
</div>
<div class="tbl-wrap">
<table>
    <thead>
        <tr>
            <th>#</th><th>Tanggal</th><th>Waktu</th>
            <th>Suhu (°C)</th><th>Status Suhu</th>
            <th>Kelembaban (%)</th><th>Status Humid</th>
        </tr>
    </thead>
    <tbody id="tblBody">
    <?php
    $n = 1;
    if ($rR) {
        while ($r = mysqli_fetch_assoc($rR)):
            $ss = $r['status_suhu']==='bahaya'  ? 'err' : ($r['status_suhu']==='waspada'  ? 'warn' : 'ok');
            $sh = $r['status_humid']==='bahaya' ? 'err' : ($r['status_humid']==='waspada' ? 'warn' : 'ok');
    ?>
        <tr>
            <td><?=$n++?></td>
            <td><?=date('d M Y',strtotime($r['created_at']))?></td>
            <td><?=date('H:i:s',strtotime($r['created_at']))?></td>
            <td><strong><?=htmlspecialchars($r['suhu'])?>°C</strong></td>
            <td><span class="badge badge-<?=$ss?>"><?=strtoupper($r['status_suhu'])?></span></td>
            <td><strong><?=htmlspecialchars($r['kelembaban'])?>%</strong></td>
            <td><span class="badge badge-<?=$sh?>"><?=strtoupper($r['status_humid'])?></span></td>
        </tr>
    <?php endwhile;
    }
    if ($n === 1): ?>
    <tr>
        <td colspan="7">
            <div class="no-data">
                <i class="fas fa-inbox"></i>
                Belum ada data untuk periode ini.<br>
                <small>Pastikan ESP32 sudah menyala dan terhubung ke WiFi.</small>
            </div>
        </td>
    </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

</div><!-- /.container -->

<!-- ══ DOWNLOAD MODAL ══════════════════════════════════════════════ -->
<div class="dl-overlay" id="dlOverlay" onclick="if(event.target===this)closeDlModal()">
<div class="dl-modal" role="dialog" aria-modal="true" aria-labelledby="dlTitle">

    <h2 id="dlTitle"><i class="fas fa-download" style="color:var(--green);margin-right:8px"></i>Unduh Laporan</h2>
    <p class="dl-sub">Ekspor data monitoring ke format pilihan Anda, lengkap dengan statistik & grafik.</p>

    <!-- Pilih Periode -->
    <span class="dl-label"><i class="fas fa-calendar-alt" style="margin-right:5px"></i>Pilih Periode</span>
    <div class="dl-pills">
        <div class="dl-pill" data-period="today"  onclick="setDlPeriod(this)">Hari Ini</div>
        <div class="dl-pill" data-period="week"   onclick="setDlPeriod(this)">Minggu Ini</div>
        <div class="dl-pill" data-period="month"  onclick="setDlPeriod(this)">Bulan Ini</div>
        <div class="dl-pill" data-period="custom" onclick="setDlPeriod(this)">Custom</div>
    </div>
    <div class="dl-range" id="dlCustomRange">
        <label for="dlStart"><strong>Dari:</strong></label>
        <input type="date" id="dlStart">
        <label for="dlEnd"><strong>s/d:</strong></label>
        <input type="date" id="dlEnd">
    </div>

    <!-- Pilih Format -->
    <span class="dl-label" style="margin-top:4px"><i class="fas fa-file-export" style="margin-right:5px"></i>Pilih Format</span>
    <div class="dl-fmt-grid">
        <div class="dl-fmt-card" data-fmt="csv" onclick="setDlFormat(this)">
            <i class="fas fa-file-csv fmt-csv"></i>
            <span class="fmt-name">CSV</span>
            <span class="fmt-desc">Kompatibel Excel & Google Sheets</span>
        </div>
        <div class="dl-fmt-card" data-fmt="excel" onclick="setDlFormat(this)">
            <i class="fas fa-file-excel fmt-excel"></i>
            <span class="fmt-name">Excel (XLSX)</span>
            <span class="fmt-desc">Warna status, freeze header, lebar kolom otomatis</span>
        </div>
        <div class="dl-fmt-card" data-fmt="pdf" onclick="setDlFormat(this)">
            <i class="fas fa-file-pdf fmt-pdf"></i>
            <span class="fmt-name">PDF</span>
            <span class="fmt-desc">Termasuk grafik tren sensor</span>
        </div>
    </div>

    <!-- Info PDF -->
    <div class="dl-note" id="dlNotePdf">
        <i class="fas fa-circle-info"></i>
        <strong>Format PDF</strong> dibuat di browser Anda dan menyertakan <strong>grafik tren sensor</strong>
        yang tampil saat ini. Pastikan grafik sudah termuat sebelum mengunduh.
        Maks. 500 baris data ditampilkan dalam tabel.
    </div>

    <div class="dl-actions">
        <button class="btn" onclick="closeDlModal()"><i class="fas fa-xmark"></i> Batal</button>
        <button class="btn btn-green" id="dlBtn" onclick="doDownload()">
            <span class="dl-spinner" id="dlSpinner"></span>
            <i class="fas fa-download" id="dlBtnIcon"></i>
            <span id="dlBtnTxt">Unduh</span>
        </button>
    </div>

</div>
</div>

<script>
// ── Kirim nilai dari PHP ke JavaScript (supaya JS tahu data awal & batas alarm) ──
const INIT_LABELS = <?=json_encode($cL)?>;
const INIT_SUHU   = <?=json_encode($cS)?>;
const INIT_HUMID  = <?=json_encode($cH)?>;
const LIM_S  = 36;   // Batas suhu bahaya (°C)
const LIM_H  = 70;   // Batas humid bahaya (%)
const FILTER = '<?=$filter?>';
const DATE_S = '<?=$s?>';
const DATE_E = '<?=$e?>';
const LOKASI = '<?=$lokasi?>';

// Statistik periode — dipakai juga oleh PDF generator
const PHP_STATS = {
    period : <?=json_encode($lbl_period)?>,
    avg_s  : <?=json_encode($st['avg_s'] ?? '-')?>,
    max_s  : <?=json_encode($st['max_s'] ?? '-')?>,
    min_s  : <?=json_encode($st['min_s'] ?? '-')?>,
    avg_h  : <?=json_encode($st['avg_h'] ?? '-')?>,
    max_h  : <?=json_encode($st['max_h'] ?? '-')?>,
    min_h  : <?=json_encode($st['min_h'] ?? '-')?>,
    al_s   : <?=(int)($st['al_s']  ?? 0)?>,
    al_h   : <?=(int)($st['al_h']  ?? 0)?>,
    total  : <?=(int)($st['total'] ?? 0)?>,
    filter : '<?=$filter?>',
    dateS  : '<?=$s?>',
    dateE  : '<?=$e?>',
};

// Interval polling (ms) — harus >= sendInterval di Arduino (20000 ms)
// Jika terlalu sering, sebagian shared hosting bisa rate-limit
const POLL_MS = 20000;

// ── Chart.js ─────────────────────────────────────────────────────
const ctx = document.getElementById('chart').getContext('2d');
const ch = new Chart(ctx, {
    type: 'line',
    data: {
        labels: INIT_LABELS,
        datasets: [
            {
                label: 'Suhu (°C)', data: INIT_SUHU, yAxisID: 'yS',
                borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.08)',
                borderWidth: 2, tension: .4, fill: true,
                pointRadius: INIT_LABELS.length > 80 ? 0 : 3, pointHoverRadius: 5
            },
            {
                label: 'Kelembaban (%)', data: INIT_HUMID, yAxisID: 'yH',
                borderColor: '#0284c7', backgroundColor: 'rgba(2,132,199,.08)',
                borderWidth: 2, tension: .4, fill: true,
                pointRadius: INIT_LABELS.length > 80 ? 0 : 3, pointHoverRadius: 5
            },
            {
                label: 'Batas Suhu (36°C)',
                data: new Array(INIT_LABELS.length).fill(LIM_S), yAxisID: 'yS',
                borderColor: 'rgba(239,68,68,.4)', borderWidth: 1.5,
                borderDash: [7, 5], pointRadius: 0, fill: false
            },
            {
                label: 'Batas Humid (70%)',
                data: new Array(INIT_LABELS.length).fill(LIM_H), yAxisID: 'yH',
                borderColor: 'rgba(2,132,199,.4)', borderWidth: 1.5,
                borderDash: [7, 5], pointRadius: 0, fill: false
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', labels: { font: { size: 11 }, padding: 14 } },
            tooltip: {
                callbacks: {
                    label: c => {
                        if (c.datasetIndex === 0) return ' Suhu: ' + c.parsed.y.toFixed(1) + '°C';
                        if (c.datasetIndex === 1) return ' Kelembaban: ' + c.parsed.y.toFixed(1) + '%';
                        return c.dataset.label + ': ' + c.parsed.y;
                    }
                }
            }
        },
        scales: {
            x: {
                ticks: { font: { size: 10 }, maxTicksLimit: 12, maxRotation: 40 },
                grid: { color: 'rgba(0,0,0,.04)' }
            },
            yS: {
                type: 'linear', position: 'left',
                title: { display: true, text: 'Suhu (°C)', font: { size: 10 } },
                ticks: { font: { size: 10 }, callback: v => v + '°' },
                grid: { color: 'rgba(0,0,0,.04)' }, suggestedMin: 15, suggestedMax: 50
            },
            yH: {
                type: 'linear', position: 'right',
                title: { display: true, text: 'Kelembaban (%)', font: { size: 10 } },
                ticks: { font: { size: 10 }, callback: v => v + '%' },
                grid: { drawOnChartArea: false }, suggestedMin: 20, suggestedMax: 100
            }
        }
    }
});

function toggleDS(i) {
    const m = ch.getDatasetMeta(i);
    m.hidden = !m.hidden;
    ch.update();
}

async function refreshChart() {
    try {
        const res = await fetch(`monitoring.php?ajax=chart&start=${DATE_S}&end=${DATE_E}&lokasi=${LOKASI}`);
        const d   = await res.json();
        ch.data.labels         = d.labels;
        ch.data.datasets[0].data = d.suhu;
        ch.data.datasets[1].data = d.humid;
        ch.data.datasets[2].data = new Array(d.labels.length).fill(LIM_S);
        ch.data.datasets[3].data = new Array(d.labels.length).fill(LIM_H);
        ch.update();
    } catch (e) { console.warn('Refresh chart gagal:', e); }
}

// ── Append titik baru ke chart (live view) ───────────────────────
const MAX_PTS = 120;
let _lS = null, _lH = null;

function appendLive(lbl, s, h) {
    const d = ch.data;
    const last = d.labels.length - 1;
    // Jika label sama (update menit yang sama), update nilai
    if (last >= 0 && d.labels[last] === lbl) {
        if (s !== null) d.datasets[0].data[last] = s;
        if (h !== null) d.datasets[1].data[last] = h;
    } else {
        // Titik baru: buang yang terlama jika sudah penuh
        if (d.labels.length >= MAX_PTS) {
            d.datasets.forEach(ds => ds.data.shift());
            d.labels.shift();
        }
        d.labels.push(lbl);
        d.datasets[0].data.push(s !== null ? s : (_lS || 0));
        d.datasets[1].data.push(h !== null ? h : (_lH || 0));
        d.datasets[2].data.push(LIM_S);
        d.datasets[3].data.push(LIM_H);
    }
    if (s !== null) _lS = s;
    if (h !== null) _lH = h;
    ch.update('none');
}

// ── UI Helpers ───────────────────────────────────────────────────
let _curS = <?=!empty($lt['suhu'])   ? (float)$lt['suhu']      : 0?>;
let _curH = <?=!empty($lt['kelembaban']) ? (float)$lt['kelembaban'] : 0?>;

function applyCard(ids, val, lim, mode, warnOffset) {
    const [cId, vId, bId, bdId, btId] = ids;
    const card = document.getElementById(cId);
    const vEl  = document.getElementById(vId);
    const bEl  = document.getElementById(bId);
    const bdEl = document.getElementById(bdId);
    const btEl = document.getElementById(btId);
    if (!card) return;

    const pct = mode === 's' ? Math.min(val / 60 * 100, 100) : Math.min(val, 100);
    vEl.textContent = val.toFixed(1);
    bEl.style.width = pct + '%';

    if (val > lim) {
        card.className = 'card err'; vEl.className = 'card-val err';
        bEl.style.background = '#ef4444'; bdEl.className = 'card-badge err';
        btEl.textContent = 'BAHAYA!';
        showAlert(mode === 's'
            ? `⚠ Suhu ${val.toFixed(1)}°C melebihi batas!`
            : `⚠ Kelembaban ${val.toFixed(1)}% melebihi batas!`);
    } else if (val > lim - warnOffset) {
        card.className = 'card warn'; vEl.className = 'card-val warn';
        bEl.style.background = '#f59e0b'; bdEl.className = 'card-badge warn';
        btEl.textContent = 'WASPADA';
    } else {
        card.className = 'card ok'; vEl.className = 'card-val';
        bEl.style.background = mode === 's' ? '#10b981' : '#0284c7';
        bdEl.className = 'card-badge'; btEl.textContent = 'AMAN';
    }
}

function updateCondition(t, h) {
    const el   = document.getElementById('vCond');
    const desc = document.getElementById('vCondDesc');
    const icon = document.getElementById('condIcon');
    const card = document.getElementById('cCond');
    if (t > LIM_S && h > LIM_H) {
        el.textContent = 'BAHAYA!'; el.style.color = '#ef4444';
        desc.textContent = 'Suhu & Kelembaban Melebihi Batas';
        icon.className = 'fas fa-circle-exclamation fa-xl'; icon.style.color = '#ef4444';
        card.className = 'card err';
    } else if (t > LIM_S) {
        el.textContent = 'PANAS!'; el.style.color = '#f59e0b';
        desc.textContent = 'Suhu Melebihi Batas (>36°C)';
        icon.className = 'fas fa-temperature-arrow-up fa-xl'; icon.style.color = '#f59e0b';
        card.className = 'card warn';
    } else if (h > LIM_H) {
        el.textContent = 'LEMBAB!'; el.style.color = '#0284c7';
        desc.textContent = 'Kelembaban Melebihi Batas (>70%)';
        icon.className = 'fas fa-droplet fa-xl'; icon.style.color = '#0284c7';
        card.className = 'card warn';
    } else {
        el.textContent = 'AMAN'; el.style.color = '#10b981';
        desc.textContent = 'Suhu & Kelembaban Normal';
        icon.className = 'fas fa-circle-check fa-xl'; icon.style.color = '#10b981';
        card.className = 'card ok';
    }
}

let alertTimer;
function showAlert(msg) {
    const el = document.getElementById('alertFlash');
    const m  = document.getElementById('alertMsg');
    m.textContent = msg; el.style.display = 'flex';
    clearTimeout(alertTimer);
    alertTimer = setTimeout(() => el.style.display = 'none', 5000);
}

// ── Status Bar ───────────────────────────────────────────────────
function setConn(state, html) {
    const bar = document.getElementById('connBar');
    const dot = document.getElementById('connDot');
    bar.className = 'conn-bar' + (state === 'ok' ? ' ok' : state === 'err' ? ' err' : '');
    dot.className = 'conn-dot' + (state === 'ok' ? ' ok' : state === 'err' ? ' err' : '');
    document.getElementById('connTxt').innerHTML = html;
}

function setTimer(sec) {
    const el = document.getElementById('connTimer');
    if (!el) return;
    el.textContent = sec > 0 ? '⟳ ' + sec + 's' : '⟳ Memperbarui…';
}

// ── Polling otomatis setiap 20 detik — inilah yang membuat dashboard "realtime" ──
let cdLeft = POLL_MS / 1000;

async function pollData() {
    setTimer(0);
    setConn('', '<i class="fas fa-rotate fa-spin"></i> Mengambil data terbaru…');
    try {
        const resLatest = await fetch(`monitoring.php?ajax=latest&lokasi=${LOKASI}`);
        const data = await resLatest.json();

        if (data && data.suhu !== undefined) {
            _curS = parseFloat(data.suhu);
            _curH = parseFloat(data.kelembaban);

            applyCard(['cSuhu','vSuhu','bSuhu','bdSuhu','bdSuhuTxt'], _curS, LIM_S, 's', 3);
            applyCard(['cHumid','vHumid','bHumid','bdHumid','bdHumidTxt'], _curH, LIM_H, 'h', 5);
            updateCondition(_curS, _curH);

            const d = new Date(data.created_at.replace(' ', 'T'));
            const newTime = d.toTimeString().slice(0, 8);
            
            document.getElementById('vTime').textContent = newTime;
            document.getElementById('vDate').textContent = d.toLocaleDateString('id-ID', {
                day: '2-digit', month: 'short', year: 'numeric'
            });

            if (FILTER === 'today') {
                appendLive(newTime, _curS, _curH);
            }

            setConn('ok',
                '<i class="fas fa-satellite-dish"></i> '
                + 'Data terakhir: <strong>' + d.toLocaleTimeString('id-ID') + '</strong>'
                + ' &nbsp;|&nbsp; Polling 20s');
        }

        // Render ulang tabel bawah setiap ada data masuk
        const resTable = await fetch(`monitoring.php?ajax=table&lokasi=${LOKASI}`);
        const htmlTable = await resTable.text();
        document.getElementById('tblBody').innerHTML = htmlTable;

    } catch (e) {
        setConn('err',
            '<i class="fas fa-plug-circle-xmark"></i> '
            + 'Polling gagal/menunggu data... pastikan alat menyala.');
        console.warn('[Poll]', e.message);
    }
    cdLeft = POLL_MS / 1000;
}

// Countdown ticker setiap 1 detik
setInterval(() => {
    cdLeft = Math.max(0, cdLeft - 1);
    setTimer(cdLeft);
    if (cdLeft === 0) pollData();
}, 1000);

// Langsung poll saat halaman dibuka
pollData();

// ── Inisialisasi kartu dari PHP (saat halaman pertama kali load) ─
<?php if (!empty($lt)): ?>
applyCard(['cSuhu','vSuhu','bSuhu','bdSuhu','bdSuhuTxt'],
    <?=(float)$lt['suhu']?>, LIM_S, 's', 3);
applyCard(['cHumid','vHumid','bHumid','bdHumid','bdHumidTxt'],
    <?=(float)$lt['kelembaban']?>, LIM_H, 'h', 5);
updateCondition(<?=(float)$lt['suhu']?>, <?=(float)$lt['kelembaban']?>);
<?php endif; ?>

function goCust() {
    const s = document.getElementById('inS')?.value;
    const e = document.getElementById('inE')?.value;
    if (s && e) location.href = `?filter=custom&start=${s}&end=${e}`;
}

// ════════════════════════════════════════════════════════════
//  DOWNLOAD MODAL
// ════════════════════════════════════════════════════════════
let _dlPeriod = FILTER;   // Periode aktif di modal (sinkron dengan filter halaman)
let _dlFormat = 'pdf';    // Format default

function openDlModal() {
    // Sinkronkan pill dengan filter yang sedang aktif di halaman
    setDlPeriod(
        document.querySelector(`.dl-pill[data-period="${FILTER}"]`),
        true // silent — tidak trigger animasi
    );
    // Aktifkan format PDF sebagai default
    setDlFormat(document.querySelector('.dl-fmt-card[data-fmt="pdf"]'), true);

    // Isi custom range dari URL kalau ada
    if (FILTER === 'custom') {
        document.getElementById('dlStart').value = DATE_S;
        document.getElementById('dlEnd').value   = DATE_E;
    } else {
        // Isi sensible default untuk custom
        document.getElementById('dlStart').value = DATE_S;
        document.getElementById('dlEnd').value   = DATE_E;
    }

    document.getElementById('dlOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeDlModal() {
    document.getElementById('dlOverlay').classList.remove('open');
    document.body.style.overflow = '';
    resetDlBtn();
}

function setDlPeriod(el, silent = false) {
    if (!el) return;
    document.querySelectorAll('.dl-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
    _dlPeriod = el.dataset.period;
    const rangeEl = document.getElementById('dlCustomRange');
    rangeEl.classList.toggle('show', _dlPeriod === 'custom');
}

function setDlFormat(el, silent = false) {
    if (!el) return;
    document.querySelectorAll('.dl-fmt-card').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
    _dlFormat = el.dataset.fmt;
    document.getElementById('dlNotePdf').classList.toggle('show', _dlFormat === 'pdf');
}

function getDlDates() {
    switch (_dlPeriod) {
        case 'week': {
            const mon = new Date();
            mon.setDate(mon.getDate() - mon.getDay() + 1);
            const sun = new Date(mon); sun.setDate(mon.getDate() + 6);
            return { type: 'week', s: fmtDate(mon), e: fmtDate(sun) };
        }
        case 'month': {
            const now = new Date();
            return {
                type: 'month',
                s: `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-01`,
                e: fmtDate(new Date(now.getFullYear(), now.getMonth()+1, 0)),
            };
        }
        case 'custom': {
            const s = document.getElementById('dlStart').value;
            const e = document.getElementById('dlEnd').value;
            if (!s || !e) { alert('Pilih tanggal awal dan akhir terlebih dahulu.'); return null; }
            if (s > e)    { alert('Tanggal awal tidak boleh lebih besar dari tanggal akhir.'); return null; }
            return { type: 'custom', s, e };
        }
        default: {
            const t = fmtDate(new Date());
            return { type: 'today', s: t, e: t };
        }
    }
}

function fmtDate(d) {
    return d.toISOString().slice(0, 10);
}

function resetDlBtn() {
    const btn    = document.getElementById('dlBtn');
    const sp     = document.getElementById('dlSpinner');
    const icon   = document.getElementById('dlBtnIcon');
    const txt    = document.getElementById('dlBtnTxt');
    btn.disabled = false;
    sp.style.display   = 'none';
    icon.style.display = '';
    txt.textContent    = 'Unduh';
}

function setDlBtnLoading(loading) {
    const btn  = document.getElementById('dlBtn');
    const sp   = document.getElementById('dlSpinner');
    const icon = document.getElementById('dlBtnIcon');
    const txt  = document.getElementById('dlBtnTxt');
    btn.disabled       = loading;
    sp.style.display   = loading ? 'inline-block' : 'none';
    icon.style.display = loading ? 'none' : '';
    txt.textContent    = loading ? 'Memproses…' : 'Unduh';
}

async function doDownload() {
    const dates = getDlDates();
    if (!dates) return;

    setDlBtnLoading(true);

    try {
        if (_dlFormat === 'pdf') {
            await generatePDF(dates);
        } else {
            // CSV / Excel — redirect ke PHP endpoint
            const url = `api/download_data.php?format=${_dlFormat}&type=${dates.type}`
                      + `&start=${dates.s}&end=${dates.e}&lokasi=${LOKASI}`;
            window.location.href = url;
            // Beri jeda kecil supaya download terpicu sebelum reset
            await new Promise(r => setTimeout(r, 1200));
        }
    } catch (err) {
        console.error('[Download]', err);
        alert('Terjadi kesalahan saat memproses. Coba lagi atau gunakan format CSV.');
    } finally {
        setDlBtnLoading(false);
    }
}

// ════════════════════════════════════════════════════════════
//  PDF GENERATOR — jsPDF + autoTable
//  Isi: Header, Statistik, Grafik (canvas), Tabel Data
// ════════════════════════════════════════════════════════════
async function generatePDF(dates) {
    if (!window.jspdf) throw new Error('jsPDF belum dimuat.');

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
    const W   = doc.internal.pageSize.getWidth();   // 210mm
    const H   = doc.internal.pageSize.getHeight();  // 297mm
    const M   = 14; // margin kiri/kanan

    // ── 1. Fetch data (JSON) dari PHP ────────────────────────
    const resp = await fetch(
        `monitoring.php?ajax=pdfdata&start=${dates.s}&end=${dates.e}&lokasi=${LOKASI}`,
        { cache: 'no-store' }
    );
    const data = await resp.json();
    const st   = data.stats || {};

    // Tentukan label periode
    const periodLabels = {
        today : `Hari Ini, ${new Date().toLocaleDateString('id-ID', {day:'2-digit',month:'long',year:'numeric'})}`,
        week  : `Minggu ke-${getWeekNumber()} Tahun ${new Date().getFullYear()}`,
        month : new Date().toLocaleDateString('id-ID', {month:'long', year:'numeric'}),
        custom: `${formatTgl(dates.s)} s/d ${formatTgl(dates.e)}`,
    };
    const period = periodLabels[_dlPeriod] || PHP_STATS.period;

    // ── 2. HEADER HALAMAN ────────────────────────────────────
    // Dark band
    doc.setFillColor(15, 23, 42);
    doc.rect(0, 0, W, 30, 'F');

    // Teks header
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(15);
    doc.setFont('helvetica', 'bold');
    doc.text('Cryonnodes — Monitoring Suhu & Kelembaban', M, 12);

    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    doc.setTextColor(148, 163, 184);
    doc.text(`${data.lokasi || ''}  |  IoT System — ESP32 + DHT22  |  PT. Krakatau Bandar Samudera`, M, 20);

    doc.setTextColor(100, 116, 139);
    doc.text(`Dicetak: ${new Date().toLocaleString('id-ID')}  |  Cryonnodes.com`, M, 27);

    let y = 38;

    // ── 3. BANNER PERIODE ────────────────────────────────────
    doc.setFillColor(240, 249, 255);
    doc.roundedRect(M, y, W - 2*M, 9, 2, 2, 'F');
    doc.setFontSize(10);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(2, 132, 199);
    // CATATAN (perbaikan): sebelumnya ada emoji 📅 di depan teks ini.
    // Font standar jsPDF ('helvetica') cuma mendukung WinAnsiEncoding
    // (kurang lebih Latin-1) — TIDAK BISA meng-encode emoji sama sekali.
    // Begitu kena karakter emoji, sisa teks di baris yang sama ikut
    // rusak jadi simbol acak ("Ø=ÜÅ..."). Diganti ke teks polos, sama
    // seperti gaya "Statistik Periode"/"Grafik Tren Sensor" di bawahnya
    // yang memang tidak pakai ikon sama sekali.
    doc.text(`Periode: ${period}`, W / 2, y + 6, { align: 'center' });
    y += 15;

    // ── 4. TABEL STATISTIK ───────────────────────────────────
    doc.setFontSize(10);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(30, 41, 59);
    doc.text('Statistik Periode', M, y);
    y += 3;

    doc.autoTable({
        startY: y,
        head: [['Metrik', 'Suhu (°C)', 'Kelembaban (%)']],
        body: [
            ['Rata-rata',     st.avg_s ?? '-', st.avg_h ?? '-'],
            ['Maksimum',      st.max_s ?? '-', st.max_h ?? '-'],
            ['Minimum',       st.min_s ?? '-', st.min_h ?? '-'],
            ['Alert Bahaya',  `${data.alert_suhu ?? 0}x`, `${data.alert_humid ?? 0}x`],
            ['Total Record',  data.total ?? 0, ''],
        ],
        theme: 'grid',
        headStyles: {
            fillColor: [15, 23, 42], textColor: [255,255,255],
            fontSize: 9, fontStyle: 'bold', halign: 'center',
        },
        bodyStyles: { fontSize: 9, valign: 'middle' },
        columnStyles: {
            0: { fontStyle: 'bold', cellWidth: 50 },
            1: { halign: 'center' },
            2: { halign: 'center' },
        },
        alternateRowStyles: { fillColor: [248, 250, 252] },
        margin: { left: M, right: M },
    });

    y = doc.lastAutoTable.finalY + 10;

    // ── 5. GRAFIK TREN SENSOR ────────────────────────────────
    const chartCanvas = document.getElementById('chart');
    if (chartCanvas && INIT_LABELS.length > 0) {
        // Buat canvas temp dengan background putih agar PNG tidak transparan
        const tmp = document.createElement('canvas');
        tmp.width  = chartCanvas.width;
        tmp.height = chartCanvas.height;
        const tmpCtx = tmp.getContext('2d');
        tmpCtx.fillStyle = '#ffffff';
        tmpCtx.fillRect(0, 0, tmp.width, tmp.height);
        tmpCtx.drawImage(chartCanvas, 0, 0);
        const chartImg = tmp.toDataURL('image/png', 0.92);

        const imgW   = W - 2*M;
        const imgH   = Math.min(imgW * (chartCanvas.height / chartCanvas.width), 68);
        const needH  = imgH + 20;

        // Pindah ke halaman baru kalau tidak cukup tempat
        if (y + needH > H - 20) { doc.addPage(); y = 20; }

        doc.setFontSize(10);
        doc.setFont('helvetica', 'bold');
        doc.setTextColor(30, 41, 59);
        doc.text('Grafik Tren Sensor', M, y);
        y += 4;

        // Border tipis
        doc.setDrawColor(203, 213, 225);
        doc.setLineWidth(0.3);
        doc.rect(M, y, imgW, imgH);
        doc.addImage(chartImg, 'PNG', M, y, imgW, imgH);
        y += imgH + 10;
    }

    // ── 6. TABEL DATA ────────────────────────────────────────
    const rows = data.rows || [];

    if (y + 30 > H - 20) { doc.addPage(); y = 20; }

    doc.setFontSize(10);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(30, 41, 59);
    const rowLabel = rows.length < (data.total ?? 0)
        ? `Data Monitoring  (menampilkan ${rows.length} dari ${data.total ?? rows.length} record)`
        : `Data Monitoring  (${rows.length} record)`;
    doc.text(rowLabel, M, y);
    y += 3;

    // Fungsi mewarnai sel status
    function statusFill(val) {
        const v = (val || '').toLowerCase();
        if (v === 'aman')    return [220, 252, 231]; // hijau muda
        if (v === 'waspada') return [254, 243, 199]; // kuning muda
        if (v === 'bahaya')  return [254, 226, 226]; // merah muda
        return null;
    }

    doc.autoTable({
        startY: y,
        head: [['#', 'Tanggal', 'Waktu', 'Suhu', 'Status Suhu', 'Humid', 'Status Humid']],
        body: rows.length ? rows : [['—', 'Belum ada data untuk periode ini', '', '', '', '', '']],
        theme: 'striped',
        headStyles: {
            fillColor: [2, 100, 160], textColor: [255,255,255],
            fontSize: 8, fontStyle: 'bold', halign: 'center',
        },
        bodyStyles: { fontSize: 8, valign: 'middle' },
        columnStyles: {
            0: { cellWidth: 8,  halign: 'center' },
            1: { cellWidth: 26 },
            2: { cellWidth: 20, halign: 'center' },
            3: { cellWidth: 18, halign: 'center' },
            4: { cellWidth: 30, halign: 'center' },
            5: { cellWidth: 18, halign: 'center' },
            6: { cellWidth: 30, halign: 'center' },
        },
        willDrawCell: (hookData) => {
            if (hookData.row.section === 'body') {
                const ci = hookData.column.index;
                if (ci === 4 || ci === 6) {
                    const fill = statusFill(hookData.cell.text[0]);
                    if (fill) {
                        doc.setFillColor(...fill);
                        doc.rect(
                            hookData.cell.x, hookData.cell.y,
                            hookData.cell.width, hookData.cell.height, 'F'
                        );
                    }
                }
            }
        },
        margin: { left: M, right: M },
        showHead: 'everyPage',
    });

    // ── 7. FOOTER SETIAP HALAMAN ─────────────────────────────
    const totalPages = doc.internal.getNumberOfPages();
    for (let i = 1; i <= totalPages; i++) {
        doc.setPage(i);
        doc.setFontSize(8);
        doc.setTextColor(148, 163, 184);
        doc.text('Cryonnodes System  |  Cryonnodes.com', M, H - 7);
        doc.text(`Halaman ${i} / ${totalPages}`, W - M, H - 7, { align: 'right' });
        // Garis footer
        doc.setDrawColor(226, 232, 240);
        doc.setLineWidth(0.3);
        doc.line(M, H - 10, W - M, H - 10);
    }

    // ── 8. SIMPAN ────────────────────────────────────────────
    const tanggal  = new Date().toISOString().slice(0, 10).replace(/-/g, '');
    const namePeriod = _dlPeriod === 'custom'
        ? `${dates.s.replace(/-/g,'')}_${dates.e.replace(/-/g,'')}`
        : _dlPeriod;
    doc.save(`Data Cryo_${LOKASI}_${namePeriod}_${tanggal}.pdf`);
    closeDlModal();
}

// ── Helper ───────────────────────────────────────────────────
function getWeekNumber() {
    const now  = new Date();
    const start = new Date(now.getFullYear(), 0, 1);
    return Math.ceil(((now - start) / 86400000 + start.getDay() + 1) / 7);
}

function formatTgl(ymd) {
    const d = new Date(ymd + 'T00:00:00');
    return d.toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' });
}

// Tutup modal dengan Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeDlModal();
});
</script>
</body>
</html>