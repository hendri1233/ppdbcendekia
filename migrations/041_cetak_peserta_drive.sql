-- Salinan PDF cetak peserta (registrasi dan data lengkap) di folder Google Drive per unit.
-- sidik = hash isi cetakan; bila data peserta berubah, PDF di Drive diganti dengan versi baru.
-- perlu_periksa = 1 ditandai setiap kali data peserta berubah, lalu sidik dihitung ulang di latar belakang.
CREATE TABLE IF NOT EXISTS tb_cetak_drive (
    id_pendaftaran CHAR(10) NOT NULL,
    jenis ENUM('registrasi','lengkap') NOT NULL,
    drive_file_id VARCHAR(100) NOT NULL,
    sidik CHAR(64) NOT NULL,
    perlu_periksa TINYINT(1) NOT NULL DEFAULT 0,
    diunggah_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id_pendaftaran, jenis),
    KEY idx_cetak_drive_periksa (perlu_periksa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
