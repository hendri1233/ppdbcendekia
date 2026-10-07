-- Split each unit/year quota into public and staff-reserved allocations.
-- Run once after 006_registration_initial_data_and_documents.sql.

ALTER TABLE `tb_pengaturan_ppdb`
  ADD COLUMN `kuota_internal` int unsigned NOT NULL DEFAULT 0 AFTER `kuota`,
  ADD COLUMN `kuota_eksternal` int unsigned NOT NULL DEFAULT 0 AFTER `kuota_internal`,
  ADD COLUMN `internal_dibuka` tinyint(1) NOT NULL DEFAULT 0 AFTER `pendaftaran_dibuka`,
  ADD COLUMN `eksternal_dibuka` tinyint(1) NOT NULL DEFAULT 0 AFTER `internal_dibuka`;

UPDATE `tb_pengaturan_ppdb`
SET `kuota_eksternal` = `kuota`,
    `eksternal_dibuka` = `pendaftaran_dibuka`;

CREATE TABLE `tb_undangan_internal` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_hash` char(64) NOT NULL,
  `nama_sdm` varchar(120) NOT NULL,
  `unit_kerja` varchar(120) NOT NULL DEFAULT '',
  `kode_unit` varchar(10) NOT NULL,
  `th_ajaran` varchar(9) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `dibuat_oleh` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_undangan_internal_token` (`token_hash`),
  KEY `idx_undangan_internal_unit_year` (`kode_unit`, `th_ajaran`, `created_at`),
  CONSTRAINT `fk_undangan_internal_setting` FOREIGN KEY (`kode_unit`, `th_ajaran`)
    REFERENCES `tb_pengaturan_ppdb` (`kode_unit`, `th_ajaran`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_undangan_internal_admin` FOREIGN KEY (`dibuat_oleh`)
    REFERENCES `tbadmin` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `jalur_pendaftaran` enum('internal','eksternal') NOT NULL DEFAULT 'eksternal' AFTER `kode_unit`,
  ADD COLUMN `undangan_internal_id` bigint unsigned DEFAULT NULL AFTER `jalur_pendaftaran`,
  ADD KEY `idx_pendaftaran_unit_year_track` (`kode_unit`, `th_ajaran`, `jalur_pendaftaran`, `status_ppdb`),
  ADD UNIQUE KEY `uq_pendaftaran_internal_invite` (`undangan_internal_id`),
  ADD CONSTRAINT `fk_pendaftaran_internal_invite` FOREIGN KEY (`undangan_internal_id`)
    REFERENCES `tb_undangan_internal` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
