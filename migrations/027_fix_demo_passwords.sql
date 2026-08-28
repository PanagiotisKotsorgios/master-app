-- ================================================================
-- 027_fix_demo_passwords.sql
-- CRITICAL FIX: migrations 014, 017, 021, 025, 026 all seeded demo
-- accounts using the string:
--
--   $2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O
--
-- Its docstrings claim it's bcrypt of "master-demo" but it's actually
-- a broken hash — password_verify('master-demo', hash) returns FALSE.
-- Every demo account (guest school owners, parent portal users,
-- athlete portal users, demo owner) was therefore unloggable except
-- via the /demo-login.php passwordless flow.
--
-- This migration force-rewrites every row that still holds the
-- broken hash to a VERIFIED bcrypt of "master-demo":
--
--   $2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO
--
-- Existing real user hashes (any operator that changed their password)
-- do not match the broken hash and are left alone.
--
-- Idempotent — after the first successful pass the broken hash no
-- longer exists and every subsequent UPDATE is a no-op.
-- ================================================================

-- Club/admin user accounts (users.password)
UPDATE users
   SET password = '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO'
 WHERE password = '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O';

-- Parent portal accounts (parent_users.password_hash)
UPDATE parent_users
   SET password_hash = '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO'
 WHERE password_hash = '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O';

-- Athlete portal accounts (athlete_users.password_hash)
UPDATE athlete_users
   SET password_hash = '$2y$10$5zL/uydtqqSBz2pv.6Y5.Oc..OG8Mxpd8/5uGo4iCB1fJPgwsyhqO'
 WHERE password_hash = '$2y$10$D2u3JqGqk2P9c8jvKf3.LO7c5o8kFjXK8Z4T6iN1r7Y2lQ3v9wS8O';
