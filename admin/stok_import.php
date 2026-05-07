<?php
/**
 * ADMIN - Stok Excel/CSV Import
 *
 * 3-Adımlı akış:
 * 1) Dosya yükle (xlsx veya csv)
 * 2) Önizleme: kaç yeni, kaç güncellenecek, hatalı satırlar
 * 3) Onay → Transaction içinde toplu UPDATE/INSERT
 *
 * Anahtar: stok_kodu (varsa güncelle, yoksa ekle)
 * Tanınan başlıklar (case-insensitive, esnek):
 *   - stok kodu / stok_kodu / kod
 *   - ürün adı / urun adi / ad / adi
 *   - ana grup
 *   - firma kodu / firma_kod
 *   - firma / firma adı
 *   - iskonto grubu / iskonto_grup / grup
 *   - birim
 *   - döviz / doviz
 *   - mt/kg fiyatı / mt_kg_fiyati / fiyat / mt fiyat / kg fiyat
 *   - boy fiyatı / boy_fiyati
 *   - kg/mt / kg_per_mt
 *   - boy / boy_uzunluk
 *   - aktif
 */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/xlsx_helper.php';
require_admin();

$db = db();
$msg = null; $err = null;
$adim = (int)($_GET['adim'] ?? 1);

session_start_secure();

// Türkçe karakter normalize (İ→i, ı→i, Ş→s, vs.) + lower + trim
function tr_norm(string $s): string {
    $s = trim($s);
    $tr = ['İ','I','Ş','Ğ','Ü','Ö','Ç','i','ı','ş','ğ','ü','ö','ç'];
    $en = ['i','i','s','g','u','o','c','i','i','s','g','u','o','c'];
    $s = str_replace($tr, $en, $s);
    return mb_strtolower($s, 'UTF-8');
}

// Lookup tabloları (kod/ad → id eşleşmesi için) - hem orijinal hem normalize key ekle
$ana_gruplar = [];
foreach ($db->query('SELECT id, ad FROM tk_ana_gruplar') as $r) {
    $ana_gruplar[tr_norm($r['ad'])] = (int)$r['id'];
}
$firmalar = []; $firmalar_kod = [];
foreach ($db->query('SELECT id, kod, ad FROM tk_firmalar') as $r) {
    $firmalar[tr_norm($r['ad'])] = (int)$r['id'];
    if (!empty($r['kod'])) {
        $firmalar_kod[tr_norm($r['kod'])] = (int)$r['id'];
    }
}
$gruplar = [];
foreach ($db->query('SELECT id, ad FROM tk_iskonto_gruplar') as $r) {
    $gruplar[tr_norm($r['ad'])] = (int)$r['id'];
}

// Header eşleşme tablosu
function header_match(string $h): ?string {
    $h = mb_strtolower(trim($h), 'UTF-8');
    $h = str_replace(['ı','i','ş','ğ','ü','ö','ç','İ','I'], ['i','i','s','g','u','o','c','i','i'], $h);
    $h = preg_replace('/[^a-z0-9 ]/', ' ', $h);
    $h = preg_replace('/\s+/', ' ', trim($h));
    $map = [
        'id'                          => 'id',
        'stok kodu'                   => 'stok_kodu',
        'stok_kodu'                   => 'stok_kodu',
        'kod'                         => 'stok_kodu',
        'urun adi'                    => 'ad',
        'urun_adi'                    => 'ad',
        'ad'                          => 'ad',
        'adi'                         => 'ad',
        'ana grup'                    => 'ana_grup',
        'ana_grup'                    => 'ana_grup',
        'firma'                       => 'firma',
        'firma adi'                   => 'firma',
        'firma kodu'                  => 'firma_kod',
        'firma_kod'                   => 'firma_kod',
        'iskonto grubu'               => 'iskonto_grup',
        'iskonto_grup'                => 'iskonto_grup',
        'grup'                        => 'iskonto_grup',
        'birim'                       => 'birim',
        'doviz'                       => 'doviz',
        'mt kg fiyati'                => 'mt_kg_fiyati',
        'mt_kg_fiyati'                => 'mt_kg_fiyati',
        'fiyat'                       => 'mt_kg_fiyati',
        'mt fiyat'                    => 'mt_kg_fiyati',
        'kg fiyat'                    => 'mt_kg_fiyati',
        'boy fiyati'                  => 'boy_fiyati',
        'boy_fiyati'                  => 'boy_fiyati',
        'kg mt'                       => 'kg_per_mt',
        'kg_per_mt'                   => 'kg_per_mt',
        'boy'                         => 'boy_uzunluk',
        'boy m'                       => 'boy_uzunluk',
        'boy_uzunluk'                 => 'boy_uzunluk',
        'aktif'                       => 'aktif',
        'aktif 1 0'                   => 'aktif',
    ];
    return $map[$h] ?? null;
}

// === ADIM 1: Dosya yükleme + parse + önizleme oluştur ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'yukle') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } elseif (!isset($_FILES['dosya']) || $_FILES['dosya']['error'] !== UPLOAD_ERR_OK) {
        $err = 'Dosya yüklenemedi (kod: ' . ($_FILES['dosya']['error'] ?? '?') . ').';
    } else {
        $tmp = $_FILES['dosya']['tmp_name'];
        $orjinal = $_FILES['dosya']['name'];
        $boyut = (int)$_FILES['dosya']['size'];
        if ($boyut > 10 * 1024 * 1024) {
            $err = 'Dosya çok büyük (en fazla 10 MB).';
        } else {
            try {
                $ext = strtolower(pathinfo($orjinal, PATHINFO_EXTENSION));
                if ($ext === 'xlsx') {
                    $rows = xlsx_read($tmp);
                } elseif ($ext === 'csv') {
                    $rows = csv_read($tmp);
                } else {
                    throw new Exception('Sadece .xlsx veya .csv dosyaları kabul edilir.');
                }

                if (count($rows) < 2) {
                    throw new Exception('Dosyada en az 1 başlık ve 1 veri satırı olmalı.');
                }

                // Header eşleştirme
                $hdr = array_map('strval', $rows[0]);
                $kolon = []; // alan adı → kolon indeksi
                foreach ($hdr as $i => $h) {
                    $alan = header_match($h);
                    if ($alan !== null) {
                        $kolon[$alan] = $i;
                    }
                }

                if (!isset($kolon['stok_kodu'])) {
                    throw new Exception('"Stok Kodu" sütunu bulunamadı. Bu sütun zorunludur.');
                }

                // Mevcut stokları kod bazlı yükle
                $mevcut_stoklar = [];
                foreach ($db->query('SELECT id, stok_kodu FROM tk_stoklar') as $r) {
                    $mevcut_stoklar[tr_norm($r['stok_kodu'])] = (int)$r['id'];
                }

                // Veri satırlarını işle
                $analiz = [
                    'toplam'      => 0,
                    'yeni'        => 0,
                    'guncellenecek' => 0,
                    'hatali'      => 0,
                    'satirlar'    => [],
                ];

                $islenmis = []; // DB'ye yazılacak
                $hatalar = [];

                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $satir_no = $i + 1;

                    // Boş satır mı?
                    $bos = true;
                    foreach ($row as $v) if (trim((string)$v) !== '') { $bos = false; break; }
                    if ($bos) continue;

                    $analiz['toplam']++;
                    $get = function ($alan) use ($row, $kolon) {
                        return isset($kolon[$alan]) ? trim((string)($row[$kolon[$alan]] ?? '')) : '';
                    };

                    $kod = $get('stok_kodu');
                    if ($kod === '') {
                        $hatalar[] = "Satır $satir_no: Stok kodu boş";
                        $analiz['hatali']++;
                        continue;
                    }

                    // Lookup'lar
                    $ana_grup_ad = $get('ana_grup');
                    $firma_ad    = $get('firma');
                    $firma_kod   = $get('firma_kod');
                    $isk_grup_ad = $get('iskonto_grup');

                    $ana_grup_id = null; $firma_id = null; $isk_grup_id = null;
                    if ($ana_grup_ad !== '') {
                        $ana_grup_id = $ana_gruplar[tr_norm($ana_grup_ad)] ?? null;
                        if ($ana_grup_id === null) {
                            $hatalar[] = "Satır $satir_no: Ana grup bulunamadı: " . $ana_grup_ad;
                            $analiz['hatali']++; continue;
                        }
                    }
                    if ($firma_kod !== '') {
                        $firma_id = $firmalar_kod[tr_norm($firma_kod)] ?? null;
                        // "Firma Kodu" sütununda ad da yazılmış olabilir, ad'da da ara
                        if ($firma_id === null) {
                            $firma_id = $firmalar[tr_norm($firma_kod)] ?? null;
                        }
                    }
                    if ($firma_id === null && $firma_ad !== '') {
                        // Önce ad olarak dene, sonra kod olarak dene
                        $firma_id = $firmalar[tr_norm($firma_ad)] ?? null;
                        if ($firma_id === null) {
                            $firma_id = $firmalar_kod[tr_norm($firma_ad)] ?? null;
                        }
                    }
                    if ($firma_id === null) {
                        $hatalar[] = "Satır $satir_no: Firma bulunamadı (kod=$firma_kod, ad=$firma_ad)";
                        $analiz['hatali']++; continue;
                    }
                    if ($isk_grup_ad !== '') {
                        $isk_grup_id = $gruplar[tr_norm($isk_grup_ad)] ?? null;
                        if ($isk_grup_id === null) {
                            $hatalar[] = "Satır $satir_no: İskonto grubu bulunamadı: " . $isk_grup_ad;
                            $analiz['hatali']++; continue;
                        }
                    }

                    $birim = strtoupper($get('birim') ?: 'MT');
                    $doviz = strtoupper($get('doviz') ?: 'TL');
                    $aktif_str = $get('aktif');
                    $aktif = ($aktif_str === '' || $aktif_str === '1' || strtolower($aktif_str) === 'true' || strtolower($aktif_str) === 'evet') ? 1 : 0;

                    $parse_decimal = function ($v) {
                        $v = trim((string)$v);
                        if ($v === '') return null;
                        $v = str_replace([',', ' '], ['.', ''], $v);
                        return is_numeric($v) ? (float)$v : null;
                    };

                    $kayit = [
                        'stok_kodu'      => $kod,
                        'ad'             => $get('ad') ?: $kod,
                        'ana_grup_id'    => $ana_grup_id,
                        'firma_id'       => $firma_id,
                        'iskonto_grup_id'=> $isk_grup_id,
                        'birim'          => $birim,
                        'doviz'          => $doviz,
                        'mt_kg_fiyati'   => $parse_decimal($get('mt_kg_fiyati')),
                        'boy_fiyati'     => $parse_decimal($get('boy_fiyati')),
                        'kg_per_mt'      => $parse_decimal($get('kg_per_mt')),
                        'boy_uzunluk'    => $get('boy_uzunluk') === '' ? null : (int)$parse_decimal($get('boy_uzunluk')),
                        'aktif'          => $aktif,
                        'satir_no'       => $satir_no,
                    ];

                    // Yeni mi güncelleme mi?
                    $key = tr_norm($kod);
                    if (isset($mevcut_stoklar[$key])) {
                        $kayit['_islem'] = 'guncelle';
                        $kayit['_id']    = $mevcut_stoklar[$key];
                        $analiz['guncellenecek']++;
                    } else {
                        // Yeni stok için iskonto grubu, ana grup zorunlu
                        if ($isk_grup_id === null) {
                            $hatalar[] = "Satır $satir_no: Yeni stok için 'İskonto Grubu' zorunlu";
                            $analiz['hatali']++; continue;
                        }
                        if ($ana_grup_id === null) {
                            $hatalar[] = "Satır $satir_no: Yeni stok için 'Ana Grup' zorunlu";
                            $analiz['hatali']++; continue;
                        }
                        $kayit['_islem'] = 'ekle';
                        $analiz['yeni']++;
                    }
                    $islenmis[] = $kayit;
                }

                // Session'a kaydet (onay adımına geçecek)
                $_SESSION['stok_import'] = [
                    'islenmis'  => $islenmis,
                    'analiz'    => $analiz,
                    'hatalar'   => $hatalar,
                    'dosya_adi' => $orjinal,
                    'tarih'     => time(),
                ];

                header('Location: /admin/stok_import.php?adim=2');
                exit;
            } catch (Exception $e) {
                $err = 'Hata: ' . $e->getMessage();
            }
        }
    }
}

// === ADIM 3: Onay → DB'ye yaz ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'onayla') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } elseif (empty($_SESSION['stok_import']['islenmis'])) {
        $err = 'Önizleme verisi bulunamadı. Yeniden yükleyin.';
    } else {
        $islenmis = $_SESSION['stok_import']['islenmis'];
        try {
            $db->beginTransaction();
            $st_upd = $db->prepare(
                'UPDATE tk_stoklar SET ad=?, ana_grup_id=COALESCE(?, ana_grup_id), 
                 firma_id=COALESCE(?, firma_id), iskonto_grup_id=COALESCE(?, iskonto_grup_id),
                 birim=?, doviz=?, mt_kg_fiyati=?, boy_fiyati=?, kg_per_mt=?, 
                 boy_uzunluk=?, aktif=? WHERE id=?'
            );
            $st_ins = $db->prepare(
                'INSERT INTO tk_stoklar (stok_kodu, ad, ana_grup_id, firma_id, iskonto_grup_id,
                 birim, doviz, mt_kg_fiyati, boy_fiyati, kg_per_mt, boy_uzunluk, aktif)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            );

            $eklendi = 0; $guncellendi = 0;
            foreach ($islenmis as $k) {
                if ($k['_islem'] === 'guncelle') {
                    $st_upd->execute([
                        $k['ad'], $k['ana_grup_id'], $k['firma_id'], $k['iskonto_grup_id'],
                        $k['birim'], $k['doviz'], $k['mt_kg_fiyati'], $k['boy_fiyati'],
                        $k['kg_per_mt'], $k['boy_uzunluk'], $k['aktif'], $k['_id']
                    ]);
                    $guncellendi++;
                } else {
                    $st_ins->execute([
                        $k['stok_kodu'], $k['ad'], $k['ana_grup_id'], $k['firma_id'],
                        $k['iskonto_grup_id'], $k['birim'], $k['doviz'],
                        $k['mt_kg_fiyati'], $k['boy_fiyati'], $k['kg_per_mt'],
                        $k['boy_uzunluk'], $k['aktif']
                    ]);
                    $eklendi++;
                }
            }
            $db->commit();
            unset($_SESSION['stok_import']);
            $msg = "İşlem tamamlandı: $eklendi yeni stok eklendi, $guncellendi stok güncellendi.";
            $adim = 1;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            $err = 'İşlem başarısız: ' . $e->getMessage();
        }
    }
}

// === ADIM 2 görüntüleme ===
if ($adim === 2 && empty($_SESSION['stok_import']['islenmis']) && empty($_SESSION['stok_import']['hatalar'])) {
    header('Location: /admin/stok_import.php?adim=1');
    exit;
}

ob_start();
?>
<div class="page-head">
    <h1>Stok Excel İçe Aktarma</h1>
    <a href="/admin/stoklar.php" class="btn">← Stok Listesi</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<?php if ($adim === 1): ?>
    <div class="alert alert-info">
        <strong>Nasıl Kullanılır:</strong>
        <ol style="margin:8px 0 0 20px;">
            <li>Önce <a href="/admin/stok_export.php?format=xlsx"><strong>Excel olarak indir</strong></a> ile mevcut stokları indirin (şablon olarak kullanın).</li>
            <li>Excel'de düzenleyin: fiyatları güncelleyin, yeni satır ekleyin (yeni stok kodu = yeni stok).</li>
            <li>Düzenlenmiş dosyayı aşağıdan yükleyin.</li>
            <li>Önizlemeyi kontrol edip onaylayın.</li>
        </ol>
    </div>

    <div class="layout-2col">
        <div class="panel">
            <div class="panel-head">📥 Excel Şablon İndir</div>
            <div class="panel-body">
                <p>Mevcut tüm stokları içeren bir Excel dosyası indirin. Bunu şablon olarak kullanabilirsiniz.</p>
                <a href="/admin/stok_export.php?format=xlsx" class="btn btn-primary">📊 Excel (XLSX) İndir</a>
                <a href="/admin/stok_export.php?format=csv" class="btn">📄 CSV İndir</a>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">📤 Excel Yükle</div>
            <div class="panel-body">
                <form method="post" enctype="multipart/form-data">
                    <?=csrf_field()?>
                    <input type="hidden" name="islem" value="yukle">
                    <div class="form-group">
                        <label>Dosya Seçin (.xlsx veya .csv, max 10 MB)</label>
                        <input type="file" name="dosya" class="form-control" accept=".xlsx,.csv" required>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Yükle ve Önizlemeyi Göster</button>
                </form>
            </div>
        </div>
    </div>

    <div class="panel" style="margin-top:16px;">
        <div class="panel-head">📋 Beklenen Sütun Başlıkları</div>
        <div class="panel-body">
            <p><strong>Zorunlu:</strong> Stok Kodu</p>
            <p><strong>Yeni stok için zorunlu:</strong> Ana Grup, Firma (kod veya ad), İskonto Grubu</p>
            <p><strong>Opsiyonel:</strong> Ürün Adı, Birim, Döviz, MT/KG Fiyatı, Boy Fiyatı, KG/MT, Boy (m), Aktif</p>
            <p style="font-size:12px;color:var(--c-text-muted);margin-top:10px;">
                Anahtar: <strong>Stok Kodu</strong>. Mevcut bir stok kodu için satır gelirse <strong>güncellenir</strong>; yeni bir kod için <strong>eklenir</strong>.
            </p>
        </div>
    </div>

<?php elseif ($adim === 2): 
    $a = $_SESSION['stok_import']['analiz'];
    $hatalar = $_SESSION['stok_import']['hatalar'];
    $islenmis = $_SESSION['stok_import']['islenmis'];
?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Toplam Satır</div>
            <div class="stat-value"><?=$a['toplam']?></div>
        </div>
        <div class="stat-card" style="border-left:4px solid var(--c-success);">
            <div class="stat-label">Yeni Eklenecek</div>
            <div class="stat-value" style="color:var(--c-success);"><?=$a['yeni']?></div>
        </div>
        <div class="stat-card" style="border-left:4px solid var(--c-info);">
            <div class="stat-label">Güncellenecek</div>
            <div class="stat-value" style="color:var(--c-info);"><?=$a['guncellenecek']?></div>
        </div>
        <div class="stat-card" style="border-left:4px solid var(--c-danger);">
            <div class="stat-label">Hatalı (Atlanacak)</div>
            <div class="stat-value" style="color:var(--c-danger);"><?=$a['hatali']?></div>
        </div>
    </div>

    <?php if ($hatalar): ?>
    <div class="panel" style="margin-bottom:16px;border-left:4px solid var(--c-danger);">
        <div class="panel-head" style="background:var(--c-danger-bg);color:var(--c-danger);">
            ⚠ Hatalı Satırlar (<?=count($hatalar)?>) — Atlanacak
        </div>
        <div class="panel-body" style="max-height:200px;overflow-y:auto;font-family:var(--font-mono);font-size:12px;">
            <?php foreach (array_slice($hatalar, 0, 100) as $hatasi): ?>
                <div>• <?=h($hatasi)?></div>
            <?php endforeach ?>
            <?php if (count($hatalar) > 100): ?>
                <div style="margin-top:8px;color:var(--c-text-muted);">... ve <?=count($hatalar)-100?> hata daha</div>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <?php if ($islenmis): ?>
    <div class="panel" style="margin-bottom:16px;">
        <div class="panel-head">İlk 20 Satır Önizleme</div>
        <div class="panel-body" style="padding:0;overflow-x:auto;">
            <table class="data-table" style="border:none;border-radius:0;font-size:12px;">
                <thead>
                    <tr>
                        <th>İşlem</th>
                        <th>Stok Kodu</th>
                        <th>Ürün Adı</th>
                        <th>Firma</th>
                        <th>Grup</th>
                        <th>Birim</th>
                        <th class="num">MT/KG Fiyat</th>
                        <th class="num">Boy Fiyat</th>
                        <th>Aktif</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($islenmis, 0, 20) as $k): 
                    $firma_ad = '';
                    foreach ($firmalar as $ad => $id) if ($id == $k['firma_id']) { $firma_ad = $ad; break; }
                    $grup_ad = '';
                    foreach ($gruplar as $ad => $id) if ($id == $k['iskonto_grup_id']) { $grup_ad = $ad; break; }
                ?>
                    <tr>
                        <td>
                            <?php if ($k['_islem']==='ekle'): ?>
                                <span class="tag-status tag-onaylandi">YENİ</span>
                            <?php else: ?>
                                <span class="tag-status tag-beklemede">GÜNCELLE</span>
                            <?php endif ?>
                        </td>
                        <td class="text-mono"><?=h($k['stok_kodu'])?></td>
                        <td><?=h($k['ad'])?></td>
                        <td><?=h(strtoupper($firma_ad))?></td>
                        <td><?=h($grup_ad)?></td>
                        <td class="text-mono"><?=h($k['birim'])?></td>
                        <td class="num"><?=$k['mt_kg_fiyati'] !== null ? num($k['mt_kg_fiyati'], 4) : '-'?></td>
                        <td class="num"><?=$k['boy_fiyati'] !== null ? num($k['boy_fiyati'], 4) : '-'?></td>
                        <td><?=$k['aktif'] ? '✓' : '✗'?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif ?>

    <?php if ($a['yeni'] + $a['guncellenecek'] > 0): ?>
    <div class="panel" style="border-left:4px solid var(--c-primary);">
        <div class="panel-body">
            <form method="post" onsubmit="return confirm('<?=$a['yeni']+$a['guncellenecek']?> kayıt veritabanına yazılacak. Devam edilsin mi?');">
                <?=csrf_field()?>
                <input type="hidden" name="islem" value="onayla">
                <div style="display:flex;gap:12px;align-items:center;justify-content:space-between;">
                    <div>
                        <strong style="font-size:16px;">
                            <?=$a['yeni']+$a['guncellenecek']?> kayıt veritabanına yazılacak
                        </strong>
                        <br><small class="text-muted">Dosya: <?=h($_SESSION['stok_import']['dosya_adi'] ?? '')?></small>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <a href="/admin/stok_import.php?adim=1" class="btn">İptal / Yeniden Yükle</a>
                        <button type="submit" class="btn btn-primary">✓ Onayla ve Kaydet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning">
        Yazılacak geçerli kayıt yok. Tüm satırlar hatalı veya boş.
        <br><a href="/admin/stok_import.php?adim=1">← Yeniden yükle</a>
    </div>
    <?php endif ?>

<?php endif ?>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
