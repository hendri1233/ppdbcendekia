-- Jalur seleksi unit SMP (prestasi/umum) pada registrasi awal, beserta isian khusus jalur prestasi.
-- Berbeda dari jalur_pendaftaran (internal/eksternal), yang menentukan kuota.
ALTER TABLE tb_pendaftaran
    ADD COLUMN jalur_seleksi VARCHAR(10) NULL DEFAULT NULL AFTER jalur_pendaftaran;

CREATE TABLE IF NOT EXISTS tb_prestasi_peserta (
    id_pendaftaran CHAR(10) NOT NULL,
    jenis_prestasi VARCHAR(80) NOT NULL DEFAULT '',
    peringkat_k5_s1 VARCHAR(30) NOT NULL DEFAULT '',
    peringkat_k5_s2 VARCHAR(30) NOT NULL DEFAULT '',
    peringkat_k6_s1 VARCHAR(30) NOT NULL DEFAULT '',
    kategori_hafalan VARCHAR(80) NOT NULL DEFAULT '',
    jumlah_hafalan VARCHAR(100) NOT NULL DEFAULT '',
    riwayat_lomba VARCHAR(100) NOT NULL DEFAULT '',
    daftar_lomba TEXT NULL,
    PRIMARY KEY (id_pendaftaran),
    CONSTRAINT fk_prestasi_pendaftaran FOREIGN KEY (id_pendaftaran) REFERENCES tb_pendaftaran (id_pendaftaran) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Surat pengantar prestasi disimpan bersama dokumen peserta lainnya.
ALTER TABLE tb_dokumen_peserta
    MODIFY COLUMN jenis_dokumen ENUM('foto','nisn','kk','akta_lahir','ktp_ayah','ktp_ibu','surat_prestasi') NOT NULL;
