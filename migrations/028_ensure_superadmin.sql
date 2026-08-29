-- ============================================================
-- migrations/028_ensure_superadmin.sql
-- Ensure at least one superadmin exists with a known password.
--
-- Runs on every deploy (idempotent):
--   • If a superadmin with email 'admin@master-app.gr' exists →
--     reset its password to the known bcrypt of 'master-admin' and
--     make sure it's active. No-op if already the same.
--   • If no such row exists → INSERT it.
--   • Password: master-admin
--   • Email:    admin@master-app.gr
--
-- The Coolify entrypoint script also auto-provisions an admin with a
-- random password printed to logs. This migration guarantees a
-- fixed-credentials fallback so recovery never depends on log access.
-- ============================================================

INSERT INTO users (school_id, name, email, password, role, active)
SELECT NULL, 'Admin', 'admin@master-app.gr',
       '$2y$12$QSixkF0bvZiop9fk84WBSOnTaMRyyX3ySd03xpPRpZopROnQeGO4.',
       'superadmin', 1
FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM users WHERE email = 'admin@master-app.gr' AND role = 'superadmin'
);

UPDATE users
   SET password = '$2y$12$QSixkF0bvZiop9fk84WBSOnTaMRyyX3ySd03xpPRpZopROnQeGO4.',
       role     = 'superadmin',
       active   = 1
 WHERE email = 'admin@master-app.gr';
