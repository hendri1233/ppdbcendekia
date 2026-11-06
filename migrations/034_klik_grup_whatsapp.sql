-- Waktu pertama pendaftar membuka tautan grup WhatsApp unit dari halaman hasil pendaftaran.
ALTER TABLE tb_pendaftaran
    ADD COLUMN grup_diklik_pada DATETIME NULL DEFAULT NULL;
