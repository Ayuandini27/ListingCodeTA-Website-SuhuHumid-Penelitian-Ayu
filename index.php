<?php
/**
 * ============================================================
 *  index.php
 *  TUJUAN: Halaman pertama yang dilihat siapapun yang membuka
 *  thermonode.my.id — berisi menu-menu utama portal (bento grid)
 *  + widget pemantauan suhu/kelembaban mengambang di pojok bawah.
 *
 *  Selain menampilkan menu, file ini juga mencatat 2 statistik
 *  kecil yang tampil di footer: jumlah pengunjung online & total
 *  visit — keduanya TIDAK berhubungan dengan sensor suhu, murni
 *  fitur statistik pengunjung website.
 * ============================================================
 */

session_start();
require __DIR__ . '/config/koneksi.php';

// ── Hitung "Online": berapa session aktif dalam 5 menit terakhir ──
const ONLINE_TIMEOUT = 300; // 5 menit toleransi online

$sessionId = session_id();
$now       = time();

$conn->query("DELETE FROM user_online WHERE last_activity < ($now - " . ONLINE_TIMEOUT . ")");

$stmtCheck = $conn->prepare("SELECT id FROM user_online WHERE session_id = ?");
$stmtCheck->bind_param("s", $sessionId);
$stmtCheck->execute();
$isKnownSession = $stmtCheck->get_result()->num_rows > 0;
$stmtCheck->close();

if ($isKnownSession) {
    $stmt = $conn->prepare("UPDATE user_online SET last_activity = ? WHERE session_id = ?");
    $stmt->bind_param("is", $now, $sessionId);
} else {
    $stmt = $conn->prepare("INSERT INTO user_online (session_id, last_activity) VALUES (?, ?)");
    $stmt->bind_param("si", $sessionId, $now);
}
$stmt->execute();
$stmt->close();

$totalOnline = $conn->query("SELECT COUNT(*) AS total FROM user_online")->fetch_assoc()['total'];

// ── Tracking: Total Visits (anti-spam refresh, 1x per session) ──
const COUNTER_FILE = __DIR__ . '/counter.txt';

if (!file_exists(COUNTER_FILE)) {
    file_put_contents(COUNTER_FILE, "0");
}

if (!isset($_SESSION['has_visited'])) {
    $totalVisit = (int) file_get_contents(COUNTER_FILE) + 1;
    file_put_contents(COUNTER_FILE, $totalVisit, LOCK_EX);
    $_SESSION['has_visited'] = $totalVisit;
} else {
    $totalVisit = $_SESSION['has_visited'];
}

$userIp  = $_SERVER['REMOTE_ADDR'];
$isAdmin = (($_SESSION['username'] ?? null) === 'admin_port') ? 'true' : 'false';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/x-icon" href="assets/img/favicon.ico?v=1.0">

    <title>KIP Maintenance Portal | PFM Department</title>

    <!-- Resource Hints -->
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <!-- Font & Icons -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">

    <!-- Stylesheet utama (sebelumnya inline, sekarang terpisah) -->
    <link rel="stylesheet" href="assets/css/style.css">

    <style>
    /* ── Footer Sensor Pill ─────────────────────────────────── */
    .sensor-pill {
        background: rgba(16, 185, 129, 0.08);
        color: #e2e8f0;
        padding: 6px 14px;
        border-radius: 50px;
        font-size: 12px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 7px;
        text-decoration: none;
        border: 1px solid rgba(16, 185, 129, 0.18);
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        cursor: pointer;
        white-space: nowrap;
    }
    .sensor-pill:hover {
        background: rgba(16, 185, 129, 0.16);
        border-color: rgba(16, 185, 129, 0.4);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(16, 185, 129, 0.15);
    }
    .sensor-pill[data-status="waspada"] {
        border-color: rgba(245, 158, 11, 0.3);
        background: rgba(245, 158, 11, 0.07);
    }
    .sensor-pill[data-status="bahaya"] {
        border-color: rgba(239, 68, 68, 0.35);
        background: rgba(239, 68, 68, 0.08);
    }
    .sensor-pill[data-status="offline"] {
        border-color: rgba(100, 116, 139, 0.2);
        background: rgba(100, 116, 139, 0.06);
    }

    /* Live dot — repakai animasi pulse-anim dari style.css */
    .sp-dot {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: #64748b;
        flex-shrink: 0;
        transition: background 0.4s;
        animation: pulse-anim 2s infinite;
    }

    /* Nilai suhu & humid */
    .sp-reading { display: flex; align-items: center; gap: 3px; line-height: 1; }
    .sp-reading i { font-size: 9px; opacity: 0.65; }
    .sp-reading strong { font-size: 13px; font-weight: 800; color: #fff; }
    .sp-reading span  { font-size: 10px; color: #94a3b8; font-weight: 600; }
    .sp-sep { color: #334155; font-size: 14px; line-height: 1; }

    /* Badge status di ujung kanan pill */
    .sp-badge {
        font-size: 9px; font-weight: 800;
        text-transform: uppercase; letter-spacing: 0.6px;
        padding: 2px 8px; border-radius: 50px;
        background: rgba(100,116,139,.15); color: #64748b;
        transition: all 0.3s;
    }
    .sensor-pill[data-status="aman"]    .sp-badge { background: rgba(16,185,129,.15); color: #10b981; }
    .sensor-pill[data-status="waspada"] .sp-badge { background: rgba(245,158,11,.15); color: #f59e0b; }
    .sensor-pill[data-status="bahaya"]  .sp-badge { background: rgba(239,68,68,.15);  color: #ef4444; }

    /* Teks "SENSOR" kecil di atas (label) */
    .sp-label {
        font-size: 8px; font-weight: 800; letter-spacing: 1px;
        color: #475569; text-transform: uppercase;
        border-right: 1px solid #1e293b;
        padding-right: 8px; line-height: 1;
    }

    /* Responsive: di layar kecil sembunyikan label & badge, sederhanakan */
    @media (max-width: 480px) {
        .sp-label, .sp-badge { display: none; }
        .sensor-pill { padding: 6px 10px; gap: 5px; }
    }
    </style>
</head>
<body>

    <nav class="top-navbar">
        <img src="assets/img/logo.png" class="logo" alt="Logo KIPMAIN">
        <div class="header-title">
            <h1>KIP MAINTENANCE PORTAL</h1>
            <p>Digital System - Port Facility Maintenance</p>
        </div>
    </nav>

    <main class="main-wrapper">
        <div class="bento-grid">

            <a href="login" class="card-main">
                <div class="icon-box"><i class="fas fa-chart-line"></i></div>
                <h3>Laporan Inspeksi</h3>
                <p>Digitalisasi report preventive & monitoring temuan secara real-time.</p>
            </a>

            <a href="jadwal_preventive" class="card-main protect-link">
                <div class="icon-box"><i class="fas fa-calendar-alt"></i></div>
                <h3>Kalender Kerja</h3>
                <p>Penjadwalan aktivitas pekerjaan preventive maintenance sesuai tasklist.</p>
            </a>

            <a href="op/pbm" class="card-main">
                <div class="icon-box"><i class="fas fa-book-open"></i></div>
                <h3>PBM Update</h3>
                <p>Pusat Informasi Bongkar Muat dan Ketersediaan alat/dermaga standby.</p>
            </a>

            <a href="https://median.co/share/nmpmdnj" target="_blank" class="card-main">
                <div class="icon-box"><i class="fas fa-mobile"></i></div>
                <h3>KIPMAIN Mobile</h3>
                <p>Memudahkan pengiriman laporan secara real-time saat inspeksi di Dermaga.</p>
            </a>

            <section class="executor-section">
                <div class="section-header">
                    <span>Work Area Executor</span>
                    <div class="line"></div>
                </div>
                <div class="executor-grid">
                    <a href="ex/m/mlogin" class="ex-card"><i class="fas fa-cog"></i><span>Mekanik</span></a>
                    <a href="ex/e/elogin" class="ex-card"><i class="fas fa-bolt"></i><span>Elektrik</span></a>
                    <a href="ex/d/dlogin" class="ex-card"><i class="fas fa-ship"></i><span>Dermaga</span></a>
                    <a href="ex/v/vlogin" class="ex-card"><i class="fas fa-tools"></i><span>Vulkanisir</span></a>
                </div>
            </section>

            <section class="executor-section">
                <div class="section-header">
                    <span>Next Project</span>
                    <div class="line"></div>
                </div>
                <div class="executor-grid">
                    <a href="ex/c/clogin" class="ex-card"><i class="fas fa-water"></i><span>Cigading 2</span></a>
                    <a href="ex/s/slogin" class="ex-card"><i class="fas fa-truck-loading"></i><span>Supporting</span></a>
                    <a href="knowledge" class="ex-card"><i class="fas fa-book"></i><span>Knowledge</span></a>
                    <a href="planner" class="ex-card"><i class="fa-solid fa-arrow-up-right-from-square"></i><span>Perencanaan</span></a>
                </div>
            </section>

        </div>
    </main>

    <footer>
        <div class="k3-ticker-wrapper">
            <div class="k3-label"><i class="fas fa-hard-hat"></i> Peraturan HSE:</div>
            <div class="ticker-content">
                <div class="ticker-scroll">
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Wajib menggunakan alat pelindung diri seperti helm safety, rompi safety, dan sepatu safety.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Seluruh pekerja wajib membawa identitas diri dan memiliki izin kerja untuk memasuki Krakatau International Port.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Batas muatan truk tidak melebihi kapasitas, Patuhi rambu-rambu keselamatan saat Anda bekerja.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Dilarang memancing dan menangkap satwa di area Krakatau International Port.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Patuhi batas kecepatan di area Krakatau International Port, Batas kecepatan truk dan alat berat maksimal 20 km/jam. Batas kecepatan roda 4 dan roda 2 maksimal 40 km/jam.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Seluruh kendaraan wajib menyalakan lampu utama di area Krakatau International Port.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Wajib menggunakan body harness saat bekerja di area ketinggian.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Pekerja di dermaga dan lapangan, pekerjaan di atas permukaan air, wajib menggunakan life jacket.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Pastikan peralatan kerja dalam keadaan baik sebelum bekerja.</span>
                    <span><i class="fas fa-circle" style="font-size:8px"></i> Dilarang membawa minuman keras dan obat-obatan terlarang.</span>
                </div>
            </div>
        </div>

        <div class="footer-flex">
            <div>
                <h3 style="font-size: 0.9rem; font-weight: 800;">PORT FACILITY MAINTENANCE DEPARTMENT</h3>
                <p style="font-size: 0.7rem; color: #64748b;">&copy; <?= date('Y'); ?> PT. Krakatau Bandar Samudera.</p>
            </div>
            <div class="stat-group">
                <div class="stat-card"><small>Online</small><span style="font-size:0.8rem"><?= $totalOnline; ?></span></div>
                <div class="stat-card"><small>Visits</small><span style="font-size:0.8rem"><?= number_format($totalVisit); ?></span></div>
                <div class="stat-card"><small>Your IP</small><span style="font-size:0.8rem"><?= htmlspecialchars($userIp); ?></span></div>
            </div>

            <!-- ── Sensor Suhu & Kelembaban Real-time ── -->
            <a href="/monitoring.php"
               class="sensor-pill"
               id="sensorPill"
               data-status="offline"
               title="Monitoring Suhu & Kelembaban — Klik untuk Dashboard">
                <span class="sp-label">Sensor</span>
                <span class="sp-dot" id="spDot"></span>
                <span class="sp-reading">
                    <i class="fas fa-temperature-half"></i>
                    <strong id="spSuhu">--</strong>
                    <span>°C</span>
                </span>
                <span class="sp-sep">·</span>
                <span class="sp-reading">
                    <i class="fas fa-droplet"></i>
                    <strong id="spHumid">--</strong>
                    <span>%</span>
                </span>
                <span class="sp-badge" id="spBadge">OFFLINE</span>
            </a>

            <div class="status-pill"><div class="pulse"></div> SYSTEM ACTIVE</div>
        </div>
    </footer>

    <!-- ════════════════════════════════════════════════════════
         WIDGET PEMANTAUAN SUHU & KELEMBABAN — pojok kanan bawah
         Pengganti posisi "jam", tetap interaktif & real-time.
         ════════════════════════════════════════════════════════ -->
    <?php include __DIR__ . '/includes/widget_suhu.php'; ?>

    <script>
        document.addEventListener('click', function (e) {
            const target = e.target.closest('.protect-link');
            if (target) {
                const isAdmin = <?= $isAdmin; ?>;
                if (!isAdmin) {
                    e.preventDefault();
                    const password = prompt("Masukkan Password Akses:");
                    if (password === "urgent") {
                        window.location.href = target.getAttribute('href');
                    } else {
                        alert("Akses Ditolak!");
                    }
                }
            }
        });
    </script>

    <!-- ── Sensor Pill: polling real-time dari ESP32 ── -->
    <script>
    (function () {
        const POLL_MS = 20000; // sama dengan widget — setiap 20 detik

        const STATUS_COLOR = {
            aman    : '#10b981',
            waspada : '#f59e0b',
            bahaya  : '#ef4444',
            offline : '#64748b',
        };

        const pill    = document.getElementById('sensorPill');
        const dot     = document.getElementById('spDot');
        const elSuhu  = document.getElementById('spSuhu');
        const elHumid = document.getElementById('spHumid');
        const badge   = document.getElementById('spBadge');

        if (!pill) return; // guard: elemen tidak ada

        function setOffline() {
            dot.style.background = STATUS_COLOR.offline;
            elSuhu.textContent   = '--';
            elHumid.textContent  = '--';
            badge.textContent    = 'OFFLINE';
            pill.dataset.status  = 'offline';
        }

        function render(d) {
            // Tentukan level status gabungan (suhu + humid)
            const lvl = (d.status_suhu === 'bahaya' || d.status_humid === 'bahaya')
                ? 'bahaya'
                : (d.status_suhu === 'waspada' || d.status_humid === 'waspada')
                    ? 'waspada'
                    : 'aman';

            dot.style.background = STATUS_COLOR[lvl];
            elSuhu.textContent   = parseFloat(d.suhu).toFixed(1);
            elHumid.textContent  = parseFloat(d.kelembaban).toFixed(1);
            badge.textContent    = lvl.toUpperCase();
            pill.dataset.status  = lvl;
        }

        async function poll() {
            try {
                const res = await fetch('/api/get_latest.php', { cache: 'no-store' });
                const d   = await res.json();
                (!d || !d.suhu) ? setOffline() : render(d);
            } catch (_) {
                setOffline();
            }
        }

        poll();                          // langsung load pertama kali
        setInterval(poll, POLL_MS);     // lalu polling tiap 20 detik
    })();
    </script>
</body>
</html>