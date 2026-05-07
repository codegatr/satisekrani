<?php
/**
 * TEKCAN - FİYAT EKRANI (Ana Sayfa)
 *
 * Excel "VERİ GİRİŞİ" sayfasının web karşılığı.
 * Satış temsilcisi burada fiyatları, iskontoları, kuru, marjları görür.
 * Sepet/teklif kayıt YOKTUR - sadece görüntüleme.
 */
require __DIR__ . '/inc/bootstrap.php';
require_login();

$db = db();

// === Veri çek ===

// 1) Döviz kurları
$kur_usd = kur_get('USD');
$kur_eur = kur_get('EUR');

// 2) Vade farkı, nakliye, kar marjı
$vade_farki = (float)ayar_get('vade_farki_aylik', 6);
$nakliye    = (float)ayar_get('nakliye_bedeli', 0.75);
$kar_marji  = (float)ayar_get('sac_hadde_kar_marji', 1.0);

// 3) Firma iskonto oranları (YÜCEL, ÇAYIROVA, TOSÇELİK)
//    tk_firma_iskonto JOIN tk_iskonto_gruplar
$iskonto_per_firma = [];
$sql = "SELECT f.id firma_id, f.kod firma_kod, f.ad firma_ad,
               g.id grup_id, g.ad grup_ad,
               fi.alis_iskonto, fi.satis_iskonto
        FROM tk_firma_iskonto fi
        JOIN tk_firmalar f         ON f.id = fi.firma_id
        JOIN tk_iskonto_gruplar g  ON g.id = fi.iskonto_grup_id
        WHERE f.kod IN ('YUCEL','CAYIROVA','TOSCELIK')
        ORDER BY f.id, g.ad";
foreach ($db->query($sql) as $r) {
    $iskonto_per_firma[$r['firma_kod']][] = $r;
}

// 4) Kroman baz fiyatlar
$kroman = $db->query('SELECT * FROM tk_kroman_baz WHERE aktif=1 ORDER BY sira')->fetchAll();

// 5) Fiyat listesi (Excel'in birebir karşılığı)
$fiyatlar_per_kategori = [];
$kategori_basliklari   = [];
$sql = "SELECT * FROM tk_fiyat_listesi WHERE aktif=1 ORDER BY kategori_sira, satir_sira";
foreach ($db->query($sql) as $r) {
    $kk = $r['kategori_kod'];
    if (!isset($fiyatlar_per_kategori[$kk])) {
        $fiyatlar_per_kategori[$kk] = [];
        $kategori_basliklari[$kk] = $r['kategori_ad'];
    }
    $fiyatlar_per_kategori[$kk][] = $r;
}

// === Düzen: Excel'deki gibi 3 kolon ===
// Sol: KOSEBENT, DKP_HRP_BAKL, GALVANIZ_SAC
// Orta: NPI_NPU_PROF, IPE_PROF, HEA_HEB_PROF, NPU_NPI_INCE, ST37_SIYAH, TRAPEZLER
// Sağ: LAMA_SILME, ST52_SIYAH
$kolon_duzeni = [
    'sol'  => ['KOSEBENT', 'DKP_HRP_BAKL', 'GALVANIZ_SAC'],
    'orta' => ['NPI_NPU_PROF', 'IPE_PROF', 'HEA_HEB_PROF', 'NPU_NPI_INCE', 'ST37_SIYAH', 'TRAPEZLER'],
    'sag'  => ['LAMA_SILME', 'ST52_SIYAH'],
];

require __DIR__ . '/inc/header.php';
?>

<!-- ÜST BAR: Logo + Butonlar + Kur -->
<div class="fiyat-topbar">
    <div class="fiyat-logo">
        <span class="logo-mark">T</span>
        <div>
            <strong>TEKCAN</strong>
            <small>DEMİR · BORU · PROFİL · SAC</small>
        </div>
    </div>
    <div class="fiyat-actions">
        <a href="/arama.php" class="btn-action">🔍 MALZEME ARAMA</a>
        <button type="button" id="btnKurGuncelle" class="btn-action">↻ KUR GÜNCELLE</button>
    </div>
    <div class="fiyat-kur-bar">
        <div class="kur-pill kur-usd">
            <span class="kur-l">1 USD</span>
            <span class="kur-v"><?= num((float)$kur_usd['satis'], 4) ?></span>
        </div>
        <div class="kur-pill kur-eur">
            <span class="kur-l">1 EUR</span>
            <span class="kur-v"><?= num((float)$kur_eur['satis'], 4) ?></span>
        </div>
        <div class="kur-pill kur-tarih">
            <span class="kur-v"><?= date('d.m.Y H:i', strtotime($kur_usd['tarih'] ?? 'now')) ?></span>
        </div>
    </div>
</div>

<!-- BÖLÜM 1: FİRMA İSKONTO ORANLARI -->
<div class="fiyat-section">
    <div class="fiyat-section-title">FİRMA İSKONTO ORANLARI</div>
    <div class="fiyat-iskonto-grid">
        <?php
        $firmalar_baslik = [
            'YUCEL'    => 'YÜCEL BORU - İSKONTO',
            'CAYIROVA' => 'ÇAYIROVA - İSKONTO',
            'TOSCELIK' => 'TOSÇELİK - İSKONTO',
        ];
        foreach ($firmalar_baslik as $kod => $baslik):
            $satirlar = $iskonto_per_firma[$kod] ?? [];
        ?>
        <div class="ftable">
            <div class="ftable-head"><?= h($baslik) ?></div>
            <table>
                <thead>
                    <tr>
                        <th>MALZEME GRUBU</th>
                        <th class="num">ALIŞ</th>
                        <th class="num">SATIŞ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$satirlar): ?>
                        <tr><td colspan="3" class="empty">Henüz iskonto tanımlı değil. <a href="/admin/iskontolar.php">Yönetim → İskontolar</a></td></tr>
                    <?php else: foreach ($satirlar as $s): ?>
                    <tr>
                        <td><?= h($s['grup_ad']) ?></td>
                        <td class="num pct"><?= num((float)$s['alis_iskonto'] * 100, 2) ?>%</td>
                        <td class="num pct"><?= num((float)$s['satis_iskonto'] * 100, 2) ?>%</td>
                    </tr>
                    <?php endforeach; endif ?>
                </tbody>
            </table>
        </div>
        <?php endforeach ?>
    </div>
</div>

<!-- BÖLÜM 2: NAKLİYE VE MARJ HESAPLARI -->
<div class="fiyat-section">
    <div class="fiyat-section-title">NAKLİYE VE MARJ HESAPLARI</div>
    <div class="fiyat-nakliye-grid">
        <div class="not-card">
            <div class="not-baslik">"ÖNEMLİ NOT"</div>
            <div class="not-icerik">
                <p>TOPLU SATIŞLARDA MUTLAKA İSKONTO VE FİYAT İSTE!</p>
                <p>VERİ GİRİŞ EKRANINI DEĞİŞTİRMEYİNİZ.</p>
                <p class="not-vurgu">VERİ GİRİŞLERİNİ GRİ ALANLARA YAPINIZ.</p>
                <a href="https://www.tekcanmetal.com/urun-fiyat-listeleri/" target="_blank" class="not-link">
                    https://www.tekcanmetal.com/urun-fiyat-listeleri/
                </a>
            </div>
        </div>

        <div class="ftable">
            <div class="ftable-head">İŞLEMLER - TL</div>
            <table>
                <thead>
                    <tr>
                        <th>MALZEME GRUBU</th>
                        <th class="num">İSKONTO</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td>NAKLİYE BEDELİ</td><td class="num"><?= num($nakliye, 3) ?></td></tr>
                    <tr><td>SAC HADDE GRUPLARI KAR MARJI</td><td class="num"><?= num($kar_marji, 3) ?></td></tr>
                </tbody>
            </table>
        </div>

        <div class="ftable">
            <div class="ftable-head">KROMAN BAZ FİYATLAR</div>
            <table>
                <thead>
                    <tr>
                        <th>MALZEME GRUBU</th>
                        <th class="num">BAZ FİYATLAR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kroman as $k): ?>
                    <tr>
                        <td><?= h($k['malzeme_grubu']) ?></td>
                        <td class="num"><span class="tl-symbol">₺</span> <?= num((float)$k['baz_fiyat'], 2) ?></td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- AYLIK VADE FARKI INDICATOR (sağ üst tarzı) -->
<div class="vade-indicator">
    AYLIK VADE FARKI: <strong><?= num($vade_farki, 0) ?>%</strong>
</div>

<!-- BÖLÜM 3: BİRİM GİRİŞ EKRANI - Fiyat Tabloları -->
<div class="fiyat-section">
    <div class="fiyat-section-title">BİRİM GİRİŞ EKRANI</div>

    <div class="fiyat-grid-3col">
        <?php foreach ($kolon_duzeni as $kolon_adi => $kategori_kodlari): ?>
        <div class="fiyat-kolon">
            <?php foreach ($kategori_kodlari as $kk):
                if (!isset($fiyatlar_per_kategori[$kk])) continue;
                $satirlar = $fiyatlar_per_kategori[$kk];
                $baslik = $kategori_basliklari[$kk];
            ?>
            <div class="ftable urun-table">
                <div class="ftable-head"><?= h($baslik) ?></div>
                <table>
                    <thead>
                        <tr>
                            <th class="sira-col">#</th>
                            <th>MALZEME GRUBU</th>
                            <th class="num">TL</th>
                            <th class="num">USD</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($satirlar as $i => $s): ?>
                        <tr>
                            <td class="sira-col"><?= $s['satir_sira'] ?></td>
                            <td><?= h($s['malzeme_grubu']) ?></td>
                            <td class="num"><?= $s['tl_fiyat'] !== null ? number_format((float)$s['tl_fiyat'], 0, ',', '.') : '-' ?></td>
                            <td class="num"><?= $s['usd_fiyat'] !== null ? number_format((float)$s['usd_fiyat'], 0, ',', '.') : '-' ?></td>
                        </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach ?>
        </div>
        <?php endforeach ?>
    </div>
</div>

<!-- Stiller (sayfa-spesifik) -->
<style>
.fiyat-topbar {
    background: #fff;
    border: 1px solid var(--c-border);
    border-radius: 8px;
    padding: 14px 18px;
    margin-bottom: 16px;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 24px;
    align-items: center;
    box-shadow: var(--shadow-sm);
}
.fiyat-logo {
    display: flex;
    gap: 12px;
    align-items: center;
}
.fiyat-logo .logo-mark {
    background: var(--c-primary);
    color: #fff;
    width: 44px;
    height: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 22px;
    border-radius: 6px;
    border: 2px solid #fff;
    box-shadow: 0 0 0 2px var(--c-primary);
}
.fiyat-logo strong {
    display: block;
    font-size: 20px;
    color: #003a8c;
    letter-spacing: 1px;
}
.fiyat-logo small {
    font-size: 10px;
    color: var(--c-text-muted);
    letter-spacing: 1.5px;
    font-weight: 600;
}
.fiyat-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
}
.btn-action {
    background: #f0f0f0;
    border: 1px solid #c9ced6;
    padding: 12px 20px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 700;
    color: var(--c-text);
    text-decoration: none;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.btn-action:hover {
    background: #e3e6eb;
    border-color: var(--c-primary);
    color: var(--c-primary) !important;
}
.fiyat-kur-bar {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.kur-pill {
    display: flex;
    align-items: center;
    gap: 12px;
    background: #1e3a8a;
    color: #fff;
    padding: 4px 10px;
    border-radius: 3px;
    font-family: var(--font-mono);
    font-size: 12px;
    border: 1px solid #c8102e;
    min-width: 200px;
    justify-content: space-between;
}
.kur-pill .kur-l { font-weight: 700; }
.kur-pill .kur-v { font-weight: 800; font-size: 13px; }
.kur-pill.kur-usd, .kur-pill.kur-eur { background: #1a1d23; }
.kur-pill.kur-tarih {
    background: #1e3a8a;
    justify-content: center;
}

.fiyat-section { margin-bottom: 18px; }
.fiyat-section-title {
    background: #1e3a8a;
    color: #fff;
    padding: 8px 14px;
    text-align: center;
    font-weight: 800;
    font-size: 14px;
    letter-spacing: 1px;
    border-radius: 4px 4px 0 0;
}

.fiyat-iskonto-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    background: #fff;
    padding: 12px;
    border: 1px solid var(--c-border);
    border-radius: 0 0 4px 4px;
}

.fiyat-nakliye-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    background: #fff;
    padding: 12px;
    border: 1px solid var(--c-border);
    border-radius: 0 0 4px 4px;
}

.ftable {
    background: #fff;
    border: 1px solid #c9ced6;
    overflow: hidden;
    border-radius: 2px;
}
.ftable-head {
    background: #c8102e;
    color: #fff;
    padding: 8px 10px;
    font-weight: 800;
    text-align: center;
    font-size: 13px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.ftable table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}
.ftable thead th {
    background: #ffeb3b;
    color: #1a1d23;
    padding: 6px 8px;
    text-align: left;
    font-weight: 800;
    font-size: 11px;
    border: 1px solid #c9ced6;
    text-transform: uppercase;
}
.ftable thead th.num { text-align: right; }
.ftable tbody td {
    padding: 5px 8px;
    border: 1px solid #e3e6eb;
    background: #f0fdf4;
    color: var(--c-text);
}
.ftable tbody td.num {
    text-align: right;
    font-family: var(--font-mono);
    font-weight: 600;
    background: #fef3c7;
}
.ftable tbody td.pct { background: #fef3c7; color: var(--c-text); font-weight: 700; }
.ftable .empty {
    background: #f5f6f8;
    text-align: center;
    color: var(--c-text-muted);
    font-style: italic;
    padding: 14px;
}
.ftable .sira-col {
    width: 30px;
    text-align: center;
    background: #f5f6f8;
    color: var(--c-text-muted);
    font-weight: 700;
    font-family: var(--font-mono);
}

.urun-table {
    margin-bottom: 12px;
}
.urun-table tbody td {
    background: #f0fdf4;
}
.urun-table tbody td.num {
    background: #fef3c7;
}
.urun-table tbody tr:hover td {
    background: #fff5b1;
}

.not-card {
    background: #fff;
    border: 1px solid #c9ced6;
    padding: 10px 14px;
    text-align: center;
    border-radius: 2px;
}
.not-baslik {
    color: var(--c-primary);
    font-weight: 800;
    font-size: 16px;
    margin-bottom: 8px;
}
.not-icerik p {
    margin: 4px 0;
    font-size: 11px;
    color: var(--c-text);
    font-weight: 600;
    line-height: 1.4;
}
.not-vurgu {
    color: var(--c-primary) !important;
    font-weight: 800 !important;
    font-size: 12px !important;
}
.not-link {
    display: block;
    margin-top: 8px;
    font-size: 11px;
    color: #1e3a8a;
    word-break: break-all;
}

.vade-indicator {
    background: #ffeb3b;
    color: #1a1d23;
    padding: 8px 14px;
    text-align: center;
    font-weight: 700;
    font-size: 13px;
    border: 1px solid #c8102e;
    border-radius: 4px;
    margin-bottom: 18px;
}
.vade-indicator strong {
    color: var(--c-primary);
    font-size: 16px;
    margin-left: 8px;
}

.fiyat-grid-3col {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
    background: #fff;
    padding: 12px;
    border: 1px solid var(--c-border);
    border-radius: 0 0 4px 4px;
}
.fiyat-kolon {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.tl-symbol {
    color: var(--c-text-muted);
    font-weight: 400;
    margin-right: 4px;
}

@media (max-width: 1100px) {
    .fiyat-iskonto-grid,
    .fiyat-nakliye-grid,
    .fiyat-grid-3col {
        grid-template-columns: 1fr;
    }
    .fiyat-topbar {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.getElementById('btnKurGuncelle').addEventListener('click', async function() {
    if (!confirm('TCMB kurları yeniden çekilecek. Devam edilsin mi?')) return;
    this.disabled = true;
    this.textContent = '⏳ Güncelleniyor...';
    try {
        const r = await fetch('/api/kur_guncelle.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: '_csrf=<?= csrf_token() ?>'
        });
        const j = await r.json();
        if (j.basarili) {
            alert('Kurlar güncellendi: ' + (j.kaynak || 'TCMB'));
            location.reload();
        } else {
            alert('Hata: ' + (j.mesaj || 'Bilinmeyen hata'));
            this.disabled = false;
            this.textContent = '↻ KUR GÜNCELLE';
        }
    } catch (e) {
        alert('Bağlantı hatası: ' + e.message);
        this.disabled = false;
        this.textContent = '↻ KUR GÜNCELLE';
    }
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
