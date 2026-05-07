<?php
/**
 * MALZEME ARAMA - Stoklar tablosunda fiyat arama
 *
 * Satış temsilcisi spesifik bir malzeme ararsa burada bulur.
 * Sepete ekleme YOK, sadece fiyat görüntüleme.
 */
require __DIR__ . '/inc/bootstrap.php';
require_login();

$db = db();

$q = trim($_GET['q'] ?? '');
$firma_id = (int)($_GET['firma_id'] ?? 0);
$grup_id = (int)($_GET['grup_id'] ?? 0);

$sonuclar = [];
if ($q !== '' || $firma_id || $grup_id) {
    $where = ['s.aktif=1'];
    $params = [];
    if ($q !== '') {
        $where[] = '(s.stok_kodu LIKE ? OR s.ad LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }
    if ($firma_id) { $where[] = 's.firma_id=?'; $params[] = $firma_id; }
    if ($grup_id)  { $where[] = 's.iskonto_grup_id=?'; $params[] = $grup_id; }
    $sql = "SELECT s.*, f.ad firma_ad, f.kod firma_kod, g.ad grup_ad, ag.ad ana_grup_ad,
                   fi.satis_iskonto, fi.alis_iskonto
            FROM tk_stoklar s
            LEFT JOIN tk_firmalar f ON f.id = s.firma_id
            LEFT JOIN tk_iskonto_gruplar g ON g.id = s.iskonto_grup_id
            LEFT JOIN tk_ana_gruplar ag ON ag.id = s.ana_grup_id
            LEFT JOIN tk_firma_iskonto fi ON fi.firma_id = s.firma_id AND fi.iskonto_grup_id = s.iskonto_grup_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY s.stok_kodu
            LIMIT 200";
    $st = $db->prepare($sql);
    $st->execute($params);
    $sonuclar = $st->fetchAll();
}

$firmalar = $db->query('SELECT id, kod, ad FROM tk_firmalar ORDER BY ad')->fetchAll();
$gruplar  = $db->query('SELECT id, ad FROM tk_iskonto_gruplar ORDER BY ad')->fetchAll();

$kur_usd = kur_get('USD');
$kur_eur = kur_get('EUR');
$kur_usd_v = (float)$kur_usd['satis'];
$kur_eur_v = (float)$kur_eur['satis'];

require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
    <h1>🔍 Malzeme Arama</h1>
    <a href="/index.php" class="btn">← Fiyat Listesi</a>
</div>

<form method="get" class="ara-form" style="margin-bottom:16px;">
    <div class="form-group" style="flex:2;">
        <label>Stok Kodu / Ürün Adı</label>
        <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
               placeholder="Örn: NPI 100, HRP, IPE 240..." autofocus>
    </div>
    <div class="form-group">
        <label>Firma</label>
        <select name="firma_id" class="form-control">
            <option value="">Tümü</option>
            <?php foreach ($firmalar as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $firma_id==$f['id']?'selected':'' ?>><?= h($f['ad']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label>İskonto Grubu</label>
        <select name="grup_id" class="form-control">
            <option value="">Tümü</option>
            <?php foreach ($gruplar as $g): ?>
                <option value="<?= $g['id'] ?>" <?= $grup_id==$g['id']?'selected':'' ?>><?= h($g['ad']) ?></option>
            <?php endforeach ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Ara</button>
</form>

<?php if ($q === '' && !$firma_id && !$grup_id): ?>
    <div class="alert alert-info">
        Bir kelime girin veya filtreleyin. Örn: <strong>NPI</strong>, <strong>HRP</strong>, <strong>IPE 240</strong>, <strong>200x100</strong>
    </div>
<?php elseif (!$sonuclar): ?>
    <div class="empty-state">Sonuç bulunamadı.</div>
<?php else: ?>
    <div class="results-info">
        <strong><?= count($sonuclar) ?></strong> sonuç bulundu
        <?= count($sonuclar) >= 200 ? ' (ilk 200 gösterildi - daha spesifik arayın)' : '' ?>
    </div>

    <table class="data-table" style="font-size:12px;">
        <thead>
            <tr>
                <th>Stok Kodu</th>
                <th>Ürün Adı</th>
                <th>Firma</th>
                <th>İskonto Grubu</th>
                <th class="num">Birim Fiyat</th>
                <th class="num">İsk. (Satış)</th>
                <th class="num">Net Fiyat (TL)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sonuclar as $s):
                // Hesapla net fiyatı
                $birim_f = $s['mt_kg_fiyati'] !== null ? (float)$s['mt_kg_fiyati'] : ($s['boy_fiyati'] !== null ? (float)$s['boy_fiyati'] : 0);
                $iskonto = $s['satis_iskonto'] !== null ? (float)$s['satis_iskonto'] : 0;
                $tl_fiyat = $birim_f * (1 - $iskonto);
                if ($s['doviz'] === 'USD') $tl_fiyat *= $kur_usd_v;
                elseif ($s['doviz'] === 'EUR') $tl_fiyat *= $kur_eur_v;
            ?>
            <tr>
                <td class="text-mono"><?= h($s['stok_kodu']) ?></td>
                <td>
                    <strong><?= h($s['ad']) ?></strong>
                    <?php if (!empty($s['boy_uzunluk'])): ?>
                        <br><small class="text-muted"><?= (int)$s['boy_uzunluk'] ?>m boy</small>
                    <?php endif ?>
                </td>
                <td>
                    <span class="badge-firma"><?= h($s['firma_kod'] ?? $s['firma_ad'] ?? '-') ?></span>
                </td>
                <td><small><?= h($s['grup_ad'] ?? '-') ?></small></td>
                <td class="num">
                    <span class="text-mono"><?= num($birim_f, 4) ?></span>
                    <small class="text-muted"><?= h($s['doviz']) ?>/<?= h($s['birim']) ?></small>
                </td>
                <td class="num">
                    <?php if ($iskonto > 0): ?>
                        <span class="badge-isk">%<?= num($iskonto * 100, 1) ?></span>
                    <?php else: ?>
                        <span class="text-muted">-</span>
                    <?php endif ?>
                </td>
                <td class="num">
                    <strong style="color:var(--c-primary);font-size:13px;"><?= tl($tl_fiyat) ?>/<?= h($s['birim']) ?></strong>
                </td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
<?php endif ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
