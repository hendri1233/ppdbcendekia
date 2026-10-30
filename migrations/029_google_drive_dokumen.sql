-- Salinan dokumen peserta dan bukti transfer di Google Drive akun yayasan.
-- Akun dihubungkan super admin lewat OAuth (google-drive-auth.php); hanya satu akun aktif.
CREATE TABLE IF NOT EXISTS tb_google_drive_akun (
    id TINYINT UNSIGNED NOT NULL,
    email VARCHAR(190) NOT NULL DEFAULT '',
    refresh_token TEXT NOT NULL,
    terhubung_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    terhubung_oleh INT NULL DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Folder yang dibuat aplikasi: root, tahun ajaran, unit, dan satu folder per peserta.
CREATE TABLE IF NOT EXISTS tb_google_drive_folders (
    folder_key VARCHAR(120) NOT NULL,
    folder_id VARCHAR(100) NOT NULL,
    dibuat_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (folder_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- drive_source_path mencatat berkas lokal yang sudah diunggah; bila berkas diganti, diunggah ulang.
ALTER TABLE tb_dokumen_peserta
    ADD COLUMN drive_file_id VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN drive_source_path VARCHAR(500) NULL DEFAULT NULL;

ALTER TABLE tb_bukti_pembayaran
    ADD COLUMN drive_file_id VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN drive_source_path VARCHAR(500) NULL DEFAULT NULL;

-- Galat unggahan terakhir untuk ditampilkan di dasbor.
ALTER TABLE tb_google_drive_akun
    ADD COLUMN galat_terakhir VARCHAR(500) NULL DEFAULT NULL;
