-- ================================================================
-- 016_athlete_portal.sql
-- Adult athlete portal — mirror of parent portal for adults.
--
-- Adds:
--   • athletes.athlete_portal_access flag (school-controlled)
--   • athlete_users table (login credentials, mirror of parent_users)
--   • athlete_documents table (uploads: δελτίο, Dan, ζώνη, ιατρικό, άλλο)
--
-- Idempotent: each column/table guarded so migration runner re-runs safely.
-- ================================================================

-- ── 1. Flag on athletes table ────────────────────────────────────
ALTER TABLE `athletes`
  ADD COLUMN `athlete_portal_access` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = school has granted the athlete their own portal login';

-- ── 2. Athlete login credentials ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `athlete_users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `school_id` INT(11) NOT NULL,
  `athlete_id` INT(11) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_login` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = sent auto-password, athlete has not changed it yet',
  `terms_accepted_at` DATETIME DEFAULT NULL,
  `terms_version` VARCHAR(10) DEFAULT NULL,
  `active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_athlete_login` (`school_id`, `email`),
  UNIQUE KEY `uniq_one_login_per_athlete` (`athlete_id`),
  KEY `idx_school` (`school_id`),
  CONSTRAINT `fk_au_school` FOREIGN KEY (`school_id`)
    REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_au_athlete` FOREIGN KEY (`athlete_id`)
    REFERENCES `athletes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Athlete uploaded documents ────────────────────────────────
CREATE TABLE IF NOT EXISTS `athlete_documents` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `school_id` INT(11) NOT NULL,
  `athlete_id` INT(11) NOT NULL,
  `type` ENUM('delta','dan','belt','medical','other') NOT NULL DEFAULT 'other'
    COMMENT 'delta=Δελτίο, dan=Dan, belt=Ζώνη, medical=Ιατρικό, other=Άλλο',
  `title` VARCHAR(200) DEFAULT NULL,
  `file_path` VARCHAR(500) NOT NULL,
  `file_size` INT(11) DEFAULT NULL,
  `mime_type` VARCHAR(100) DEFAULT NULL,
  `issued_date` DATE DEFAULT NULL,
  `expires_at` DATE DEFAULT NULL,
  `uploaded_by` ENUM('school','athlete','parent') NOT NULL DEFAULT 'athlete',
  `verified_by_school` TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at` DATETIME DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_school_athlete` (`school_id`, `athlete_id`),
  KEY `idx_type` (`type`),
  CONSTRAINT `fk_ad_school` FOREIGN KEY (`school_id`)
    REFERENCES `schools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ad_athlete` FOREIGN KEY (`athlete_id`)
    REFERENCES `athletes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
