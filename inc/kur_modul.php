<?php
/**
 * Döviz Kuru Güncelleme Modülü
 * 
 * - Birincil kaynak: TCMB (Türkiye Cumhuriyet Merkez Bankası) XML servisi
 * - Yedek kaynak: exchangerate-api.com
 * 
 * Çağrı:
 *   $sonuc = kur_guncelle();   // ['basarili'=>bool, 'mesaj'=>string, 'kurlar'=>[]]
 */

function kur_guncelle(): array {
    $hatalar = [];

    // 1) Önce TCMB dene
    $tcmb = kur_tcmb_cek();
    if ($tcmb['ok']) {
        kur_kaydet($tcmb['kurlar'], 'TCMB');
        kur_log('basarili', 'TCMB: ' . count($tcmb['kurlar']) . ' kur güncellendi');
        return [
            'basarili' => true,
            'mesaj'    => 'TCMB kurları başarıyla güncellendi',
            'kurlar'   => $tcmb['kurlar'],
            'kaynak'   => 'TCMB'
        ];
    }
    $hatalar[] = 'TCMB: ' . $tcmb['hata'];

    // 2) Yedek API
    $yedek = kur_yedek_cek();
    if ($yedek['ok']) {
        kur_kaydet($yedek['kurlar'], 'YEDEK');
        kur_log('basarili', 'Yedek API: ' . count($yedek['kurlar']) . ' kur güncellendi');
        return [
            'basarili' => true,
            'mesaj'    => 'Yedek API\'den kurlar güncellendi (TCMB cevap vermedi)',
            'kurlar'   => $yedek['kurlar'],
            'kaynak'   => 'YEDEK'
        ];
    }
    $hatalar[] = 'YEDEK: ' . $yedek['hata'];

    kur_log('hata', implode(' | ', $hatalar));
    return [
        'basarili' => false,
        'mesaj'    => 'Kur güncellenemedi: ' . implode(' | ', $hatalar),
        'kurlar'   => []
    ];
}

function kur_tcmb_cek(): array {
    $ch = curl_init(TCMB_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERAGENT      => 'TekcanSatis/1.0 (+kur_guncelleme)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $xml_raw = curl_exec($ch);
    $http    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err     = curl_error($ch);
    curl_close($ch);

    if (!$xml_raw || $http !== 200) {
        return ['ok' => false, 'hata' => "HTTP $http $err"];
    }

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml_raw);
    if (!$doc) {
        $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
        return ['ok' => false, 'hata' => 'XML parse: ' . implode(';', $errs)];
    }

    $kurlar = [];
    foreach ($doc->Currency as $c) {
        $kod = (string)$c['CurrencyCode'];
        if (!in_array($kod, ['USD', 'EUR'], true)) continue;
        // ForexBuying / ForexSelling = döviz alış / satış
        // BanknoteBuying / BanknoteSelling = efektif alış / satış
        $alis  = (float)str_replace(',', '.', (string)$c->ForexBuying);
        $satis = (float)str_replace(',', '.', (string)$c->ForexSelling);
        $ealis = (float)str_replace(',', '.', (string)$c->BanknoteBuying);
        $esat  = (float)str_replace(',', '.', (string)$c->BanknoteSelling);

        if ($alis > 0 && $satis > 0) {
            $kurlar[] = [
                'doviz' => $kod,
                'alis'  => $alis,
                'satis' => $satis,
                'efektif_alis'  => $ealis ?: null,
                'efektif_satis' => $esat ?: null
            ];
        }
    }

    if (empty($kurlar)) {
        return ['ok' => false, 'hata' => 'XML\'de USD/EUR bulunamadı'];
    }
    return ['ok' => true, 'kurlar' => $kurlar];
}

function kur_yedek_cek(): array {
    $ch = curl_init(KUR_BACKUP_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_USERAGENT      => 'TekcanSatis/1.0',
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $http !== 200) {
        return ['ok' => false, 'hata' => "HTTP $http"];
    }
    $j = json_decode($resp, true);
    $rates = $j['rates'] ?? null;
    if (!$rates || !isset($rates['TRY'])) {
        return ['ok' => false, 'hata' => 'TRY kur bulunamadı'];
    }

    // Bu API USD bazlı; TRY = X TL per 1 USD
    $usd_try = (float)$rates['TRY'];
    $eur_usd = isset($rates['EUR']) ? (float)$rates['EUR'] : 0;
    $eur_try = ($eur_usd > 0) ? ($usd_try / $eur_usd) : 0;

    $kurlar = [
        ['doviz' => 'USD', 'alis' => $usd_try, 'satis' => $usd_try,
         'efektif_alis' => null, 'efektif_satis' => null],
    ];
    if ($eur_try > 0) {
        $kurlar[] = ['doviz' => 'EUR', 'alis' => $eur_try, 'satis' => $eur_try,
                     'efektif_alis' => null, 'efektif_satis' => null];
    }
    return ['ok' => true, 'kurlar' => $kurlar];
}

function kur_kaydet(array $kurlar, string $kaynak): void {
    $st = db()->prepare(
        "INSERT INTO tk_kur (doviz_kodu, alis, satis, efektif_alis, efektif_satis, kaynak, tarih) 
         VALUES (?,?,?,?,?,?,NOW())"
    );
    foreach ($kurlar as $k) {
        $st->execute([
            $k['doviz'], $k['alis'], $k['satis'],
            $k['efektif_alis'], $k['efektif_satis'], $kaynak
        ]);
    }
}

function kur_log(string $durum, string $aciklama): void {
    try {
        db()->prepare(
            'INSERT INTO tk_kur_log (durum, aciklama) VALUES (?,?)'
        )->execute([$durum, $aciklama]);
    } catch (Throwable $e) {
        error_log('kur_log hatası: ' . $e->getMessage());
    }
}
