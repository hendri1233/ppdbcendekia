-- Pertanyaan komitmen, alternatif infaq/SPP, program wakaf, peran serta orang tua, dan PIP di langkah
-- "Kesehatan dan profil ananda". Isiannya baru tampil setelah migrasi ini dijalankan.
ALTER TABLE tb_detail_dapodik
    ADD COLUMN komitmen_pakaian_muslim VARCHAR(30) NOT NULL DEFAULT '',
    ADD COLUMN ibadah_ananda VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN waktu_mendampingi VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN pendapat_spp VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN infaq_spp_pilihan VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN infaq_spp_nominal VARCHAR(15) NOT NULL DEFAULT '',
    ADD COLUMN program_wakaf_pilihan VARCHAR(40) NOT NULL DEFAULT '',
    ADD COLUMN program_wakaf_lainnya VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN program_wakaf_alasan VARCHAR(255) NOT NULL DEFAULT '',
    ADD COLUMN peran_mitra VARCHAR(500) NOT NULL DEFAULT '',
    ADD COLUMN peran_mitra_lainnya VARCHAR(150) NOT NULL DEFAULT '',
    ADD COLUMN penerima_pip VARCHAR(10) NOT NULL DEFAULT '',
    ADD COLUMN alasan_pip VARCHAR(60) NOT NULL DEFAULT '';
