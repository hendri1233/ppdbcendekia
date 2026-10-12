-- Run once after migrations 001 through 013.
-- Stores the WhatsApp group invitation link used when a participant is accepted.

CREATE TABLE `tb_unit_whatsapp_groups` (
  `kode_unit` varchar(10) NOT NULL,
  `link_grup` varchar(500) NOT NULL DEFAULT '',
  `diperbarui_oleh` int DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`kode_unit`),
  KEY `idx_unit_whatsapp_group_admin` (`diperbarui_oleh`),
  CONSTRAINT `fk_unit_whatsapp_group_unit` FOREIGN KEY (`kode_unit`)
    REFERENCES `tb_unit_pendidikan` (`kode_unit`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_unit_whatsapp_group_admin` FOREIGN KEY (`diperbarui_oleh`)
    REFERENCES `tbadmin` (`id`) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
