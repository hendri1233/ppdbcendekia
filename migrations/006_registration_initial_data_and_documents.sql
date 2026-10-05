-- Migration 006: Registrasi awal -> validasi bukti bayar -> formulir lengkap.
-- Jalankan sekali setelah 005_whatsapp_status_tokens.sql.

-- 1) Status PPDB diperluas mengikuti alur baru.
ALTER TABLE `tb_pendaftaran`
  MODIFY COLUMN `status_ppdb` enum(
    'menunggu_kontak',
    'menunggu_pembayaran',
    'pembayaran_diperiksa',
    'pembayaran_terverifikasi',
    'menunggu_formulir',
    'formulir_terisi',
    'diterima',
    'daftar_tunggu',
    'ditolak',
    'data_lama'
  ) NOT NULL DEFAULT 'data_lama';

-- 2) Data awal pendaftar + token formulir lengkap.
ALTER TABLE `tb_pendaftaran`
  ADD COLUMN `token_formulir` char(64) DEFAULT NULL AFTER `token_whatsapp`,
  ADD COLUMN `pendaftar` enum('Ayah','Bunda','Ayah dan Bunda','Wali') DEFAULT NULL AFTER `jenis_kelamin`,
  ADD COLUMN `no_hp_pendaftar` varchar(20) NOT NULL DEFAULT '' AFTER `no_hp`,
  ADD COLUMN `no_hp_ayah` varchar(20) NOT NULL DEFAULT '' AFTER `no_hp_pendaftar`,
  ADD COLUMN `no_hp_ibu` varchar(20) NOT NULL DEFAULT '' AFTER `no_hp_ayah`,
  ADD UNIQUE KEY `uq_pendaftaran_token_formulir` (`token_formulir`),
  ADD KEY `idx_pendaftaran_pendaftar` (`pendaftar`);

-- 3) Berkas pendukung: foto, NISN, KK, akta kelahiran, KTP ayah, KTP bunda.
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
  CONSTRAINT `fk_dokumen_pendaftaran` FOREIGN KEY (`id_pendaftaran`)
    REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
