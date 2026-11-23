<?php
/**
 * ============================================================
 *  api/get_latest.php
 *  TUJUAN: Memberi tahu "berapa suhu & kelembaban SAAT INI?"
 *  dalam bentuk JSON ringkas — HANYA 1 baris data, bukan riwayat.
 *
 *  Dipakai oleh:
 *  - assets/js/widget-suhu.js (widget pojok kanan bawah, di SEMUA halaman)
 *
 *  Membaca dari monitoring_live (1 baris yang terus ditimpa oleh
 *  inputsuhu.php setiap ESP32 kirim data), BUKAN dari monitoring_suhu
 *  (riwayat) — supaya nilai yang dikembalikan SELALU yang paling baru,
 *  terlepas dari apakah baris itu ikut dicatat ke riwayat atau tidak.
 *
 *  Nama kolom "updated_at" DIALIASKAN menjadi "created_at" di query
 *  supaya bentuk JSON persis sama seperti sebelumnya — jadi
 *  assets/js/widget-suhu.js tidak perlu diubah untuk bagian itu.
 *
 *  TAMBAHAN: field "offline" (true/false) — dihitung lewat
 *  isAlatOffline() di config/koneksi.php, berdasarkan seberapa lama
 *  monitoring_live belum di-update. Dipakai widget-suhu.js untuk
 *  menampilkan badge OFFLINE yang sebenarnya, bukan cuma saat baris
 *  live-nya benar-benar kosong.
 * ============================================================
 */

require __DIR__ . '/../config/koneksi.php';

header('Content-Type: application/json');

// Ambil satu-satunya baris live (id selalu = 1)
$result = $conn->query(
    "SELECT suhu, kelembaban, status_suhu, status_humid, updated_at AS created_at
     FROM monitoring_live
     WHERE id = 1"
);

$row = $result ? $result->fetch_assoc() : null;

if ($row) {
    $row['offline'] = isAlatOffline($row['created_at']);
}

// Kalau baris live belum pernah ke-set (harusnya tidak mungkin setelah
// migrasi SQL dijalankan, tapi dijaga saja) kirim status 'empty' supaya
// widget di JS bisa menampilkan "Offline" dengan rapi, bukan error mentah.
echo json_encode($row ?: ['status' => 'empty', 'message' => 'Belum ada data sensor', 'offline' => true]);

$conn->close();