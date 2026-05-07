<?php
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/kur_modul.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'hata' => 'Yöntem desteklenmiyor'], 405);
}

if (!csrf_check($_POST['_csrf'] ?? '')) {
    json_out(['ok' => false, 'hata' => 'CSRF doğrulama hatası'], 403);
}

// Çok sık güncellemeyi engelle (5 dk)
$st = db()->query(
    'SELECT MAX(tarih) FROM tk_kur WHERE tarih > (NOW() - INTERVAL 5 MINUTE)'
);
$son = $st->fetchColumn();
if ($son) {
    json_out([
        'ok'    => true,
        'mesaj' => 'Son güncelleme 5 dk içinde yapıldı, kurlar güncel.',
        'cache' => true
    ]);
}

$sonuc = kur_guncelle();

if ($sonuc['basarili']) {
    json_out([
        'ok'     => true,
        'mesaj'  => $sonuc['mesaj'],
        'kaynak' => $sonuc['kaynak'],
        'kurlar' => $sonuc['kurlar']
    ]);
} else {
    json_out(['ok' => false, 'hata' => $sonuc['mesaj']], 500);
}
