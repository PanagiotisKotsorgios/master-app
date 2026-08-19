-- ============================================================
-- migrations/010_impersonation_otp.sql
-- OTP-verified impersonation for admins.
--
-- Existing instant-impersonation flow in admin/schools.php stays
-- available for backward compatibility. The new OTP flow lives
-- at /admin/impersonate.php?school=ID and requires the admin to
-- receive a 6-digit code (via email) and enter it before the
-- session actually flips to the target school.
--
-- Audit trail: one row per attempt (successful or not).
-- ============================================================

CREATE TABLE IF NOT EXISTS impersonation_otp (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  admin_user_id        INT NOT NULL,          -- who requested
  target_school_id     INT NOT NULL,          -- which school to impersonate
  target_user_id       INT NULL,              -- resolved owner user, populated when consumed
  code_hash            CHAR(64) NOT NULL,     -- sha256 of the 6-digit code
  expires_at           DATETIME NOT NULL,
  consumed_at          DATETIME NULL,
  attempts             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip                   VARCHAR(45) NULL,
  user_agent           VARCHAR(255) NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_user_id, created_at),
  KEY idx_target (target_school_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
