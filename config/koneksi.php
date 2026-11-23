<?php
$host = "localhost";
$user = "username_database";
$pass = "password_database";
$db = "nama_database";

$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    http_response_code(550);
    header('Content-Type: application/json');
    error_log('Koneksi DB gagal: ' . mysqli_connect_error());

die(json_encode([
        'status'  => 'error',
        'message' => 'Koneksi database gagal. Cek config/koneksi.php',
    ]));
}
date_default_timezone_set('Asia/Jakarta');
$conn->query("SET time_zone = '+07:00'");


// ── Pengaturan bersama: deteksi status OFFLINE alat ─────────────
// Ditaruh di sini (bukan di inputsuhu.php / get_latest.php /
// monitoring.php masing-masing) karena koneksi.php ini SATU-SATUNYA
// file yang di-require oleh semuanya — jadi konstanta & fungsinya
// otomatis konsisten di mana pun dipakai, tidak perlu didefinisikan
// ulang / bisa ke-drift beda nilai di file lain.
//
// ESP32 menimpa monitoring_live setiap 20 detik SELAMA alat menyala
// (lihat api/inputsuhu.php LANGKAH 4). Jadi kalau monitoring_live
// .updated_at sudah lebih tua dari OFFLINE_AFTER_DETIK, berarti sudah
// lewat beberapa kali jadwal kirim tanpa kabar sama sekali — bukan
// cuma telat 1x karena jaringan lambat, tapi kemungkinan besar alat
// mati/kabel lepas.
//
// PENTING: 90 detik ini BEDA TUJUAN dengan HEARTBEAT_DETIK (300 detik)
// di inputsuhu.php. HEARTBEAT_DETIK menjawab "perlu dicatat ke riwayat
// atau tidak", sedangkan OFFLINE_AFTER_DETIK menjawab "alatnya masih
// hidup atau tidak". Sengaja tidak disamakan — kalau dipatok ke 300
// detik, butuh waktu 5-10 menit baru dashboard bilang OFFLINE padahal
// alat sudah mati sejak awal; kurang cepat untuk sistem K3.
const OFFLINE_AFTER_DETIK = 90;

function isAlatOffline(?string $waktuUpdate): bool {
    if (!$waktuUpdate) return true;
    return (time() - strtotime($waktuUpdate)) >= OFFLINE_AFTER_DETIK;
}


// ── Pengaturan bersama: daftar lokasi/ruangan yang valid ─────────
// Kapasitor bank punya 2 ruangan, masing-masing dengan unit ESP32
// sendiri-sendiri: "atas" dan "bawah". Sama seperti OFFLINE_AFTER_DETIK
// di atas, daftar ini SENGAJA ditaruh di sini (bukan diulang di tiap
// file) supaya SATU-SATUNYA sumber kebenaran dipakai oleh:
//   - inputsuhu.php      → validasi field "lokasi" yang dikirim ESP32
//   - get_latest.php     → validasi ?lokasi= dari widget
//   - monitoring.php     → validasi ?lokasi= dari dashboard + switcher
//   - download_data.php  → validasi ?lokasi= saat unduh laporan
// Kalau nanti nambah ruangan/alat ketiga, cukup tambah 1 baris di
// LOKASI_LABEL — file lain otomatis ikut, tidak perlu diubah satu-satu.
//
// LOKASI_DEFAULT = 'atas' supaya tetap backward-compatible dengan unit
// ESP32 ruang atas yang sudah berjalan duluan, seandainya firmware-nya
// belum sempat diupdate untuk ikut mengirim field "lokasi".
const LOKASI_DEFAULT = 'atas';
const LOKASI_LABEL = [
    'atas'  => 'Ruang Atas',
    'bawah' => 'Ruang Bawah',
];

function validasiLokasi(?string $lokasi): string {
    $lokasi = strtolower(trim((string) $lokasi));
    return array_key_exists($lokasi, LOKASI_LABEL) ? $lokasi : LOKASI_DEFAULT;
}

function labelLokasi(string $lokasi): string {
    return LOKASI_LABEL[$lokasi] ?? ucfirst($lokasi);
}
?>