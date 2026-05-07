<?php
require __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$q  = trim($_GET['q'] ?? '');
$ag = (int)($_GET['ana_grup']      ?? 0);
$ig = (int)($_GET['iskonto_grup']  ?? 0);
$fr = (int)($_GET['firma']         ?? 0);

$where  = ['s.aktif=1'];
$params = [];

if ($q !== '') {
    // Stok kodu veya adda LIKE araması
    $where[] = '(s.stok_kodu LIKE :q1 OR s.ad LIKE :q2)';
    $params[':q1'] = '%' . $q . '%';
    $params[':q2'] = '%' . $q . '%';
}
if ($ag > 0) {
    $where[] = 's.ana_grup_id = :ag';
    $params[':ag'] = $ag;
}
if ($ig > 0) {
    $where[] = 's.iskonto_grup_id = :ig';
    $params[':ig'] = $ig;
}
if ($fr > 0) {
    $where[] = 's.firma_id = :fr';
    $params[':fr'] = $fr;
}

$sql = "SELECT 
            s.id, s.stok_kodu, s.ad, s.firma_id, s.iskonto_grup_id,
            s.mt_kg_fiyati, s.kg_per_mt, s.boy_fiyati, s.boy_uzunluk,
            s.birim, s.doviz, s.ana_grup_id,
            f.ad AS firma_ad,
            ig.ad AS iskonto_grup_ad,
            COALESCE(fi.satis_iskonto, 0) AS iskonto
        FROM tk_stoklar s
        LEFT JOIN tk_firmalar f ON f.id = s.firma_id
        LEFT JOIN tk_iskonto_gruplar ig ON ig.id = s.iskonto_grup_id
        LEFT JOIN tk_firma_iskonto fi 
                  ON fi.firma_id = s.firma_id 
                  AND fi.iskonto_grup_id = s.iskonto_grup_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.firma_id, s.iskonto_grup_id, s.stok_kodu
        LIMIT 100";

try {
    $st = db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {
    json_out(['ok' => false, 'hata' => 'Sorgu hatası'], 500);
}

// Toplam kayıt için ayrı count
$countSql = "SELECT COUNT(*) FROM tk_stoklar s WHERE " . implode(' AND ', $where);
$st = db()->prepare($countSql);
$st->execute($params);
$toplam = (int)$st->fetchColumn();

// İskontolu fiyat hesapla
foreach ($rows as &$r) {
    $brut = (float)$r['mt_kg_fiyati'];
    $isk  = (float)$r['iskonto'];
    $r['iskontolu_fiyat'] = round($brut * (1 - $isk), 4);
    $r['iskonto'] = $isk;
    $r['boy_fiyati'] = (float)$r['boy_fiyati'];
}

json_out([
    'ok'      => true,
    'toplam'  => $toplam,
    'stoklar' => $rows
]);
