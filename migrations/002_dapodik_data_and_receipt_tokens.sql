-- Run once after 001_multi_unit_ppdb.sql.
-- Existing registrations are preserved and receive random receipt tokens.

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `token_bukti` char(64) DEFAULT NULL AFTER `sumber_informasi`,
  ADD UNIQUE KEY `uq_pendaftaran_token` (`token_bukti`);

UPDATE `tb_pendaftaran`
SET `token_bukti` = SHA2(CONCAT(`id_pendaftaran`, UUID(), RAND()), 256)
WHERE `token_bukti` IS NULL;

CREATE TABLE `tb_detail_dapodik` (
  `id_pendaftaran` char(10) NOT NULL,
  `nik` char(16) NOT NULL DEFAULT '',
  `nomor_kk` char(16) NOT NULL DEFAULT '',
  `nomor_registrasi_akta` varchar(100) NOT NULL DEFAULT '',
  `kewarganegaraan` enum('WNI','WNA') NOT NULL DEFAULT 'WNI',
  `kebutuhan_khusus` varchar(120) NOT NULL DEFAULT 'Tidak ada',
  `alamat_jalan` varchar(200) NOT NULL DEFAULT '',
  `rt` varchar(5) NOT NULL DEFAULT '',
  `rw` varchar(5) NOT NULL DEFAULT '',
  `desa_kelurahan` varchar(100) NOT NULL DEFAULT '',
  `kecamatan` varchar(100) NOT NULL DEFAULT '',
  `kabupaten` varchar(100) NOT NULL DEFAULT '',
  `provinsi` varchar(100) NOT NULL DEFAULT '',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `jenis_tinggal` varchar(40) NOT NULL DEFAULT '',
  `alat_transportasi` varchar(80) NOT NULL DEFAULT '',
  `ayah_nama` varchar(100) NOT NULL DEFAULT '',
  `ayah_nik` char(16) NOT NULL DEFAULT '',
  `ayah_tahun_lahir` smallint unsigned DEFAULT NULL,
  `ayah_pendidikan` varchar(40) NOT NULL DEFAULT '',
  `ayah_pekerjaan` varchar(100) NOT NULL DEFAULT '',
  `ayah_penghasilan` varchar(40) NOT NULL DEFAULT '',
  `ibu_nama` varchar(100) NOT NULL DEFAULT '',
  `ibu_nik` char(16) NOT NULL DEFAULT '',
  `ibu_tahun_lahir` smallint unsigned DEFAULT NULL,
  `ibu_pendidikan` varchar(40) NOT NULL DEFAULT '',
  `ibu_pekerjaan` varchar(100) NOT NULL DEFAULT '',
  `ibu_penghasilan` varchar(40) NOT NULL DEFAULT '',
  `wali_nama` varchar(100) NOT NULL DEFAULT '',
  `wali_nik` char(16) NOT NULL DEFAULT '',
  `wali_tahun_lahir` smallint unsigned DEFAULT NULL,
  `wali_pendidikan` varchar(40) NOT NULL DEFAULT '',
  `wali_pekerjaan` varchar(100) NOT NULL DEFAULT '',
  `wali_penghasilan` varchar(40) NOT NULL DEFAULT '',
  `tinggi_badan_cm` decimal(5,2) DEFAULT NULL,
  `berat_badan_kg` decimal(5,2) DEFAULT NULL,
  `lingkar_kepala_cm` decimal(5,2) DEFAULT NULL,
  `jarak_ke_sekolah_km` decimal(6,2) DEFAULT NULL,
  `waktu_tempuh_menit` smallint unsigned DEFAULT NULL,
  `jumlah_saudara_kandung` tinyint unsigned DEFAULT NULL,
  `jenis_pendaftaran` enum('Siswa baru','Pindahan','Kembali aktif') NOT NULL DEFAULT 'Siswa baru',
  `nipd` varchar(30) DEFAULT NULL,
  `tanggal_masuk_sekolah` date DEFAULT NULL,
  `pernah_paud_tk` enum('Ya','Tidak','Belum diketahui') NOT NULL DEFAULT 'Belum diketahui',
  PRIMARY KEY (`id_pendaftaran`),
  CONSTRAINT `fk_detail_dapodik_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;