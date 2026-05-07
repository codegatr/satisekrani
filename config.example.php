<?php
/**
 * TEKCAN SATIŞ - Yapılandırma Dosyası
 *
 * BU DOSYA GÜNCELLEME ZIP'İNE DAHİL EDİLMEZ.
 * Sunucuda manuel olarak düzenlenir.
 */

// === VERİTABANI ===
const DB_HOST = 'localhost';
const DB_NAME = 'tekcan_satis';
const DB_USER = 'tekcan_user';
const DB_PASS = 'CHANGE_ME_STRONG_PASSWORD';
const DB_CHARSET = 'utf8mb4';
const DB_PORT = 3306;

// === GÜVENLİK ===
// CSRF için HMAC anahtarı - rastgele 64 karakter olmalı
// Üretmek için: bin2hex(random_bytes(32))
const APP_SECRET = 'CHANGE_ME_RANDOM_64_CHAR_HEX_SECRET_KEY_FOR_HMAC_CSRF_TOKENS_XYZ';

// Oturum süresi (saniye)
const SESSION_LIFETIME = 28800; // 8 saat

// === UYGULAMA ===
const APP_NAME = 'Tekcan Satış';
const APP_VERSION = '1.0.0';
const APP_TIMEZONE = 'Europe/Istanbul';

// Hata gösterme (production'da false yapın)
const APP_DEBUG = false;

// Site URL (cron için, sonunda / olmadan)
const APP_URL = 'https://satis.tekcanmetal.com';

// === DÖVİZ KURU ===
// TCMB API URL (XML)
const TCMB_URL = 'https://www.tcmb.gov.tr/kurlar/today.xml';

// Yedek API (TCMB cevap vermezse)
const KUR_BACKUP_URL = 'https://api.exchangerate-api.com/v4/latest/USD';

// Cron koruması (URL key parametresi)
const CRON_SECRET = 'CHANGE_ME_RANDOM_HEX_KEY_FOR_CRON_URL';

// === AKILLI GÜNCELLE ===
// GitHub Personal Access Token (Private repo için zorunlu, public için boş bırakın)
// Token oluştur: https://github.com/settings/tokens (scope: repo)
const GITHUB_TOKEN = '';
