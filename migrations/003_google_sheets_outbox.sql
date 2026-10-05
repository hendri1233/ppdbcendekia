-- Run once after 001_multi_unit_ppdb.sql and 002_dapodik_data_and_receipt_tokens.sql.
-- Existing registrations are queued for the initial Google Sheets backfill.

CREATE TABLE `tb_google_sync_outbox` (
  `id_pendaftaran` char(10) NOT NULL,
  `status` enum('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_pendaftaran`),
  KEY `idx_google_sync_status` (`status`, `created_at`),
  CONSTRAINT `fk_google_sync_registration` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tb_google_sync_outbox` (`id_pendaftaran`, `status`)
SELECT `id_pendaftaran`, 'pending' FROM `tb_pendaftaran`;