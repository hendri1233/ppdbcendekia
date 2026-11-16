-- Batas pembayaran per unit dan tahun ajaran berupa tanggal tetap (bukan jumlah hari per peserta).
-- Semua pendaftar yang menunggu pembayaran memakai tanggal ini. Kosong = memakai masa_pembayaran_hari lama.
ALTER TABLE tb_pengaturan_ppdb
    ADD COLUMN batas_pembayaran DATE NULL DEFAULT NULL AFTER masa_pembayaran_hari;
