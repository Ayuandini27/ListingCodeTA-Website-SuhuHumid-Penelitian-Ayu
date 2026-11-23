<?php
/**
 * ============================================================
 *  api/inputsuhu.php
 *  TUJUAN: Pintu masuk SATU-SATUNYA tempat ESP32 mengirim data,
 *  untuk KEDUA ruangan (atas & bawah). Alat memanggil URL ini
 *  lewat HTTP POST setiap 20 detik:
 *      https://thermonode.my.id/api/inputsuhu.php
 *
 *  Sejak update multi-ruangan, ESP32 WAJIB menyertakan 1 field
 *  tambahan di body POST-nya: "lokasi" (isinya "atas" / "bawah"),
 *  supaya server tahu data ini milik ruangan yang mana. Kalau
 *  field ini tidak dikirim (misalnya firmware lama yang belum
 *  sempat diupdate), server otomatis menganggapnya "atas" —
 *  lihat LOKASI_DEFAULT & validasiLokasi() di config/koneksi.php.
 *
 *  Alur kerja file ini:
 *  1. Pastikan request yang masuk benar2 metode POST
 *  2. Ambil & validasi lokasi ("atas"/"bawah") — kalau tidak
 *     valid/tidak dikirim, dianggap "atas" (default)
 *  3. Validasi angka suhu & kelembaban masih wajar
 *  4. Hitung status (aman/waspada/bahaya) — DIHITUNG DI SINI,
 *     bukan oleh ESP32, supaya kalau batasnya berubah nanti,
 *     cukup ubah 1 file ini saja, tidak perlu update firmware.
 *  5. SELALU timpa baris live milik lokasi terkait di tabel
 *     monitoring_live (1 baris PER RUANGAN) — ini yang bikin
 *     widget & angka real-time TETAP update tiap 20 detik,
 *     persis seperti sebelumnya, hanya sekarang per-ruangan.
 *  6. HANYA catat baris baru ke monitoring_suhu (riwayat/chart)
 *     KALAU nilainya berubah cukup jauh dari data terakhir yang
 *     tercatat DI RUANGAN YANG SAMA, ATAU sudah lewat 5 menit
 *     sejak baris terakhir ruangan itu (heartbeat, bukti alat
 *     masih hidup). Dibandingkan PER RUANGAN (bukan baris riwayat
 *     terakhir lintas-ruangan) — supaya selisih alami suhu atas
 *     vs bawah TIDAK disalahartikan sebagai "perubahan mendadak"
 *     tiap kali dua ESP32 itu bergantian mengirim data, yang kalau
 *     dibiarkan akan membanjiri tabel riwayat. Ini yang membuat
 *     database tidak membengkak — TANPA perlu mengubah kode ESP32
 *     sama sekali, logikanya tetap 100% di server, sama seperti
 *     prinsip penentuan status di atas.
 *  7. Balas dalam format JSON (dibaca oleh ESP32 & oleh JS website)
 *
 *  Catatan: dibanding versi sebelumnya, response JSON di LANGKAH 7
 *  menambahkan field "lokasi" & "dicatat_riwayat" (true/false). Ini
 *  tidak mengganggu ESP32 selama firmware cuma mengecek field "status".
 * ============================================================
 */

declare(strict_types=1);

require __DIR__ . '/../config/koneksi.php';

header('Content-Type: application/json');


// ── Batas nilai untuk menentukan status ─────────────────────
// Hanya didefinisikan SEKALI di sini — dipakai oleh fungsi
// tentukanStatus() di bawah, dan otomatis konsisten dengan
// tampilan warna di dashboard (monitoring.php memakai angka
// yang sama untuk menggambar kartu/grafik). SAMA untuk kedua
// ruangan — kalau nanti ternyata ruang bawah butuh ambang batas
// berbeda, pisahkan jadi mis. BATAS_SUHU_BAWAH di sini.
const BATAS_SUHU  = 36.0;   // °C  → di atas ini = BAHAYA
const WARN_SUHU   = 33.0;   // °C  → di atas ini = WASPADA
const BATAS_HUMID = 70.0;   // %RH → di atas ini = BAHAYA
const WARN_HUMID  = 68.0;   // %RH → di atas ini = WASPADA

// ── Batas untuk keputusan "perlu dicatat ke riwayat atau tidak" ──
// Kalau SALAH SATU dari tiga syarat ini terpenuhi, baris baru
// ditambahkan ke monitoring_suhu. Kalau tidak ada yang terpenuhi,
// data tetap diterima & monitoring_live tetap di-update (LANGKAH 5),
// hanya saja tidak menambah baris riwayat.
//
// Nilai default di bawah ini mengikuti usulan awal (delta 0.5°C /
// 1%RH, heartbeat 5 menit). DHT22 punya akurasi ±0.5°C — kalau
// riwayat terasa masih terlalu ramai/gampang kepancing noise sensor,
// naikkan DELTA_SUHU_MIN ke 0.7–1.0, jangan diturunkan di bawah 0.5.
const DELTA_SUHU_MIN  = 0.5;   // °C   minimal perubahan suhu
const DELTA_HUMID_MIN = 1.0;   // %RH  minimal perubahan kelembaban
const HEARTBEAT_DETIK = 300;   // 5 menit — jaminan "alat masih hidup"

function tentukanStatus(float $nilai, float $batasBahaya, float $batasWaspada): string {
    if ($nilai > $batasBahaya)  return 'bahaya';
    if ($nilai > $batasWaspada) return 'waspada';
    return 'aman';
}


// ── LANGKAH 1: Tolak kalau bukan POST ───────────────────────
// ESP32 selalu kirim via POST. Kalau ada yang coba akses lewat
// browser biasa (GET), langsung ditolak di sini.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Gunakan metode POST']);
    exit;
}


// ── LANGKAH 2: Ambil & validasi lokasi ("atas" / "bawah") ───
// validasiLokasi() ada di config/koneksi.php — kalau field "lokasi"
// tidak dikirim atau isinya bukan salah satu dari LOKASI_LABEL,
// otomatis dianggap 'atas' (LOKASI_DEFAULT). is_string(...) dicek
// dulu supaya request aneh (mis. lokasi[]=x, jadi array) tidak bikin
// error fatal — cukup dianggap tidak valid lalu jatuh ke default.
$lokasiRaw = $_POST['lokasi'] ?? null;
$lokasi    = validasiLokasi(is_string($lokasiRaw) ? $lokasiRaw : null);


// ── LANGKAH 3: Validasi data yang dikirim ESP32 ─────────────
// filter_var(..., FILTER_VALIDATE_FLOAT) mengembalikan `false`
// kalau yang dikirim bukan angka sama sekali (misal kosong/teks).
$suhu       = isset($_POST['suhu'])       ? filter_var($_POST['suhu'], FILTER_VALIDATE_FLOAT)       : false;
$kelembaban = isset($_POST['kelembaban']) ? filter_var($_POST['kelembaban'], FILTER_VALIDATE_FLOAT) : false;

$valid = $suhu !== false && $kelembaban !== false
    && $suhu >= -40 && $suhu <= 80           // Batas wajar sensor DHT22
    && $kelembaban >= 0 && $kelembaban <= 100;

if (!$valid) {
    http_response_code(400);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Data tidak valid (suhu/kelembaban kosong atau di luar batas wajar)',
    ]);
    exit;
}


// ── LANGKAH 4: Hitung status aman/waspada/bahaya ────────────
$statusSuhu  = tentukanStatus($suhu, BATAS_SUHU, WARN_SUHU);
$statusHumid = tentukanStatus($kelembaban, BATAS_HUMID, WARN_HUMID);


// ── LANGKAH 5: SELALU timpa status live milik lokasi ini ────
// Tabel monitoring_live sekarang punya 1 baris PER LOKASI (primary
// key = kolom lokasi, bukan id tetap seperti dulu), masing-masing
// terus-menerus ditimpa lewat ON DUPLICATE KEY UPDATE. Inilah yang
// dibaca get_latest.php, JADI WIDGET & ANGKA REAL-TIME DI
// MONITORING.PHP TETAP UPDATE TIAP 20 DETIK SEPERTI BIASA — per
// ruangan, terlepas dari apakah LANGKAH 6 di bawah menambah baris
// riwayat atau tidak.
$stmtLive = $conn->prepare(
    "INSERT INTO monitoring_live (lokasi, suhu, kelembaban, status_suhu, status_humid, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW())
     ON DUPLICATE KEY UPDATE
        suhu = VALUES(suhu),
        kelembaban = VALUES(kelembaban),
        status_suhu = VALUES(status_suhu),
        status_humid = VALUES(status_humid),
        updated_at = VALUES(updated_at)"
);
$stmtLive->bind_param("sddss", $lokasi, $suhu, $kelembaban, $statusSuhu, $statusHumid);
$stmtLive->execute();
$stmtLive->close();


// ── LANGKAH 6: Putuskan apakah perlu dicatat ke riwayat ─────
// Dibandingkan dengan baris TERAKHIR YANG TERCATAT DI LOKASI YANG
// SAMA di monitoring_suhu (bukan sample sebelumnya, dan bukan baris
// terakhir lintas-ruangan) — supaya perubahan kecil yang menumpuk
// pelan-pelan tetap kena tangkap saat totalnya sudah signifikan,
// TANPA salah anggap selisih alami atas-vs-bawah sebagai lonjakan.
$shouldLog = true; // default: catat (dipakai kalau ini baris pertama utk lokasi ini)

$stmtLast = $conn->prepare(
    "SELECT suhu, kelembaban, created_at FROM monitoring_suhu
     WHERE lokasi = ? ORDER BY created_at DESC LIMIT 1"
);
$stmtLast->bind_param("s", $lokasi);
$stmtLast->execute();
$lastLogged = $stmtLast->get_result()->fetch_assoc();
$stmtLast->close();

if ($lastLogged) {
    $deltaSuhu  = abs($suhu - (float) $lastLogged['suhu']);
    $deltaHumid = abs($kelembaban - (float) $lastLogged['kelembaban']);
    $detikLalu  = time() - strtotime($lastLogged['created_at']);

    $shouldLog = $deltaSuhu  >= DELTA_SUHU_MIN
              || $deltaHumid >= DELTA_HUMID_MIN
              || $detikLalu  >= HEARTBEAT_DETIK;
}

$logged   = false;
$insertId = null;

if ($shouldLog) {
    $stmt = $conn->prepare(
        "INSERT INTO monitoring_suhu (lokasi, suhu, kelembaban, status_suhu, status_humid)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sddss", $lokasi, $suhu, $kelembaban, $statusSuhu, $statusHumid);

    if ($stmt->execute()) {
        $logged   = true;
        $insertId = $stmt->insert_id;
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Gagal simpan riwayat: ' . $stmt->error]);
        $stmt->close();
        $conn->close();
        exit;
    }
    $stmt->close();
}


// ── LANGKAH 7: Kirim balasan JSON ───────────────────────────
// ESP32 membaca balasan ini untuk tahu apakah pengiriman sukses
// (lihat Serial Monitor di firmware — ia mencari kata "Sukses").
// Field "status" tetap "ok" seperti sebelumnya, jadi firmware yang
// sudah ada TIDAK PERLU diubah sama sekali (field baru "lokasi"
// hanya tambahan info, bukan pengganti field lama).
echo json_encode([
    'status'          => 'ok',
    'lokasi'          => $lokasi,
    'id'              => $insertId,        // null kalau kali ini cuma update live, tidak nambah riwayat
    'suhu'            => round($suhu, 1),
    'kelembaban'      => round($kelembaban, 1),
    'status_suhu'     => $statusSuhu,
    'status_humid'    => $statusHumid,
    'dicatat_riwayat' => $logged,           // true = nambah baris baru di monitoring_suhu
    'waktu'           => date('Y-m-d H:i:s'),
]);

$conn->close();