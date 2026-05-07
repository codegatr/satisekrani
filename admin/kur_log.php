<?php
/**
 * ADMIN - Döviz Kur Geçmişi
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

// Manuel güncelleme tetikle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'guncelle_simdi') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        require_once __DIR__ . '/../inc/kur_modul.php';
        $sonuc = kur_guncelle();
        if (!empty($sonuc['basarili'])) {
            $detay = '';
            if (!empty($sonuc['kurlar'])) {
                $parts = [];
                foreach ($sonuc['kurlar'] as $kod => $k) {
                    $parts[] = $kod . '=' . num((float)$k['satis'], 4);
                }
                $detay = ' (' . implode(' | ', $parts) . ')';
            }
            $msg = 'Kurlar güncellendi. Kaynak: ' . ($sonuc['kaynak'] ?? '-') . $detay;
        } else {
            $err = 'Güncelleme başarısız: ' . ($sonuc['mesaj'] ?? 'Bilinmeyen hata');
        }
    }
}

// Son 100 kur kaydı (USD ve EUR)
$kurlar = $db->query(
    "SELECT * FROM tk_kur ORDER BY id DESC LIMIT 100"
)->fetchAll();

// Son 50 log
$logs = $db->query(
    "SELECT * FROM tk_kur_log ORDER BY id DESC LIMIT 50"
)->fetchAll();

ob_start();
?>
<div class="page-head">
    <h1>Döviz Kur Geçmişi</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="panel" style="margin-bottom:16px;">
    <div class="panel-head">Manuel Güncelleme</div>
    <div class="panel-body">
        <form method="post" style="display:flex;gap:12px;align-items:center;">
            <?=csrf_field()?>
            <input type="hidden" name="islem" value="guncelle_simdi">
            <button type="submit" class="btn btn-primary">↻ TCMB'den Şimdi Güncelle</button>
            <span class="text-muted">Otomatik güncelleme: Hafta içi 16:30 (cron)</span>
        </form>
    </div>
</div>

<div class="layout-2col">
    <div class="panel">
        <div class="panel-head">Kur Geçmişi <span class="badge"><?=count($kurlar)?></span></div>
        <div class="panel-body" style="padding:0;max-height:600px;overflow-y:auto;">
            <table class="data-table" style="border:none;border-radius:0;">
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Döviz</th>
                        <th class="num">Alış</th>
                        <th class="num">Satış</th>
                        <th>Kaynak</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kurlar as $k): ?>
                    <tr>
                        <td class="text-mono"><?=h(date('d.m.Y H:i', strtotime($k['tarih'])))?></td>
                        <td><strong><?=h($k['doviz_kodu'])?></strong></td>
                        <td class="num"><?=num((float)$k['alis'], 4)?></td>
                        <td class="num"><?=num((float)$k['satis'], 4)?></td>
                        <td><small class="text-mono"><?=h($k['kaynak'] ?? '-')?></small></td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">Güncelleme Logu <span class="badge"><?=count($logs)?></span></div>
        <div class="panel-body" style="padding:0;max-height:600px;overflow-y:auto;">
            <table class="data-table" style="border:none;border-radius:0;">
                <thead>
                    <tr>
                        <th>Tarih</th>
                        <th>Durum</th>
                        <th>Mesaj</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="text-mono"><?=h(date('d.m.Y H:i:s', strtotime($l['tarih'])))?></td>
                        <td>
                            <?php if ($l['durum'] === 'basarili'): ?>
                                <span class="tag-status tag-onaylandi">OK</span>
                            <?php else: ?>
                                <span class="tag-status tag-iptal">HATA</span>
                            <?php endif ?>
                        </td>
                        <td><small><?=h($l['aciklama'])?></small></td>
                    </tr>
                    <?php endforeach ?>
                    <?php if (!$logs): ?>
                    <tr><td colspan="3" class="text-center text-muted" style="padding:20px;">Henüz log kaydı yok.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
