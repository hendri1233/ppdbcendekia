-- Kuota per jenjang (TK A / TK B) untuk unit TK, dipisah jalur internal dan eksternal.
-- Jumlah per jalur menjadi kuota_internal / kuota_eksternal di tb_pengaturan_ppdb.
CREATE TABLE IF NOT EXISTS tb_kuota_tingkat (
    kode_unit VARCHAR(10) NOT NULL,
    th_ajaran VARCHAR(9) NOT NULL,
    tingkat_kelas VARCHAR(10) NOT NULL,
    kuota_internal INT UNSIGNED NOT NULL DEFAULT 0,
    kuota_eksternal INT UNSIGNED NOT NULL DEFAULT 0,
    diperbarui_pada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kode_unit, th_ajaran, tingkat_kelas)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
