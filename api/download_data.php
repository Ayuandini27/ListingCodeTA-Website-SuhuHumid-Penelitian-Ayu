<?php
/**
 * ============================================================
 *  api/download_data.php
 *  TUJUAN: Mengubah data di database jadi file CSV yang bisa
 *  dibuka di Excel/Spreadsheet — dipakai tombol "Unduh" di
 *  monitoring.php (laporan harian/mingguan/bulanan/custom).
 *
 *  Alur kerja:
 *  1. Tentukan rentang tanggal (today/week/month/custom)
 *  2. Set header HTTP supaya browser otomatis download file
 *  3. Ambil data dari database sesuai rentang tanggal
 *  4. Tulis baris per baris ke format CSV
 *  5. Tambahkan ringkasan total alert di baris terakhir
 * ============================================================
 */
/**
 * ============================================================
 *  api/download_data.php  — Multi-Format Export v2
 *  ============================================================
 *  Mendukung tiga format download:
 *    ?format=csv    → CSV (UTF-8 BOM) dengan statistik + data
 *    ?format=excel  → XLSX native via ZipArchive (tanpa Composer)
 *    ?format=json   → JSON mentah untuk PDF client-side (jsPDF)
 *
 *  Periode via:
 *    ?type=today | week | month | custom
 *    &start=Y-m-d &end=Y-m-d   (hanya untuk type=custom)
 * ============================================================
 */
declare(strict_types=1);
require __DIR__ . '/../config/koneksi.php';

// ── Parameter validasi ───────────────────────────────────────
$allowedFormats = ['csv', 'excel', 'json'];
$allowedTypes   = ['today', 'week', 'month', 'custom'];

$format = in_array($_GET['format'] ?? 'csv', $allowedFormats) ? $_GET['format'] : 'csv';
$type   = in_array($_GET['type']   ?? 'today', $allowedTypes) ? $_GET['type']   : 'today';

// Lokasi ruangan mana yang mau diunduh — default 'atas' (daftar resmi
// & validasiLokasi()/labelLokasi() ada di config/koneksi.php).
$lokasiRaw   = $_GET['lokasi'] ?? null;
$lokasi      = validasiLokasi(is_string($lokasiRaw) ? $lokasiRaw : null);
$lokasiLabel = labelLokasi($lokasi);                  // "Ruang Atas" / "Ruang Bawah" — utk judul laporan
$lokasiSlug  = str_replace(' ', '', $lokasiLabel);    // "RuangAtas" / "RuangBawah" — utk nama file

// ── Rentang tanggal ──────────────────────────────────────────
switch ($type) {
    case 'week':
        $s       = date('Y-m-d', strtotime('monday this week'));
        $e       = date('Y-m-d', strtotime('sunday this week'));
        $period  = 'Minggu ke-' . date('W') . ' Tahun ' . date('Y');
        $baseName = 'Mingguan_W' . date('W') . '_' . date('Y');
        break;
    case 'month':
        $s       = date('Y-m-01');
        $e       = date('Y-m-t');
        $period  = date('F Y');
        $baseName = date('F_Y');
        break;
    case 'custom':
        $s       = $conn->real_escape_string($_GET['start'] ?? date('Y-m-d'));
        $e       = $conn->real_escape_string($_GET['end']   ?? date('Y-m-d'));
        $period  = date('d M Y', strtotime($s)) . ' s/d ' . date('d M Y', strtotime($e));
        $baseName = str_replace('-', '', $s) . '_sd_' . str_replace('-', '', $e);
        break;
    default:   // today
        $s       = $e = date('Y-m-d');
        $period  = 'Hari Ini, ' . date('d F Y');
        $baseName = 'Harian_' . date('d-m-Y');
}

// ── Ambil data monitoring ────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT suhu, kelembaban, status_suhu, status_humid, created_at
     FROM monitoring_suhu
     WHERE lokasi = ? AND DATE(created_at) BETWEEN ? AND ?
     ORDER BY created_at ASC"
);
$stmt->bind_param('sss', $lokasi, $s, $e);
$stmt->execute();
$res = $stmt->get_result();

$rows   = [];
$alertS = $alertH = 0;
while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
    if ($row['status_suhu']  === 'bahaya') $alertS++;
    if ($row['status_humid'] === 'bahaya') $alertH++;
}
$stmt->close();

// ── Statistik periode ────────────────────────────────────────
$lokasiEsc = $conn->real_escape_string($lokasi);
$stRes = $conn->query(
    "SELECT ROUND(AVG(suhu),1) avg_s, MAX(suhu) max_s, MIN(suhu) min_s,
            ROUND(AVG(kelembaban),1) avg_h, MAX(kelembaban) max_h, MIN(kelembaban) min_h,
            COUNT(*) total
     FROM monitoring_suhu
     WHERE lokasi = '$lokasiEsc' AND DATE(created_at) BETWEEN '$s' AND '$e'"
);
$st = $stRes ? ($stRes->fetch_assoc() ?? []) : [];
$conn->close();

// ── Dispatch ke formatter ────────────────────────────────────
match ($format) {
    'excel' => exportExcel($rows, $st, $period, $baseName, $alertS, $alertH, $lokasiLabel, $lokasiSlug),
    'json'  => exportJson($rows, $st, $period, $alertS, $alertH, $lokasiLabel),
    default => exportCSV($rows, $st, $period, $baseName, $alertS, $alertH, $lokasiLabel, $lokasiSlug),
};
exit;


// ════════════════════════════════════════════════════════════
//  FORMAT 1: CSV
// ════════════════════════════════════════════════════════════
function exportCSV(array $rows, array $st, string $period, string $baseName, int $alertS, int $alertH, string $lokasiLabel, string $lokasiSlug): void
{
    $filename = 'Data Monitoring Cryo_' . $lokasiSlug . '_' . $baseName . '_' . date('Ymd') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM — supaya Excel kenali karakter °C

    // ── Info laporan ──
    fputcsv($out, ['Cryonnodes — Laporan Monitoring Suhu & Kelembaban']);
    fputcsv($out, ['Lokasi', $lokasiLabel]);
    fputcsv($out, ['Periode', $period]);
    fputcsv($out, ['Tanggal Cetak', date('d F Y H:i:s')]);
    fputcsv($out, ['Total Data', count($rows)]);
    fputcsv($out, []);

    // ── Statistik ──
    fputcsv($out, ['=== STATISTIK PERIODE ===']);
    fputcsv($out, ['Metrik',       'Suhu (°C)',         'Kelembaban (%)']);
    fputcsv($out, ['Rata-rata',    $st['avg_s'] ?? '-', $st['avg_h'] ?? '-']);
    fputcsv($out, ['Maksimum',     $st['max_s'] ?? '-', $st['max_h'] ?? '-']);
    fputcsv($out, ['Minimum',      $st['min_s'] ?? '-', $st['min_h'] ?? '-']);
    fputcsv($out, ['Alert Bahaya', $alertS . 'x',       $alertH . 'x']);
    fputcsv($out, []);

    // ── Header tabel ──
    fputcsv($out, ['No', 'Tanggal', 'Waktu', 'Suhu (°C)', 'Status Suhu', 'Kelembaban (%)', 'Status Humid']);

    // ── Baris data ──
    $n = 1;
    foreach ($rows as $row) {
        fputcsv($out, [
            $n++,
            date('d/m/Y', strtotime($row['created_at'])),
            date('H:i:s',  strtotime($row['created_at'])),
            number_format((float) $row['suhu'],       1, '.', ''),
            strtoupper($row['status_suhu']),
            number_format((float) $row['kelembaban'], 1, '.', ''),
            strtoupper($row['status_humid']),
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, ['--- End of Report | Cryonnodes System | Cryonnodes.com ---']);
    fclose($out);
}


// ════════════════════════════════════════════════════════════
//  FORMAT 2: JSON  (dipakai oleh jsPDF di monitoring.php)
// ════════════════════════════════════════════════════════════
function exportJson(array $rows, array $st, string $period, int $alertS, int $alertH, string $lokasiLabel): void
{
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); // Izinkan fetch dari halaman yang sama

    // Tabel untuk jsPDF autoTable — maksimal 500 baris terakhir untuk performa PDF
    $tableRows = [];
    $n = 1;
    foreach (array_slice($rows, 0, 500) as $row) {
        $tableRows[] = [
            $n++,
            date('d M Y',  strtotime($row['created_at'])),
            date('H:i:s',  strtotime($row['created_at'])),
            number_format((float) $row['suhu'],       1) . '°C',
            strtoupper($row['status_suhu']),
            number_format((float) $row['kelembaban'], 1) . '%',
            strtoupper($row['status_humid']),
        ];
    }

    echo json_encode([
        'lokasi'    => $lokasiLabel,
        'period'    => $period,
        'generated' => date('d F Y H:i:s'),
        'total'     => count($rows),
        'shown'     => min(count($rows), 500),
        'stats'     => [
            'avg_s' => $st['avg_s'] ?? '-',
            'max_s' => $st['max_s'] ?? '-',
            'min_s' => $st['min_s'] ?? '-',
            'avg_h' => $st['avg_h'] ?? '-',
            'max_h' => $st['max_h'] ?? '-',
            'min_h' => $st['min_h'] ?? '-',
        ],
        'alert_suhu'  => $alertS,
        'alert_humid' => $alertH,
        'rows'        => $tableRows,
    ]);
}


// ════════════════════════════════════════════════════════════
//  FORMAT 3: EXCEL — XLSX murni PHP, tanpa library eksternal
//  Teknik: ZipArchive + OpenXML (standar Office Open XML)
// ════════════════════════════════════════════════════════════
function exportExcel(array $rows, array $st, string $period, string $baseName, int $alertS, int $alertH, string $lokasiLabel, string $lokasiSlug): void
{
    if (!class_exists('ZipArchive')) {
        // ZipArchive tidak ada di server ini — fallback ke CSV
        exportCSV($rows, $st, $period, $baseName, $alertS, $alertH, $lokasiLabel, $lokasiSlug);
        return;
    }

    $filename = 'Data Cryo_' . $lokasiSlug . '_' . str_replace(['/', ' ', ','], '_', $baseName) . '_' . date('Ymd') . '.xlsx';

    // ── Susun baris worksheet ────────────────────────────────
    //  Baris 1–3  : info laporan
    //  Baris 4    : kosong
    //  Baris 5    : judul seksi statistik
    //  Baris 6    : label kolom statistik
    //  Baris 7–11 : nilai statistik
    //  Baris 12   : kosong
    //  Baris 13   : header tabel data
    //  Baris 14+  : data monitoring

    $TABEL_HEADER_ROW = 13; // 1-indexed

    $sheet = [
        /* 1  */ ['Cryonnodes — Laporan Monitoring Suhu & Kelembaban — ' . $lokasiLabel],
        /* 2  */ ['Periode: ' . $period],
        /* 3  */ ['Dicetak: ' . date('d F Y H:i:s') . '    |    Total Data: ' . count($rows) . ' baris'],
        /* 4  */ [],
        /* 5  */ ['STATISTIK PERIODE'],
        /* 6  */ ['Metrik',        'Suhu (°C)',         'Kelembaban (%)'],
        /* 7  */ ['Rata-rata',     $st['avg_s'] ?? '-', $st['avg_h'] ?? '-'],
        /* 8  */ ['Maksimum',      $st['max_s'] ?? '-', $st['max_h'] ?? '-'],
        /* 9  */ ['Minimum',       $st['min_s'] ?? '-', $st['min_h'] ?? '-'],
        /* 10 */ ['Alert Bahaya',  $alertS . 'x',       $alertH . 'x'],
        /* 11 */ ['Total Data',    count($rows),         ''],
        /* 12 */ [],
        /* 13 */ ['No', 'Tanggal', 'Waktu', 'Suhu (°C)', 'Status Suhu', 'Kelembaban (%)', 'Status Humid'],
    ];

    // Tambah baris data
    $n = 1;
    foreach ($rows as $row) {
        $sheet[] = [
            $n++,
            date('d/m/Y', strtotime($row['created_at'])),
            date('H:i:s',  strtotime($row['created_at'])),
            (float) $row['suhu'],
            strtoupper($row['status_suhu']),
            (float) $row['kelembaban'],
            strtoupper($row['status_humid']),
        ];
    }

    // Bangun XLSX dan kirim
    $xlsx = buildXLSX($sheet, $TABEL_HEADER_ROW);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('Pragma: no-cache');
    echo $xlsx;
}


// ── XLSX Builder ─────────────────────────────────────────────
function buildXLSX(array $sheetData, int $tblHeaderRow): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'thermo_xl_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml',         xlContent());
    $zip->addFromString('_rels/.rels',                 xlRels());
    $zip->addFromString('xl/workbook.xml',             xlWorkbook());
    $zip->addFromString('xl/_rels/workbook.xml.rels',  xlWorkbookRels());
    $zip->addFromString('xl/styles.xml',               xlStyles());
    $zip->addFromString('xl/worksheets/sheet1.xml',    xlSheet($sheetData, $tblHeaderRow));

    $zip->close();
    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

function xlContent(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml"          ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
  <Override PartName="/xl/styles.xml"            ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
</Types>'; }

function xlRels(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>'; }

function xlWorkbook(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets><sheet name="Monitoring" sheetId="1" r:id="rId1"/></sheets>
</workbook>'; }

function xlWorkbookRels(): string { return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
  <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"   Target="styles.xml"/>
</Relationships>'; }

function xlStyles(): string {
    // Style index reference (s="N"):
    //  0 = default/normal
    //  1 = judul laporan (biru bold besar)
    //  2 = info baris (period, tanggal)
    //  3 = header seksi (light blue bg, bold)
    //  4 = label statistik (bold, gray bg)
    //  5 = nilai statistik (center, gray bg)
    //  6 = header tabel (dark navy bg, putih, center, border)
    //  7 = sel data normal (border)
    //  8 = sel data center (border, untuk No/Suhu/Humid)
    //  9 = status AMAN (green bg, center, border)
    // 10 = status WASPADA (yellow bg, center, border)
    // 11 = status BAHAYA (red bg, center, border)

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <fonts count="5">
    <font><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="14"/><color rgb="FF0F4C75"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>
    <font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>
    <font><sz val="10"/><color rgb="FF475569"/><name val="Calibri"/><family val="2"/></font>
  </fonts>
  <fills count="9">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F4FD"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF1F5F9"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF0F172A"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF3C7"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0F9FF"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color auto="1"/></left>
      <right style="thin"><color auto="1"/></right>
      <top style="thin"><color auto="1"/></top>
      <bottom style="thin"><color auto="1"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="12">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0"/>
    <xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="7" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>
  </cellXfs>
</styleSheet>';
}

function xlSheet(array $data, int $tblHeaderRow): string
{
    $COLS = ['A','B','C','D','E','F','G'];

    // Freeze panes — beku di bawah baris header tabel
    $freezeCell = 'A' . ($tblHeaderRow + 1);

    $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    // sheetViews HARUS sebelum cols & sheetData (urutan wajib OpenXML)
    $xml .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
    $xml .= '<pane ySplit="' . $tblHeaderRow . '" topLeftCell="' . $freezeCell
          . '" activePane="bottomLeft" state="frozen"/>';
    $xml .= '</sheetView></sheetViews>';

    // Lebar kolom
    $xml .= '<cols>';
    $xml .= '<col min="1" max="1" width="16" customWidth="1"/>'; // A: No / Metrik
    $xml .= '<col min="2" max="2" width="14" customWidth="1"/>'; // B: Tanggal / Nilai
    $xml .= '<col min="3" max="3" width="12" customWidth="1"/>'; // C: Waktu / Nilai
    $xml .= '<col min="4" max="4" width="13" customWidth="1"/>'; // D: Suhu (°C)
    $xml .= '<col min="5" max="5" width="15" customWidth="1"/>'; // E: Status Suhu
    $xml .= '<col min="6" max="6" width="16" customWidth="1"/>'; // F: Kelembaban (%)
    $xml .= '<col min="7" max="7" width="16" customWidth="1"/>'; // G: Status Humid
    $xml .= '</cols>';

    $xml .= '<sheetData>';

    foreach ($data as $rIdx => $row) {
        $rowNum = $rIdx + 1;

        if (empty($row)) {
            $xml .= '<row r="' . $rowNum . '" ht="6" customHeight="1"/>';
            continue;
        }

        // Tinggi baris khusus
        $ht  = ($rowNum === 1) ? ' ht="22" customHeight="1"' : ' ht="18" customHeight="1"';
        $xml .= '<row r="' . $rowNum . '"' . $ht . '>';

        foreach ($row as $cIdx => $val) {
            if ($cIdx >= count($COLS)) break;
            $cellRef = $COLS[$cIdx] . $rowNum;

            // ── Tentukan style ──
            if ($rowNum === 1) {
                $style = ($cIdx === 0) ? 1 : 0; // Judul laporan
            } elseif ($rowNum <= 3) {
                $style = ($cIdx === 0) ? 2 : 0; // Info laporan (period, tanggal)
            } elseif ($rowNum === 5) {
                $style = ($cIdx === 0) ? 3 : 0; // Header seksi statistik
            } elseif ($rowNum === 6) {
                $style = ($cIdx <= 2) ? 4 : 0;  // Label kolom statistik (bold)
            } elseif ($rowNum >= 7 && $rowNum <= 11) {
                $style = ($cIdx === 0) ? 4 : 5; // Baris nilai statistik
            } elseif ($rowNum === $tblHeaderRow) {
                $style = 6; // Header tabel data — dark navy, putih
            } elseif ($rowNum > $tblHeaderRow) {
                // Baris data monitoring — warna per status
                if ($cIdx === 4 || $cIdx === 6) {
                    $v = strtolower((string) $val);
                    $style = match ($v) {
                        'aman'    => 9,
                        'waspada' => 10,
                        'bahaya'  => 11,
                        default   => 8,
                    };
                } elseif ($cIdx === 0 || $cIdx === 3 || $cIdx === 5) {
                    $style = 8; // No, Suhu, Kelembaban — center
                } else {
                    $style = 7; // Tanggal, Waktu — rata kiri
                }
            } else {
                $style = 0;
            }

            // ── Tulis sel ──
            if ($val === '' || $val === null) {
                $xml .= '<c r="' . $cellRef . '" s="' . $style . '"/>';
                continue;
            }

            if (is_int($val) || is_float($val)) {
                $xml .= '<c r="' . $cellRef . '" s="' . $style . '"><v>' . $val . '</v></c>';
            } else {
                $esc  = htmlspecialchars((string) $val, ENT_XML1, 'UTF-8');
                $xml .= '<c r="' . $cellRef . '" t="inlineStr" s="' . $style . '"><is><t>'
                      . $esc . '</t></is></c>';
            }
        }

        $xml .= '</row>';
    }

    $xml .= '</sheetData>';
    $xml .= '</worksheet>';
    return $xml;
}