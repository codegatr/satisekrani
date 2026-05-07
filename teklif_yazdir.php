<?php
ob_start();
require __DIR__ . '/inc/bootstrap.php';
require_login();
$user = current_user();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('Geçersiz teklif ID');

$st = db()->prepare(
    'SELECT s.*, u.ad_soyad AS personel_ad
     FROM tk_satislar s
     LEFT JOIN tk_users u ON u.id = s.kullanici_id
     WHERE s.id = ? LIMIT 1'
);
$st->execute([$id]);
$teklif = $st->fetch();
if (!$teklif) die('Teklif bulunamadı');

if ($user['rol'] !== 'admin' && (int)$teklif['kullanici_id'] !== (int)$user['id']) {
    http_response_code(403);
    die('Bu teklife erişim yetkiniz yok');
}

$st = db()->prepare(
    'SELECT k.*, f.ad AS firma_ad
     FROM tk_satis_kalemleri k
     LEFT JOIN tk_firmalar f ON f.id = k.firma_id
     WHERE k.satis_id = ? ORDER BY k.id'
);
$st->execute([$id]);
$kalemler = $st->fetchAll();

$kdv_orani = (float)(ayar_get('kdv_orani', '0.20'));
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Teklif <?= h($teklif['teklif_no']) ?></title>
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family: Arial, sans-serif; padding: 24px; color: #222; font-size: 13px; }
.print-header { display:flex; justify-content:space-between; align-items:flex-start;
                border-bottom: 3px solid #d50000; padding-bottom: 16px; margin-bottom: 24px; }
.brand h1 { font-size: 24px; color: #d50000; margin-bottom: 4px; }
.brand small { color: #666; }
.teklif-info { text-align: right; }
.teklif-info h2 { font-size: 18px; margin-bottom: 8px; }
.info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; margin-bottom: 24px; }
.info-box { border: 1px solid #ccc; padding: 12px 16px; border-radius: 4px; }
.info-box h4 { font-size: 11px; color: #666; text-transform: uppercase; margin-bottom: 6px; }
table.kalem { width:100%; border-collapse: collapse; margin-bottom: 16px; }
table.kalem th { background:#222; color:#fff; padding: 8px; text-align: left; font-size: 12px; }
table.kalem td { padding: 8px; border-bottom: 1px solid #eee; }
table.kalem tr:nth-child(even) td { background: #fafafa; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.totals { width: 350px; margin-left: auto; margin-top: 12px; }
.totals tr td { padding: 6px 12px; }
.totals tr.grand td { background: #d50000; color: #fff; font-size: 15px; font-weight: bold; padding: 10px 12px; }
.notes { margin-top: 24px; padding: 12px; background: #fffbe5; border: 1px solid #ffd700; }
.footer { margin-top: 36px; padding-top: 16px; border-top: 1px solid #ccc;
          display:flex; justify-content: space-between; font-size: 11px; color: #666; }
.btn-print { background: #d50000; color: #fff; padding: 8px 16px; border: 0;
             border-radius: 4px; cursor: pointer; font-size: 13px; }
@media print {
    body { padding: 0; }
    .no-print { display: none !important; }
}
</style>
</head>
<body>

<div class="no-print" style="text-align:right; margin-bottom:16px;">
    <button class="btn-print" onclick="window.print()">🖨 Yazdır</button>
    <button class="btn-print" onclick="window.close()" style="background:#666;">Kapat</button>
</div>

<div class="print-header">
    <div class="brand">
        <h1><?= h(ayar_get('sirket_adi', 'TEKCAN METAL')) ?></h1>
        <small>
            <?= h(ayar_get('sirket_adres', '')) ?> &middot; 
            <?= h(ayar_get('sirket_telefon', '')) ?>
        </small>
    </div>
    <div class="teklif-info">
        <h2>FİYAT TEKLİFİ</h2>
        <div><b>No:</b> <?= h($teklif['teklif_no']) ?></div>
        <div><b>Tarih:</b> <?= date('d.m.Y H:i', strtotime($teklif['tarih'])) ?></div>
    </div>
</div>

<div class="info-grid">
    <div class="info-box">
        <h4>Müşteri Bilgileri</h4>
        <div><b><?= h($teklif['musteri_adi'] ?: '-') ?></b></div>
        <?php if ($teklif['musteri_telefon']): ?>
        <div><?= h($teklif['musteri_telefon']) ?></div>
        <?php endif ?>
    </div>
    <div class="info-box">
        <h4>Teklif Şartları</h4>
        <div><b>Vade:</b> <?= (int)$teklif['vade_ay'] ?> Ay</div>
        <div><b>USD/TRY:</b> <?= num((float)$teklif['usd_kuru'], 4) ?></div>
        <div><b>EUR/TRY:</b> <?= num((float)$teklif['eur_kuru'], 4) ?></div>
        <div><b>Personel:</b> <?= h($teklif['personel_ad']) ?></div>
    </div>
</div>

<table class="kalem">
    <thead>
        <tr>
            <th style="width:32px;">#</th>
            <th>Ürün</th>
            <th>Firma</th>
            <th class="text-center">Miktar</th>
            <th class="text-right">Birim Fiyat</th>
            <th class="text-right">Tutar (₺)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($kalemler as $i => $k): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td>
                <b><?= h($k['stok_kodu']) ?></b><br>
                <small><?= h($k['ad']) ?></small>
            </td>
            <td><?= h($k['firma_ad']) ?></td>
            <td class="text-center">
                <?= num((float)$k['miktar'], 3) ?> <?= h($k['birim']) ?>
            </td>
            <td class="text-right"><?= num((float)$k['birim_fiyat'], 4) ?> ₺</td>
            <td class="text-right"><b><?= num((float)$k['tutar']) ?></b></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>

<table class="totals">
    <tr>
        <td>Ara Toplam:</td>
        <td class="text-right"><?= tl((float)$teklif['ara_toplam']) ?></td>
    </tr>
    <?php if ((float)$teklif['vade_farki_tutar'] > 0): ?>
    <tr>
        <td>Vade Farkı (<?= (int)$teklif['vade_ay'] ?> Ay):</td>
        <td class="text-right"><?= tl((float)$teklif['vade_farki_tutar']) ?></td>
    </tr>
    <?php endif ?>
    <?php if ((float)$teklif['kdv_tutar'] > 0): ?>
    <tr>
        <td>KDV (%<?= num($kdv_orani*100, 0) ?>):</td>
        <td class="text-right"><?= tl((float)$teklif['kdv_tutar']) ?></td>
    </tr>
    <?php endif ?>
    <tr class="grand">
        <td>GENEL TOPLAM:</td>
        <td class="text-right"><?= tl((float)$teklif['genel_toplam']) ?></td>
    </tr>
</table>

<?php if ($teklif['notlar']): ?>
<div class="notes">
    <b>Notlar:</b><br>
    <?= nl2br(h($teklif['notlar'])) ?>
</div>
<?php endif ?>

<div class="footer">
    <div>Bu teklif <?= h($teklif['personel_ad']) ?> tarafından hazırlanmıştır.</div>
    <div>Yazdırma: <?= date('d.m.Y H:i') ?></div>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
