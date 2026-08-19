-- ============================================================
-- migrations/012_event_tatamis_schedule.sql
-- Physical layout + schedule for tournament events.
--
-- Three new tables, all optional:
--   event_zones          — logical zones of the venue (e.g.
--                          "Main Hall", "Balcony Rings")
--   event_tatamis        — individual tatamis / rings within a
--                          zone, with human names ("Tatami 1",
--                          "Ring A"), sequential number, and
--                          optional color for the schedule board
--   event_schedule_blocks — time-boxed activities on a tatami
--                          (a category, a break, an opening
--                          ceremony), optionally linked to an
--                          event_category
--
-- Downstream compatibility: event_matches already has a
-- ring_number column; we do NOT touch it. New pages can join
-- event_tatamis via ring_number to know which tatami a match
-- belongs to. Existing bracket/match code keeps working.
-- ============================================================

CREATE TABLE IF NOT EXISTS event_zones (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  event_id     INT NOT NULL,
  name         VARCHAR(80) NOT NULL,
  sort_order   SMALLINT NOT NULL DEFAULT 0,
  notes        VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event (event_id, sort_order),
  CONSTRAINT fk_ez_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_tatamis (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  event_id      INT NOT NULL,
  zone_id       INT NULL,
  name          VARCHAR(60) NOT NULL,        -- "Tatami 1", "Ring A"
  ring_number   INT NOT NULL,                -- matches event_matches.ring_number
  color         VARCHAR(20) NULL,            -- hex or CSS name for schedule board
  sort_order    SMALLINT NOT NULL DEFAULT 0,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event (event_id, ring_number),
  KEY idx_zone (zone_id),
  UNIQUE KEY uk_event_ring (event_id, ring_number),
  CONSTRAINT fk_et_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_et_zone  FOREIGN KEY (zone_id)  REFERENCES event_zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_schedule_blocks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  event_id      INT NOT NULL,
  tatami_id     INT NULL,                    -- NULL = event-wide (opening, break)
  category_id   INT NULL,                    -- optional link to event_categories
  title         VARCHAR(120) NOT NULL,
  block_type    ENUM('category','break','ceremony','other') NOT NULL DEFAULT 'category',
  starts_at     DATETIME NOT NULL,
  ends_at       DATETIME NOT NULL,
  color         VARCHAR(20) NULL,
  notes         VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_event_time (event_id, starts_at),
  KEY idx_tatami_time (tatami_id, starts_at),
  KEY idx_category (category_id),
  CONSTRAINT fk_esb_event  FOREIGN KEY (event_id)    REFERENCES events(id)             ON DELETE CASCADE,
  CONSTRAINT fk_esb_tatami FOREIGN KEY (tatami_id)   REFERENCES event_tatamis(id)      ON DELETE SET NULL,
  CONSTRAINT fk_esb_cat    FOREIGN KEY (category_id) REFERENCES event_categories(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
