-- ============================================================
-- migrations/001_events.sql — MAster Events Subsystem (Phase 1)
-- ============================================================
-- Adds tables that power:
--   • φιλικά, διασυλλογικά, πρωταθλήματα (championship/friendly)
--   • camps, seminars, meetings, εξετάσεις ζωνών
--   • cross-club registration + payments (bank/IRIS/Viva/Stripe/cash)
--   • categories with age/weight/gender/belt filters
--   • pools & brackets (Phase 2 tables ready, wired in Phase 1)
--   • public discovery (visibility=public|unlisted|invite_only)
--   • parent-portal followers & notifications
--
-- All tables prefixed event_* for grouping. Multi-tenant via
-- school_id + FK cascade. Safe to re-run (IF NOT EXISTS).
-- Run once:  php tools/run_events_migration.php
-- ============================================================

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(160) NOT NULL UNIQUE,
    organiser_school_id INT NOT NULL,
    federation_id INT NULL,
    type ENUM('championship','friendly','camp','seminar','meeting','exam') NOT NULL DEFAULT 'friendly',

    title VARCHAR(200) NOT NULL,
    subtitle VARCHAR(255) NULL,
    description MEDIUMTEXT NULL,
    banner_path VARCHAR(255) NULL,

    sport VARCHAR(40) NULL,
    sport_style VARCHAR(60) NULL,

    visibility ENUM('public','unlisted','invite_only') NOT NULL DEFAULT 'public',
    status ENUM('draft','open','closed','in_progress','completed','cancelled') NOT NULL DEFAULT 'draft',

    venue_name VARCHAR(200) NULL,
    venue_address VARCHAR(255) NULL,
    venue_url VARCHAR(255) NULL,
    venue_lat DECIMAL(10,7) NULL,
    venue_lng DECIMAL(10,7) NULL,

    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    registration_opens_at DATETIME NULL,
    registration_closes_at DATETIME NULL,
    payment_due_at DATETIME NULL,

    max_participants INT NULL,
    ring_count TINYINT UNSIGNED NOT NULL DEFAULT 1,

    rules_pdf_path VARCHAR(255) NULL,

    fee_model ENUM('per_athlete','per_team','flat','free') NOT NULL DEFAULT 'per_athlete',
    fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    late_fee_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    late_fee_starts_at DATETIME NULL,
    refund_policy TEXT NULL,

    payment_methods VARCHAR(255) NOT NULL DEFAULT 'bank,iris,cash',
    bank_iban VARCHAR(40) NULL,
    bank_beneficiary VARCHAR(120) NULL,
    bank_name VARCHAR(80) NULL,
    bank_reference_template VARCHAR(120) NOT NULL DEFAULT 'MASTER-EV{event_id}-CL{school_id}',

    commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0,

    contact_email VARCHAR(120) NULL,
    contact_phone VARCHAR(40) NULL,

    meta JSON NULL,

    created_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_org (organiser_school_id),
    INDEX idx_public (status, visibility, starts_at),
    INDEX idx_slug (slug),
    INDEX idx_type (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(120) NOT NULL,
    gender ENUM('M','F','MX') NOT NULL DEFAULT 'MX',
    min_age TINYINT UNSIGNED NULL,
    max_age TINYINT UNSIGNED NULL,
    min_weight DECIMAL(5,2) NULL,
    max_weight DECIMAL(5,2) NULL,
    belt_from VARCHAR(120) NULL,
    belt_to VARCHAR(120) NULL,
    style VARCHAR(60) NULL,
    max_slots INT NULL,
    fee_override DECIMAL(10,2) NULL,
    format ENUM('single_elim','double_elim','round_robin','pool_ko','pool_only','exhibition') NOT NULL DEFAULT 'single_elim',
    pool_size TINYINT UNSIGNED NOT NULL DEFAULT 4,
    display_order INT NOT NULL DEFAULT 0,
    INDEX idx_event (event_id),
    CONSTRAINT fk_evcat_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_requirements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    code VARCHAR(60) NOT NULL,
    label VARCHAR(160) NOT NULL,
    help_text VARCHAR(255) NULL,
    required TINYINT(1) NOT NULL DEFAULT 1,
    file_types VARCHAR(120) NOT NULL DEFAULT 'pdf,jpg,jpeg,png',
    max_size_kb INT NOT NULL DEFAULT 5000,
    display_order INT NOT NULL DEFAULT 0,
    INDEX idx_event (event_id),
    CONSTRAINT fk_evreq_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    invited_school_id INT NULL,
    invited_email VARCHAR(160) NULL,
    token VARCHAR(64) NOT NULL,
    status ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    INDEX idx_token (token),
    CONSTRAINT fk_evinv_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NULL,
    registering_school_id INT NOT NULL,
    athlete_id INT NOT NULL,
    coach_user_id INT NULL,
    athlete_snapshot JSON NULL,
    status ENUM('pending','approved','rejected','withdrawn','checked_in','no_show','disqualified') NOT NULL DEFAULT 'pending',
    payment_status ENUM('unpaid','proof_uploaded','verified','refunded','waived') NOT NULL DEFAULT 'unpaid',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    verified_at DATETIME NULL,
    verified_by INT NULL,
    seed INT NULL,
    pool_id INT NULL,
    notes_organiser VARCHAR(500) NULL,
    notes_participant VARCHAR(500) NULL,
    show_public TINYINT(1) NOT NULL DEFAULT 1,
    withdrew_at DATETIME NULL,
    withdraw_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_event_cat_ath (event_id, category_id, athlete_id),
    INDEX idx_event_status (event_id, status),
    INDEX idx_school (registering_school_id),
    INDEX idx_athlete (athlete_id),
    INDEX idx_cat (category_id),
    CONSTRAINT fk_evreg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_evreg_cat FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_registration_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    registration_id INT NOT NULL,
    requirement_id INT NULL,
    file_path VARCHAR(300) NOT NULL,
    mime VARCHAR(80) NULL,
    size_bytes INT NULL,
    sha256 CHAR(64) NULL,
    status ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    uploaded_by INT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reg (registration_id),
    CONSTRAINT fk_evdoc_reg FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    paying_school_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    method ENUM('bank','iris','viva','stripe','cash') NOT NULL DEFAULT 'bank',
    reference_code VARCHAR(80) NULL,
    proof_file_path VARCHAR(300) NULL,
    proof_uploaded_at DATETIME NULL,
    status ENUM('pending','proof_uploaded','verified','rejected','refunded') NOT NULL DEFAULT 'pending',
    verified_by INT NULL,
    verified_at DATETIME NULL,
    verification_notes VARCHAR(500) NULL,
    external_txn_id VARCHAR(120) NULL,
    meta JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_event_status (event_id, status),
    INDEX idx_school (paying_school_id),
    CONSTRAINT fk_evpay_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_payment_registrations (
    payment_id INT NOT NULL,
    registration_id INT NOT NULL,
    PRIMARY KEY (payment_id, registration_id),
    UNIQUE KEY uk_reg (registration_id),
    CONSTRAINT fk_evpr_pay FOREIGN KEY (payment_id) REFERENCES event_payments(id) ON DELETE CASCADE,
    CONSTRAINT fk_evpr_reg FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_pools (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(40) NOT NULL,
    format ENUM('round_robin','swiss') NOT NULL DEFAULT 'round_robin',
    display_order INT NOT NULL DEFAULT 0,
    INDEX idx_event_cat (event_id, category_id),
    CONSTRAINT fk_evpool_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_evpool_cat FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NOT NULL,
    pool_id INT NULL,
    round_label VARCHAR(60) NULL,
    bracket_position INT NOT NULL DEFAULT 0,
    ring_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
    scheduled_at DATETIME NULL,
    red_registration_id INT NULL,
    blue_registration_id INT NULL,
    red_score INT NOT NULL DEFAULT 0,
    blue_score INT NOT NULL DEFAULT 0,
    winner_registration_id INT NULL,
    result_type ENUM('points','ippon','waza','ko','dq','walkover','draw','pending') NOT NULL DEFAULT 'pending',
    status ENUM('scheduled','live','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    referee_user_id INT NULL,
    judges JSON NULL,
    notes VARCHAR(500) NULL,
    INDEX idx_ring_time (event_id, ring_number, scheduled_at),
    INDEX idx_cat (event_id, category_id),
    CONSTRAINT fk_evmatch_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_evmatch_cat FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_id INT NOT NULL,
    registration_id INT NOT NULL,
    place TINYINT UNSIGNED NOT NULL,
    medal ENUM('gold','silver','bronze','none') NOT NULL DEFAULT 'none',
    points INT NOT NULL DEFAULT 0,
    UNIQUE KEY uk_reg (registration_id),
    INDEX idx_event_cat (event_id, category_id),
    CONSTRAINT fk_evres_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_evres_reg FOREIGN KEY (registration_id) REFERENCES event_registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_updates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    title VARCHAR(160) NOT NULL,
    body_md TEXT NOT NULL,
    pinned TINYINT(1) NOT NULL DEFAULT 0,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_pub (event_id, published_at),
    CONSTRAINT fk_evupd_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_followers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    user_id INT NULL,
    parent_user_id INT NULL,
    email VARCHAR(160) NULL,
    channel ENUM('email','push','web') NOT NULL DEFAULT 'email',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    UNIQUE KEY uk_event_user (event_id, user_id),
    UNIQUE KEY uk_event_parent (event_id, parent_user_id),
    UNIQUE KEY uk_event_email (event_id, email),
    CONSTRAINT fk_evfol_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS event_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    reporter_ip VARCHAR(64) NULL,
    reporter_email VARCHAR(160) NULL,
    reason VARCHAR(60) NOT NULL,
    details VARCHAR(500) NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event (event_id),
    CONSTRAINT fk_evrpt_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
