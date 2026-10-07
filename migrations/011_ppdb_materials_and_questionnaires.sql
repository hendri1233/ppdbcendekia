CREATE TABLE `tb_ppdb_materials` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kode_unit` varchar(10) NOT NULL,
  `th_ajaran` varchar(9) NOT NULL,
  `jenis_materi` enum('brosur','rincian_biaya','sop') NOT NULL,
  `isi_html` mediumtext NOT NULL,
  `nama_file_asli` varchar(255) DEFAULT NULL,
  `path_file_asli` varchar(1000) DEFAULT NULL,
  `mime_file_asli` varchar(120) DEFAULT NULL,
  `ukuran_file_asli` int unsigned DEFAULT NULL,
  `nama_file_pratinjau` varchar(255) DEFAULT NULL,
  `path_file_pratinjau` varchar(1000) DEFAULT NULL,
  `mime_file_pratinjau` varchar(120) DEFAULT NULL,
  `ukuran_file_pratinjau` int unsigned DEFAULT NULL,
  `updated_by` int NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ppdb_material_unit_year_type` (`kode_unit`,`th_ajaran`,`jenis_materi`),
  KEY `idx_ppdb_material_admin` (`updated_by`),
  CONSTRAINT `fk_ppdb_material_setting` FOREIGN KEY (`kode_unit`,`th_ajaran`) REFERENCES `tb_pengaturan_ppdb` (`kode_unit`,`th_ajaran`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ppdb_material_admin` FOREIGN KEY (`updated_by`) REFERENCES `tbadmin` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_ppdb_questionnaires` (
  `kode_unit` varchar(10) NOT NULL,
  `th_ajaran` varchar(9) NOT NULL,
  `questions_json` mediumtext NOT NULL,
  `updated_by` int NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kode_unit`,`th_ajaran`),
  KEY `idx_ppdb_questionnaire_admin` (`updated_by`),
  CONSTRAINT `fk_ppdb_questionnaire_setting` FOREIGN KEY (`kode_unit`,`th_ajaran`) REFERENCES `tb_pengaturan_ppdb` (`kode_unit`,`th_ajaran`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ppdb_questionnaire_admin` FOREIGN KEY (`updated_by`) REFERENCES `tbadmin` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `tb_ppdb_questionnaire_answers` (
  `id_pendaftaran` char(10) NOT NULL,
  `jawaban_json` mediumtext NOT NULL,
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_pendaftaran`),
  CONSTRAINT `fk_ppdb_questionnaire_answer_registration` FOREIGN KEY (`id_pendaftaran`) REFERENCES `tb_pendaftaran` (`id_pendaftaran`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
