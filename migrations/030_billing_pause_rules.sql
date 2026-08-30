-- ================================================================
-- 030_billing_pause_rules.sql
-- Recurring no-charge months at school and department level.
-- School rules take precedence over department rules in application code.
-- ================================================================

CREATE TABLE IF NOT EXISTS `school_billing_pause_months` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `month_num` TINYINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_school_pause_month` (`school_id`, `month_num`),
  KEY `idx_school_pause` (`school_id`),
  CONSTRAINT `fk_school_billing_pause_school`
    FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `department_billing_pause_months` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL,
  `department_id` INT NOT NULL,
  `month_num` TINYINT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_department_pause_month` (`department_id`, `month_num`),
  KEY `idx_department_pause_school` (`school_id`),
  KEY `idx_department_pause_department` (`department_id`),
  CONSTRAINT `fk_department_billing_pause_school`
    FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_department_billing_pause_department`
    FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
