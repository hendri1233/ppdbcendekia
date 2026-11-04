-- Centang berkas yang sudah diserahkan unit ke yayasan; satu baris berarti berkas tersebut sudah dicentang.
-- Token tautan per unit disimpan di tb_cms_settings dengan kunci checklist_token_<kode_unit>.
CREATE TABLE IF NOT EXISTS tb_checklist_berkas (
    kode_unit VARCHAR(10) NOT NULL,
    kode_berkas VARCHAR(30) NOT NULL,
    dicentang_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (kode_unit, kode_berkas),
    CONSTRAINT fk_checklist_berkas_unit FOREIGN KEY (kode_unit) REFERENCES tb_unit_pendidikan (kode_unit) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
