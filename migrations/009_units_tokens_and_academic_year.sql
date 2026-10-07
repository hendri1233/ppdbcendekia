-- Add the KB and TPA units, standardize unit names, enable mixed-case token
-- matching, and begin new PPDB configurations with academic year 2027/2028.
-- Run once after 008_internal_single_use_codes.sql.

ALTER TABLE `tb_unit_pendidikan`
  MODIFY COLUMN `jenjang` enum('KB','TPA','TK','SD','SMP') NOT NULL;

UPDATE `tb_unit_pendidikan`
SET `nama_unit` = CASE `npsn`
  WHEN '69990330' THEN 'SMP IT CENDEKIA TAKENGON'
  WHEN '69862386' THEN 'SD IT CENDEKIA TAKENGON'
  WHEN '69934833' THEN 'TK SWASTA ISLAM TERPADU CENDEKIA'
  ELSE `nama_unit`
END
WHERE `npsn` IN ('69990330','69862386','69934833');

INSERT INTO `tb_unit_pendidikan` (`kode_unit`,`nama_unit`,`jenjang`,`npsn`,`email`,`alamat`)
VALUES
  ('KB-CEN','KB IT CENDEKIA','KB','70037526','',''),
  ('TPA-CEN','TPA IT CENDEKIA','TPA','70037536','','')
ON DUPLICATE KEY UPDATE
  `nama_unit`=VALUES(`nama_unit`),
  `jenjang`=VALUES(`jenjang`),
  `npsn`=VALUES(`npsn`);

ALTER TABLE `tb_token_internal`
  MODIFY COLUMN `kode_token` char(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

UPDATE `tb_pengaturan_ppdb`
SET `pendaftaran_dibuka`=0,`internal_dibuka`=0,`eksternal_dibuka`=0
WHERE CAST(SUBSTRING_INDEX(`th_ajaran`,'/',1) AS UNSIGNED) < 2027;

UPDATE `tb_token_internal`
SET `revoked_at`=NOW()
WHERE `used_at` IS NULL
  AND `revoked_at` IS NULL
  AND CAST(SUBSTRING_INDEX(`th_ajaran`,'/',1) AS UNSIGNED) < 2027;

INSERT IGNORE INTO `tb_pengaturan_ppdb` (`kode_unit`,`th_ajaran`)
SELECT `kode_unit`,'2027/2028' FROM `tb_unit_pendidikan`;
