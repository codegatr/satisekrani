<?php
/**
 * KUR GÜNCELLEME - CRON GÖREVİ
 * 
 * DirectAdmin Cron örneği (TCMB hafta içi 15:30 sonrası kur açıklar):
 *   30 16 * * 1-5  /usr/local/bin/php /home/USER/domains/SITE/public_html/cron/kur_guncelle.php
 * 
 * Veya web üzerinden çağırma:
 *   wget -q -O- "https://SITE/cron/kur_guncelle.php?key=GIZLI_ANAHTAR"
 */

// Web çağrısı için basit anahtar koruması
$cron_key = 'CRON_GIZLI_ANAHTAR_DEGISTIRIN_2026';

if (PHP_SAPI !== 'cli') {
    if (($_GET['key'] ?? '') !== $cron_key) {
        http_response_code(403);
        die('Yetkisiz');
    }
}

require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/kur_modul.php';

$baslangic = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Kur güncelleme başlatıldı...\n";

$sonuc = kur_guncelle();
$sure  = round(microtime(true) - $baslangic, 2);

if ($sonuc['basarili']) {
    echo "[OK] " . $sonuc['mesaj'] . " (Kaynak: {$sonuc['kaynak']}, {$sure}s)\n";
    foreach ($sonuc['kurlar'] as $k) {
        echo "  - {$k['doviz']}: alış " . number_format($k['alis'], 4) .
             " / satış " . number_format($k['satis'], 4) . "\n";
    }
    exit(0);
} else {
    echo "[HATA] " . $sonuc['mesaj'] . " ({$sure}s)\n";
    exit(1);
}
