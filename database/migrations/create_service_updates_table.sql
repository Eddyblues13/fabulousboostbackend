-- ============================================
-- Service Updates Table SQL
-- Run this if migration doesn't work
-- ============================================

CREATE TABLE IF NOT EXISTS `service_updates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `service` VARCHAR(255) NOT NULL,
  `details` TEXT NOT NULL,
  `date` DATE NOT NULL,
  `update` TEXT NOT NULL,
  `category` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `service_updates_date_index` (`date`),
  KEY `service_updates_category_index` (`category`),
  KEY `service_updates_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- END OF SQL
-- ============================================

