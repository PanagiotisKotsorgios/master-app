-- ============================================================
-- migrations/006_school_approval.sql
-- Adds an explicit club/school approval workflow layered ON TOP
-- of the existing plan_status (trial/active/expired/suspended).
--
-- New schools created via register.php will still work exactly
-- as before — the migration defaults approval_status to
-- 'approved' for every EXISTING row, so nothing breaks.
--
-- Newly registered schools going forward default to 'approved'
-- too (see schools DEFAULT below). Admins who want a manual
-- gate can flip it to 'pending' via /admin/schools.php and use
-- requireApprovedSchool() in gated pages.
--
-- Idempotent via the migration runner's 'Duplicate column name'
-- + 'already exists' skip rules.
-- ============================================================

ALTER TABLE schools
  ADD COLUMN approval_status ENUM('pending','approved','rejected','suspended')
             NOT NULL DEFAULT 'approved'
             AFTER plan_status;

ALTER TABLE schools
  ADD COLUMN approved_at DATETIME NULL AFTER approval_status;

ALTER TABLE schools
  ADD COLUMN approved_by INT NULL AFTER approved_at;

ALTER TABLE schools
  ADD INDEX idx_approval_status (approval_status);

-- Backfill: every existing row gets approved_at set to now() so
-- audit history has a starting point.
UPDATE schools
   SET approved_at = COALESCE(approved_at, NOW())
 WHERE approval_status = 'approved';

CREATE TABLE IF NOT EXISTS school_approval_history (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  school_id    INT NOT NULL,
  actor_id     INT NULL,
  from_status  VARCHAR(20) NULL,
  to_status    VARCHAR(20) NOT NULL,
  reason       VARCHAR(500) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_school (school_id),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
