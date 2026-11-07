-- Kolom alternatif infaq/SPP yang ditambahkan ke migrasi 035 setelah 035 dijalankan di produksi.
-- Jalankan bila kolom infaq_spp_pilihan belum ada di tb_detail_dapodik.
ALTER TABLE tb_detail_dapodik
    ADD COLUMN infaq_spp_pilihan VARCHAR(40) NOT NULL DEFAULT '' AFTER pendapat_spp,
    ADD COLUMN infaq_spp_nominal VARCHAR(15) NOT NULL DEFAULT '' AFTER infaq_spp_pilihan;
