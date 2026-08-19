-- ============================================================
-- migrations/011_camp_registrations.sql
-- Camp-specific extra fields attached to an event_registration.
--
-- Rationale: camps (multi-day training events) need info that
-- doesn't fit the "athlete + weight category" registration
-- model — dietary notes, arrival/departure times, roommate
-- preference, t-shirt size, accompanying adults. This table
-- extends (does not replace) event_registrations.
-- ============================================================

CREATE TABLE IF NOT EXISTS camp_registrations (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  registration_id         INT NOT NULL UNIQUE,
  arrival_at              DATETIME NULL,
  departure_at            DATETIME NULL,
  tshirt_size             ENUM('XS','S','M','L','XL','XXL','3XL') NULL,
  dietary_notes           VARCHAR(255) NULL,
  medical_notes           VARCHAR(500) NULL,
  roommate_preference     VARCHAR(120) NULL,
  accompanying_adults     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  transportation          ENUM('own','shared_bus','pickup_needed') NULL,
  emergency_contact_name  VARCHAR(120) NULL,
  emergency_contact_phone VARCHAR(40)  NULL,
  notes                   TEXT         NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_camp_reg FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
