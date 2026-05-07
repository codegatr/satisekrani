<?php
/**
 * ADMIN - Fiyat Listesi Yönetimi
 *
 * Excel'in birebir karşılığı: kategori bazlı tablolar, inline TL/USD düzenleme.
 * Kroman baz fiyatları + Nakliye/Marj ayarları da burada.
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        $islem = $_POST['islem'] ?? '';
        try {
            $parse = function ($v) {
                $v = trim((string)$v);
                if ($v === '') return null;
                $v = str_replace([',', ' ', '.'], ['.', '', ''], $v);
                // Yukarıdaki TR ondalık. ama . da kaldırıldı. Daha güvenli yöntem:
                return null;
            };
            // Daha güvenli sayı parse
            $parse_num = function ($v) {
                $v = trim((string)$v);
                if ($v === '') return null;
                // TR formatı: "31.861" = 31861, "31.861,50" = 31861.50
                // Eğer hem . hem , varsa: . binlik, , ondalık
                if (strpos($v, ',') !== false) {
                    $v = str_replace('.', '', $v);   // . binlikleri kaldır
                    $v = str_replace(',', '.', $v);  // , ondalık olsun
                }
                // Sadece . varsa: ondalık olarak bırak (tek nokta)
                if (substr_count($v, '.') > 1) {
                    // Birden fazla nokta var, son hariç hepsi binlik
                    $parts = explode('.', $v);
                    $last = array_pop($parts);
                    $v = implode('', $parts) . '.' . $last;
                }
                return is_numeric($v) ? (float)$v : null;
            };

            if ($islem === 'fiyat_kaydet') {
                $tl_arr = $_POST['tl'] ?? [];
                $usd_arr = $_POST['usd'] ?? [];
                $db->beginTransaction();
                $st = $db->prepare('UPDATE tk_fiyat_listesi SET tl_fiyat=?, usd_fiyat=? WHERE id=?');
                $sayac = 0;
                foreach ($tl_arr as $id => $tl_v) {
                    $tl = $parse_num($tl_v);
                    $usd = $parse_num($usd_arr[$id] ?? '');
                    $st->execute([$tl, $usd, (int)$id]);
                    $sayac++;
                }
                $db->commit();
                $msg = "$sayac kalem fiyatı güncellendi.";
            }
            elseif ($islem === 'satir_ekle') {
                $kk = trim($_POST['kategori_kod'] ?? '');
                $mg = trim($_POST['malzeme_grubu'] ?? '');
                if ($kk === '' || $mg === '') throw new Exception('Kategori ve malzeme grubu zorunlu.');
                // Bu kategorideki son sıra + 1
                $st = $db->prepare('SELECT COALESCE(MAX(satir_sira),0)+1, MIN(kategori_sira), MIN(kategori_ad) FROM tk_fiyat_listesi WHERE kategori_kod=?');
                $st->execute([$kk]);
                [$yeni_sira, $kategori_sira, $kategori_ad] = $st->fetch(PDO::FETCH_NUM);
                $tl = $parse_num($_POST['tl_fiyat'] ?? '');
                $usd = $parse_num($_POST['usd_fiyat'] ?? '');
                $st = $db->prepare(
                    'INSERT INTO tk_fiyat_listesi (kategori_kod, kategori_ad, kategori_sira, satir_sira, malzeme_grubu, tl_fiyat, usd_fiyat, ana_birim) VALUES (?,?,?,?,?,?,?,?)'
                );
                $st->execute([$kk, $kategori_ad ?? $kk, $kategori_sira ?? 99, $yeni_sira, $mg, $tl, $usd, $_POST['ana_birim'] ?? 'TL']);
                $msg = "Yeni satır eklendi: $mg";
            }
            elseif ($islem === 'satir_sil') {
                $id = (int)($_POST['id'] ?? 0);
                $db->prepare('DELETE FROM tk_fiyat_listesi WHERE id=?')->execute([$id]);
                $msg = 'Satır silindi.';
            }
            elseif ($islem === 'kroman_kaydet') {
                $arr = $_POST['baz'] ?? [];
                $db->beginTransaction();
                $st = $db->prepare('UPDATE tk_kroman_baz SET baz_fiyat=? WHERE id=?');
                foreach ($arr as $id => $v) {
                    $bf = $parse_num($v);
                    if ($bf !== null) $st->execute([$bf, (int)$id]);
                }
                $db->commit();
                $msg = 'Kroman baz fiyatları güncellendi.';
            }
            elseif ($islem === 'ayar_kaydet') {
                ayar_set('nakliye_bedeli', (string)$parse_num($_POST['nakliye_bedeli'] ?? '0'));
                ayar_set('sac_hadde_kar_marji', (string)$parse_num($_POST['sac_hadde_kar_marji'] ?? '0'));
                ayar_set('vade_farki_aylik', (string)$parse_num($_POST['vade_farki_aylik'] ?? '0'));
                $msg = 'Marj ve vade ayarları kaydedildi.';
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Hata: ' . $e->getMessage();
        }
    }
}

// Veri yükle
$fiyatlar = [];
foreach ($db->query('SELECT * FROM tk_fiyat_listesi ORDER BY kategori_sira, satir_sira') as $r) {
    $fiyatlar[$r['kategori_kod']][] = $r;
}
$kroman = $db->query('SELECT * FROM tk_kroman_baz ORDER BY sira')->fetchAll();

ob_start();
?>
<div class="page-head">
    <h1>📋 Fiyat Listesi Yönetimi</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif ?>

<div class="alert alert-info" style="font-size:12px;">
    💡 <strong>İpucu:</strong> Fiyatları doğrudan tabloda düzenleyin, ardından kategorinin altındaki "Kaydet" butonuna basın.
    Sayı formatı: <code>31861</code> veya <code>31.861</code> veya <code>31.861,50</code>
</div>

<!-- Marj/Vade ayarları + Kroman baz -->
<div class="layout-2col">
    <div class="panel">
        <div class="panel-head">⚙ Nakliye / Marj / Vade</div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="islem" value="ayar_kaydet">
                <div class="form-group">
                    <label>Nakliye Bedeli (oran)</label>
                    <input type="text" name="nakliye_bedeli" class="form-control text-right text-mono"
                           value="<?= h(ayar_get('nakliye_bedeli', '0.750')) ?>">
                    <small class="text-muted">Excel'de "NAKLİYE BEDELİ" değeri (örn: 0.75)</small>
                </div>
                <div class="form-group">
                    <label>Sac/Hadde Grupları Kar Marjı</label>
                    <input type="text" name="sac_hadde_kar_marji" class="form-control text-right text-mono"
                           value="<?= h(ayar_get('sac_hadde_kar_marji', '1.000')) ?>">
                </div>
                <div class="form-group">
                    <label>Aylık Vade Farkı (%)</label>
                    <input type="text" name="vade_farki_aylik" class="form-control text-right text-mono"
                           value="<?= h(ayar_get('vade_farki_aylik', '6')) ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Kaydet</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">🏭 Kroman Baz Fiyatlar</div>
        <div class="panel-body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="islem" value="kroman_kaydet">
                <table class="data-table" style="font-size:12px;">
                    <thead>
                        <tr><th>Malzeme Grubu</th><th class="num" width="120">Baz Fiyat (₺)</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kroman as $k): ?>
                        <tr>
                            <td><?= h($k['malzeme_grubu']) ?></td>
                            <td>
                                <input type="text" name="baz[<?= $k['id'] ?>]" class="form-control text-right text-mono mini"
                                       value="<?= num((float)$k['baz_fiyat'], 2) ?>" style="width:100px;">
                            </td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-primary btn-block" style="margin-top:10px;">Kaydet</button>
            </form>
        </div>
    </div>
</div>

<!-- Her kategori için ayrı bir form -->
<?php foreach ($fiyatlar as $kk => $satirlar):
    $baslik = $satirlar[0]['kategori_ad'];
?>
<div class="panel" style="margin-top:16px;">
    <div class="panel-head">
        <span><?= h($baslik) ?></span>
        <span class="badge"><?= count($satirlar) ?> satır</span>
    </div>
    <div class="panel-body" style="padding:0;">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="islem" value="fiyat_kaydet">
            <table class="data-table" style="border:none;border-radius:0;font-size:12px;">
                <thead>
                    <tr>
                        <th class="num" width="40">#</th>
                        <th>Malzeme Grubu</th>
                        <th class="num" width="140">TL</th>
                        <th class="num" width="120">USD</th>
                        <th width="60"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($satirlar as $s): ?>
                    <tr>
                        <td class="num text-mono text-muted"><?= $s['satir_sira'] ?></td>
                        <td><?= h($s['malzeme_grubu']) ?></td>
                        <td class="num">
                            <input type="text" name="tl[<?= $s['id'] ?>]" class="form-control text-right text-mono"
                                   value="<?= $s['tl_fiyat'] !== null ? number_format((float)$s['tl_fiyat'], 0, ',', '.') : '' ?>"
                                   style="width:120px;">
                        </td>
                        <td class="num">
                            <input type="text" name="usd[<?= $s['id'] ?>]" class="form-control text-right text-mono"
                                   value="<?= $s['usd_fiyat'] !== null ? number_format((float)$s['usd_fiyat'], 0, ',', '.') : '' ?>"
                                   style="width:100px;">
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-x sil-btn" data-id="<?= $s['id'] ?>" data-mg="<?= h($s['malzeme_grubu']) ?>" title="Sil">×</button>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            <div style="padding:10px;display:flex;gap:8px;justify-content:space-between;background:var(--c-surface-2);">
                <button type="button" class="btn ekle-btn" data-kk="<?= h($kk) ?>" data-baslik="<?= h($baslik) ?>">+ Yeni Satır</button>
                <button type="submit" class="btn btn-primary">💾 Bu Kategoriyi Kaydet</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach ?>

<!-- Yeni satır modal (basit prompt-bazlı) -->
<form method="post" id="ekleForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="satir_ekle">
    <input type="hidden" name="kategori_kod" id="ekle_kk">
    <input type="hidden" name="malzeme_grubu" id="ekle_mg">
    <input type="hidden" name="tl_fiyat" id="ekle_tl">
    <input type="hidden" name="usd_fiyat" id="ekle_usd">
</form>

<form method="post" id="silForm" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="satir_sil">
    <input type="hidden" name="id" id="sil_id">
</form>

<script>
document.querySelectorAll('.ekle-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const baslik = btn.dataset.baslik;
        const mg = prompt(`"${baslik}" kategorisine yeni satır:\n\nMalzeme grubu adı:`);
        if (!mg) return;
        const tl = prompt('TL fiyat (boş bırakılabilir):') || '';
        const usd = prompt('USD fiyat (boş bırakılabilir):') || '';
        document.getElementById('ekle_kk').value = btn.dataset.kk;
        document.getElementById('ekle_mg').value = mg;
        document.getElementById('ekle_tl').value = tl;
        document.getElementById('ekle_usd').value = usd;
        document.getElementById('ekleForm').submit();
    });
});

document.querySelectorAll('.sil-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm(`"${btn.dataset.mg}" satırı silinecek. Emin misiniz?`)) return;
        document.getElementById('sil_id').value = btn.dataset.id;
        document.getElementById('silForm').submit();
    });
});
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
