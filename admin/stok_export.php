<?php
/**
 * ADMIN - Stok Excel/CSV Export
 *
 * Tüm aktif/pasif stokları XLSX veya CSV olarak indirir.
 * Filtreler stoklar.php ile uyumlu (firma_id, grup_id, durum, q).
 */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/xlsx_helper.php';
require_admin();

$db = db();

// Filtreler (stoklar.php ile aynı)
$q = trim($_GET['q'] ?? '');
$firma_id = (int)($_GET['firma_id'] ?? 0);
$grup_id = (int)($_GET['grup_id'] ?? 0);
$durum = $_GET['durum'] ?? '';
$format = ($_GET['format'] ?? 'xlsx') === 'csv' ? 'csv' : 'xlsx';

$where = []; $params = [];
if ($q !== '') {
    $where[] = '(s.stok_kodu LIKE ? OR s.ad LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($firma_id) { $where[] = 's.firma_id=?'; $params[] = $firma_id; }
if ($grup_id) { $where[] = 's.iskonto_grup_id=?'; $params[] = $grup_id; }
if ($durum === 'aktif') $where[] = 's.aktif=1';
elseif ($durum === 'pasif') $where[] = 's.aktif=0';

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT s.id, s.stok_kodu, s.ad,
               ag.ad AS ana_grup,
               f.kod AS firma_kod, f.ad AS firma_ad,
               ig.ad AS iskonto_grup,
               s.birim, s.doviz,
               s.mt_kg_fiyati, s.boy_fiyati, s.kg_per_mt, s.boy_uzunluk,
               s.aktif
        FROM tk_stoklar s
        LEFT JOIN tk_ana_gruplar ag  ON ag.id = s.ana_grup_id
        LEFT JOIN tk_firmalar f      ON f.id = s.firma_id
        LEFT JOIN tk_iskonto_gruplar ig ON ig.id = s.iskonto_grup_id
        $where_sql
        ORDER BY ag.ad, f.ad, ig.ad, s.stok_kodu";
$st = $db->prepare($sql);
$st->execute($params);
$stoklar = $st->fetchAll();

// Sütun başlıkları (Türkçe)
$headers = [
    'ID',
    'Stok Kodu',
    'Ürün Adı',
    'Ana Grup',
    'Firma Kodu',
    'Firma',
    'İskonto Grubu',
    'Birim',
    'Döviz',
    'MT/KG Fiyatı',
    'Boy Fiyatı',
    'KG/MT',
    'Boy (m)',
    'Aktif (1/0)',
];

$rows = [];
foreach ($stoklar as $s) {
    $rows[] = [
        (int)$s['id'],
        $s['stok_kodu'],
        $s['ad'],
        $s['ana_grup'] ?? '',
        $s['firma_kod'] ?? '',
        $s['firma_ad'] ?? '',
        $s['iskonto_grup'] ?? '',
        $s['birim'],
        $s['doviz'],
        $s['mt_kg_fiyati'] !== null ? (float)$s['mt_kg_fiyati'] : '',
        $s['boy_fiyati'] !== null ? (float)$s['boy_fiyati'] : '',
        $s['kg_per_mt'] !== null ? (float)$s['kg_per_mt'] : '',
        $s['boy_uzunluk'] !== null ? (int)$s['boy_uzunluk'] : '',
        (int)$s['aktif'],
    ];
}

$tarih = date('Ymd_His');
$dosya_adi = "stoklar_$tarih.$format";
$tmpfile = tempnam(sys_get_temp_dir(), 'tkexp_');

try {
    if ($format === 'xlsx') {
        xlsx_write($tmpfile, $headers, $rows, 'Stoklar');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    } else {
        csv_write($tmpfile, $headers, $rows);
        header('Content-Type: text/csv; charset=utf-8');
    }
    header('Content-Disposition: attachment; filename="' . $dosya_adi . '"');
    header('Content-Length: ' . filesize($tmpfile));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    readfile($tmpfile);
    @unlink($tmpfile);
    exit;
} catch (Exception $e) {
    @unlink($tmpfile);
    http_response_code(500);
    die('Export hatası: ' . h($e->getMessage()));
}
