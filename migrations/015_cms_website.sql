-- Situs web yayasan: konten beranda, halaman Tentang dan Kurikulum, berita,
-- slider, profil unit, pengaturan situs, dan pembatas laju asisten AI.
-- Hanya menambah tabel baru; tabel PPDB yang sudah ada tidak diubah.

CREATE TABLE IF NOT EXISTS tb_cms_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_cms_slides (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    image_path VARCHAR(255) NOT NULL,
    title VARCHAR(160) NULL,
    caption VARCHAR(300) NULL,
    link_url VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cms_slides_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_cms_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page VARCHAR(20) NOT NULL,
    section_key VARCHAR(40) NOT NULL,
    title VARCHAR(160) NOT NULL,
    subtitle VARCHAR(255) NULL,
    body_html MEDIUMTEXT NULL,
    image_path VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_sections (page, section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Profil manajemen dan fasilitas: daftar berfoto yang diatur admin.
CREATE TABLE IF NOT EXISTS tb_cms_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('manajemen', 'fasilitas') NOT NULL,
    title VARCHAR(160) NOT NULL,
    subtitle VARCHAR(160) NULL,
    description TEXT NULL,
    image_path VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cms_items_type (item_type, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_cms_news (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(200) NOT NULL,
    excerpt VARCHAR(400) NULL,
    body_html MEDIUMTEXT NULL,
    cover_path VARCHAR(255) NULL,
    kode_unit VARCHAR(10) NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Berita',
    status ENUM('draft', 'published') NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cms_news_slug (slug),
    KEY idx_cms_news_public (status, published_at),
    KEY idx_cms_news_unit (kode_unit, status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_cms_unit_profiles (
    kode_unit VARCHAR(10) NOT NULL PRIMARY KEY,
    tagline VARCHAR(200) NULL,
    description_html MEDIUMTEXT NULL,
    photo_path VARCHAR(255) NULL,
    phone VARCHAR(30) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tb_ai_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_requests_ip (ip_hash, created_at),
    KEY idx_ai_requests_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kerangka bagian halaman. Isi dibiarkan kosong agar tidak ada klaim yang belum
-- diverifikasi; admin mengisinya melalui panel.
INSERT IGNORE INTO tb_cms_sections (page, section_key, title, subtitle, sort_order) VALUES
('tentang', 'prakata', 'Prakata pimpinan', 'Sambutan pimpinan yayasan', 1),
('tentang', 'visi_misi', 'Visi dan misi', 'Arah dan komitmen yayasan', 2),
('kurikulum', 'penggerak', 'Program Penggerak', NULL, 1),
('kurikulum', 'k13', 'Kurikulum 2013', NULL, 2),
('kurikulum', 'merdeka', 'Kurikulum Merdeka Belajar', NULL, 3),
('kurikulum', 'deep_learning', 'Pembelajaran Mendalam (Deep Learning)', NULL, 4);

-- Kontak resmi dari profil yayasan yang sudah terverifikasi.
INSERT IGNORE INTO tb_cms_settings (setting_key, setting_value) VALUES
('site_tagline', 'Shaleh, cerdas, dan terampil.'),
('contact_address', 'Jalan Pertamina-Kebet, Kampung Lemah Burbana, Kecamatan Bebesen, Kabupaten Aceh Tengah, Aceh 24552'),
('contact_phone', '082181649543'),
('contact_email', 'wakafcendekiatakengon@gmail.com'),
('contact_website', 'https://cendekiabisa.com'),
('ai_enabled', '1');
