-- Riwayat pengingat WhatsApp dari menu nama peserta (daftar peserta).
-- Tanpa migrasi ini pengingat tetap dapat dikirim, hanya waktunya tidak dicatat.
ALTER TABLE tb_pendaftaran
    ADD COLUMN pengingat_wa_terakhir DATETIME NULL DEFAULT NULL,
    ADD COLUMN pengingat_wa_jumlah SMALLINT UNSIGNED NOT NULL DEFAULT 0;
