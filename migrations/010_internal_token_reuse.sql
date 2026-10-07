ALTER TABLE `tb_pendaftaran`
  DROP INDEX `uq_pendaftaran_internal_token`,
  ADD KEY `idx_pendaftaran_internal_token` (`token_internal_id`);

CREATE TABLE `tb_token_internal_reuse_approvals` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `token_internal_id` bigint unsigned NOT NULL,
  `approved_by` int NOT NULL,
  `approved_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_token_reuse_approval_token` (`token_internal_id`,`approved_at`),
  KEY `idx_token_reuse_approval_admin` (`approved_by`),
  CONSTRAINT `fk_token_reuse_approval_token` FOREIGN KEY (`token_internal_id`) REFERENCES `tb_token_internal` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_token_reuse_approval_admin` FOREIGN KEY (`approved_by`) REFERENCES `tbadmin` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
