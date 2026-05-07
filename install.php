<?php
/**
 * TEKCAN SATIŞ - Web Kurulum Yardımcısı
 *
 * Bu dosya yalnızca ilk kurulum içindir. Kurulum sonrası SİLİNMELİDİR.
 */

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Kurulum tamamlandıysa engelle
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $count = (int)$pdo->query('SELECT COUNT(*) FROM tk_users')->fetchColumn();
        if ($count > 0) {
            die('
            <!DOCTYPE html><html lang="tr"><head><meta charset="utf-8"><title>Kurulum Yapılmış</title>
            <style>body{font-family:sans-serif;max-width:600px;margin:60px auto;padding:20px;background:#f5f6f8}
            .box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:30px}
            h1{color:#c8102e}a{color:#c8102e}</style></head>
            <body><div class="box">
            <h1>⚠ Kurulum Zaten Yapılmış</h1>
            <p>Sistem zaten kurulu görünüyor. Bu dosyayı sunucudan <strong>silmelisiniz</strong>.</p>
            <p><a href="/login.php">→ Giriş sayfasına git</a></p>
            </div></body></html>');
        }
    } catch (Exception $e) {
        // DB henüz hazır değilse devam et
    }
}

$step = (int)($_GET['step'] ?? 1);
$err = null; $msg = null;

ob_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form 1: config oluştur
    if (($_POST['islem'] ?? '') === 'olustur_config') {
        $db_host = trim($_POST['db_host'] ?? 'localhost');
        $db_port = (int)($_POST['db_port'] ?? 3306);
        $db_name = trim($_POST['db_name'] ?? '');
        $db_user = trim($_POST['db_user'] ?? '');
        $db_pass = $_POST['db_pass'] ?? '';

        try {
            // Bağlantı testi
            $test_dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db_host, $db_port, $db_name);
            $test_pdo = new PDO($test_dsn, $db_user, $db_pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            $secret = bin2hex(random_bytes(32));
            $cron_secret = bin2hex(random_bytes(16));

            $config = "<?php\n";
            $config .= "// TEKCAN SATIŞ - Yapılandırma\n";
            $config .= "// Otomatik oluşturuldu: " . date('Y-m-d H:i:s') . "\n\n";
            $config .= "const APP_NAME    = 'Tekcan Satış Ekranı';\n";
            $config .= "const APP_DEBUG   = false;\n";
            $config .= "const APP_TIMEZONE= 'Europe/Istanbul';\n";
            $config .= "const APP_SECRET  = " . var_export($secret, true) . ";\n";
            $config .= "const SESSION_LIFETIME = 28800;\n\n";
            $config .= "const DB_HOST    = " . var_export($db_host, true) . ";\n";
            $config .= "const DB_PORT    = $db_port;\n";
            $config .= "const DB_NAME    = " . var_export($db_name, true) . ";\n";
            $config .= "const DB_USER    = " . var_export($db_user, true) . ";\n";
            $config .= "const DB_PASS    = " . var_export($db_pass, true) . ";\n";
            $config .= "const DB_CHARSET = 'utf8mb4';\n\n";
            $config .= "const CRON_SECRET = " . var_export($cron_secret, true) . ";\n";
            $config .= "const TCMB_URL    = 'https://www.tcmb.gov.tr/kurlar/today.xml';\n";

            if (file_put_contents(__DIR__ . '/config.php', $config) === false) {
                throw new Exception('config.php yazılamadı. Klasör yazma izni eksik olabilir.');
            }

            // Şema yükle
            $schema = file_get_contents(__DIR__ . '/sql/01_schema.sql');
            $test_pdo->exec($schema);

            // Seed yükle
            $seed = file_get_contents(__DIR__ . '/sql/02_seed.sql');
            $test_pdo->exec($seed);

            header('Location: install.php?step=3&cron=' . urlencode($cron_secret));
            exit;
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Tekcan Satış - Kurulum</title>
<link rel="stylesheet" href="/assets/css/app.css">
<style>
body { background: #1a1d23; min-height: 100vh; padding: 40px 20px; }
.install-card { max-width: 640px; margin: 0 auto; background: #fff; border-radius: 10px; padding: 40px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); border-top: 4px solid #c8102e; }
.steps { display: flex; gap: 8px; margin-bottom: 30px; }
.step { flex: 1; padding: 10px; text-align: center; background: #f5f6f8; border-radius: 6px; font-size: 12px; font-weight: 600; color: #6b7280; }
.step.active { background: #c8102e; color: #fff; }
.step.done { background: #15803d; color: #fff; }
h1 { margin-top: 0; color: #1a1d23; }
.warning { background: #fef3c7; border: 1px solid #fcd34d; color: #b45309; padding: 14px; border-radius: 6px; margin-bottom: 20px; }
.success { background: #dcfce7; border: 1px solid #86efac; color: #15803d; padding: 14px; border-radius: 6px; margin-bottom: 20px; }
code { background: #f5f6f8; padding: 2px 6px; border-radius: 4px; font-family: 'JetBrains Mono', monospace; font-size: 12px; }
pre { background: #1a1d23; color: #fff; padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; }
</style>
</head>
<body>
<div class="install-card">
    <div class="steps">
        <div class="step <?=$step==1?'active':($step>1?'done':'')?>">1. Hoşgeldiniz</div>
        <div class="step <?=$step==2?'active':($step>2?'done':'')?>">2. Veritabanı</div>
        <div class="step <?=$step==3?'active':''?>">3. Tamamlandı</div>
    </div>

    <?php if ($err): ?><div class="alert alert-danger"><?=h($err)?></div><?php endif ?>

    <?php if ($step == 1): ?>
        <h1>🚀 Kuruluma Hoşgeldiniz</h1>
        <p>Tekcan Satış Ekranı kurulumu için aşağıdaki adımları takip edeceksiniz:</p>
        <ol>
            <li>Veritabanı bağlantı bilgilerinizi girin</li>
            <li>Sistem otomatik olarak <code>config.php</code> oluşturup şemayı yüklesin</li>
            <li>Yönetici girişi ile başlayın</li>
        </ol>
        <div class="warning">
            <strong>⚠ Hazırlanması gerekenler:</strong>
            <ul>
                <li>Boş bir veritabanı (utf8mb4 charset)</li>
                <li>Veritabanı kullanıcı adı ve parolası</li>
                <li>Web sunucusunun bu klasöre yazma izni</li>
            </ul>
        </div>
        <a href="?step=2" class="btn btn-primary btn-block">Devam Et →</a>

    <?php elseif ($step == 2): ?>
        <h1>🗄 Veritabanı Bilgileri</h1>
        <form method="post">
            <input type="hidden" name="islem" value="olustur_config">
            <div class="form-group">
                <label>MySQL Sunucu</label>
                <input type="text" name="db_host" class="form-control" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="db_port" class="form-control" value="3306" required>
            </div>
            <div class="form-group">
                <label>Veritabanı Adı *</label>
                <input type="text" name="db_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Kullanıcı Adı *</label>
                <input type="text" name="db_user" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Parola</label>
                <input type="password" name="db_pass" class="form-control">
            </div>
            <button type="submit" class="btn btn-primary btn-block">Kur ve Başlat →</button>
        </form>

    <?php elseif ($step == 3): ?>
        <h1>✓ Kurulum Tamamlandı</h1>
        <div class="success">
            <strong>Sistem başarıyla kuruldu!</strong> Veritabanı şeması ve örnek veriler yüklendi.
        </div>

        <p><strong>Varsayılan giriş bilgileri:</strong></p>
        <ul>
            <li>Kullanıcı: <code>admin</code></li>
            <li>Parola: <code>admin123</code></li>
        </ul>
        <div class="warning">
            <strong>⚠ ZORUNLU GÜVENLİK ADIMLARI:</strong>
            <ol>
                <li>İlk girişten sonra parolanızı değiştirin (Yönetim → Personel Yönetimi)</li>
                <li><strong><code>install.php</code> dosyasını sunucudan silin!</strong></li>
                <li>Aşağıdaki cron komutunu hosting panelinden ekleyin:</li>
            </ol>
            <pre>30 16 * * 1-5  wget -q -O- "https://<?=h($_SERVER['HTTP_HOST'] ?? 'siteniz.com')?>/cron/kur_guncelle.php?key=<?=h($_GET['cron'] ?? '')?>" &gt;/dev/null 2&gt;&amp;1</pre>
        </div>

        <a href="/login.php" class="btn btn-primary btn-block">Giriş Sayfasına Git →</a>
    <?php endif ?>
</div>
</body>
</html>
