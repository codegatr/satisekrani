<?php
ob_start();
require __DIR__ . '/inc/bootstrap.php';
session_start_secure();

if (user_logged_in()) {
    header('Location: /index.php');
    exit;
}

$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['_csrf'] ?? '')) {
        $hata = 'Geçersiz oturum, sayfayı yenileyin.';
    } else {
        $kullanici = trim($_POST['kullanici'] ?? '');
        $parola    = $_POST['parola']   ?? '';

        if ($kullanici === '' || $parola === '') {
            $hata = 'Kullanıcı adı ve parola zorunludur.';
        } else {
            $st = db()->prepare(
                'SELECT * FROM tk_users WHERE kullanici_adi=? AND aktif=1 LIMIT 1'
            );
            $st->execute([$kullanici]);
            $u = $st->fetch();

            if ($u && password_verify($parola, $u['parola_hash'])) {
                // Başarılı
                session_regenerate_id(true);
                $_SESSION['user_id']    = (int)$u['id'];
                $_SESSION['user_ad']    = $u['ad_soyad'];
                $_SESSION['user_rol']   = $u['rol'];
                $_SESSION['login_time'] = time();

                db()->prepare('UPDATE tk_users SET son_giris=NOW() WHERE id=?')
                    ->execute([$u['id']]);

                db()->prepare(
                    'INSERT INTO tk_giris_log (kullanici_adi, ip, durum, user_agent) 
                     VALUES (?,?,?,?)'
                )->execute([$kullanici, ip_adresi(), 'basarili',
                            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

                header('Location: /index.php');
                exit;
            } else {
                db()->prepare(
                    'INSERT INTO tk_giris_log (kullanici_adi, ip, durum, user_agent) 
                     VALUES (?,?,?,?)'
                )->execute([$kullanici, ip_adresi(), 'basarisiz',
                            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
                $hata = 'Kullanıcı adı veya parola hatalı.';
                sleep(1); // brute-force soğutma
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Giriş - <?= h(APP_NAME) ?></title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="login-body">
<div class="login-card">
    <div class="login-logo">
        <h1>TEKCAN</h1>
        <small>Personel Satış Ekranı</small>
    </div>

    <?php if ($hata): ?>
    <div class="alert alert-danger"><?= h($hata) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="form-group">
            <label>Kullanıcı Adı</label>
            <input type="text" name="kullanici" required autofocus
                   value="<?= h($_POST['kullanici'] ?? '') ?>">
        </div>
        <div class="form-group">
            <label>Parola</label>
            <input type="password" name="parola" required>
        </div>
        <button type="submit" class="btn btn-primary btn-block">GİRİŞ YAP</button>
    </form>

    <div class="login-footer">
        <small>v<?= APP_VERSION ?> &middot; <?= date('Y') ?></small>
    </div>
</div>
</body>
</html>
<?php ob_end_flush(); ?>
