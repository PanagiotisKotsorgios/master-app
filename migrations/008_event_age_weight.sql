-- ============================================================
-- migrations/008_event_age_weight.sql
-- First-class age groups + weight classes for events.
--
-- Non-breaking: existing event_categories keeps working exactly
-- as before. The new tables let organisers define age groups
-- and weight classes SEPARATELY, then GENERATE the cartesian
-- product into event_categories via a one-click helper.
--
-- Rationale: modeling age × weight × gender as a flat single
-- table (event_categories) is fine for small events, but for
-- tournaments with 3 age groups × 5 weight classes × 2 genders
-- (= 30 categories) it's tedious. This split makes it easier
-- while remaining downstream-compatible.
-- ============================================================

CREATE TABLE IF NOT EXISTS event_age_groups (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    event_id      INT NOT NULL,
    name          VARCHAR(80) NOT NULL,           -- "Παιδάκια", "Παίδες", "Έφηβοι", "Ανδρες"
    min_age       TINYINT UNSIGNED NULL,
    max_age       TINYINT UNSIGNED NULL,
    gender        ENUM('M','F','MX') NOT NULL DEFAULT 'MX',
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    notes         VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_event (event_id, sort_order),
    CONSTRAINT fk_eag_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS event_weight_classes (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    age_group_id  INT NOT NULL,
    name          VARCHAR(60) NOT NULL,           -- "-45kg", "-52kg", "+80kg"
    min_weight    DECIMAL(5,2) NULL,
    max_weight    DECIMAL(5,2) NULL,
    sort_order    SMALLINT NOT NULL DEFAULT 0,
    fee_amount    DECIMAL(10,2) NULL,             -- optional per-class override
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ag (age_group_id, sort_order),
    CONSTRAINT fk_ewc_ag FOREIGN KEY (age_group_id) REFERENCES event_age_groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reverse link on event_categories so we know which category was
-- generated from which (age_group_id, weight_class_id) pair.
ALTER TABLE event_categories
  ADD COLUMN generated_from_age_group_id    INT NULL AFTER max_weight;

ALTER TABLE event_categories
  ADD COLUMN generated_from_weight_class_id INT NULL AFTER generated_from_age_group_id;

ALTER TABLE event_categories
  ADD INDEX idx_generated (generated_from_age_group_id, generated_from_weight_class_id);
