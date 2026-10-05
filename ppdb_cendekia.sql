-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Waktu pembuatan: 17 Jul 2022 pada 15.06
-- Versi server: 10.4.22-MariaDB
-- Versi PHP: 8.0.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ppdb_cendekia`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `tbadmin`
--

CREATE TABLE `tbadmin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struktur dari tabel `tb_unit_pendidikan`
--

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

INSERT INTO `tb_pengaturan_ppdb` (`kode_unit`, `th_ajaran`) VALUES
('TK-CEN', '2026/2027'), ('SD-CEN', '2026/2027'), ('SMP-CEN', '2026/2027');

CREATE TABLE `tb_sequence_pendaftaran` (
  `tahun` smallint unsigned NOT NULL,
  `nomor_terakhir` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`tahun`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Struktur dari tabel `tb_pendaftaran`
--

CREATE TABLE `tb_pendaftaran` (
  `id_pendaftaran` char(10) NOT NULL,
  `tgl_daftar` date DEFAULT NULL,
  `waktu_daftar` datetime NOT NULL DEFAULT current_timestamp(),
  `th_ajaran` varchar(20) DEFAULT 'Menunggu',
  `kode_unit` varchar(10) DEFAULT NULL,
  `nomor_antrian` int unsigned DEFAULT NULL,
  `status_ppdb` enum('menunggu_kontak','menunggu_pembayaran','pembayaran_diperiksa','pembayaran_terverifikasi','menunggu_formulir','formulir_terisi','diterima','daftar_tunggu','ditolak','data_lama') NOT NULL DEFAULT 'data_lama',
  `status_data` enum('belum_diperiksa','terverifikasi','perlu_perbaikan') NOT NULL DEFAULT 'belum_diperiksa',
  `status_pembayaran` enum('belum_bayar','menunggu_verifikasi','terverifikasi','ditolak') NOT NULL DEFAULT 'belum_bayar',
  `tanggal_batas_bayar` date DEFAULT NULL,
  `data_diperiksa_oleh` int DEFAULT NULL,
  `data_diperiksa_pada` datetime DEFAULT NULL,
  `pembayaran_diperiksa_oleh` int DEFAULT NULL,
  `pembayaran_diperiksa_pada` datetime DEFAULT NULL,
  `NISN` varchar(20) NOT NULL DEFAULT '',
  `asal_sekolah` varchar(100) NOT NULL DEFAULT '',
  `nm_peserta` varchar(50) DEFAULT NULL,
  `tmp_lahir` varchar(50) DEFAULT NULL,
  `tgl_lahir` date DEFAULT NULL,
  `jenis_kelamin` enum('laki-laki','perempuan') DEFAULT NULL,
  `pendaftar` enum('Ayah','Bunda','Ayah dan Bunda','Wali') DEFAULT NULL,
  `no_hp` varchar(20) NOT NULL DEFAULT '',
  `no_hp_pendaftar` varchar(20) NOT NULL DEFAULT '',
  `no_hp_ayah` varchar(20) NOT NULL DEFAULT '',
  `no_hp_ibu` varchar(20) NOT NULL DEFAULT '',
  `agama` varchar(15) DEFAULT NULL,
  `alamat` text DEFAULT NULL,
  `sumber_informasi` varchar(200) NOT NULL DEFAULT '',
  `token_bukti` char(64) NOT NULL,
  `token_whatsapp` char(64) DEFAULT NULL,
  `token_formulir` char(64) DEFAULT NULL,
  PRIMARY KEY (`id_pendaftaran`),
  UNIQUE KEY `uq_ppdb_unit_year_queue` (`kode_unit`, `th_ajaran`, `nomor_antrian`),
  KEY `idx_pendaftaran_unit` (`kode_unit`),
  KEY `idx_pendaftaran_pendaftar` (`pendaftar`),
  KEY `idx_ppdb_status_unit_year` (`status_ppdb`, `kode_unit`, `th_ajaran`),
  UNIQUE KEY `uq_pendaftaran_token` (`token_bukti`),
  UNIQUE KEY `uq_pendaftaran_token_whatsapp` (`token_whatsapp`),
  UNIQUE KEY `uq_pendaftaran_token_formulir` (`token_formulir`),
  CONSTRAINT `fk_pendaftaran_unit` FOREIGN KEY (`kode_unit`) REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT `fk_ppdb_data_admin` FOREIGN KEY (`data_diperiksa_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_ppdb_payment_admin` FOREIGN KEY (`pembayaran_diperiksa_oleh`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE `tb_google_sync_outbox` (
  `id_pendaftaran` char(10) NOT NULL,
  `status` enum('pending','synced','failed') NOT NULL DEFAULT 'pending',
  `attempts` int unsigned NOT NULL DEFAULT 0,
  `last_error` varchar(255) DEFAULT NULL,
  `last_attempt_at` datetime DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_pendaftaran`),
  KEY `idx_google_sync_status` (`status`, `created_at`),
  CONSTRAINT `fk_google_sync_registration` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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

CREATE TABLE `tb_dokumen_peserta` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_pendaftaran` char(10) NOT NULL,
  `jenis_dokumen` enum('foto','nisn','kk','akta_lahir','ktp_ayah','ktp_ibu') NOT NULL,
  `nama_file_asli` varchar(255) NOT NULL,
  `path_file` varchar(500) NOT NULL,
  `mime_type` varchar(80) NOT NULL,
  `ukuran_byte` int unsigned NOT NULL,
  `diunggah_pada` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dokumen_pendaftaran_jenis` (`id_pendaftaran`,`jenis_dokumen`),
  KEY `idx_dokumen_pendaftaran` (`id_pendaftaran`),
  CONSTRAINT `fk_dokumen_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
