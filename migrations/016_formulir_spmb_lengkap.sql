-- Migration 016: Formulir lengkap mengikuti Google Form SPMB lama.
-- Jalankan sekali setelah 015_cms_website.sql.
--
-- 1) Data ananda tambahan (identitas, kesehatan, profil, kontak).
-- 2) Data orang tua/wali tambahan: satu baris per peran (ayah, ibu, wali).
-- 3) Draf formulir agar isian yang terhenti di tengah jalan tidak hilang.
-- 4) Aturan wajib/opsional per isian yang diatur super admin.
-- 5) Penanda revisi formulir untuk peserta yang sudah diterima.
-- 6) Riwayat perubahan data setiap kali wali murid mengirim revisi.

ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `revisi_dibuka` tinyint(1) NOT NULL DEFAULT 0 AFTER `status_data`;

ALTER TABLE `tb_detail_dapodik`
  ADD COLUMN `nama_panggilan` varchar(50) NOT NULL DEFAULT '' AFTER `nik`,
  ADD COLUMN `golongan_darah` varchar(20) NOT NULL DEFAULT '' AFTER `nama_panggilan`,
  ADD COLUMN `anak_ke` tinyint unsigned DEFAULT NULL AFTER `jumlah_saudara_kandung`,
  ADD COLUMN `dusun` varchar(100) NOT NULL DEFAULT '' AFTER `rw`,
  ADD COLUMN `kode_pos` varchar(5) NOT NULL DEFAULT '' AFTER `provinsi`,
  ADD COLUMN `email` varchar(120) NOT NULL DEFAULT '' AFTER `kode_pos`,
  ADD COLUMN `status_nisn` varchar(20) NOT NULL DEFAULT '' AFTER `kebutuhan_khusus`,
  ADD COLUMN `alasan_tanpa_nisn` varchar(255) NOT NULL DEFAULT '' AFTER `status_nisn`,
  ADD COLUMN `kelompok_tk` varchar(10) NOT NULL DEFAULT '' AFTER `jenis_pendaftaran`,
  ADD COLUMN `npsn_sekolah_asal` varchar(8) NOT NULL DEFAULT '' AFTER `alasan_tanpa_nisn`,
  ADD COLUMN `jenjang_sekolah_asal` varchar(20) NOT NULL DEFAULT '' AFTER `npsn_sekolah_asal`,
  ADD COLUMN `riwayat_penyakit` enum('','Tidak ada','Ada') NOT NULL DEFAULT '' AFTER `jenjang_sekolah_asal`,
  ADD COLUMN `nama_penyakit` varchar(255) NOT NULL DEFAULT '' AFTER `riwayat_penyakit`,
  ADD COLUMN `pantangan_penyakit` varchar(255) NOT NULL DEFAULT '' AFTER `nama_penyakit`,
  ADD COLUMN `karakter_menonjol` varchar(255) NOT NULL DEFAULT '' AFTER `pantangan_penyakit`,
  ADD COLUMN `kecerdasan_menonjol` varchar(255) NOT NULL DEFAULT '' AFTER `karakter_menonjol`,
  ADD COLUMN `minat_bakat` varchar(255) NOT NULL DEFAULT '' AFTER `kecerdasan_menonjol`,
  ADD COLUMN `hobi` varchar(150) NOT NULL DEFAULT '' AFTER `minat_bakat`,
  ADD COLUMN `cita_cita` varchar(150) NOT NULL DEFAULT '' AFTER `hobi`,
  ADD COLUMN `mempunyai_wali` enum('','Ya','Tidak') NOT NULL DEFAULT '' AFTER `ibu_penghasilan`,
  ADD COLUMN `catatan_validasi` text DEFAULT NULL AFTER `pernah_paud_tk`;

CREATE TABLE `tb_orang_tua_peserta` (
  `id_pendaftaran` char(10) NOT NULL,
  `peran` enum('ayah','ibu','wali') NOT NULL,
  `status_hidup` varchar(20) NOT NULL DEFAULT '',
  `tempat_lahir` varchar(50) NOT NULL DEFAULT '',
  `nama_panggilan` varchar(50) NOT NULL DEFAULT '',
  `suku` varchar(50) NOT NULL DEFAULT '',
  `golongan_darah` varchar(20) NOT NULL DEFAULT '',
  `status_pekerjaan` varchar(60) NOT NULL DEFAULT '',
  `nama_instansi` varchar(150) NOT NULL DEFAULT '',
  `alamat_instansi` varchar(255) NOT NULL DEFAULT '',
  `bidang_spesifik` varchar(150) NOT NULL DEFAULT '',
  `pengeluaran_bulanan` varchar(60) NOT NULL DEFAULT '',
  `hubungan_keluarga` varchar(60) NOT NULL DEFAULT '',
  `no_hp` varchar(20) NOT NULL DEFAULT '',
  `tinggal_bersama_ananda` enum('','Ya','Tidak') NOT NULL DEFAULT '',
  `alamat` varchar(200) NOT NULL DEFAULT '',
  `dusun` varchar(100) NOT NULL DEFAULT '',
  `kampung` varchar(100) NOT NULL DEFAULT '',
  `kecamatan` varchar(100) NOT NULL DEFAULT '',
  `kabupaten` varchar(100) NOT NULL DEFAULT '',
  `ibadah_sering_tertinggal` varchar(255) NOT NULL DEFAULT '',
  `jumlah_buku` varchar(50) NOT NULL DEFAULT '',
  `judul_buku_dibaca` text DEFAULT NULL,
  `hobi` varchar(150) NOT NULL DEFAULT '',
  `tokoh_dikagumi` varchar(255) NOT NULL DEFAULT '',
  `riwayat_penyakit` enum('','Tidak ada','Ada') NOT NULL DEFAULT '',
  `nama_penyakit` varchar(255) NOT NULL DEFAULT '',
  `perilaku_positif` text DEFAULT NULL,
  `perilaku_negatif` text DEFAULT NULL,
  `sosok_terdekat` varchar(150) NOT NULL DEFAULT '',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_pendaftaran`,`peran`),
  CONSTRAINT `fk_orang_tua_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_formulir_aturan_isian` (
  `nama_isian` varchar(60) NOT NULL,
  `wajib` tinyint(1) NOT NULL DEFAULT 0,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`nama_isian`),
  CONSTRAINT `fk_aturan_isian_admin` FOREIGN KEY (`updated_by`) REFERENCES `tbadmin` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_formulir_revisi` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_pendaftaran` char(10) NOT NULL,
  `perubahan_json` mediumtext NOT NULL,
  `jumlah_perubahan` smallint unsigned NOT NULL DEFAULT 0,
  `dikirim_pada` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_formulir_revisi_pendaftaran` (`id_pendaftaran`,`dikirim_pada`),
  CONSTRAINT `fk_formulir_revisi_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_formulir_draf` (
  `id_pendaftaran` char(10) NOT NULL,
  `data_json` mediumtext NOT NULL,
  `langkah` tinyint unsigned NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id_pendaftaran`),
  CONSTRAINT `fk_formulir_draf_pendaftaran` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
