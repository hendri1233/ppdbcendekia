-- Kuota per jenis kelamin untuk unit SMP, dipisah jalur internal dan eksternal.
-- Jumlah per jalur menjadi kuota_internal / kuota_eksternal di tb_pengaturan_ppdb.
-- Selama belum diisi di menu Kuota & pembayaran, pendaftaran SMP hanya dibatasi kuota jalur.
CREATE TABLE IF NOT EXISTS tb_kuota_jenis_kelamin (
    kode_unit VARCHAR(10) NOT NULL,
    th_ajaran VARCHAR(9) NOT NULL,
    jenis_kelamin VARCHAR(10) NOT NULL,
    kuota_internal INT UNSIGNED NOT NULL DEFAULT 0,
    kuota_eksternal INT UNSIGNED NOT NULL DEFAULT 0,
    diperbarui_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kode_unit, th_ajaran, jenis_kelamin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
