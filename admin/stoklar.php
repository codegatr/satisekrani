<?php
/**
 * ADMIN - Stok Listesi
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

// Tek stok güncelleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'guncelle') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $mt_kg = (float)str_replace(',', '.', (string)($_POST['mt_kg_fiyati'] ?? '0'));
            $boy_f = (float)str_replace(',', '.', (string)($_POST['boy_fiyati'] ?? '0'));
            $aktif = !empty($_POST['aktif']) ? 1 : 0;
            $st = $db->prepare('UPDATE tk_stoklar SET mt_kg_fiyati=?, boy_fiyati=?, aktif=? WHERE id=?');
            $st->execute([$mt_kg ?: null, $boy_f ?: null, $aktif, $id]);
            $msg = 'Stok güncellendi.';
        } catch (Exception $e) {
            $err = 'Hata: ' . $e->getMessage();
        }
    }
}

// Filtre + arama
$q = trim($_GET['q'] ?? '');
$firma_id = (int)($_GET['firma_id'] ?? 0);
$grup_id = (int)($_GET['grup_id'] ?? 0);
$durum = $_GET['durum'] ?? 'aktif';
$sayfa = max(1, (int)($_GET['sayfa'] ?? 1));
$per = 50;

$where = []; $params = [];
if ($q !== '') {
    $where[] = '(s.stok_kodu LIKE ? OR s.ad LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}
if ($firma_id) { $where[] = 's.firma_id=?'; $params[] = $firma_id; }
if ($grup_id) { $where[] = 's.iskonto_grup_id=?'; $params[] = $grup_id; }
if ($durum === 'aktif') $where[] = 's.aktif=1';
elseif ($durum === 'pasif') $where[] = 's.aktif=0';

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st_count = $db->prepare("SELECT COUNT(*) FROM tk_stoklar s $where_sql");
$st_count->execute($params);
$toplam = (int)$st_count->fetchColumn();
$toplam_sayfa = max(1, (int)ceil($toplam / $per));

$offset = ($sayfa - 1) * $per;
$sql = "SELECT s.*, f.ad firma_ad, g.ad grup_ad
        FROM tk_stoklar s
        LEFT JOIN tk_firmalar f ON f.id = s.firma_id
        LEFT JOIN tk_iskonto_gruplar g ON g.id = s.iskonto_grup_id
        $where_sql
        ORDER BY s.id DESC
        LIMIT $per OFFSET $offset";
$stx = $db->prepare($sql);
$stx->execute($params);
$stoklar = $stx->fetchAll();

$firmalar = $db->query('SELECT id, ad FROM tk_firmalar ORDER BY ad')->fetchAll();
$gruplar  = $db->query('SELECT id, ad FROM tk_iskonto_gruplar ORDER BY ad')->fetchAll();

ob_start();
?>
<div class="page-head">
    <h1>Stok Listesi</h1>
    <div style="display:flex;gap:8px;">
        <?php
        // Mevcut filtreleri export linkine taşı
        $exp_qs = http_build_query(array_filter([
            'q' => $q ?: null,
            'firma_id' => $firma_id ?: null,
            'grup_id' => $grup_id ?: null,
            'durum' => $durum ?: null,
        ]));
        ?>
        <a href="/admin/stok_export.php?format=xlsx<?= $exp_qs ? '&' . $exp_qs : '' ?>" class="btn btn-success" title="Filtrelenmiş listeyi Excel olarak indir">📊 Excel İndir</a>
        <a href="/admin/stok_import.php" class="btn btn-primary">📤 Excel İçe Aktar</a>
        <a href="/admin/" class="btn">← Yönetim</a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<form method="get" class="ara-form">
    <div class="form-group" style="flex:2;">
        <label>Arama (kod / ürün adı)</label>
        <input type="text" name="q" class="form-control" value="<?=h($q)?>" placeholder="Stok kodu veya ürün adı">
    </div>
    <div class="form-group">
        <label>Firma</label>
        <select name="firma_id" class="form-control">
            <option value="">Tümü</option>
            <?php foreach ($firmalar as $f): ?>
                <option value="<?=$f['id']?>" <?=$firma_id==$f['id']?'selected':''?>><?=h($f['ad'])?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label>İskonto Grubu</label>
        <select name="grup_id" class="form-control">
            <option value="">Tümü</option>
            <?php foreach ($gruplar as $g): ?>
                <option value="<?=$g['id']?>" <?=$grup_id==$g['id']?'selected':''?>><?=h($g['ad'])?></option>
            <?php endforeach ?>
        </select>
    </div>
    <div class="form-group">
        <label>Durum</label>
        <select name="durum" class="form-control">
            <option value="">Tümü</option>
            <option value="aktif" <?=$durum==='aktif'?'selected':''?>>Aktif</option>
            <option value="pasif" <?=$durum==='pasif'?'selected':''?>>Pasif</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Filtrele</button>
</form>

<div class="results-info">
    Toplam <strong><?=number_format($toplam, 0, ',', '.')?></strong> sonuç bulundu.
    Sayfa <?=$sayfa?>/<?=$toplam_sayfa?>
</div>

<?php if (!$stoklar): ?>
    <div class="empty-state">Sonuç bulunamadı.</div>
<?php else: ?>
<form method="post">
    <?=csrf_field()?>
    <input type="hidden" name="islem" value="guncelle">
    <table class="data-table">
        <thead>
            <tr>
                <th>Stok Kodu</th>
                <th>Ürün Adı</th>
                <th>Firma</th>
                <th>İsk. Grup</th>
                <th>Birim</th>
                <th class="num">MT/KG Fiyatı</th>
                <th class="num">Boy Fiyatı</th>
                <th>Aktif</th>
                <th>İşlem</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($stoklar as $s): ?>
            <tr>
                <td class="text-mono"><?=h($s['stok_kodu'])?></td>
                <td><?=h($s['ad'])?>
                    <?php if (!empty($s['boy_uzunluk'])): ?><br><small class="text-muted"><?=h((string)$s['boy_uzunluk'])?>m</small><?php endif ?>
                </td>
                <td><span class="badge-firma"><?=h($s['firma_ad'] ?? '-')?></span></td>
                <td><small><?=h($s['grup_ad'] ?? '-')?></small></td>
                <td class="text-mono"><?=h($s['birim'])?></td>
                <td class="num">
                    <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
                        <?=csrf_field()?>
                        <input type="hidden" name="islem" value="guncelle">
                        <input type="hidden" name="id" value="<?=$s['id']?>">
                        <input type="hidden" name="aktif" value="<?=$s['aktif']?>">
                        <input type="hidden" name="boy_fiyati" value="<?=h((string)($s['boy_fiyati'] ?? ''))?>">
                        <input type="number" step="0.0001" name="mt_kg_fiyati" value="<?=h((string)($s['mt_kg_fiyati'] ?? ''))?>" class="form-control mini" style="width:100px;" placeholder="-">
                        <button type="submit" class="btn" style="padding:4px 8px;font-size:11px;">↻</button>
                    </form>
                </td>
                <td class="num">
                    <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
                        <?=csrf_field()?>
                        <input type="hidden" name="islem" value="guncelle">
                        <input type="hidden" name="id" value="<?=$s['id']?>">
                        <input type="hidden" name="aktif" value="<?=$s['aktif']?>">
                        <input type="hidden" name="mt_kg_fiyati" value="<?=h((string)($s['mt_kg_fiyati'] ?? ''))?>">
                        <input type="number" step="0.0001" name="boy_fiyati" value="<?=h((string)($s['boy_fiyati'] ?? ''))?>" class="form-control mini" style="width:100px;" placeholder="-">
                        <button type="submit" class="btn" style="padding:4px 8px;font-size:11px;">↻</button>
                    </form>
                </td>
                <td><?=$s['aktif'] ? '<span style="color:var(--c-success)">●</span>' : '<span style="color:var(--c-danger)">●</span>'?></td>
                <td>
                    <a href="/admin/stok_duzenle.php?id=<?=$s['id']?>" class="btn" style="padding:4px 8px;font-size:11px;">Düzenle</a>
                </td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</form>
<?php endif ?>

<?php if ($toplam_sayfa > 1): ?>
<div class="pagination">
    <?php
    $qs = $_GET; unset($qs['sayfa']);
    $base = '?' . http_build_query($qs);
    $sep = $base === '?' ? '' : '&';
    ?>
    <a href="<?=$base.$sep?>sayfa=1" class="page-link <?=$sayfa==1?'disabled':''?>">«</a>
    <a href="<?=$base.$sep?>sayfa=<?=max(1,$sayfa-1)?>" class="page-link <?=$sayfa==1?'disabled':''?>">‹</a>
    <?php
    $start = max(1, $sayfa - 3);
    $end = min($toplam_sayfa, $sayfa + 3);
    for ($i = $start; $i <= $end; $i++):
    ?>
        <a href="<?=$base.$sep?>sayfa=<?=$i?>" class="page-link <?=$i==$sayfa?'active':''?>"><?=$i?></a>
    <?php endfor ?>
    <a href="<?=$base.$sep?>sayfa=<?=min($toplam_sayfa,$sayfa+1)?>" class="page-link <?=$sayfa==$toplam_sayfa?'disabled':''?>">›</a>
    <a href="<?=$base.$sep?>sayfa=<?=$toplam_sayfa?>" class="page-link <?=$sayfa==$toplam_sayfa?'disabled':''?>">»</a>
</div>
<?php endif ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
