-- ============================================================
-- TEKCAN SATIŞ - v1.2.0 Migration
-- Akıllı Güncelle (Smart Update) için tk_update_log tablosu
-- ============================================================
-- Idempotent: Birden fazla çalıştırılabilir, hata vermez

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
