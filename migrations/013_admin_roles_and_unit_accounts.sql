-- Run once after the existing PPDB migrations.
-- Existing administrator accounts remain super admins.
-- Create unit administrator accounts from the super-admin panel so passwords
-- are chosen securely and are never embedded in source control.

ALTER TABLE `tbadmin`
  ADD COLUMN `role` enum('super_admin','admin_unit') NOT NULL DEFAULT 'super_admin' AFTER `password`,
  ADD COLUMN `kode_unit` varchar(10) DEFAULT NULL AFTER `role`,
  ADD UNIQUE KEY `uq_tbadmin_email` (`email`),
  ADD KEY `idx_tbadmin_unit_role` (`kode_unit`,`role`),
  ADD CONSTRAINT `fk_tbadmin_unit` FOREIGN KEY (`kode_unit`)
    REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON UPDATE CASCADE ON DELETE RESTRICT;
