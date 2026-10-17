-- Migration 020: simpan provinsi alamat wali untuk dropdown wilayah berjenjang.
-- Jalankan sekali setelah 019_parent_academic_titles.sql.

ALTER TABLE `tb_orang_tua_peserta`
  ADD COLUMN `provinsi` varchar(100) NOT NULL DEFAULT '' AFTER `dusun`;
