<?php
/**
 * ADMIN - İskonto Oranları
 * Firma × İskonto Grubu matrisi (satis_iskonto, alis_iskonto)
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        try {
            $oranlar = $_POST['oran'] ?? [];
            $alis_oranlar = $_POST['alis'] ?? [];
            $db->beginTransaction();
            $st = $db->prepare(
                'INSERT INTO tk_firma_iskonto (firma_id, iskonto_grup_id, satis_iskonto, alis_iskonto)
                 VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE satis_iskonto=VALUES(satis_iskonto), alis_iskonto=VALUES(alis_iskonto)'
            );
            $sayac = 0;
            foreach ($oranlar as $firma_id => $gruplar) {
                foreach ($gruplar as $grup_id => $oran_yuzde) {
                    $satis = (float)str_replace(',', '.', (string)$oran_yuzde) / 100.0;
                    $alis_yuzde = $alis_oranlar[$firma_id][$grup_id] ?? '';
                    $alis = $alis_yuzde === '' ? 0.0 : (float)str_replace(',', '.', (string)$alis_yuzde) / 100.0;
                    $satis = max(0.0, min(1.0, $satis));
                    $alis  = max(0.0, min(1.0, $alis));
                    $st->execute([(int)$firma_id, (int)$grup_id, $satis, $alis]);
                    $sayac++;
                }
            }
            $db->commit();
            $msg = $sayac . ' iskonto oranı güncellendi.';
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'Hata: ' . $e->getMessage();
        }
    }
}

$firmalar = $db->query('SELECT id, kod, ad FROM tk_firmalar ORDER BY ad')->fetchAll();
$gruplar  = $db->query('SELECT id, ad FROM tk_iskonto_gruplar ORDER BY ad')->fetchAll();

// Mevcut oranlar matrisi
$mevcut = []; $mevcut_alis = [];
foreach ($db->query('SELECT firma_id, iskonto_grup_id, satis_iskonto, alis_iskonto FROM tk_firma_iskonto') as $r) {
    $mevcut[$r['firma_id']][$r['iskonto_grup_id']] = $r['satis_iskonto'];
    $mevcut_alis[$r['firma_id']][$r['iskonto_grup_id']] = $r['alis_iskonto'];
}

$secilen_firma = (int)($_GET['firma'] ?? ($firmalar[0]['id'] ?? 0));

ob_start();
?>
<div class="page-head">
    <h1>İskonto Oranları</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="alert alert-info">
    <strong>Bilgi:</strong> Oranlar yüzde (%) olarak girilir. Örneğin %15 indirim için <code>15</code> yazın.
    Müşteri teklifleri <strong>satış iskontosu</strong> ile hesaplanır.
</div>

<div class="panel">
    <div class="panel-head">Firma Seçin</div>
    <div class="panel-body">
        <div class="filter-row">
            <?php foreach ($firmalar as $f): ?>
                <a href="?firma=<?=$f['id']?>"
                   class="btn <?=$secilen_firma == $f['id'] ? 'btn-primary' : ''?>">
                    <?=h($f['ad'])?>
                </a>
            <?php endforeach ?>
        </div>
    </div>
</div>

<?php if ($secilen_firma): 
    $firma = null;
    foreach ($firmalar as $f) if ($f['id'] == $secilen_firma) { $firma = $f; break; }
?>
<div class="panel" style="margin-top:16px;">
    <div class="panel-head"><?=h($firma['ad'] ?? '')?> - İskonto Oranları (%) <span class="badge"><?=count($gruplar)?> grup</span></div>
    <div class="panel-body">
        <form method="post">
            <?=csrf_field()?>
            <div style="max-height:600px;overflow-y:auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>İskonto Grubu</th>
                            <th width="160">Satış İskontosu (%)</th>
                            <th width="160">Alış İskontosu (%)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($gruplar as $g): 
                        $satis_d = $mevcut[$secilen_firma][$g['id']] ?? null;
                        $alis_d  = $mevcut_alis[$secilen_firma][$g['id']] ?? null;
                        $satis_yz = $satis_d !== null ? rtrim(rtrim(number_format((float)$satis_d * 100, 4, '.', ''), '0'), '.') : '';
                        $alis_yz  = $alis_d  !== null ? rtrim(rtrim(number_format((float)$alis_d  * 100, 4, '.', ''), '0'), '.') : '';
                    ?>
                        <tr>
                            <td><?=h($g['ad'])?></td>
                            <td class="num">
                                <input type="number" step="0.01" min="0" max="100"
                                    name="oran[<?=$secilen_firma?>][<?=$g['id']?>]"
                                    class="form-control text-right"
                                    value="<?=h($satis_yz)?>"
                                    placeholder="0.00">
                            </td>
                            <td class="num">
                                <input type="number" step="0.01" min="0" max="100"
                                    name="alis[<?=$secilen_firma?>][<?=$g['id']?>]"
                                    class="form-control text-right"
                                    value="<?=h($alis_yz)?>"
                                    placeholder="0.00">
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top:16px;text-align:right;">
                <button type="submit" class="btn btn-primary">Tüm Oranları Kaydet</button>
            </div>
        </form>
    </div>
</div>
<?php endif ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
