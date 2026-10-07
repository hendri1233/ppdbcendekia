-- Run once after migrations 001, 002, and 003.
-- Existing registrations are marked as legacy; their payment/admission state is not assumed.

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `waktu_daftar` datetime NOT NULL DEFAULT current_timestamp() AFTER `tgl_daftar`,
  ADD COLUMN `nomor_antrian` int unsigned DEFAULT NULL AFTER `kode_unit`,
  ADD COLUMN `status_ppdb` enum('menunggu_verifikasi','menunggu_pembayaran','pembayaran_diperiksa','diterima','daftar_tunggu','ditolak','data_lama') NOT NULL DEFAULT 'data_lama',
  ADD COLUMN `status_data` enum('belum_diperiksa','terverifikasi','perlu_perbaikan') NOT NULL DEFAULT 'belum_diperiksa',
  ADD COLUMN `status_pembayaran` enum('belum_bayar','menunggu_verifikasi','terverifikasi','ditolak') NOT NULL DEFAULT 'belum_bayar',
  ADD COLUMN `tanggal_batas_bayar` date DEFAULT NULL,
  ADD COLUMN `data_diperiksa_oleh` int DEFAULT NULL,
  ADD COLUMN `data_diperiksa_pada` datetime DEFAULT NULL,
  ADD COLUMN `pembayaran_diperiksa_oleh` int DEFAULT NULL,
  ADD COLUMN `pembayaran_diperiksa_pada` datetime DEFAULT NULL,
  ADD UNIQUE KEY `uq_ppdb_unit_year_queue` (`kode_unit`, `th_ajaran`, `nomor_antrian`),
  ADD KEY `idx_ppdb_status_unit_year` (`status_ppdb`, `kode_unit`, `th_ajaran`),
  ADD CONSTRAINT `fk_ppdb_data_admin` FOREIGN KEY (`data_diperiksa_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ppdb_payment_admin` FOREIGN KEY (`pembayaran_diperiksa_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `tb_pengaturan_ppdb` (
  `kode_unit` varchar(10) NOT NULL,
  `th_ajaran` varchar(9) NOT NULL,
  `kuota` int unsigned NOT NULL DEFAULT 0,
  `biaya_pendaftaran` decimal(12,2) unsigned NOT NULL DEFAULT 0,
  `bank_nama` varchar(80) NOT NULL DEFAULT '',
  `nomor_rekening` varchar(50) NOT NULL DEFAULT '',
  `nama_pemilik_rekening` varchar(120) NOT NULL DEFAULT '',
  `masa_pembayaran_hari` tinyint unsigned NOT NULL DEFAULT 7,
  `pendaftaran_dibuka` tinyint(1) NOT NULL DEFAULT 0,
  `nomor_antrian_terakhir` int unsigned NOT NULL DEFAULT 0,
  `diperbarui_oleh` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kode_unit`, `th_ajaran`),
  CONSTRAINT `fk_pengaturan_unit` FOREIGN KEY (`kode_unit`) REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pengaturan_admin` FOREIGN KEY (`diperbarui_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tb_pengaturan_ppdb` (`kode_unit`, `th_ajaran`, `kuota`, `biaya_pendaftaran`, `pendaftaran_dibuka`)
SELECT `kode_unit`, '2026/2027', 0, 0, 0 FROM `tb_unit_pendidikan`;

CREATE TABLE `tb_sequence_pendaftaran` (
  `tahun` smallint unsigned NOT NULL,
  `nomor_terakhir` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tahun`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `tb_sequence_pendaftaran` (`tahun`, `nomor_terakhir`)
SELECT YEAR(CURDATE()), COALESCE(MAX(CASE WHEN LEFT(`id_pendaftaran`, 5) = CONCAT('P', YEAR(CURDATE())) THEN CAST(RIGHT(`id_pendaftaran`, 5) AS UNSIGNED) ELSE 0 END), 0)
FROM `tb_pendaftaran`;

CREATE TABLE `tb_bukti_pembayaran` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_pendaftaran` char(10) NOT NULL,
  `nomor_referensi` varchar(40) NOT NULL,
  `nama_file_asli` varchar(255) NOT NULL,
  `path_file` varchar(500) NOT NULL,
  `mime_type` varchar(80) NOT NULL,
  `ukuran_byte` int unsigned NOT NULL,
  `sha256` char(64) NOT NULL,
  `status_verifikasi` enum('menunggu_verifikasi','disetujui','ditolak') NOT NULL DEFAULT 'menunggu_verifikasi',
  `catatan_admin` varchar(500) NOT NULL DEFAULT '',
  `diunggah_pada` datetime NOT NULL DEFAULT current_timestamp(),
  `diperiksa_oleh` int DEFAULT NULL,
  `diperiksa_pada` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_bukti_status` (`status_verifikasi`, `diunggah_pada`),
  KEY `idx_bukti_pendaftaran` (`id_pendaftaran`),
  CONSTRAINT `fk_bukti_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bukti_admin` FOREIGN KEY (`diperiksa_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;