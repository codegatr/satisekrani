<?php
/**
 * MALZEME ARAMA + FİYAT KARŞILAŞTIRMA
 *
 * Kullanıcı bir malzeme arar, sistem aynı iskonto grubundaki tüm firmaların
 * (YÜCEL, ÇAYIROVA, TOSÇELİK, vb.) stoklarını yan yana karşılaştırır.
 * Net TL fiyatı (iskonto + döviz çevirisi dahil) hesaplanır, en ucuz vurgulanır.
 */
require __DIR__ . '/inc/bootstrap.php';
require_login();

$db = db();

$q = trim($_GET['q'] ?? '');
$grup_id_filtre = (int)($_GET['grup_id'] ?? 0);

// Döviz kurları
$kur_usd = (float)kur_get('USD')['satis'];
$kur_eur = (float)kur_get('EUR')['satis'];
if ($kur_usd <= 0) $kur_usd = 1;
if ($kur_eur <= 0) $kur_eur = 1;

// === Karşılaştırma Mantığı ===
// 1) Arama kelimesine uyan stokların iskonto_grup_id'lerini topla
// 2) O gruplardaki TÜM aktif stokları çek (her firmadan)
// 3) Net fiyatı hesapla, gruba göre PHP'de grupla, en ucuzu işaretle

$gruplar_listesi = [];
$grup_ids = [];

if ($q !== '' || $grup_id_filtre) {
    if ($grup_id_filtre) {
        $grup_ids = [$grup_id_filtre];
    } else {
        $st = $db->prepare(
            'SELECT DISTINCT iskonto_grup_id 
             FROM tk_stoklar 
             WHERE aktif=1 AND iskonto_grup_id IS NOT NULL
               AND (stok_kodu LIKE ? OR ad LIKE ?)
             LIMIT 30'
        );
        $st->execute(['%' . $q . '%', '%' . $q . '%']);
        $grup_ids = $st->fetchAll(PDO::FETCH_COLUMN);
    }

    if ($grup_ids) {
        // Performans: çok büyük iskonto gruplarında (yüzlerce stok) sadece arama kelimesine
        // BENZER stokları + her firmadan örnekleri çek. Aksi halde sayfa MB'larca olur.
        $place = implode(',', array_fill(0, count($grup_ids), '?'));

        // Önce bu gruplardaki TOPLAM stok sayısı
        $count_sql = "SELECT COUNT(*) FROM tk_stoklar WHERE aktif=1 AND iskonto_grup_id IN ($place)";
        $st = $db->prepare($count_sql);
        $st->execute($grup_ids);
        $toplam_stok = (int)$st->fetchColumn();

        // Eğer 200'den fazla stok varsa: arama kelimesi içerenleri öncele
        $kelime_filtre = '';
        $params = $grup_ids;
        if ($toplam_stok > 200 && $q !== '') {
            $kelime_filtre = ' AND (s.stok_kodu LIKE ? OR s.ad LIKE ?)';
            $params[] = '%' . $q . '%';
            $params[] = '%' . $q . '%';
        }

        $sql = "SELECT s.id, s.stok_kodu, s.ad, s.birim, s.doviz, s.boy_uzunluk,
                       s.mt_kg_fiyati, s.boy_fiyati, s.kg_per_mt,
                       s.firma_id, f.kod firma_kod, f.ad firma_ad,
                       s.iskonto_grup_id, g.ad grup_ad,
                       fi.satis_iskonto, fi.alis_iskonto
                FROM tk_stoklar s
                JOIN tk_firmalar f ON f.id = s.firma_id
                JOIN tk_iskonto_gruplar g ON g.id = s.iskonto_grup_id
                LEFT JOIN tk_firma_iskonto fi 
                       ON fi.firma_id = s.firma_id 
                      AND fi.iskonto_grup_id = s.iskonto_grup_id
                WHERE s.aktif=1 AND s.iskonto_grup_id IN ($place)
                  $kelime_filtre
                ORDER BY g.ad, s.boy_uzunluk, f.ad, s.stok_kodu
                LIMIT 300";
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll();

        foreach ($rows as $r) {
            $gid = (int)$r['iskonto_grup_id'];
            $birim_f = $r['mt_kg_fiyati'] !== null ? (float)$r['mt_kg_fiyati']
                     : ($r['boy_fiyati'] !== null ? (float)$r['boy_fiyati'] : 0);
            $iskonto = $r['satis_iskonto'] !== null ? (float)$r['satis_iskonto'] : 0;

            $birim_tl = $birim_f;
            if ($r['doviz'] === 'USD') $birim_tl *= $kur_usd;
            elseif ($r['doviz'] === 'EUR') $birim_tl *= $kur_eur;

            $r['net_tl']     = $birim_tl * (1 - $iskonto);
            $r['birim_tl']   = $birim_tl;
            $r['iskonto_pct']= $iskonto * 100;

            if (!isset($gruplar_listesi[$gid])) {
                $gruplar_listesi[$gid] = [
                    'ad' => $r['grup_ad'],
                    'kalemler' => [],
                ];
            }
            $gruplar_listesi[$gid]['kalemler'][] = $r;
        }

        foreach ($gruplar_listesi as $gid => &$grup) {
            $gecerliler = array_filter($grup['kalemler'], fn($k) => $k['net_tl'] > 0);
            usort($gecerliler, fn($a, $b) => $a['net_tl'] <=> $b['net_tl']);
            $grup['en_ucuz_id'] = !empty($gecerliler) ? $gecerliler[0]['id'] : null;
            $grup['en_ucuz_tl'] = !empty($gecerliler) ? $gecerliler[0]['net_tl'] : 0;
            $grup['firma_sayisi'] = count(array_unique(array_column($grup['kalemler'], 'firma_id')));
            usort($grup['kalemler'], function($a, $b) {
                if ($a['net_tl'] <= 0 && $b['net_tl'] <= 0) return 0;
                if ($a['net_tl'] <= 0) return 1;
                if ($b['net_tl'] <= 0) return -1;
                return $a['net_tl'] <=> $b['net_tl'];
            });
        }
        unset($grup);
    }
}

require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
    <h1>🔍 Malzeme Arama & Fiyat Karşılaştırma</h1>
    <a href="/index.php" class="btn">← Fiyat Listesi</a>
</div>

<form method="get" class="ara-form" style="margin-bottom:16px;">
    <div class="form-group" style="flex:3;">
        <label>Stok Kodu / Ürün Adı</label>
        <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
               placeholder="Örn: NPI 100, HRP, IPE 240, DKP 1.5..." autofocus>
    </div>
    <button type="submit" class="btn btn-primary">Karşılaştır</button>
</form>

<?php if ($q === '' && !$grup_id_filtre): ?>
    <div class="alert alert-info">
        <strong>💡 Nasıl çalışır:</strong> Bir malzeme adı girin (örn. <code>NPI 100</code>).
        Sistem aynı iskonto grubundaki <strong>YÜCEL, ÇAYIROVA, TOSÇELİK</strong> ve diğer tedarikçilerin
        stoklarını yan yana karşılaştırır, iskontolu net TL fiyatını hesaplar ve <strong>en uygun firmayı</strong> 🏆 ile vurgular.
    </div>
<?php elseif (empty($gruplar_listesi)): ?>
    <div class="empty-state">Sonuç bulunamadı. Farklı bir kelime deneyin.</div>
<?php else:
    $karsilastirilabilir = 0;
    foreach ($gruplar_listesi as $g) if ($g['firma_sayisi'] >= 2) $karsilastirilabilir++;
?>
    <div class="results-info" style="margin-bottom:16px;">
        <strong><?= count($gruplar_listesi) ?></strong> kategori bulundu
        <?php if ($karsilastirilabilir > 0): ?>
            · <strong><?= $karsilastirilabilir ?></strong> kategoride firma karşılaştırması yapılabiliyor
        <?php endif ?>
        · USD: <?= num($kur_usd, 4) ?> · EUR: <?= num($kur_eur, 4) ?>
    </div>

    <?php foreach ($gruplar_listesi as $gid => $grup):
        $kalemler = $grup['kalemler'];
        $cok_firma = $grup['firma_sayisi'] >= 2;
        $en_ucuz_tl = $grup['en_ucuz_tl'];
    ?>
    <div class="karsilastirma-card">
        <div class="kk-baslik">
            <span class="kk-grup-ad"><?= h($grup['ad']) ?></span>
            <span class="kk-bilgi">
                <?= count($kalemler) ?> ürün · <?= $grup['firma_sayisi'] ?> firma
                <?php if ($cok_firma): ?>
                    <span class="kk-rozet">⚡ KARŞILAŞTIRILABİLİR</span>
                <?php endif ?>
            </span>
        </div>

        <table class="kk-tablo">
            <thead>
                <tr>
                    <th class="kk-rank">#</th>
                    <th>Firma</th>
                    <th>Stok / Ürün</th>
                    <th class="num">Liste Fiyatı</th>
                    <th class="num">İskonto</th>
                    <th class="num">Net TL</th>
                    <?php if ($cok_firma): ?><th class="num">Fark</th><?php endif ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($kalemler as $idx => $k):
                    $en_ucuz = ($k['id'] == $grup['en_ucuz_id']);
                    $gecersiz = ($k['net_tl'] <= 0);
                    $fark_pct = ($en_ucuz_tl > 0 && $k['net_tl'] > 0)
                        ? (($k['net_tl'] - $en_ucuz_tl) / $en_ucuz_tl * 100) : 0;
                ?>
                <tr class="<?= $en_ucuz && $cok_firma ? 'kk-ucuz' : ($gecersiz ? 'kk-gecersiz' : '') ?>">
                    <td class="kk-rank">
                        <?php if ($en_ucuz && $cok_firma): ?>
                            <span class="kk-medal">🏆</span>
                        <?php elseif (!$gecersiz): ?>
                            <span class="kk-rank-num"><?= $idx + 1 ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif ?>
                    </td>
                    <td><span class="badge-firma kk-firma"><?= h($k['firma_kod'] ?? $k['firma_ad']) ?></span></td>
                    <td>
                        <div class="kk-stok"><?= h($k['ad']) ?></div>
                        <div class="kk-stok-meta">
                            <span class="text-mono"><?= h($k['stok_kodu']) ?></span>
                            <?php if ($k['boy_uzunluk']): ?> · <?= (int)$k['boy_uzunluk'] ?>m<?php endif ?>
                        </div>
                    </td>
                    <td class="num">
                        <?php if ($k['birim_tl'] > 0): ?>
                            <span class="text-mono"><?= num($k['birim_tl'], 2) ?> TL</span>
                            <?php if ($k['doviz'] !== 'TL'): ?>
                                <br><small class="text-muted">(<?= num((float)($k['mt_kg_fiyati'] ?? $k['boy_fiyati']), 2) ?> <?= h($k['doviz']) ?>)</small>
                            <?php endif ?>
                            <small class="text-muted">/<?= h($k['birim']) ?></small>
                        <?php else: ?>
                            <span class="text-muted">Fiyat yok</span>
                        <?php endif ?>
                    </td>
                    <td class="num">
                        <?php if ($k['iskonto_pct'] > 0): ?>
                            <span class="kk-iskonto">%<?= num($k['iskonto_pct'], 1) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif ?>
                    </td>
                    <td class="num">
                        <?php if ($k['net_tl'] > 0): ?>
                            <strong class="kk-net <?= $en_ucuz && $cok_firma ? 'kk-net-ucuz' : '' ?>">
                                <?= tl($k['net_tl']) ?>/<?= h($k['birim']) ?>
                            </strong>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif ?>
                    </td>
                    <?php if ($cok_firma): ?>
                    <td class="num">
                        <?php if ($en_ucuz): ?>
                            <span class="kk-fark-en-ucuz">EN UYGUN</span>
                        <?php elseif ($fark_pct > 0): ?>
                            <span class="kk-fark">+<?= num($fark_pct, 1) ?>%</span>
                            <br><small class="text-muted">+<?= tl($k['net_tl'] - $en_ucuz_tl) ?></small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif ?>
                    </td>
                    <?php endif ?>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php endforeach ?>
<?php endif ?>

<style>
.karsilastirma-card { background:#fff;border:1px solid var(--c-border);border-radius:8px;margin-bottom:16px;overflow:hidden;box-shadow:var(--shadow-sm); }
.kk-baslik { background:linear-gradient(90deg,var(--c-primary) 0%,var(--c-primary-dark) 100%);color:#fff;padding:10px 16px;display:flex;justify-content:space-between;align-items:center;font-weight:700;flex-wrap:wrap;gap:8px; }
.kk-grup-ad { font-size:14px;text-transform:uppercase;letter-spacing:0.5px; }
.kk-bilgi { font-size:11px;opacity:0.95;display:flex;align-items:center;gap:8px; }
.kk-rozet { background:#ffeb3b;color:#1a1d23;padding:2px 8px;border-radius:10px;font-weight:800;font-size:10px;letter-spacing:0.5px; }
.kk-tablo { width:100%;border-collapse:collapse;font-size:13px; }
.kk-tablo thead th { background:var(--c-surface-2);color:var(--c-text-muted);padding:8px 10px;text-align:left;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:0.5px;border-bottom:2px solid var(--c-border-strong); }
.kk-tablo thead th.num { text-align:right; }
.kk-tablo .kk-rank { width:40px;text-align:center; }
.kk-tablo tbody td { padding:10px;border-bottom:1px solid var(--c-border);vertical-align:middle; }
.kk-tablo tbody td.num { text-align:right;font-family:var(--font-mono); }
.kk-tablo tbody tr:last-child td { border-bottom:none; }
.kk-tablo tbody tr:hover td { background:#fafbfc; }
.kk-tablo tr.kk-ucuz td { background:linear-gradient(90deg,#dcfce7 0%,#f0fdf4 100%) !important; }
.kk-tablo tr.kk-ucuz td:first-child { border-left:4px solid var(--c-success); }
.kk-tablo tr.kk-gecersiz td { opacity:0.5;background:var(--c-surface-2); }
.kk-medal { font-size:22px; }
.kk-rank-num { display:inline-block;width:24px;height:24px;line-height:24px;background:var(--c-surface-2);border:1px solid var(--c-border-strong);border-radius:50%;text-align:center;font-family:var(--font-mono);font-size:11px;font-weight:700;color:var(--c-text-muted); }
.kk-firma { font-size:11px;padding:4px 10px;font-weight:800; }
.kk-stok { font-weight:600;color:var(--c-text);font-size:13px; }
.kk-stok-meta { font-size:11px;color:var(--c-text-muted);margin-top:2px; }
.kk-iskonto { background:var(--c-warning-bg);color:var(--c-warning);padding:3px 8px;border-radius:4px;font-weight:700;font-size:12px; }
.kk-net { font-size:14px;color:var(--c-text); }
.kk-net-ucuz { color:var(--c-success) !important;font-size:16px !important;font-weight:800; }
.kk-fark { color:var(--c-danger);font-weight:700;font-size:12px;background:var(--c-danger-bg);padding:2px 8px;border-radius:4px;display:inline-block; }
.kk-fark-en-ucuz { background:var(--c-success);color:#fff;padding:4px 10px;border-radius:4px;font-weight:800;font-size:11px;letter-spacing:0.5px; }
</style>

<?php require __DIR__ . '/inc/footer.php'; ?>
