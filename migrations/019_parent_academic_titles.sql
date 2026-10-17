-- Migration 019: simpan gelar akademik ayah, ibu, dan wali.
-- Jalankan sekali setelah 018_parent_marital_status.sql.

ALTER TABLE `tb_orang_tua_peserta`
  ADD COLUMN `gelar` varchar(80) NOT NULL DEFAULT '' AFTER `tempat_lahir`;
