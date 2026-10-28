-- Jenjang tujuan untuk unit TK (TK A / TK B) yang dipilih saat registrasi awal.
ALTER TABLE tb_pendaftaran
    ADD COLUMN tingkat_kelas VARCHAR(10) NULL DEFAULT NULL AFTER kode_unit;
