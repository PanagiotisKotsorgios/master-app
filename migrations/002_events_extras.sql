-- ============================================================
-- migrations/002_events_extras.sql — Phase 4 additions
-- ============================================================
--  • event_custom_fields  (organiser-defined per-event form fields)
--  • event_registration_field_values (submitted answers per registration)
--  • events.refund_pct     (org-configurable partial refund %)
--  • event_registrations.registration_extra JSON (custom answers snapshot)
-- Safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS event_custom_fields (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    code VARCHAR(60) NOT NULL,
    label VARCHAR(160) NOT NULL,
    help_text VARCHAR(255) NULL,
    field_type ENUM('text','textarea','select','number','date','checkbox') NOT NULL DEFAULT 'text',
    options TEXT NULL,                       -- newline-separated for select
    required TINYINT(1) NOT NULL DEFAULT 0,
    display_order INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_event_code (event_id, code),
    INDEX idx_event (event_id),
    CONSTRAINT fk_evcf_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_registration_field_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL,
    field_id INT NOT NULL,
    value_text TEXT NULL,
    UNIQUE KEY uk_reg_field (registration_id, field_id),
    INDEX idx_field (field_id),
    CONSTRAINT fk_evrfv_reg   FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE,
    CONSTRAINT fk_evrfv_field FOREIGN KEY (field_id)        REFERENCES event_custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Refund policy fields on the event
ALTER TABLE events
    ADD COLUMN refund_pct_full TINYINT UNSIGNED NOT NULL DEFAULT 100  AFTER refund_policy,
    ADD COLUMN refund_pct_partial TINYINT UNSIGNED NOT NULL DEFAULT 50  AFTER refund_pct_full,
    ADD COLUMN refund_full_until_days TINYINT UNSIGNED NOT NULL DEFAULT 14 AFTER refund_pct_partial,
    ADD COLUMN refund_partial_until_days TINYINT UNSIGNED NOT NULL DEFAULT 7  AFTER refund_full_until_days;

-- Notification bookkeeping: which followers have been notified about which update
CREATE TABLE IF NOT EXISTS event_update_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    update_id INT NOT NULL,
    follower_id INT NOT NULL,
    status ENUM('sent','failed') NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_upd_fol (update_id, follower_id),
    CONSTRAINT fk_evud_upd FOREIGN KEY (update_id)   REFERENCES event_updates(id)   ON DELETE CASCADE,
    CONSTRAINT fk_evud_fol FOREIGN KEY (follower_id) REFERENCES event_followers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reminder bookkeeping: don't double-send match reminders
CREATE TABLE IF NOT EXISTS event_match_reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    match_id INT NOT NULL,
    kind ENUM('t_minus_15','t_minus_60') NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_match_kind (match_id, kind),
    CONSTRAINT fk_evmr_match FOREIGN KEY (match_id) REFERENCES event_matches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
