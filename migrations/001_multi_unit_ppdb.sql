-- Run once against the existing ppdb_cendekia database.
-- Existing registrations and administrators are retained. Old grade data is archived.

CREATE TABLE `tb_unit_pendidikan` (
  `kode_unit` varchar(10) NOT NULL,
  `nama_unit` varchar(120) NOT NULL,
  `jenjang` enum('TK','SD','SMP') NOT NULL,
  `npsn` char(8) NOT NULL,
  `email` varchar(120) NOT NULL,
  `alamat` text NOT NULL,
  PRIMARY KEY (`kode_unit`),
  UNIQUE KEY `uq_unit_npsn` (`npsn`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tb_unit_pendidikan` (`kode_unit`, `nama_unit`, `jenjang`, `npsn`, `email`, `alamat`) VALUES
('TK-CEN', 'TK Swasta Islam Terpadu Cendekia', 'TK', '69934833', 'tksit.cendekia@gmail.com', 'Jalan Pertamina-Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh'),
('SD-CEN', 'SD IT Cendekia Takengon', 'SD', '69862386', 'sditcendekia.takengon@gmail.com', 'Jalan Pertamina-Kebet, Dusun Pediwi, Kampung Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh'),
('SMP-CEN', 'SMP IT Cendekia Takengon', 'SMP', '69990330', 'smpitcendekiatkn@gmail.com', 'Jalan Pertamina-Kebet, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh');

RENAME TABLE `tb_nilai` TO `tb_nilai_legacy`;

ALTER TABLE `tb_pendaftaran`
  CHANGE COLUMN `jurusan` `jurusan_lama` varchar(50) DEFAULT NULL,
  CHANGE COLUMN `raport` `raport_lama` int(5) DEFAULT NULL,
  MODIFY COLUMN `th_ajaran` varchar(20) DEFAULT 'Menunggu',
  MODIFY COLUMN `NISN` varchar(20) NOT NULL DEFAULT '',
  MODIFY COLUMN `asal_sekolah` varchar(100) NOT NULL DEFAULT '',
  MODIFY COLUMN `no_hp` varchar(20) NOT NULL DEFAULT '',
  MODIFY COLUMN `sumber_informasi` varchar(200) NOT NULL DEFAULT '',
  ADD COLUMN `kode_unit` varchar(10) DEFAULT NULL AFTER `th_ajaran`,
  ADD KEY `idx_pendaftaran_unit` (`kode_unit`),
  ADD CONSTRAINT `fk_pendaftaran_unit` FOREIGN KEY (`kode_unit`) REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON UPDATE CASCADE ON DELETE RESTRICT;