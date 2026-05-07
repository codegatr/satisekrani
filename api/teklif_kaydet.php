<?php
require __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'hata' => 'Yöntem desteklenmiyor'], 405);
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) json_out(['ok' => false, 'hata' => 'Geçersiz veri'], 400);

if (!csrf_check($data['_csrf'] ?? '')) {
    json_out(['ok' => false, 'hata' => 'CSRF doğrulama hatası'], 403);
}

$kalemler = $data['kalemler'] ?? [];
if (!is_array($kalemler) || empty($kalemler)) {
    json_out(['ok' => false, 'hata' => 'Sepet boş'], 400);
}

$musteri_ad  = trim($data['musteri_adi'] ?? '');
$musteri_tel = trim($data['musteri_tel'] ?? '');
$vade_ay     = max(0, min(24, (int)($data['vade_ay'] ?? 0)));
$kdv_dahil   = !empty($data['kdv_dahil']) ? 1 : 0;

$kdv_orani  = (float)(ayar_get('kdv_orani', '0.20'));
$vade_farki = (float)(ayar_get('aylik_vade_farki', '0.06'));

$kur_usd = kur_get('USD');
$kur_eur = kur_get('EUR');
$kullanici = current_user();

// Hesaplama
$ara_toplam = 0;
$temiz_kalemler = [];
foreach ($kalemler as $k) {
    $stok_id = (int)($k['stok_id'] ?? 0);
    $miktar  = (float)($k['miktar']  ?? 0);
    $bf      = (float)($k['birim_fiyat'] ?? 0);
    if ($stok_id <= 0 || $miktar <= 0 || $bf <= 0) continue;

    // Stok'u DB'den de kontrol et (güvenlik)
    $st = db()->prepare('SELECT * FROM tk_stoklar WHERE id=? AND aktif=1');
    $st->execute([$stok_id]);
    $s = $st->fetch();
    if (!$s) continue;

    $tutar = $miktar * $bf;
    $ara_toplam += $tutar;
    $temiz_kalemler[] = [
        'stok_id'    => $stok_id,
        'firma_id'   => (int)$s['firma_id'],
        'stok_kodu'  => $s['stok_kodu'],
        'ad'         => $s['ad'],
        'miktar'     => $miktar,
        'birim'      => $s['birim'],
        'birim_fiyat'=> $bf,
        'iskonto'    => (float)($k['iskonto'] ?? 0),
        'tutar'      => round($tutar, 2)
    ];
}

if (empty($temiz_kalemler)) {
    json_out(['ok' => false, 'hata' => 'Geçerli kalem yok'], 400);
}

$vade_farki_tutar = $ara_toplam * $vade_farki * $vade_ay;
$toplam_vadeli    = $ara_toplam + $vade_farki_tutar;
$kdv_tutar        = $kdv_dahil ? $toplam_vadeli * $kdv_orani : 0;
$genel_toplam     = $toplam_vadeli + $kdv_tutar;

// Teklif numarası: TKL-YYYYMMDD-NNNN
$bugun_prefix = 'TKL-' . date('Ymd') . '-';
$st = db()->prepare(
    "SELECT teklif_no FROM tk_satislar 
     WHERE teklif_no LIKE ? ORDER BY id DESC LIMIT 1"
);
$st->execute([$bugun_prefix . '%']);
$son = $st->fetchColumn();
$next = $son ? ((int)substr($son, -4)) + 1 : 1;
$teklif_no = $bugun_prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

try {
    db()->beginTransaction();

    $st = db()->prepare(
        "INSERT INTO tk_satislar 
         (kullanici_id, teklif_no, musteri_adi, musteri_telefon, vade_ay, kdv_dahil,
          ara_toplam, vade_farki_tutar, kdv_tutar, genel_toplam, usd_kuru, eur_kuru) 
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    $st->execute([
        (int)$kullanici['id'], $teklif_no,
        $musteri_ad ?: null, $musteri_tel ?: null,
        $vade_ay, $kdv_dahil,
        round($ara_toplam, 2), round($vade_farki_tutar, 2),
        round($kdv_tutar, 2), round($genel_toplam, 2),
        $kur_usd['satis'], $kur_eur['satis']
    ]);
    $satis_id = (int)db()->lastInsertId();

    $st = db()->prepare(
        "INSERT INTO tk_satis_kalemleri 
         (satis_id, stok_id, firma_id, stok_kodu, ad, miktar, birim, 
          birim_fiyat, iskonto, tutar) 
         VALUES (?,?,?,?,?,?,?,?,?,?)"
    );
    foreach ($temiz_kalemler as $k) {
        $st->execute([
            $satis_id, $k['stok_id'], $k['firma_id'],
            $k['stok_kodu'], $k['ad'], $k['miktar'], $k['birim'],
            $k['birim_fiyat'], $k['iskonto'], $k['tutar']
        ]);
    }

    db()->commit();
} catch (PDOException $e) {
    db()->rollBack();
    error_log('Teklif kaydet hatası: ' . $e->getMessage());
    json_out(['ok' => false, 'hata' => 'Kayıt hatası'], 500);
}

json_out([
    'ok'        => true,
    'id'        => $satis_id,
    'teklif_no' => $teklif_no,
    'genel_toplam' => round($genel_toplam, 2)
]);
