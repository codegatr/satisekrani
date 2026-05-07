<?php
ob_start();
require __DIR__ . '/inc/bootstrap.php';
require_login();
$user = current_user();

$sayfa = max(1, (int)($_GET['p'] ?? 1));
$adet  = 25;
$offset = ($sayfa - 1) * $adet;

$where = '';
$params = [];
if ($user['rol'] !== 'admin') {
    $where = 'WHERE s.kullanici_id = ?';
    $params[] = $user['id'];
}

// Arama
$ara = trim($_GET['ara'] ?? '');
if ($ara !== '') {
    $where .= ($where ? ' AND ' : 'WHERE ') . '(s.teklif_no LIKE ? OR s.musteri_adi LIKE ?)';
    $params[] = '%' . $ara . '%';
    $params[] = '%' . $ara . '%';
}

$sqlCount = "SELECT COUNT(*) FROM tk_satislar s $where";
$st = db()->prepare($sqlCount);
$st->execute($params);
$toplam = (int)$st->fetchColumn();
$toplam_sayfa = max(1, ceil($toplam / $adet));

$sql = "SELECT s.*, u.ad_soyad AS personel_ad
        FROM tk_satislar s
        LEFT JOIN tk_users u ON u.id = s.kullanici_id
        $where
        ORDER BY s.id DESC
        LIMIT $adet OFFSET $offset";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
    <h2>Tekliflerim</h2>
    <form method="get" class="ara-form">
        <input type="text" name="ara" value="<?= h($ara) ?>" 
               placeholder="Teklif no veya müşteri adı..." class="form-control">
        <button class="btn btn-primary">Ara</button>
    </form>
</div>

<div class="panel">
    <div class="panel-body">

    <?php if (empty($rows)): ?>
        <div class="empty-state">Henüz teklif kaydı bulunmuyor.</div>
    <?php else: ?>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Teklif No</th>
                    <th>Tarih</th>
                    <th>Müşteri</th>
                    <?php if ($user['rol'] === 'admin'): ?>
                    <th>Personel</th>
                    <?php endif ?>
                    <th>Vade</th>
                    <th class="text-right">Ara Toplam</th>
                    <th class="text-right">Genel Toplam</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td><b><?= h($r['teklif_no']) ?></b></td>
                    <td><?= date('d.m.Y H:i', strtotime($r['tarih'])) ?></td>
                    <td><?= h($r['musteri_adi'] ?: '-') ?></td>
                    <?php if ($user['rol'] === 'admin'): ?>
                    <td><?= h($r['personel_ad']) ?></td>
                    <?php endif ?>
                    <td><?= (int)$r['vade_ay'] ?> Ay</td>
                    <td class="text-right"><?= tl((float)$r['ara_toplam']) ?></td>
                    <td class="text-right"><b><?= tl((float)$r['genel_toplam']) ?></b></td>
                    <td>
                        <a href="/teklif_yazdir.php?id=<?= $r['id'] ?>" 
                           target="_blank" class="btn btn-sm btn-outline">Yazdır</a>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <?php if ($toplam_sayfa > 1): ?>
        <div class="pagination">
            <?php for ($i = 1; $i <= $toplam_sayfa; $i++): ?>
                <a href="?p=<?= $i ?>&ara=<?= urlencode($ara) ?>"
                   class="page-link<?= $i === $sayfa ? ' active' : '' ?>"><?= $i ?></a>
            <?php endfor ?>
        </div>
        <?php endif ?>

    <?php endif ?>
    </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
<?php ob_end_flush(); ?>
