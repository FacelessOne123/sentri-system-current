-- 011_barangay_address_terms.sql
-- Barangay-scoped address dictionary (streets, subdivisions, phases, blocks,
-- puroks and sitios) used by the residence suggestion box.
--
-- Source data: OpenStreetMap (ODbL) snapshot + LGU override CSV.
-- Suggestions are advisory only; residents may still type a free-form address.

CREATE TABLE IF NOT EXISTS `barangay_address_terms` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `barangay_name` VARCHAR(150) NOT NULL,
  `term` VARCHAR(190) NOT NULL,
  `term_key` VARCHAR(190) NOT NULL,
  `term_type` ENUM('street','road','subdivision','phase','block','purok','sitio','landmark','other') NOT NULL DEFAULT 'other',
  `parent_term` VARCHAR(190) DEFAULT NULL,
  `latitude` DECIMAL(10,7) DEFAULT NULL,
  `longitude` DECIMAL(10,7) DEFAULT NULL,
  `source` ENUM('osm','lgu','manual') NOT NULL DEFAULT 'osm',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_address_term` (`barangay_name`, `term_key`, `term_type`),
  KEY `idx_address_brgy` (`barangay_name`),
  KEY `idx_address_type` (`term_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
