-- Antrean pesan WhatsApp (Wablas) yang dikirim bertahap dengan jeda acak agar nomor tidak diblokir.
CREATE TABLE tb_wa_antrean (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nomor VARCHAR(20) NOT NULL,
    pesan TEXT NOT NULL,
    jenis VARCHAR(30) NOT NULL DEFAULT '',
    id_pendaftaran CHAR(10) NULL DEFAULT NULL,
    status ENUM('menunggu','terkirim','gagal','dibatalkan') NOT NULL DEFAULT 'menunggu',
    percobaan TINYINT UNSIGNED NOT NULL DEFAULT 0,
    galat VARCHAR(255) NOT NULL DEFAULT '',
    dijadwalkan_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    dikirim_pada DATETIME NULL DEFAULT NULL,
    dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_wa_antrean_kirim (status, dijadwalkan_pada),
    KEY idx_wa_antrean_peserta (id_pendaftaran)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
