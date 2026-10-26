-- Pembatas percobaan masuk admin: 10 kali gagal per alamat IP dalam 15 menit.
-- IP disimpan sebagai hash SHA-256, bukan alamat aslinya.
CREATE TABLE IF NOT EXISTS `tb_login_attempts` (
  `ip_hash` char(64) NOT NULL,
  `window_started_at` datetime NOT NULL,
  `attempts` tinyint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
