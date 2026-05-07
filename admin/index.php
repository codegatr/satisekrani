<?php
/**
 * ADMIN - Dashboard
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();

// İstatistikler
$stats = [
    'kullanici'   => (int)$db->query('SELECT COUNT(*) FROM tk_users WHERE aktif=1')->fetchColumn(),
    'stok'        => (int)$db->query('SELECT COUNT(*) FROM tk_stoklar WHERE aktif=1')->fetchColumn(),
    'firma'       => (int)$db->query('SELECT COUNT(*) FROM tk_firmalar')->fetchColumn(),
    'iskonto_grup'=> (int)$db->query('SELECT COUNT(*) FROM tk_iskonto_gruplar')->fetchColumn(),
    'fiyat_kalem' => (int)$db->query('SELECT COUNT(*) FROM tk_fiyat_listesi WHERE aktif=1')->fetchColumn(),
    'kategori'    => (int)$db->query('SELECT COUNT(DISTINCT kategori_kod) FROM tk_fiyat_listesi WHERE aktif=1')->fetchColumn(),
];

$kur_usd = kur_get('USD');
$kur_eur = kur_get('EUR');

$pageTitle = 'Yönetim Paneli';
ob_start();
?>
<div class="page-head">
    <h1>Yönetim Paneli</h1>
    <div class="text-muted text-mono">Hoşgeldiniz, <?=h(current_user()['ad_soyad'])?></div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Aktif Personel</div>
        <div class="stat-value"><?=$stats['kullanici']?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Aktif Stok</div>
        <div class="stat-value"><?=number_format($stats['stok'], 0, ',', '.')?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Tedarikçi Firma</div>
        <div class="stat-value"><?=$stats['firma']?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">İskonto Grubu</div>
        <div class="stat-value"><?=$stats['iskonto_grup']?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Fiyat Kalem</div>
        <div class="stat-value"><?=$stats['fiyat_kalem']?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Fiyat Kategorisi</div>
        <div class="stat-value"><?=$stats['kategori']?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">USD Kur</div>
        <div class="stat-value"><?=num((float)$kur_usd['satis'], 4)?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">EUR Kur</div>
        <div class="stat-value"><?=num((float)$kur_eur['satis'], 4)?></div>
    </div>
</div>

<div class="page-head" style="margin-top:30px;border-bottom:none;">
    <h1 style="font-size:18px;">Yönetim Modülleri</h1>
</div>

<div class="admin-grid">
    <a href="/admin/users.php" class="admin-card">
        <div class="ac-icon">◉</div>
        <div class="ac-title">Personel Yönetimi</div>
        <div class="ac-desc">Personel ekle, düzenle, parola sıfırla</div>
    </a>
    <a href="/admin/fiyat_listesi.php" class="admin-card" style="border-left-color:#1e3a8a;">
        <div class="ac-icon" style="color:#1e3a8a;">₺</div>
        <div class="ac-title">Fiyat Listesi</div>
        <div class="ac-desc">Excel'in birebir karşılığı - kategori bazlı TL/USD düzenleme</div>
    </a>
    <a href="/admin/iskontolar.php" class="admin-card">
        <div class="ac-icon">%</div>
        <div class="ac-title">İskonto Oranları</div>
        <div class="ac-desc">Firma bazlı iskonto oranlarını düzenle</div>
    </a>
    <a href="/admin/stoklar.php" class="admin-card">
        <div class="ac-icon">▦</div>
        <div class="ac-title">Stok Listesi</div>
        <div class="ac-desc">Tüm stokları görüntüle, düzenle ve Excel ile içe aktar</div>
    </a>
    <a href="/admin/ayarlar.php" class="admin-card">
        <div class="ac-icon">⚙</div>
        <div class="ac-title">Sistem Ayarları</div>
        <div class="ac-desc">KDV, vade farkı, döviz tipi vb.</div>
    </a>
    <a href="/admin/kur_log.php" class="admin-card">
        <div class="ac-icon">$</div>
        <div class="ac-title">Döviz Kur Geçmişi</div>
        <div class="ac-desc">TCMB güncelleme logları</div>
    </a>
    <a href="/admin/guncelleme.php" class="admin-card" style="border-left-color:var(--c-success);">
        <div class="ac-icon" style="color:var(--c-success);">⬇</div>
        <div class="ac-title">Akıllı Güncelle</div>
        <div class="ac-desc">GitHub'dan yeni sürüm kontrol ve indir</div>
    </a>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
