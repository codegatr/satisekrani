<?php
/**
 * ADMIN - Personel (Kullanıcı) Yönetimi
 */
require __DIR__ . '/../inc/bootstrap.php';
require_admin();

$db = db();
$msg = null; $err = null;

// İşlem
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? null)) {
        $err = 'Oturum doğrulaması başarısız. Sayfayı yenileyin.';
    } else {
        $islem = $_POST['islem'] ?? '';
        try {
            if ($islem === 'ekle') {
                $kul   = trim($_POST['kullanici_adi'] ?? '');
                $ad    = trim($_POST['ad_soyad'] ?? '');
                $eposta= trim($_POST['email'] ?? '');
                $rol   = ($_POST['rol'] ?? 'personel') === 'admin' ? 'admin' : 'personel';
                $sifre = $_POST['sifre'] ?? '';
                if (mb_strlen($kul) < 3 || mb_strlen($ad) < 2 || mb_strlen($sifre) < 6) {
                    throw new Exception('Kullanıcı adı/parola/ad soyad bilgileri eksik veya çok kısa.');
                }
                $st = $db->prepare('INSERT INTO tk_users (kullanici_adi, ad_soyad, email, parola_hash, rol, aktif) VALUES (?,?,?,?,?,1)');
                $st->execute([$kul, $ad, $eposta, password_hash($sifre, PASSWORD_BCRYPT), $rol]);
                $msg = 'Personel başarıyla eklendi.';
            }
            elseif ($islem === 'guncelle') {
                $id = (int)($_POST['id'] ?? 0);
                $ad = trim($_POST['ad_soyad'] ?? '');
                $eposta = trim($_POST['email'] ?? '');
                $rol = ($_POST['rol'] ?? 'personel') === 'admin' ? 'admin' : 'personel';
                $aktif = !empty($_POST['aktif']) ? 1 : 0;
                $st = $db->prepare('UPDATE tk_users SET ad_soyad=?, email=?, rol=?, aktif=? WHERE id=?');
                $st->execute([$ad, $eposta, $rol, $aktif, $id]);
                $msg = 'Personel bilgileri güncellendi.';
            }
            elseif ($islem === 'sifre_sifirla') {
                $id = (int)($_POST['id'] ?? 0);
                $yeni = $_POST['yeni_sifre'] ?? '';
                if (mb_strlen($yeni) < 6) throw new Exception('Yeni parola en az 6 karakter olmalı.');
                $st = $db->prepare('UPDATE tk_users SET parola_hash=? WHERE id=?');
                $st->execute([password_hash($yeni, PASSWORD_BCRYPT), $id]);
                $msg = 'Parola sıfırlandı.';
            }
        } catch (Exception $e) {
            $err = 'Hata: ' . $e->getMessage();
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $err = 'Bu kullanıcı adı zaten kullanılıyor.';
            } else {
                $err = 'Veritabanı hatası: ' . $e->getMessage();
            }
        }
    }
}

$users = $db->query('SELECT * FROM tk_users ORDER BY rol DESC, ad_soyad ASC')->fetchAll();

ob_start();
?>
<div class="page-head">
    <h1>Personel Yönetimi</h1>
    <a href="/admin/" class="btn">← Yönetim</a>
</div>

<?php if ($msg): ?><div class="alert alert-success"><?=h($msg)?></div><?php endif ?>
<?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

<div class="layout-2col">
    <div class="panel">
        <div class="panel-head">Mevcut Personeller <span class="badge"><?=count($users)?></span></div>
        <div class="panel-body" style="padding:0;">
            <table class="data-table" style="border:none;border-radius:0;">
                <thead>
                    <tr>
                        <th>Kullanıcı</th>
                        <th>Ad Soyad</th>
                        <th>Rol</th>
                        <th>Durum</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td class="text-mono"><?=h($u['kullanici_adi'])?></td>
                        <td><?=h($u['ad_soyad'])?>
                            <?php if ($u['email']): ?><br><small class="text-muted"><?=h($u['email'])?></small><?php endif ?>
                        </td>
                        <td>
                            <?php if ($u['rol']==='admin'): ?>
                                <span class="tag-status tag-onaylandi">YÖNETİCİ</span>
                            <?php else: ?>
                                <span class="tag-status tag-beklemede">PERSONEL</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <?php if ($u['aktif']): ?>
                                <span style="color:var(--c-success);">● Aktif</span>
                            <?php else: ?>
                                <span style="color:var(--c-danger);">● Pasif</span>
                            <?php endif ?>
                        </td>
                        <td>
                            <a href="?duzenle=<?=$u['id']?>" class="btn" style="padding:4px 10px;font-size:12px;">Düzenle</a>
                        </td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <?php
        $duzenle_id = (int)($_GET['duzenle'] ?? 0);
        $du = null;
        if ($duzenle_id) {
            $st = $db->prepare('SELECT * FROM tk_users WHERE id=?');
            $st->execute([$duzenle_id]);
            $du = $st->fetch();
        }
        ?>
        <?php if ($du): ?>
            <div class="panel-head">Düzenle: <?=h($du['ad_soyad'])?></div>
            <div class="panel-body">
                <form method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="islem" value="guncelle">
                    <input type="hidden" name="id" value="<?=$du['id']?>">
                    <div class="form-group">
                        <label>Kullanıcı Adı</label>
                        <input type="text" class="form-control" value="<?=h($du['kullanici_adi'])?>" disabled>
                    </div>
                    <div class="form-group">
                        <label>Ad Soyad</label>
                        <input type="text" name="ad_soyad" class="form-control" value="<?=h($du['ad_soyad'])?>" required>
                    </div>
                    <div class="form-group">
                        <label>E-posta</label>
                        <input type="email" name="email" class="form-control" value="<?=h($du['email'])?>">
                    </div>
                    <div class="form-group">
                        <label>Rol</label>
                        <select name="rol" class="form-control">
                            <option value="personel" <?=$du['rol']==='personel'?'selected':''?>>Personel</option>
                            <option value="admin" <?=$du['rol']==='admin'?'selected':''?>>Yönetici</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><input type="checkbox" name="aktif" value="1" <?=$du['aktif']?'checked':''?>> Aktif</label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Kaydet</button>
                </form>

                <hr style="margin:20px 0;border:none;border-top:1px solid var(--c-border);">

                <form method="post" onsubmit="return confirm('Parolayı sıfırlamak istediğinize emin misiniz?');">
                    <?=csrf_field()?>
                    <input type="hidden" name="islem" value="sifre_sifirla">
                    <input type="hidden" name="id" value="<?=$du['id']?>">
                    <div class="form-group">
                        <label>Yeni Parola (en az 6 karakter)</label>
                        <input type="text" name="yeni_sifre" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-danger btn-block">Parolayı Sıfırla</button>
                </form>

                <a href="/admin/users.php" class="btn btn-block" style="margin-top:10px;">İptal / Yeni Kayıt</a>
            </div>
        <?php else: ?>
            <div class="panel-head">Yeni Personel Ekle</div>
            <div class="panel-body">
                <form method="post">
                    <?=csrf_field()?>
                    <input type="hidden" name="islem" value="ekle">
                    <div class="form-group">
                        <label>Kullanıcı Adı *</label>
                        <input type="text" name="kullanici_adi" class="form-control" required minlength="3" pattern="[a-zA-Z0-9_.-]+">
                    </div>
                    <div class="form-group">
                        <label>Ad Soyad *</label>
                        <input type="text" name="ad_soyad" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>E-posta</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Rol</label>
                        <select name="rol" class="form-control">
                            <option value="personel">Personel</option>
                            <option value="admin">Yönetici</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Parola (en az 6 karakter) *</label>
                        <input type="text" name="sifre" class="form-control" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Personeli Ekle</button>
                </form>
            </div>
        <?php endif ?>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/../inc/header.php';
echo $content;
require __DIR__ . '/../inc/footer.php';
