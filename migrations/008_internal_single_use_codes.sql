-- Replace per-staff invitation links with one shared entry point and
-- unit/year-specific, single-use codes. Migration 009 enables mixed-case codes.
-- Run once after 007_internal_external_quotas.sql.

CREATE TABLE `tb_token_internal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode_token` char(9) NOT NULL,
  `kode_unit` varchar(10) NOT NULL,
  `th_ajaran` varchar(9) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token_internal_code` (`kode_token`),
  KEY `idx_token_internal_unit_year` (`kode_unit`, `th_ajaran`, `used_at`, `revoked_at`),
  CONSTRAINT `fk_token_internal_setting` FOREIGN KEY (`kode_unit`, `th_ajaran`)
    REFERENCES `tb_pengaturan_ppdb` (`kode_unit`, `th_ajaran`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_token_internal_attempts` (
  `ip_hash` char(64) NOT NULL,
  `window_started_at` datetime NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `token_internal_id` bigint unsigned DEFAULT NULL AFTER `undangan_internal_id`,
  ADD UNIQUE KEY `uq_pendaftaran_internal_token` (`token_internal_id`),
  ADD CONSTRAINT `fk_pendaftaran_internal_token` FOREIGN KEY (`token_internal_id`)
    REFERENCES `tb_token_internal` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
