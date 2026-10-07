-- Run once after 004_quota_payment_workflow.sql.
-- Keeps admin-generated WhatsApp links valid without invalidating the original registration link.

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `token_whatsapp` char(64) DEFAULT NULL AFTER `token_bukti`,
  ADD UNIQUE KEY `uq_pendaftaran_token_whatsapp` (`token_whatsapp`);