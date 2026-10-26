-- Cache hasil geocoding OpenStreetMap Nominatim untuk peta dashboard:
-- alamat formulir pendaftar -> koordinat desa/kecamatan, dan koordinat
-- perkiraan IP pengunjung -> nama kabupaten/kota. Hasil gagal juga dicatat
-- (found = 0) agar tidak diminta ulang setiap kali dashboard dibuka.
CREATE TABLE IF NOT EXISTS `tb_geo_cache` (
  `cache_key` varchar(191) NOT NULL,
  `found` tinyint(1) NOT NULL DEFAULT 0,
  `lat` decimal(10,7) DEFAULT NULL,
  `lon` decimal(10,7) DEFAULT NULL,
  `kabupaten` varchar(100) DEFAULT NULL,
  `provinsi` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cache_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
