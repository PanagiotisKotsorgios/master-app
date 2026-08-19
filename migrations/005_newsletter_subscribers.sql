-- ============================================================
-- migrations/005_newsletter_subscribers.sql
-- Public newsletter opt-in list.
--
-- Populated by /api/newsletter_subscribe.php (POST from the
-- landing page footer form). Emails can unsubscribe via a
-- one-click token link. GDPR-relevant fields (ip, user_agent,
-- consent_at) are stored for audit.
-- ============================================================

CREATE TABLE IF NOT EXISTS newsletter_subscribers (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  email             VARCHAR(190) NOT NULL,
  name              VARCHAR(120) NULL,
  status            ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
  source            VARCHAR(60)  NULL,        -- e.g. 'landing_footer', 'admin_import'
  ip                VARCHAR(45)  NULL,
  user_agent        VARCHAR(255) NULL,
  consent_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  unsubscribe_token CHAR(48)     NOT NULL,
  unsubscribed_at   DATETIME     NULL,
  created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_email (email),
  UNIQUE KEY uk_token (unsubscribe_token),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
