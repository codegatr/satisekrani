<?php
/**
 * TEKCAN SATIŞ - Akıllı Güncelle (Smart Update) Modülü
 *
 * GitHub Releases tabanlı otomatik güncelleme.
 *
 * Yöntem:
 * - GitHub API ile son release bilgisini al (manifest.json'daki "repo" değerini kullanır).
 * - Asset ZIP'ini "2-aşamalı download" ile indir (private repo için Authorization header
 *   redirect sırasında drop edilir, bu yüzden 2 aşama: 1) API → asset URL al, 2) asset URL'yi
 *   auth header OLMADAN indir).
 * - Geçici klasöre çıkar, exclude_files (config.php, sql/, vs.) hariç tüm dosyaları kopyala.
 * - manifest.json'daki post_update_sql'leri çalıştır.
 * - Log tablosuna kaydet.
 *
 * config.php'ye eklenebilecek opsiyonel sabitler:
 *   const GITHUB_TOKEN = 'ghp_xxxxx';   // Private repo için zorunlu
 *   const UPDATE_AUTO  = false;         // Otomatik güncelleme (cron ile)
 */

if (!defined('UPDATE_TMP_DIR')) {
    define('UPDATE_TMP_DIR', sys_get_temp_dir() . '/tk_update_' . substr(md5(__DIR__), 0, 8));
}

/**
 * Mevcut sürümü ve repo bilgisini manifest.json'dan oku
 */
function update_local_manifest(): array {
    $path = __DIR__ . '/../manifest.json';
    if (!is_readable($path)) {
        throw new Exception('manifest.json okunamıyor: ' . $path);
    }
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j)) {
        throw new Exception('manifest.json bozuk JSON.');
    }
    return $j;
}

/**
 * GitHub API çağrısı (CURL ile, opsiyonel token)
 */
function update_gh_api(string $url, ?string $token = null): array {
    $ch = curl_init($url);
    $headers = [
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: TekcanSatis-AkilliGuncelle/1.0',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception('GitHub API hatası (CURL): ' . $err);
    }
    if ($http >= 400) {
        throw new Exception("GitHub API HTTP $http: " . substr($body ?: '', 0, 200));
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new Exception('GitHub API geçersiz yanıt.');
    }
    return $data;
}

/**
 * GitHub'dan son release'i al
 */
function update_get_latest_release(string $repo, ?string $token = null): array {
    $url = "https://api.github.com/repos/$repo/releases/latest";
    return update_gh_api($url, $token);
}

/**
 * Sürüm karşılaştırma. v1.2.3 / 1.2.3 ikisini de destekler.
 * @return int  -1: a<b, 0: eşit, 1: a>b
 */
function update_version_compare(string $a, string $b): int {
    $a = ltrim($a, 'vV');
    $b = ltrim($b, 'vV');
    return version_compare($a, $b);
}

/**
 * Sürüm kontrol — local vs remote
 */
function update_check(?string $token = null): array {
    $manifest = update_local_manifest();
    $repo = $manifest['repo'] ?? '';
    if (!$repo || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        throw new Exception('manifest.json\'da geçerli "repo" alanı yok (ör: codegatr/tekcan-satis).');
    }

    $local = $manifest['version'] ?? '0.0.0';
    $release = update_get_latest_release($repo, $token);
    $remote = $release['tag_name'] ?? ($release['name'] ?? '0.0.0');
    $cmp = update_version_compare($local, $remote);

    return [
        'local'         => $local,
        'remote'        => $remote,
        'guncelleme_var'=> $cmp < 0,
        'release_notu'  => $release['body'] ?? '',
        'release_tarih' => $release['published_at'] ?? '',
        'release_url'   => $release['html_url'] ?? '',
        'asset_url'     => null,
        'asset_name'    => null,
        'asset_boyut'   => 0,
        'tarball_url'   => $release['tarball_url'] ?? null,
        'zipball_url'   => $release['zipball_url'] ?? null,
        'repo'          => $repo,
        'tag'           => $release['tag_name'] ?? null,
        '_assets'       => $release['assets'] ?? [],
    ];
}

/**
 * Release'in ZIP asset'ini bul (öncelik: .zip uzantılı)
 */
function update_find_zip_asset(array $assets): ?array {
    foreach ($assets as $a) {
        if (preg_match('/\.zip$/i', $a['name'] ?? '')) {
            return $a;
        }
    }
    return $assets[0] ?? null;
}

/**
 * Asset ZIP'i "2-aşamalı" indir (private repo Auth header drop sorununu bypass eder)
 *
 * Aşama 1: API endpoint'e auth ile istek, redirect'i takip ETME, Location header'ı al
 * Aşama 2: Location URL'yi auth OLMADAN indir (signed URL zaten erişim verir)
 */
function update_download_asset(string $asset_url, string $dest_path, ?string $token = null): int {
    // Aşama 1: redirect URL'yi al
    $ch = curl_init($asset_url);
    $headers = [
        'Accept: application/octet-stream',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: TekcanSatis-AkilliGuncelle/1.0',
    ];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $download_url = $asset_url;
    if ($http >= 300 && $http < 400 && preg_match('/^Location:\s*(.+)$/mi', (string)$resp, $m)) {
        $download_url = trim($m[1]);
    } elseif ($http === 200) {
        // Public repo: doğrudan body geldi, normal download yap
    } elseif ($http >= 400) {
        throw new Exception("Asset indirme reddedildi (HTTP $http). Token doğru mu?");
    }

    // Aşama 2: Auth header OLMADAN indir
    $fp = fopen($dest_path, 'w');
    if (!$fp) throw new Exception("Yerel dosya yazılamadı: $dest_path");

    $ch2 = curl_init($download_url);
    curl_setopt_array($ch2, [
        CURLOPT_HTTPHEADER     => ['User-Agent: TekcanSatis-AkilliGuncelle/1.0'],
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $ok = curl_exec($ch2);
    $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    $err2 = curl_error($ch2);
    curl_close($ch2);
    fclose($fp);

    if ($http2 >= 400 || !$ok) {
        @unlink($dest_path);
        throw new Exception("ZIP indirme hatası (HTTP $http2): $err2");
    }
    return filesize($dest_path);
}

/**
 * ZIP'i geçici klasöre çıkar, kök klasör yolunu döndürür
 */
function update_extract_zip(string $zip_path, string $dest_dir): string {
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive eklentisi gerekli.');
    }
    if (!is_dir($dest_dir) && !@mkdir($dest_dir, 0755, true)) {
        throw new Exception("Geçici klasör oluşturulamadı: $dest_dir");
    }
    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        throw new Exception('ZIP açılamadı (geçersiz arşiv).');
    }
    $zip->extractTo($dest_dir);
    // Kök klasörü tahmin et: çoğu ZIP "tekcan_satis/" gibi tek kök ile gelir
    $rootDir = $dest_dir;
    $entries = scandir($dest_dir);
    $entries = array_filter($entries, fn($e) => $e !== '.' && $e !== '..');
    if (count($entries) === 1) {
        $first = reset($entries);
        if (is_dir($dest_dir . '/' . $first)) {
            $rootDir = $dest_dir . '/' . $first;
        }
    }
    $zip->close();
    return $rootDir;
}

/**
 * Source klasördeki dosyaları target üzerine kopyala (exclude listesi hariç)
 *
 * @return array ['kopyalandi' => N, 'atlandi' => N]
 */
function update_apply_files(string $source, string $target, array $exclude): array {
    $kopyalandi = 0; $atlandi = 0;
    $exclude_set = array_flip(array_map(fn($e) => rtrim(str_replace('\\', '/', $e), '/'), $exclude));

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        $rel = ltrim(str_replace('\\', '/', substr($item->getPathname(), strlen($source))), '/');
        if ($rel === '') continue;

        // Exclude kontrolü (dosya veya üst klasör adı)
        $skip = false;
        foreach ($exclude_set as $ex => $_) {
            if ($rel === $ex || strpos($rel, $ex . '/') === 0) {
                $skip = true; break;
            }
        }
        if ($skip) { $atlandi++; continue; }

        $dest_path = $target . '/' . $rel;
        if ($item->isDir()) {
            if (!is_dir($dest_path) && !@mkdir($dest_path, 0755, true)) {
                throw new Exception("Klasör oluşturulamadı: $dest_path");
            }
        } else {
            $dest_dir = dirname($dest_path);
            if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);
            if (!@copy($item->getPathname(), $dest_path)) {
                throw new Exception("Dosya kopyalanamadı: $rel");
            }
            @chmod($dest_path, 0644);
            $kopyalandi++;
        }
    }
    return ['kopyalandi' => $kopyalandi, 'atlandi' => $atlandi];
}

/**
 * Geçici klasörü güvenle sil
 */
function update_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isDir()) @rmdir($f->getPathname());
        else @unlink($f->getPathname());
    }
    @rmdir($dir);
}

/**
 * post_update_sql'leri çalıştır (idempotent INFORMATION_SCHEMA pattern beklenir)
 */
function update_run_sql(array $sql_list): int {
    if (!$sql_list) return 0;
    $db = db();
    $sayac = 0;
    foreach ($sql_list as $sql) {
        $sql = trim((string)$sql);
        if ($sql === '') continue;
        try {
            $db->exec($sql);
            $sayac++;
        } catch (PDOException $e) {
            throw new Exception('SQL hatası: ' . $e->getMessage() . ' | SQL: ' . substr($sql, 0, 100));
        }
    }
    return $sayac;
}

/**
 * Update log kaydı
 */
function update_log(string $eski, string $yeni, string $durum, string $aciklama): void {
    try {
        $st = db()->prepare(
            'INSERT INTO tk_update_log (eski_surum, yeni_surum, durum, aciklama, kullanici_id, ip)
             VALUES (?,?,?,?,?,?)'
        );
        $u = function_exists('current_user') ? current_user() : null;
        $st->execute([
            $eski, $yeni, $durum, $aciklama,
            $u['id'] ?? null,
            ip_adresi(),
        ]);
    } catch (Exception $e) {
        error_log('Update log yazılamadı: ' . $e->getMessage());
    }
}

/**
 * TAM GÜNCELLEME AKIŞI
 *
 * @return array ['basarili' => bool, 'mesaj' => string, 'detay' => array]
 */
function update_apply_now(?string $token = null): array {
    $eski = '0.0.0';
    $yeni = '0.0.0';
    try {
        // 1) Sürüm bilgisi
        $check = update_check($token);
        $eski = $check['local'];
        $yeni = $check['remote'];

        if (!$check['guncelleme_var']) {
            return [
                'basarili' => false,
                'mesaj'    => 'Zaten güncel sürümdesiniz (' . $eski . ').',
                'detay'    => $check,
            ];
        }

        update_log($eski, $yeni, 'baslatildi', 'Güncelleme başlatıldı');

        // 2) ZIP asset'ini bul
        $asset = update_find_zip_asset($check['_assets']);
        if (!$asset || empty($asset['url'])) {
            // Asset yoksa zipball kullan (kaynak kodu tarball)
            if (empty($check['zipball_url'])) {
                throw new Exception('Bu release\'de indirilebilir ZIP bulunamadı.');
            }
            $asset = ['url' => $check['zipball_url'], 'name' => 'zipball.zip', 'size' => 0];
        }

        // 3) Geçici klasör hazırla
        update_rmtree(UPDATE_TMP_DIR);
        @mkdir(UPDATE_TMP_DIR, 0755, true);
        $zip_path = UPDATE_TMP_DIR . '/release.zip';

        // 4) İndir
        $boyut = update_download_asset($asset['url'], $zip_path, $token);

        // 5) Çıkar
        $extracted = update_extract_zip($zip_path, UPDATE_TMP_DIR . '/extracted');

        // 6) Yeni manifest'i oku
        $new_mf_path = $extracted . '/manifest.json';
        $new_manifest = is_readable($new_mf_path)
            ? json_decode(file_get_contents($new_mf_path), true)
            : [];
        $exclude = $new_manifest['exclude_files'] ?? ['config.php', 'manifest.json', '.git/', '.gitignore', 'sql/', 'install.php'];
        // config.php her zaman exclude'da olmalı (zorla)
        if (!in_array('config.php', $exclude, true)) $exclude[] = 'config.php';

        // 7) Dosyaları uygula
        $target = realpath(__DIR__ . '/..');
        $sonuc = update_apply_files($extracted, $target, $exclude);

        // 8) Yeni manifest'i ayrıca yaz (sürüm bilgisi güncellensin)
        if (is_readable($new_mf_path)) {
            @copy($new_mf_path, $target . '/manifest.json');
        }

        // 9) post_update_sql çalıştır
        $sql_count = 0;
        if (!empty($new_manifest['post_update_sql']) && is_array($new_manifest['post_update_sql'])) {
            $sql_count = update_run_sql($new_manifest['post_update_sql']);
        }

        // 10) Geçici klasörü temizle
        update_rmtree(UPDATE_TMP_DIR);

        $mesaj = sprintf(
            'Güncelleme tamamlandı: %s → %s (%d dosya kopyalandı, %d atlandı, %d SQL çalıştırıldı)',
            $eski, $yeni, $sonuc['kopyalandi'], $sonuc['atlandi'], $sql_count
        );
        update_log($eski, $yeni, 'basarili', $mesaj);

        return [
            'basarili' => true,
            'mesaj'    => $mesaj,
            'detay'    => array_merge($sonuc, ['boyut' => $boyut, 'sql' => $sql_count]),
        ];
    } catch (Exception $e) {
        $msg = 'Güncelleme HATASI: ' . $e->getMessage();
        update_log($eski, $yeni, 'hata', $msg);
        update_rmtree(UPDATE_TMP_DIR);
        return [
            'basarili' => false,
            'mesaj'    => $msg,
            'detay'    => [],
        ];
    }
}
