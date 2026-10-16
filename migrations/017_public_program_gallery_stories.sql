-- Konten publik tambahan: program yayasan, galeri, prestasi, testimoni, alumni.
-- Jalankan sekali setelah 016_formulir_spmb_lengkap.sql.

CREATE TABLE IF NOT EXISTS `tb_cms_showcase` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `content_type` enum('program','gallery','prestasi','testimoni','alumni') NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT '',
  `title` varchar(160) NOT NULL,
  `subtitle` varchar(200) NOT NULL DEFAULT '',
  `body_html` mediumtext DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `person_name` varchar(160) NOT NULL DEFAULT '',
  `person_role` varchar(160) NOT NULL DEFAULT '',
  `kode_unit` varchar(10) DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cms_showcase_public` (`content_type`,`is_active`,`sort_order`,`id`),
  KEY `idx_cms_showcase_unit` (`kode_unit`,`content_type`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
