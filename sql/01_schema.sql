-- =====================================================
-- TEKCAN SATIŞ EKRANI - VERİTABANI ŞEMASI v1.0.0
-- PHP 8.3+ / MariaDB 10.5+
-- Tablo prefix: tk_ (TekCan)
-- =====================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- ===== KULLANICILAR (Personeller) =====
CREATE TABLE IF NOT EXISTS `tk_users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad_soyad` VARCHAR(120) NOT NULL,
  `kullanici_adi` VARCHAR(60) NOT NULL,
  `parola_hash` VARCHAR(255) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `telefon` VARCHAR(30) DEFAULT NULL,
  `rol` ENUM('admin','personel') NOT NULL DEFAULT 'personel',
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  `son_giris` DATETIME DEFAULT NULL,
  `kayit_tarihi` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kullanici` (`kullanici_adi`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== AYARLAR (Genel sistem ayarları) =====
CREATE TABLE IF NOT EXISTS `tk_ayarlar` (
  `anahtar` VARCHAR(80) NOT NULL,
  `deger` TEXT,
  `aciklama` VARCHAR(255) DEFAULT NULL,
  `guncelleme` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== DÖVİZ KURLARI =====
CREATE TABLE IF NOT EXISTS `tk_kur` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `doviz_kodu` VARCHAR(5) NOT NULL,
  `alis` DECIMAL(12,4) NOT NULL,
  `satis` DECIMAL(12,4) NOT NULL,
  `efektif_alis` DECIMAL(12,4) DEFAULT NULL,
  `efektif_satis` DECIMAL(12,4) DEFAULT NULL,
  `kaynak` VARCHAR(20) NOT NULL DEFAULT 'TCMB',
  `tarih` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_doviz_tarih` (`doviz_kodu`,`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== ANA GRUPLAR =====
CREATE TABLE IF NOT EXISTS `tk_ana_gruplar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad` VARCHAR(80) NOT NULL,
  `sira` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== FİRMALAR (Tedarikçiler) =====
CREATE TABLE IF NOT EXISTS `tk_firmalar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kod` VARCHAR(20) NOT NULL,
  `ad` VARCHAR(100) NOT NULL,
  `ana_grup_id` INT UNSIGNED DEFAULT NULL,
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_kod` (`kod`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== İSKONTO GRUPLARI =====
CREATE TABLE IF NOT EXISTS `tk_iskonto_gruplar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad` VARCHAR(120) NOT NULL,
  `ana_grup_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_anagrup` (`ana_grup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== FİRMA İSKONTOLARI (Alış / Satış oranları) =====
CREATE TABLE IF NOT EXISTS `tk_firma_iskonto` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firma_id` INT UNSIGNED NOT NULL,
  `iskonto_grup_id` INT UNSIGNED NOT NULL,
  `alis_iskonto` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `satis_iskonto` DECIMAL(6,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_firma_grup` (`firma_id`,`iskonto_grup_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== STOKLAR (Ana ürün listesi) =====
CREATE TABLE IF NOT EXISTS `tk_stoklar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ana_grup_id` INT UNSIGNED NOT NULL,
  `iskonto_grup_id` INT UNSIGNED NOT NULL,
  `firma_id` INT UNSIGNED NOT NULL,
  `stok_kodu` VARCHAR(120) NOT NULL,
  `ad` VARCHAR(255) NOT NULL,
  `mt_kg_fiyati` DECIMAL(14,4) DEFAULT NULL,
  `kg_per_mt` DECIMAL(12,4) DEFAULT NULL,
  `boy_fiyati` DECIMAL(14,4) DEFAULT NULL,
  `boy_uzunluk` SMALLINT DEFAULT 6,
  `birim` VARCHAR(10) NOT NULL DEFAULT 'MT',
  `doviz` VARCHAR(5) NOT NULL DEFAULT 'TL',
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `ix_anagrup` (`ana_grup_id`),
  KEY `ix_iskontogrup` (`iskonto_grup_id`),
  KEY `ix_firma` (`firma_id`),
  KEY `ix_stokkodu` (`stok_kodu`),
  FULLTEXT KEY `ft_arama` (`stok_kodu`,`ad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== SATIŞLAR (Yapılan teklifler) =====
CREATE TABLE IF NOT EXISTS `tk_satislar` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kullanici_id` INT UNSIGNED NOT NULL,
  `teklif_no` VARCHAR(30) NOT NULL,
  `musteri_adi` VARCHAR(255) DEFAULT NULL,
  `musteri_telefon` VARCHAR(30) DEFAULT NULL,
  `vade_ay` TINYINT NOT NULL DEFAULT 0,
  `kdv_dahil` TINYINT(1) NOT NULL DEFAULT 1,
  `ara_toplam` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `vade_farki_tutar` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `kdv_tutar` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `genel_toplam` DECIMAL(14,2) NOT NULL DEFAULT 0,
  `usd_kuru` DECIMAL(12,4) DEFAULT NULL,
  `eur_kuru` DECIMAL(12,4) DEFAULT NULL,
  `notlar` TEXT,
  `tarih` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_teklif` (`teklif_no`),
  KEY `ix_kullanici` (`kullanici_id`),
  KEY `ix_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== SATIŞ KALEMLERİ =====
CREATE TABLE IF NOT EXISTS `tk_satis_kalemleri` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `satis_id` INT UNSIGNED NOT NULL,
  `stok_id` INT UNSIGNED NOT NULL,
  `firma_id` INT UNSIGNED NOT NULL,
  `stok_kodu` VARCHAR(120) NOT NULL,
  `ad` VARCHAR(255) NOT NULL,
  `miktar` DECIMAL(12,3) NOT NULL,
  `birim` VARCHAR(10) NOT NULL,
  `birim_fiyat` DECIMAL(14,4) NOT NULL,
  `iskonto` DECIMAL(6,4) NOT NULL DEFAULT 0,
  `tutar` DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_satis` (`satis_id`),
  KEY `ix_stok` (`stok_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== NAKLİYECİLER =====
CREATE TABLE IF NOT EXISTS `tk_nakliyeciler` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad` VARCHAR(120) NOT NULL,
  `telefon` VARCHAR(30) DEFAULT NULL,
  `guzergah` VARCHAR(255) DEFAULT NULL,
  `aktif` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== FABRİKA TEMSİLCİLERİ =====
CREATE TABLE IF NOT EXISTS `tk_fabrika_temsilcileri` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ad` VARCHAR(120) NOT NULL,
  `telefon` VARCHAR(30) DEFAULT NULL,
  `fabrika` VARCHAR(120) DEFAULT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== KUR GÜNCELLEME LOG =====
CREATE TABLE IF NOT EXISTS `tk_kur_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tarih` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `durum` ENUM('basarili','hata') NOT NULL,
  `aciklama` TEXT,
  PRIMARY KEY (`id`),
  KEY `ix_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== GİRİŞ LOG =====
CREATE TABLE IF NOT EXISTS `tk_giris_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kullanici_adi` VARCHAR(60) NOT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `durum` ENUM('basarili','basarisiz') NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `tarih` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

-- ===== AKILLI GÜNCELLE LOG =====
CREATE TABLE IF NOT EXISTS `tk_update_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `eski_surum` VARCHAR(20) DEFAULT NULL,
  `yeni_surum` VARCHAR(20) DEFAULT NULL,
  `durum` ENUM('basarili','hata','baslatildi') NOT NULL,
  `aciklama` TEXT,
  `kullanici_id` INT UNSIGNED DEFAULT NULL,
  `ip` VARCHAR(45) DEFAULT NULL,
  `tarih` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_tarih` (`tarih`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;

SET FOREIGN_KEY_CHECKS=1;
