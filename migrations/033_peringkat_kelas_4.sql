-- Jalur prestasi SMP kini memakai peringkat kelas 4 dan 5.
-- Kolom peringkat_k6_s1 dipertahankan agar data pendaftar lama tidak hilang.
ALTER TABLE tb_prestasi_peserta
    ADD COLUMN peringkat_k4_s1 VARCHAR(30) NOT NULL DEFAULT '' AFTER jenis_prestasi,
    ADD COLUMN peringkat_k4_s2 VARCHAR(30) NOT NULL DEFAULT '' AFTER peringkat_k4_s1;
