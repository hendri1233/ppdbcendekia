-- Perkiraan lokasi pengakses tautan pendaftaran unit, ditebak dari IP lewat
-- database GeoLite2 lokal saat kunjungan dicatat. IP sendiri tidak disimpan;
-- koordinat dibulatkan ke tingkat kota.
ALTER TABLE `tb_kunjungan_unit`
  ADD COLUMN `kota` varchar(80) DEFAULT NULL AFTER `jumlah_tayang`,
  ADD COLUMN `wilayah` varchar(80) DEFAULT NULL AFTER `kota`,
  ADD COLUMN `negara` char(2) DEFAULT NULL AFTER `wilayah`,
  ADD COLUMN `lat` decimal(6,2) DEFAULT NULL AFTER `negara`,
  ADD COLUMN `lon` decimal(6,2) DEFAULT NULL AFTER `lat`;
