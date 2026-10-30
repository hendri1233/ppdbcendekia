-- Jadwal buka dan tutup pendaftaran per unit dan tahun ajaran (waktu WIB).
-- Kosong berarti tanpa batas; centang buka internal/eksternal tetap menjadi sakelar utama.
ALTER TABLE tb_pengaturan_ppdb
    ADD COLUMN jadwal_buka DATETIME NULL DEFAULT NULL AFTER eksternal_dibuka,
    ADD COLUMN jadwal_tutup DATETIME NULL DEFAULT NULL AFTER jadwal_buka;
