-- Migration 018: simpan status pernikahan ayah dan ibu sebagai satu data keluarga.
-- Jalankan sekali setelah 017_public_program_gallery_stories.sql.

ALTER TABLE `tb_detail_dapodik`
  ADD COLUMN `status_pernikahan_orang_tua` varchar(30) NOT NULL DEFAULT '' AFTER `ibu_penghasilan`;

UPDATE `tb_orang_tua_peserta`
SET `status_hidup` = 'Meninggal dunia'
WHERE `status_hidup` = 'Meninggal';
