<?php
/**
 * ADMIN - Sistem Ayarları
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

$ayar_listesi = [
    'kdv_orani'          => ['label' => 'KDV Oranı (%)', 'type' => 'number', 'step' => '0.01', 'help' => 'Varsayılan: 20'],
    'vade_farki_aylik'   => ['label' => 'Aylık Vade Farkı (%)', 'type' => 'number', 'step' => '0.01', 'help' => 'Varsayılan: 6'],
    'nakliye_marji'      => ['label' => 'Nakliye Marjı', 'type' => 'number', 'step' => '0.01', 'help' => 'Varsayılan: 0.75'],
    'firma_adi'          => ['label' => 'Firma Adı', 'type' => 'text', 'help' => 'Yazdırma çıktılarında görünür'],
    'firma_adresi'       => ['label' => 'Firma Adresi', 'type' => 'textarea', 'help' => ''],
    'firma_telefon'      => ['label' => 'Firma Telefonu', 'type' => 'text', 'help' => ''],
    'firma_eposta'       => ['label' => 'Firma E-posta', 'type' => 'email', 'help' => ''],
    'teklif_gecerlilik'  => ['label' => 'Teklif Geçerlilik Süresi (gün)', 'type' => 'number', 'help' => 'Varsayılan: 7'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        try {
            foreach ($ayar_listesi as $anahtar => $meta) {
                if (isset($_POST[$anahtar])) {
                    ayar_set($anahtar, trim((string)$_POST[$anahtar]));
                }
            }
            $msg = 'Ayarlar kaydedildi.';
            // ayar cache'ini temizlemek için sayfa yeniden yüklendiğinde fresh okunur
            // (cache statik fonksiyon içinde, request bazlı; sonraki request'te yeniden okunur)
        } catch (Exception $e) {
            $err = 'Hata: ' . $e->getMessage();
        }
    }
}

ob_start();
?>
<div class="page-head">
    <h1>Sistem Ayarları</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="panel">
    <div class="panel-head">Hesaplama ve Firma Bilgileri</div>
    <div class="panel-body">
        <form method="post">
            <?=csrf_field()?>
            <div class="layout-2col">
                <div>
                    <?php foreach (['kdv_orani','vade_farki_aylik','nakliye_marji','teklif_gecerlilik'] as $key): $m = $ayar_listesi[$key]; ?>
                        <div class="form-group">
                            <label><?=h($m['label'])?></label>
                            <input type="<?=$m['type']?>" name="<?=$key?>" class="form-control"
                                value="<?=h(ayar_get($key, ''))?>"
                                <?=isset($m['step'])?'step="'.$m['step'].'"':''?>>
                            <?php if ($m['help']): ?><small class="text-muted"><?=h($m['help'])?></small><?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
                <div>
                    <?php foreach (['firma_adi','firma_telefon','firma_eposta','firma_adresi'] as $key): $m = $ayar_listesi[$key]; ?>
                        <div class="form-group">
                            <label><?=h($m['label'])?></label>
                            <?php if ($m['type'] === 'textarea'): ?>
                                <textarea name="<?=$key?>" class="form-control" rows="3"><?=h(ayar_get($key, ''))?></textarea>
                            <?php else: ?>
                                <input type="<?=$m['type']?>" name="<?=$key?>" class="form-control"
                                    value="<?=h(ayar_get($key, ''))?>">
                            <?php endif ?>
                            <?php if ($m['help']): ?><small class="text-muted"><?=h($m['help'])?></small><?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Ayarları Kaydet</button>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
