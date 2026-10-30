-- Satu file Google Spreadsheet per unit dan tahun ajaran (folder Drive "PPDB 2027/2028").
-- ID diisi super admin di halaman Kuota & pembayaran; baris berikut mengisi tahun 2027/2028.
ALTER TABLE tb_pengaturan_ppdb
    ADD COLUMN google_spreadsheet_id VARCHAR(100) NOT NULL DEFAULT '' AFTER nama_pemilik_rekening;

UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id = '1TZU0SKDAtrgsZyHzuX6Mpxp8WZiwe6TJin5VedCwHOI' WHERE kode_unit = 'KB-CEN' AND th_ajaran = '2027/2028';
UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id = '1AIAFYL3uMiij4G5HCEPUBRJspXA2a3Ext26mxk9aPkM' WHERE kode_unit = 'TPA-CEN' AND th_ajaran = '2027/2028';
UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id = '1uiLNtlf_VaKBatTeoyv96Ua3LmrRdFsHhXdSfu6AXSk' WHERE kode_unit = 'TK-CEN' AND th_ajaran = '2027/2028';
UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id = '1qNX9Pe67nL7LClXt6_u6UoymztHzpwYwasJAqG5L6Yc' WHERE kode_unit = 'SD-CEN' AND th_ajaran = '2027/2028';
UPDATE tb_pengaturan_ppdb SET google_spreadsheet_id = '1qpFUbQfY6cgY9dskDgsn6QCI4xRQKgz5U25VJXR11w0' WHERE kode_unit = 'SMP-CEN' AND th_ajaran = '2027/2028';
