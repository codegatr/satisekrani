# Tekcan Satış Ekranı

PHP 8.3+ destekli, MariaDB veritabanı kullanan personel satış ve teklif yönetim sistemi.
TCMB üzerinden otomatik döviz kuru güncelleme özelliği bulunur.

## Özellikler

- **Stok Yönetimi:** 1.877 stok kalemi, 6 tedarikçi firma (YÜCEL, ÇAYIROVA, TOSÇELİK, KROMAN, ÇELSANTAŞ, SDM SAC), 39 iskonto grubu (HR/CR Boru, NPI/NPU/IPE/HEA/HEB, DKP/HRP/ST-37/ST-52, Galvaniz, Baklavalı, Trapez vs.)
- **Personel Satış Ekranı:** Hızlı arama, filtreleme, sepet yönetimi, anında iskonto uygulamalı fiyat hesaplama
- **Teklif Yönetimi:** Teklif kayıt, geçmiş teklifler, yazdırılabilir teklif çıktısı
- **Döviz Kuru:** TCMB XML servisinden otomatik USD/EUR kuru çekme (yedek: exchangerate-api.com), cron ile hafta içi 16:30
- **Yönetim Paneli:** Personel, stok, iskonto oranları, sistem ayarları yönetimi
- **Güvenlik:** HMAC tabanlı CSRF, bcrypt parola hash, giriş logları
- **Hesaplama:** KDV, vade farkı, nakliye marjı dahil otomatik fiyat hesaplama

## Sistem Gereksinimleri

- **PHP:** 8.3 veya üzeri
- **PHP Eklentileri:** PDO, pdo_mysql, mbstring, simplexml, curl, json
- **Veritabanı:** MariaDB 10.4+ veya MySQL 8.0+
- **Web Sunucu:** Apache + mod_rewrite veya LiteSpeed
- **DirectAdmin/cPanel:** Tam uyumlu (shared hosting)

## Kurulum

### 1) Veritabanı Oluştur

Hosting panelinden (DirectAdmin/cPanel) yeni bir veritabanı ve kullanıcı oluşturun. Charset: `utf8mb4`, Collation: `utf8mb4_turkish_ci`.

### 2) SQL Şema ve Seed Yükle

Sırasıyla aşağıdaki dosyaları phpMyAdmin'den (veya komut satırından) çalıştırın:

```bash
mysql -u kullanici -p veritabani_adi < sql/01_schema.sql
mysql -u kullanici -p veritabani_adi < sql/02_seed.sql
```

### 3) Yapılandırma

```bash
cp config.example.php config.php
```

`config.php` dosyasını açıp aşağıdakileri doldurun:

```php
const DB_HOST = 'localhost';
const DB_NAME = 'veritabani_adiniz';
const DB_USER = 'kullanici_adiniz';
const DB_PASS = 'parolaniz';
const APP_SECRET = 'GUVENLI_RASTGELE_BIR_ANAHTAR';   // Aşağıdaki komut ile üretin
const CRON_SECRET = 'CRON_GUVENLI_BIR_ANAHTAR';      // Cron URL'sinde key parametresi
```

`APP_SECRET` üretmek için:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

### 4) Dosya İzinleri

```bash
chmod 644 config.php
chmod -R 755 assets/ admin/ api/ inc/ cron/
```

### 5) Cron Ayarı (TCMB Otomatik Güncelleme)

DirectAdmin/cPanel cron paneline ekleyin:

```
30 16 * * 1-5  wget -q -O- "https://siteniz.com.tr/cron/kur_guncelle.php?key=CRON_SECRET_DEGERINIZ" >/dev/null 2>&1
```

(Hafta içi 16:30 — TCMB 15:30 sonrası kur açıklar)

### 6) İlk Giriş

Tarayıcıda `https://siteniz.com.tr/login.php` adresine gidin.

- **Kullanıcı:** `admin`
- **Parola:** `admin123`

> ⚠️ **ÖNEMLİ:** İlk girişten sonra mutlaka **Yönetim → Personel Yönetimi** kısmından parolanızı değiştirin!

## Klasör Yapısı

```
tekcan_satis/
├── admin/                  # Yönetim paneli sayfaları
│   ├── index.php           # Dashboard
│   ├── users.php           # Personel yönetimi
│   ├── ayarlar.php         # Sistem ayarları (KDV, vade vs.)
│   ├── iskontolar.php      # Firma × iskonto grubu matrisi
│   ├── stoklar.php         # Stok listesi/düzenleme
│   └── kur_log.php         # Döviz kur geçmişi
├── api/                    # JSON AJAX endpoint'leri
│   ├── ara.php             # Stok arama
│   ├── stok_detay.php      # Tek stok detay
│   ├── iskonto_gruplar.php
│   ├── teklif_kaydet.php
│   └── kur_guncelle.php    # Manuel kur güncelleme
├── assets/css/app.css      # Tek CSS dosyası
├── cron/kur_guncelle.php   # Cron giriş noktası
├── inc/                    # Yardımcı kütüphaneler
│   ├── bootstrap.php       # DB, CSRF, auth, helpers
│   ├── header.php          # Layout başlık
│   ├── footer.php          # Layout alt
│   └── kur_modul.php       # TCMB + yedek API entegrasyonu
├── sql/
│   ├── 01_schema.sql       # 11 tablo şeması
│   └── 02_seed.sql         # Stok, firma, iskonto grubu, admin
├── config.example.php      # Yapılandırma şablonu
├── config.php              # ⚠️ Sizin yapılandırmanız (git'e gitmemeli)
├── login.php               # Giriş ekranı
├── logout.php
├── index.php               # Ana satış ekranı
├── teklifler.php           # Geçmiş teklifler
├── teklif_yazdir.php       # Yazdırılabilir teklif çıktısı
├── manifest.json           # Smart Update meta
└── README.md
```

## Hesaplama Mantığı

**Birim Fiyat (İskontolu)** = Liste Fiyat × (1 − İskonto/100)
**Ara Toplam** = Birim Fiyat × Miktar
**Vade Farkı** = Ara Toplam × (Aylık Vade Farkı/100) × Vade Ay Sayısı
**KDV** = (Ara Toplam + Vade Farkı) × KDV Oranı
**Genel Toplam** = Ara Toplam + Vade Farkı + KDV (KDV opsiyonel)

İskonto oranı, **firma × iskonto grubu** matrisinden okunur (`tk_firma_iskonto`). Her stok kalemi bir firmaya ve bir iskonto grubuna bağlıdır.

## Veritabanı Şeması (Özet)

| Tablo | Amaç |
|---|---|
| `tk_users` | Personel/yönetici kullanıcılar |
| `tk_ayarlar` | Sistem ayarları (key/value) |
| `tk_kur` | Döviz kuru geçmişi (USD, EUR) |
| `tk_kur_log` | TCMB güncelleme logları |
| `tk_giris_log` | Giriş denemeleri logu |
| `tk_ana_gruplar` | BORULAR/HADDELER/SACLAR |
| `tk_firmalar` | Tedarikçi firmalar |
| `tk_iskonto_gruplar` | İskonto kategorileri |
| `tk_firma_iskonto` | Firma × grup oran matrisi |
| `tk_stoklar` | Stok kataloğu (1.877 kayıt) |
| `tk_satislar` | Teklif başlıkları |
| `tk_satis_kalemleri` | Teklif satırları |

## Standartlar

- **PHP 8.3+ strict typing**
- **PDO + Exception mode** (SQL injection korumalı)
- **HMAC tabanlı CSRF token**
- **bcrypt parola hash** (cost 10)
- **utf8mb4_turkish_ci** collation
- **Tablo prefix:** `tk_`
- **ASCII-only filename/URL** (Türkçe karakter sadece görüntüde)

## Sorun Giderme

**Q: "Yapılandırma dosyası bulunamadı" hatası alıyorum.**
A: `config.example.php` dosyasını `config.php` olarak kopyalayın.

**Q: TCMB güncellemesi çalışmıyor.**
A: Yönetim → Döviz Kur Geçmişi → "TCMB'den Şimdi Güncelle" butonuna basın. Hata logu burada görünür. Sunucudan `https://www.tcmb.gov.tr/kurlar/today.xml` adresine erişim olmalı.

**Q: Şifremi unuttum.**
A: Başka bir admin'iniz varsa Personel Yönetimi'nden sıfırlayabilir. Yoksa veritabanından doğrudan:
```sql
UPDATE tk_users SET sifre_hash = '$2y$10$...' WHERE kullanici_adi = 'admin';
```
(`$2y$10$...` kısmı için `php -r "echo password_hash('yeni_parola', PASSWORD_BCRYPT);"`)

## Lisans

CODEGA – Tüm hakları Tekcan Metal'e aittir.

---

**Geliştirici:** CODEGA — codega.com.tr
**İletişim:** info@codega.com.tr
**Versiyon:** 1.0.0 — 7 Mayıs 2026
