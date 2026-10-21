-- Akses tautan pendaftaran per unit. Satu baris per pengunjung per unit per hari,
-- dikenali dari hash cookie acak yang sama dengan tb_kunjungan_web (IP tidak disimpan).
CREATE TABLE IF NOT EXISTS `tb_kunjungan_unit` (
  `tanggal` date NOT NULL,
  `kode_unit` varchar(10) NOT NULL,
  `visitor_hash` char(64) NOT NULL,
  `jumlah_tayang` int unsigned NOT NULL DEFAULT 1,
  `terakhir_dilihat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tanggal`,`kode_unit`,`visitor_hash`),
  KEY `idx_kunjungan_unit_kode` (`kode_unit`),
  CONSTRAINT `fk_kunjungan_unit` FOREIGN KEY (`kode_unit`)
    REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
