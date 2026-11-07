-- Batas pengisian formulir lengkap per unit dan tahun ajaran (waktu WIB).
-- Pengisian dimulai sejak jadwal buka dan boleh melewati jadwal tutup; kosong berarti tanpa batas.
ALTER TABLE tb_pengaturan_ppdb
    ADD COLUMN batas_formulir DATETIME NULL DEFAULT NULL AFTER jadwal_tutup;
