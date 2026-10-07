-- Satu baris per pengunjung per hari. IP tidak disimpan; pengunjung dikenali
-- dari hash cookie acak sehingga statistik tetap anonim.
CREATE TABLE IF NOT EXISTS `tb_kunjungan_web` (
  `tanggal` date NOT NULL,
  `visitor_hash` char(64) NOT NULL,
  `jumlah_tayang` int unsigned NOT NULL DEFAULT 1,
  `terakhir_dilihat` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tanggal`,`visitor_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
