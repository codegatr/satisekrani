<?php
/**
 * ADMIN - Stok Detay / Düzenleme
 *
 * Tek bir stoğun tüm alanlarını düzenler.
 * Erişim: /admin/stok_duzenle.php?id=NN
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/stoklar.php');
    exit;
}

// İşlem: kaydet veya sil
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        $islem = $_POST['islem'] ?? '';
        try {
            if ($islem === 'sil') {
                // Önce satışlarda kullanılıyor mu kontrol
                $st = $db->prepare('SELECT COUNT(*) FROM tk_satis_kalemleri WHERE stok_id=?');
                $st->execute([$id]);
                $kullanim = (int)$st->fetchColumn();
                if ($kullanim > 0) {
                    throw new Exception("Bu stok $kullanim adet satış kaleminde kullanıldığı için silinemez. Pasifleştirmeyi düşünün.");
                }
                $st = $db->prepare('DELETE FROM tk_stoklar WHERE id=?');
                $st->execute([$id]);
                header('Location: /admin/stoklar.php?silindi=1');
                exit;
            }
            elseif ($islem === 'kaydet') {
                $stok_kodu = trim($_POST['stok_kodu'] ?? '');
                $ad        = trim($_POST['ad'] ?? '');
                $ana_grup_id = (int)($_POST['ana_grup_id'] ?? 0);
                $firma_id   = (int)($_POST['firma_id'] ?? 0);
                $isk_grup_id= (int)($_POST['iskonto_grup_id'] ?? 0);
                $birim      = strtoupper(trim($_POST['birim'] ?? 'MT'));
                $doviz      = strtoupper(trim($_POST['doviz'] ?? 'TL'));
                $aktif      = !empty($_POST['aktif']) ? 1 : 0;

                $parse_dec = function ($v) {
                    $v = trim((string)$v);
                    if ($v === '') return null;
                    $v = str_replace([',', ' '], ['.', ''], $v);
                    return is_numeric($v) ? (float)$v : null;
                };

                $mt_kg_fiyati = $parse_dec($_POST['mt_kg_fiyati'] ?? '');
                $boy_fiyati   = $parse_dec($_POST['boy_fiyati'] ?? '');
                $kg_per_mt    = $parse_dec($_POST['kg_per_mt'] ?? '');
                $boy_uzunluk_str = trim($_POST['boy_uzunluk'] ?? '');
                $boy_uzunluk  = $boy_uzunluk_str === '' ? null : (int)$boy_uzunluk_str;

                if ($stok_kodu === '') throw new Exception('Stok kodu zorunlu.');
                if ($ad === '') throw new Exception('Ürün adı zorunlu.');
                if ($ana_grup_id <= 0) throw new Exception('Ana grup zorunlu.');
                if ($firma_id <= 0) throw new Exception('Firma zorunlu.');
                if ($isk_grup_id <= 0) throw new Exception('İskonto grubu zorunlu.');

                $st = $db->prepare(
                    'UPDATE tk_stoklar SET stok_kodu=?, ad=?, ana_grup_id=?, firma_id=?,
                     iskonto_grup_id=?, birim=?, doviz=?, mt_kg_fiyati=?, boy_fiyati=?,
                     kg_per_mt=?, boy_uzunluk=?, aktif=? WHERE id=?'
                );
                $st->execute([
                    $stok_kodu, $ad, $ana_grup_id, $firma_id, $isk_grup_id,
                    $birim, $doviz, $mt_kg_fiyati, $boy_fiyati,
                    $kg_per_mt, $boy_uzunluk, $aktif, $id
                ]);
                $msg = 'Stok kaydedildi.';
            }
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $err = 'Stok kodu çakışması (zaten başka bir kayıtta kullanılıyor).';
            } else {
                $err = 'Veritabanı hatası: ' . $e->getMessage();
            }
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }
}

// Stok yükle
$st = $db->prepare(
    'SELECT s.*, ag.ad ana_grup_ad, f.ad firma_ad, ig.ad isk_grup_ad
     FROM tk_stoklar s
     LEFT JOIN tk_ana_gruplar ag  ON ag.id = s.ana_grup_id
     LEFT JOIN tk_firmalar f      ON f.id  = s.firma_id
     LEFT JOIN tk_iskonto_gruplar ig ON ig.id = s.iskonto_grup_id
     WHERE s.id = ? LIMIT 1'
);
$st->execute([$id]);
$stok = $st->fetch();
if (!$stok) {
    header('Location: /admin/stoklar.php?bulunamadi=1');
    exit;
}

// Dropdown verileri
$ana_gruplar = $db->query('SELECT id, ad FROM tk_ana_gruplar ORDER BY ad')->fetchAll();
$firmalar   = $db->query('SELECT id, kod, ad FROM tk_firmalar ORDER BY ad')->fetchAll();
$gruplar    = $db->query('SELECT id, ad FROM tk_iskonto_gruplar ORDER BY ad')->fetchAll();

// Bu stoğun satış geçmişi (özet)
$st = $db->prepare(
    'SELECT COUNT(*) adet, COALESCE(SUM(miktar),0) toplam_miktar, COALESCE(SUM(tutar),0) toplam_tutar
     FROM tk_satis_kalemleri WHERE stok_id=?'
);
$st->execute([$id]);
$satis_ozet = $st->fetch();

ob_start();
?>
<div class="page-head">
    <h1>Stok Düzenle</h1>
    <a href="/admin/stoklar.php" class="btn">← Stok Listesi</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="layout-2col">
    <!-- Sol: Düzenleme formu -->
    <div class="panel">
        <div class="panel-head">
            <span><?=h($stok['stok_kodu'])?> - <?=h($stok['ad'])?></span>
            <span class="badge">ID: <?=$stok['id']?></span>
        </div>
        <div class="panel-body">
            <form method="post">
                <?=csrf_field()?>
                <input type="hidden" name="islem" value="kaydet">

                <div class="layout-2col" style="grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Stok Kodu *</label>
                        <input type="text" name="stok_kodu" class="form-control text-mono"
                               value="<?=h($stok['stok_kodu'])?>" required maxlength="120">
                    </div>
                    <div class="form-group">
                        <label>Birim *</label>
                        <select name="birim" class="form-control">
                            <?php foreach (['MT','KG','ADET','M2'] as $b): ?>
                                <option value="<?=$b?>" <?=$stok['birim']===$b?'selected':''?>><?=$b?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Ürün Adı *</label>
                    <input type="text" name="ad" class="form-control"
                           value="<?=h($stok['ad'])?>" required maxlength="255">
                </div>

                <div class="layout-2col" style="grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Ana Grup *</label>
                        <select name="ana_grup_id" class="form-control" required>
                            <option value="">- Seç -</option>
                            <?php foreach ($ana_gruplar as $ag): ?>
                                <option value="<?=$ag['id']?>" <?=$stok['ana_grup_id']==$ag['id']?'selected':''?>><?=h($ag['ad'])?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Firma *</label>
                        <select name="firma_id" class="form-control" required>
                            <option value="">- Seç -</option>
                            <?php foreach ($firmalar as $f): ?>
                                <option value="<?=$f['id']?>" <?=$stok['firma_id']==$f['id']?'selected':''?>>
                                    <?=h($f['ad'])?>
                                    <?php if (!empty($f['kod'])): ?>(<?=h($f['kod'])?>)<?php endif ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>İskonto Grubu *</label>
                        <select name="iskonto_grup_id" class="form-control" required>
                            <option value="">- Seç -</option>
                            <?php foreach ($gruplar as $g): ?>
                                <option value="<?=$g['id']?>" <?=$stok['iskonto_grup_id']==$g['id']?'selected':''?>><?=h($g['ad'])?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid var(--c-border);margin:16px 0;">
                <h3 style="font-size:13px;color:var(--c-text-muted);text-transform:uppercase;letter-spacing:0.5px;margin:0 0 12px 0;">Fiyat Bilgileri</h3>

                <div class="layout-2col" style="grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>Döviz</label>
                        <select name="doviz" class="form-control">
                            <?php foreach (['TL','USD','EUR'] as $d): ?>
                                <option value="<?=$d?>" <?=$stok['doviz']===$d?'selected':''?>><?=$d?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Boy Uzunluğu (m)</label>
                        <select name="boy_uzunluk" class="form-control">
                            <option value="">- Seç -</option>
                            <?php foreach ([6,12,13] as $b): ?>
                                <option value="<?=$b?>" <?=$stok['boy_uzunluk']==$b?'selected':''?>><?=$b?>m</option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>

                <div class="layout-2col" style="grid-template-columns:1fr 1fr 1fr;gap:12px;">
                    <div class="form-group">
                        <label>MT/KG Fiyatı</label>
                        <input type="number" step="0.0001" name="mt_kg_fiyati" class="form-control text-right text-mono"
                               value="<?=h((string)$stok['mt_kg_fiyati'])?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Boy Fiyatı</label>
                        <input type="number" step="0.0001" name="boy_fiyati" class="form-control text-right text-mono"
                               value="<?=h((string)$stok['boy_fiyati'])?>" placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>KG/MT Oranı</label>
                        <input type="number" step="0.0001" name="kg_per_mt" class="form-control text-right text-mono"
                               value="<?=h((string)$stok['kg_per_mt'])?>" placeholder="0.00">
                    </div>
                </div>

                <div class="form-group" style="background:var(--c-surface-2);padding:10px;border-radius:6px;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="aktif" value="1" <?=$stok['aktif']?'checked':''?>>
                        <strong>Aktif</strong>
                        <small class="text-muted">(Pasif stoklar satış ekranında görünmez)</small>
                    </label>
                </div>

                <div style="display:flex;gap:8px;justify-content:space-between;margin-top:16px;">
                    <button type="submit" class="btn btn-primary">💾 Kaydet</button>
                    <a href="/admin/stoklar.php" class="btn">İptal</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Sağ: Bilgi paneli -->
    <div>
        <div class="panel" style="margin-bottom:16px;">
            <div class="panel-head">📊 Mevcut Bilgiler</div>
            <div class="panel-body" style="font-size:13px;">
                <div class="opt-row"><span class="text-muted">Stok ID:</span> <strong>#<?=$stok['id']?></strong></div>
                <div class="opt-row"><span class="text-muted">Ana Grup:</span> <strong><?=h($stok['ana_grup_ad'] ?? '-')?></strong></div>
                <div class="opt-row"><span class="text-muted">Firma:</span> <strong><?=h($stok['firma_ad'] ?? '-')?></strong></div>
                <div class="opt-row"><span class="text-muted">İskonto Grubu:</span> <strong><?=h($stok['isk_grup_ad'] ?? '-')?></strong></div>
                <div class="opt-row"><span class="text-muted">Birim / Döviz:</span> <strong><?=h($stok['birim'])?> / <?=h($stok['doviz'])?></strong></div>
                <div class="opt-row"><span class="text-muted">Durum:</span>
                    <strong style="color:<?=$stok['aktif']?'var(--c-success)':'var(--c-danger)'?>;">
                        <?=$stok['aktif']?'● Aktif':'● Pasif'?>
                    </strong>
                </div>
            </div>
        </div>

        <div class="panel" style="margin-bottom:16px;">
            <div class="panel-head">📈 Satış Geçmişi</div>
            <div class="panel-body" style="font-size:13px;">
                <div class="opt-row"><span class="text-muted">Teklif kaleminde kullanım:</span> <strong><?=$satis_ozet['adet']?> kez</strong></div>
                <div class="opt-row"><span class="text-muted">Toplam miktar:</span> <strong class="text-mono"><?=num((float)$satis_ozet['toplam_miktar'], 3)?> <?=h($stok['birim'])?></strong></div>
                <div class="opt-row"><span class="text-muted">Toplam tutar:</span> <strong class="text-mono"><?=tl((float)$satis_ozet['toplam_tutar'])?></strong></div>
            </div>
        </div>

        <div class="panel" style="border-left:4px solid var(--c-danger);">
            <div class="panel-head" style="color:var(--c-danger);">⚠ Tehlikeli Bölge</div>
            <div class="panel-body">
                <p style="font-size:12px;color:var(--c-text-muted);margin:0 0 10px 0;">
                    Stoğu silmek geri alınamaz. Satış kaleminde kullanılan stoklar silinemez (önce pasifleştirin).
                </p>
                <form method="post" onsubmit="return confirm('Bu stok kalıcı olarak silinecek. Emin misiniz?');">
                    <?=csrf_field()?>
                    <input type="hidden" name="islem" value="sil">
                    <button type="submit" class="btn btn-danger btn-block">🗑 Stoğu Sil</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
