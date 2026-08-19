-- ============================================================
-- migrations/013_marketing_popup.sql
-- Admin-controlled one-time login popup + lead capture.
--
-- Superadmin can create/edit ONE popup shown once to each
-- logged-in user (dashboard). Users choose "Ενδιαφέρομαι" or
-- "Αργότερα"; both actions record a row so the popup stops
-- appearing. Interested clicks also trigger a Brevo email to
-- the configured admin notification address.
-- ============================================================

CREATE TABLE IF NOT EXISTS marketing_popups (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  title                VARCHAR(180) NOT NULL,
  body_html            TEXT NOT NULL,
  cta_label            VARCHAR(60)  NOT NULL DEFAULT 'Ενδιαφέρομαι',
  dismiss_label        VARCHAR(60)  NOT NULL DEFAULT 'Αργότερα',
  icon                 VARCHAR(32)  NOT NULL DEFAULT 'fa-solid fa-globe',
  notify_email         VARCHAR(190) NULL,
  enabled              TINYINT(1)   NOT NULL DEFAULT 0,
  audience             ENUM('all','club_admins','parents','employees') NOT NULL DEFAULT 'all',
  starts_at            DATETIME     NULL,
  ends_at              DATETIME     NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_enabled (enabled, starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS marketing_popup_actions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  popup_id      INT NOT NULL,
  user_id       INT NOT NULL,
  school_id     INT NULL,
  action        ENUM('interested','dismissed') NOT NULL,
  user_email    VARCHAR(190) NULL,
  user_name     VARCHAR(190) NULL,
  user_phone    VARCHAR(40)  NULL,
  ip_address    VARCHAR(45)  NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_popup_user (popup_id, user_id),
  KEY idx_action (action, created_at),
  CONSTRAINT fk_mpa_popup FOREIGN KEY (popup_id) REFERENCES marketing_popups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed one disabled starter popup (free-website offer) so the
-- admin sees something already filled in when they open the page.
INSERT INTO marketing_popups (title, body_html, cta_label, dismiss_label, icon, enabled)
SELECT
  'Δωρεάν επαγγελματική ιστοσελίδα για τη σχολή σας',
  '<p>Θέλετε μια σύγχρονη, mobile-first ιστοσελίδα για τη σχολή σας — <strong>χωρίς κόστος</strong>;</p><p>Αναλαμβάνουμε σχεδιασμό, φιλοξενία και σύνδεση με το MAster ώστε γονείς και αθλητές να βρίσκουν εύκολα εγγραφές, ωρολόγιο πρόγραμμα και events.</p><p>Πατήστε <strong>Ενδιαφέρομαι</strong> για να επικοινωνήσουμε μαζί σας.</p>',
  'Ενδιαφέρομαι',
  'Αργότερα',
  'fa-solid fa-globe',
  0
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM marketing_popups LIMIT 1);
