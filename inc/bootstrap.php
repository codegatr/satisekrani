<?php
/**
 * TEKCAN SATIŞ - Bootstrap
 * Tüm sayfalardan ilk önce include edilir.
 */

// Hata raporlama
if (!file_exists(__DIR__ . '/../config.php')) {
    die('Yapılandırma dosyası bulunamadı. config.example.php → config.php olarak kopyalayın.');
}
require_once __DIR__ . '/../config.php';

// Sürüm sabiti — eski config.php'lerde olmayabilir
if (!defined('APP_VERSION')) {
    $mf = @file_get_contents(__DIR__ . '/../manifest.json');
    $ver = '1.0.0';
    if ($mf && ($j = json_decode($mf, true)) && !empty($j['version'])) {
        $ver = $j['version'];
    }
    define('APP_VERSION', $ver);
}

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

// === Veritabanı (PDO) ===
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_turkish_ci"
            ]);
        } catch (PDOException $e) {
            if (APP_DEBUG) {
                die('DB Hatası: ' . htmlspecialchars($e->getMessage()));
            }
            error_log('DB hatası: ' . $e->getMessage());
            http_response_code(500);
            die('Veritabanı bağlantı hatası. Lütfen sistem yöneticisi ile iletişime geçin.');
        }
    }
    return $pdo;
}

// === Session başlatıcı ===
function session_start_secure(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_name('TKSESS');
    session_start();
}

// === HMAC tabanlı CSRF Token ===
function csrf_token(): string {
    session_start_secure();
    if (empty($_SESSION['_csrf_seed'])) {
        $_SESSION['_csrf_seed'] = bin2hex(random_bytes(16));
    }
    return hash_hmac('sha256', $_SESSION['_csrf_seed'], APP_SECRET);
}

function csrf_check(?string $token): bool {
    if (!$token) return false;
    return hash_equals(csrf_token(), $token);
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

// === Auth helpers ===
function user_logged_in(): bool {
    session_start_secure();
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    if (!user_logged_in()) return null;
    static $u = null;
    if ($u === null) {
        $st = db()->prepare('SELECT * FROM tk_users WHERE id=? AND aktif=1 LIMIT 1');
        $st->execute([$_SESSION['user_id']]);
        $u = $st->fetch() ?: null;
    }
    return $u;
}

function require_login(): void {
    if (!user_logged_in()) {
        header('Location: /login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    $u = current_user();
    if (!$u || $u['rol'] !== 'admin') {
        http_response_code(403);
        die('Bu işlem için yetkiniz yok.');
    }
}

// === Helpers ===
function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tl(float $n, int $dec = 2): string {
    return number_format($n, $dec, ',', '.') . ' ₺';
}

function num(float $n, int $dec = 2): string {
    return number_format($n, $dec, ',', '.');
}

function ip_adresi(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ayar_get(string $anahtar, ?string $varsayilan = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT anahtar, deger FROM tk_ayarlar') as $r) {
            $cache[$r['anahtar']] = $r['deger'];
        }
    }
    return $cache[$anahtar] ?? $varsayilan;
}

function ayar_set(string $anahtar, string $deger): void {
    $st = db()->prepare(
        'INSERT INTO tk_ayarlar (anahtar, deger) VALUES (?,?) 
         ON DUPLICATE KEY UPDATE deger=VALUES(deger)'
    );
    $st->execute([$anahtar, $deger]);
}

// === Döviz Kuru ===
function kur_get(string $doviz = 'USD'): array {
    $st = db()->prepare(
        'SELECT alis, satis, tarih FROM tk_kur 
         WHERE doviz_kodu=? ORDER BY id DESC LIMIT 1'
    );
    $st->execute([$doviz]);
    $r = $st->fetch();
    return $r ?: ['alis' => 0, 'satis' => 0, 'tarih' => null];
}

// === JSON Response (API için) ===
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// === Türkçe büyük harf (mb_strtoupper Türkçe i/I dönüşümü için) ===
function tr_upper(string $s): string {
    $s = str_replace(['i','ı','ş','ğ','ü','ö','ç'], ['İ','I','Ş','Ğ','Ü','Ö','Ç'], $s);
    return mb_strtoupper($s, 'UTF-8');
}

function tr_lower(string $s): string {
    $s = str_replace(['I','İ','Ş','Ğ','Ü','Ö','Ç'], ['ı','i','ş','ğ','ü','ö','ç'], $s);
    return mb_strtolower($s, 'UTF-8');
}

/**
 * Şema versiyon takibi + otomatik migration.
 * Akıllı Güncelle dışı manuel yükleme senaryolarında da yeni tablolar otomatik oluşur.
 *
 * Mantık: tk_ayarlar tablosunda 'schema_version' anahtarı kontrol edilir.
 * Eğer hedef sürümden eski ise sql/ klasöründeki migration scriptleri sırayla çalıştırılır.
 * Tüm migration scriptleri idempotent (CREATE TABLE IF NOT EXISTS) olmalıdır.
 */
function schema_versiyon_kontrol(): void {
    $hedef = '1.3.0';
    try {
        $db = db();

        // tk_ayarlar tablosu yok mu (ilk kurulum)? Sessizce çık - install.php halletsin.
        $check = $db->query("SHOW TABLES LIKE 'tk_ayarlar'")->fetch();
        if (!$check) return;

        $st = $db->prepare("SELECT deger FROM tk_ayarlar WHERE anahtar='schema_version'");
        $st->execute();
        $mevcut = $st->fetchColumn() ?: '1.0.0';

        if (version_compare($mevcut, $hedef, '>=')) return; // güncel

        // Migration scriptlerini sırayla bul ve çalıştır
        $sql_dir = __DIR__ . '/../sql';
        if (!is_dir($sql_dir)) return;

        $scripts = glob($sql_dir . '/0*_migration_v*.sql') ?: [];
        sort($scripts);

        foreach ($scripts as $script) {
            // Dosya adından sürüm: 04_migration_v1.3.0.sql -> 1.3.0
            if (!preg_match('/_v(\d+\.\d+\.\d+)\.sql$/', basename($script), $m)) continue;
            $script_ver = $m[1];

            // Bu sürüm zaten uygulanmış mı?
            if (version_compare($mevcut, $script_ver, '>=')) continue;

            $sql = file_get_contents($script);
            if ($sql === false) continue;

            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                error_log("Migration $script_ver hatası: " . $e->getMessage());
                // Idempotent değilse veya kısmi hata - devam et
            }
        }

        // Sürümü güncelle
        $db->prepare(
            "INSERT INTO tk_ayarlar (anahtar, deger) VALUES ('schema_version', ?)
             ON DUPLICATE KEY UPDATE deger=VALUES(deger)"
        )->execute([$hedef]);

    } catch (Exception $e) {
        error_log('Schema versiyon kontrol hatası: ' . $e->getMessage());
    }
}

// Çağır - ilk request'te veya sürüm değişiminde tetiklenir
schema_versiyon_kontrol();
