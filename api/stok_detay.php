<?php
require __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_out(['ok' => false, 'hata' => 'Geçersiz ID'], 400);

$sql = "SELECT 
            s.*,
            f.ad AS firma_ad,
            ig.ad AS iskonto_grup_ad,
            COALESCE(fi.satis_iskonto, 0) AS iskonto
        FROM tk_stoklar s
        LEFT JOIN tk_firmalar f ON f.id = s.firma_id
        LEFT JOIN tk_iskonto_gruplar ig ON ig.id = s.iskonto_grup_id
        LEFT JOIN tk_firma_iskonto fi 
                  ON fi.firma_id = s.firma_id 
                  AND fi.iskonto_grup_id = s.iskonto_grup_id
        WHERE s.id = ? LIMIT 1";

$st = db()->prepare($sql);
$st->execute([$id]);
$r = $st->fetch();

if (!$r) json_out(['ok' => false, 'hata' => 'Stok bulunamadı'], 404);

$brut = (float)$r['mt_kg_fiyati'];
$isk  = (float)$r['iskonto'];
$r['iskontolu_fiyat'] = round($brut * (1 - $isk), 4);

json_out(['ok' => true, 'stok' => $r]);
