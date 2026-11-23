<?php
/**
 * ============================================================
 *  includes/widget_suhu.php
 *  Widget pemantauan suhu & kelembaban — tempel di halaman apa
 *  saja dengan satu baris:
 *
 *      <?php include __DIR__ . '/includes/widget_suhu.php'; ?>
 *
 *  Letakkan tepat sebelum </body>.
 *
 *  PRASYARAT di halaman pemanggil:
 *  1. Font Awesome sudah dimuat (index.php sudah memuatnya)
 *  2. assets/css/style.css sudah dimuat (sumber token warna)
 *  3. assets/css/widget-suhu.css & assets/js/widget-suhu.js
 *     otomatis dimuat di bawah ini menggunakan path absolut "/"
 *     — aman dipanggil dari folder bertingkat (ex/m/mlogin.php dst).
 * ============================================================
 */
?>
<link rel="stylesheet" href="/assets/css/widget-suhu.css">

<div class="suhu-widget" id="suhuWidget" data-state="collapsed">

    <!-- Panel detail — muncul saat pill diklik -->
    <div class="suhu-widget__panel" id="suhuPanel">
        <div class="suhu-widget__panel-head">
            <span><i class="fas fa-satellite-dish"></i> Pemantauan Real-time</span>
            <button class="suhu-widget__close" id="suhuCloseBtn" aria-label="Tutup panel">&times;</button>
        </div>

        <div class="suhu-widget__metric">
            <div class="suhu-widget__metric-icon"><i class="fas fa-temperature-half"></i></div>
            <div class="suhu-widget__metric-body">
                <span class="suhu-widget__metric-label">Suhu</span>
                <span class="suhu-widget__metric-value"><span id="suhuValBig">--</span>°C</span>
            </div>
            <span class="suhu-widget__badge" id="suhuBadge">--</span>
        </div>

        <div class="suhu-widget__metric">
            <div class="suhu-widget__metric-icon"><i class="fas fa-droplet"></i></div>
            <div class="suhu-widget__metric-body">
                <span class="suhu-widget__metric-label">Kelembaban</span>
                <span class="suhu-widget__metric-value"><span id="humidValBig">--</span>%</span>
            </div>
            <span class="suhu-widget__badge" id="humidBadge">--</span>
        </div>

        <div class="suhu-widget__footer">
            <span id="suhuUpdated">Memuat data…</span>
            <a href="/monitoring.php" class="suhu-widget__link">
                Dashboard <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>

    <!-- Pill ringkas — selalu terlihat, pengganti posisi "jam" -->
    <button class="suhu-widget__pill" id="suhuPillBtn" aria-expanded="false" aria-controls="suhuPanel" type="button">
        <span class="suhu-widget__dot" id="suhuDot"></span>
        <span class="suhu-widget__reading">
            <span id="suhuVal">--</span><span class="suhu-widget__unit">°C</span>
        </span>
        <span class="suhu-widget__sep">|</span>
        <span class="suhu-widget__reading">
            <span id="humidVal">--</span><span class="suhu-widget__unit">%</span>
        </span>
        <i class="fas fa-chevron-up suhu-widget__chev"></i>
    </button>
</div>

<script src="/assets/js/widget-suhu.js" defer></script>