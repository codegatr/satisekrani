<?php
/**
 * ADMIN - Akıllı Güncelle (Smart Update)
 *
 * GitHub Releases tabanlı sürüm kontrol ve güncelleme.
 */
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/update_modul.php';
require_admin();

$db = db();
$msg = null; $err = null;
$check_sonuc = null;

$github_token = defined('GITHUB_TOKEN') ? GITHUB_TOKEN : null;

// İşlemler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız.';
    } else {
        $islem = $_POST['islem'] ?? '';
        try {
            if ($islem === 'kontrol') {
                $check_sonuc = update_check($github_token);
                $msg = $check_sonuc['guncelleme_var']
                    ? 'Yeni sürüm bulundu: ' . $check_sonuc['remote']
                    : 'Sisteminiz güncel (' . $check_sonuc['local'] . ').';
            }
            elseif ($islem === 'guncelle') {
                @set_time_limit(300);
                @ignore_user_abort(true);
                $sonuc = update_apply_now($github_token);
                if ($sonuc['basarili']) {
                    $msg = $sonuc['mesaj'];
                } else {
                    $err = $sonuc['mesaj'];
                }
            }
        } catch (Exception $e) {
            $err = 'Hata: ' . $e->getMessage();
        }
    }
}

// Mevcut manifest bilgisi
try {
    $manifest = update_local_manifest();
} catch (Exception $e) {
    $manifest = ['version' => '?', 'repo' => '?', 'name' => 'Tekcan Satış'];
    $err = $err ?: ('Manifest okuma hatası: ' . $e->getMessage());
}

// Geçmiş güncelleme logları
$loglar = $db->query(
    "SELECT l.*, u.kullanici_adi 
     FROM tk_update_log l 
     LEFT JOIN tk_users u ON u.id = l.kullanici_id 
     ORDER BY l.id DESC LIMIT 30"
)->fetchAll();

// İlk girişte otomatik kontrol et (sadece GET'te)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['nocheck']) && !empty($manifest['repo']) && $manifest['repo'] !== '?') {
    try {
        $check_sonuc = update_check($github_token);
    } catch (Exception $e) {
        $err = 'Sürüm kontrol edilemedi: ' . $e->getMessage();
    }
}

ob_start();
?>
<div class="page-head">
    <h1>🔄 Akıllı Güncelle</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="layout-2col">
    <!-- Sol: Sürüm bilgisi + güncelleme butonu -->
    <div>
        <div class="panel" style="margin-bottom:16px;">
            <div class="panel-head">📦 Sürüm Bilgisi</div>
            <div class="panel-body">
                <div class="opt-row">
                    <span class="text-muted">Mevcut Sürüm:</span>
                    <strong style="font-size:18px;color:var(--c-primary);">v<?=h($manifest['version'] ?? '?')?></strong>
                </div>
                <div class="opt-row">
                    <span class="text-muted">Uygulama:</span>
                    <strong><?=h($manifest['name'] ?? 'Tekcan Satış')?></strong>
                </div>
                <div class="opt-row">
                    <span class="text-muted">GitHub Repo:</span>
                    <a href="https://github.com/<?=h($manifest['repo'] ?? '')?>" target="_blank" class="text-mono"><?=h($manifest['repo'] ?? '?')?></a>
                </div>
                <div class="opt-row">
                    <span class="text-muted">Min. PHP:</span>
                    <strong><?=h($manifest['min_php'] ?? '?')?> (Mevcut: <?=PHP_VERSION?>)</strong>
                </div>
                <div class="opt-row">
                    <span class="text-muted">GitHub Token:</span>
                    <strong style="color:<?=$github_token ? 'var(--c-success)' : 'var(--c-warning)'?>;">
                        <?=$github_token ? '● Tanımlı' : '○ Yok (public repo için sorun değil)'?>
                    </strong>
                </div>
            </div>
        </div>

        <?php if ($check_sonuc): ?>
            <?php if ($check_sonuc['guncelleme_var']): ?>
                <div class="panel" style="border-left:4px solid var(--c-success);">
                    <div class="panel-head" style="background:var(--c-success-bg);color:var(--c-success);">
                        🎉 Yeni Sürüm Mevcut: <?=h($check_sonuc['remote'])?>
                    </div>
                    <div class="panel-body">
                        <div class="opt-row">
                            <span class="text-muted">Sizin sürümünüz:</span>
                            <strong>v<?=h($check_sonuc['local'])?></strong>
                        </div>
                        <div class="opt-row">
                            <span class="text-muted">Yeni sürüm:</span>
                            <strong style="color:var(--c-success);"><?=h($check_sonuc['remote'])?></strong>
                        </div>
                        <?php if ($check_sonuc['release_tarih']): ?>
                        <div class="opt-row">
                            <span class="text-muted">Yayın tarihi:</span>
                            <strong class="text-mono"><?=h(date('d.m.Y H:i', strtotime($check_sonuc['release_tarih'])))?></strong>
                        </div>
                        <?php endif ?>
                        <?php if ($check_sonuc['release_url']): ?>
                        <div class="opt-row">
                            <span class="text-muted">GitHub:</span>
                            <a href="<?=h($check_sonuc['release_url'])?>" target="_blank">Release sayfası ↗</a>
                        </div>
                        <?php endif ?>

                        <?php if ($check_sonuc['release_notu']): ?>
                        <details style="margin-top:12px;">
                            <summary style="cursor:pointer;font-weight:600;color:var(--c-text);">📝 Release Notları</summary>
                            <pre style="background:var(--c-surface-2);padding:10px;border-radius:6px;font-size:12px;white-space:pre-wrap;margin-top:8px;max-height:200px;overflow-y:auto;"><?=h($check_sonuc['release_notu'])?></pre>
                        </details>
                        <?php endif ?>

                        <hr style="border:none;border-top:1px solid var(--c-border);margin:16px 0;">

                        <form method="post" onsubmit="return confirm('Sistem güncellenecek. Bu işlem birkaç dakika sürebilir ve devam ederken sayfayı kapatmayın. Emin misiniz?');">
                            <?=csrf_field()?>
                            <input type="hidden" name="islem" value="guncelle">
                            <button type="submit" class="btn btn-primary btn-block" style="font-size:15px;padding:12px;">
                                ⬇️ Şimdi Güncelle (<?=h($check_sonuc['local'])?> → <?=h($check_sonuc['remote'])?>)
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="panel" style="border-left:4px solid var(--c-info);">
                    <div class="panel-head" style="background:var(--c-info-bg);color:var(--c-info);">
                        ✓ Sisteminiz Güncel
                    </div>
                    <div class="panel-body">
                        <p>Mevcut sürümünüz (<strong>v<?=h($check_sonuc['local'])?></strong>) son sürümle eşit.</p>
                        <form method="post">
                            <?=csrf_field()?>
                            <input type="hidden" name="islem" value="kontrol">
                            <button type="submit" class="btn">↻ Tekrar Kontrol Et</button>
                        </form>
                    </div>
                </div>
            <?php endif ?>
        <?php else: ?>
            <div class="panel">
                <div class="panel-head">🔍 Sürüm Kontrol</div>
                <div class="panel-body">
                    <form method="post">
                        <?=csrf_field()?>
                        <input type="hidden" name="islem" value="kontrol">
                        <button type="submit" class="btn btn-primary btn-block">GitHub'dan Sürüm Kontrol Et</button>
                    </form>
                </div>
            </div>
        <?php endif ?>

        <div class="alert alert-info" style="margin-top:16px;font-size:12px;">
            <strong>💡 İpuçları:</strong>
            <ul style="margin:6px 0 0 18px;padding:0;">
                <li>Güncelleme öncesi mutlaka veritabanınızı yedekleyin</li>
                <li><code>config.php</code> dosyanız korunur, üzerine yazılmaz</li>
                <li>Private repo için <code>config.php</code>'ye <code>const GITHUB_TOKEN = 'ghp_...';</code> ekleyin</li>
                <li>Güncelleme sırasında diğer kullanıcılar sistemi kullanmasın</li>
            </ul>
        </div>
    </div>

    <!-- Sağ: Güncelleme geçmişi -->
    <div>
        <div class="panel">
            <div class="panel-head">📜 Güncelleme Geçmişi <span class="badge"><?=count($loglar)?></span></div>
            <div class="panel-body" style="padding:0;max-height:700px;overflow-y:auto;">
                <table class="data-table" style="border:none;border-radius:0;font-size:12px;">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Sürüm</th>
                            <th>Durum</th>
                            <th>Açıklama</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$loglar): ?>
                            <tr><td colspan="4" class="text-center text-muted" style="padding:30px;">Henüz güncelleme kaydı yok.</td></tr>
                        <?php else: ?>
                            <?php foreach ($loglar as $l): ?>
                            <tr>
                                <td class="text-mono" style="font-size:11px;"><?=h(date('d.m H:i', strtotime($l['tarih'])))?></td>
                                <td class="text-mono">
                                    <?=h($l['eski_surum'] ?? '?')?> → <strong><?=h($l['yeni_surum'] ?? '?')?></strong>
                                </td>
                                <td>
                                    <?php if ($l['durum'] === 'basarili'): ?>
                                        <span class="tag-status tag-onaylandi">OK</span>
                                    <?php elseif ($l['durum'] === 'hata'): ?>
                                        <span class="tag-status tag-iptal">HATA</span>
                                    <?php else: ?>
                                        <span class="tag-status tag-beklemede">…</span>
                                    <?php endif ?>
                                </td>
                                <td><small><?=h(mb_substr($l['aciklama'] ?? '', 0, 100, 'UTF-8'))?></small></td>
                            </tr>
                            <?php endforeach ?>
                        <?php endif ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
