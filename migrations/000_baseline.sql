-- ============================================================
-- migrations/000_baseline.sql — MAster base schema (idempotent)
-- ============================================================
-- Generated from a phpMyAdmin dump, schema-only (no user data).
-- Safe to re-run: CREATE TABLE IF NOT EXISTS + ALTER errors are
-- treated as 'already applied' by tools/run_events_migration.php.
-- ============================================================

-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Εξυπηρετητής: localhost:3306
-- Χρόνος δημιουργίας: 07 Απρ 2026 στις 00:35:08
-- Έκδοση διακομιστή: 10.6.24-MariaDB-cll-lve
-- Έκδοση PHP: 8.4.17
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
CREATE TABLE IF NOT EXISTS `adult_members` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `birthdate` date DEFAULT NULL,
  `amka` varchar(20) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `membership_type` varchar(100) DEFAULT 'Τακτικό',
  `membership_expires` date DEFAULT NULL,
  `monthly_fee` decimal(8,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `adult_subscriptions` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `type` enum('monthly','quarterly','annual','onetime') DEFAULT 'monthly',
  `amount` decimal(8,2) NOT NULL,
  `paid_at` date DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date NOT NULL,
  `payment_method` enum('cash','card','deposit') DEFAULT 'cash',
  `receipt_number` varchar(100) DEFAULT NULL,
  `status` enum('paid','pending','overdue') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `athletes` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `full_name` varchar(200) NOT NULL,
  `father_name` varchar(100) DEFAULT NULL,
  `mother_name` varchar(100) DEFAULT NULL,
  `birthdate` date DEFAULT NULL,
  `amka` varchar(20) DEFAULT NULL,
  `registration_date` date DEFAULT curdate(),
  `phone` varchar(30) DEFAULT NULL,
  `parent_phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `parent_email` varchar(150) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `medical_cert_expiry` date DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `parent_portal_access` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `debt_from_month` varchar(7) DEFAULT NULL,
  `debt_until_month` varchar(7) DEFAULT NULL,
  `debt_notified_at` datetime DEFAULT NULL,
  `debt_notified_type` varchar(10) DEFAULT NULL,
  `monthly_fee` decimal(8,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `athlete_pause_periods` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `pause_from` date NOT NULL,
  `pause_until` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_log` (
  `id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `size_bytes` bigint(20) DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bank_transfer_requests` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_name` varchar(200) DEFAULT NULL,
  `user_email` varchar(200) DEFAULT NULL,
  `plan_slug` varchar(50) DEFAULT NULL,
  `billing_cycle` enum('monthly','annual') DEFAULT 'monthly',
  `amount` decimal(8,2) DEFAULT NULL,
  `reference_code` varchar(50) DEFAULT NULL,
  `status` enum('pending','confirmed','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `confirmed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `billing_history` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `description` varchar(300) DEFAULT NULL,
  `amount` decimal(8,2) DEFAULT 0.00,
  `currency` varchar(10) DEFAULT 'EUR',
  `status` enum('paid','pending','failed','refunded') DEFAULT 'pending',
  `payment_method` enum('stripe','bank_transfer','trial','manual') DEFAULT 'stripe',
  `invoice_id` varchar(150) DEFAULT NULL,
  `stripe_charge_id` varchar(150) DEFAULT NULL,
  `bank_reference` varchar(100) DEFAULT NULL,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `plan_slug` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_log` (
  `id` int(11) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `recipient_email` varchar(200) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `broadcast_messages` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` text NOT NULL,
  `channels` varchar(20) NOT NULL DEFAULT 'email',
  `recipient_filter` varchar(50) NOT NULL DEFAULT 'all',
  `total_sent` int(11) NOT NULL DEFAULT 0,
  `total_failed` int(11) NOT NULL DEFAULT 0,
  `status` enum('sending','done','failed') NOT NULL DEFAULT 'sending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `competitions` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `comp_date` date NOT NULL,
  `location` varchar(200) DEFAULT NULL,
  `comp_type` varchar(100) DEFAULT NULL,
  `cost` decimal(8,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `competition_participants` (
  `id` int(11) NOT NULL,
  `competition_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `weight_category` varchar(50) DEFAULT NULL,
  `result` varchar(100) DEFAULT NULL,
  `medal` enum('none','gold','silver','bronze') DEFAULT 'none',
  `points` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cookie_consents` (
  `id` int(11) NOT NULL,
  `ip_hash` varchar(64) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `session_id` varchar(128) DEFAULT NULL,
  `analytics_accepted` tinyint(1) DEFAULT 0,
  `necessary_accepted` tinyint(1) DEFAULT 1,
  `functional_accepted` tinyint(1) DEFAULT 1,
  `consent_date` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `user_agent` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupons` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `discount_type` enum('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value` decimal(8,2) NOT NULL DEFAULT 0.00,
  `applies_to_plan` enum('basic','pro','any') NOT NULL DEFAULT 'any',
  `max_uses` int(11) DEFAULT NULL COMMENT 'NULL = unlimited',
  `used_count` int(11) NOT NULL DEFAULT 0,
  `valid_from` date DEFAULT NULL,
  `valid_until` date DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `coupon_redemptions` (
  `id` int(11) NOT NULL,
  `coupon_id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `redeemed_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cron_runs` (
  `id` int(11) NOT NULL,
  `job_name` varchar(100) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `started_at` datetime NOT NULL,
  `finished_at` datetime DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'running',
  `message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;

CREATE TABLE IF NOT EXISTS `cron_run_log` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `run_at` timestamp NULL DEFAULT current_timestamp(),
  `sent` int(11) NOT NULL DEFAULT 0,
  `failed` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `departments` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `schedule` text DEFAULT NULL,
  `coach_id` int(11) DEFAULT NULL,
  `max_athletes` int(11) DEFAULT 30,
  `monthly_fee` decimal(8,2) DEFAULT 0.00,
  `sport` varchar(50) DEFAULT 'taekwondo',
  `active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `employee_privileges` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'References users.id (employee role)',
  `schools_view` tinyint(1) NOT NULL DEFAULT 0,
  `schools_create` tinyint(1) NOT NULL DEFAULT 0,
  `schools_edit` tinyint(1) NOT NULL DEFAULT 0,
  `schools_delete` tinyint(1) NOT NULL DEFAULT 0,
  `schools_impersonate` tinyint(1) NOT NULL DEFAULT 0,
  `users_view` tinyint(1) NOT NULL DEFAULT 0,
  `users_create` tinyint(1) NOT NULL DEFAULT 0,
  `users_edit` tinyint(1) NOT NULL DEFAULT 0,
  `users_delete` tinyint(1) NOT NULL DEFAULT 0,
  `users_impersonate` tinyint(1) NOT NULL DEFAULT 0,
  `athletes_view` tinyint(1) NOT NULL DEFAULT 0,
  `athletes_create` tinyint(1) NOT NULL DEFAULT 0,
  `athletes_edit` tinyint(1) NOT NULL DEFAULT 0,
  `athletes_delete` tinyint(1) NOT NULL DEFAULT 0,
  `payments_view` tinyint(1) NOT NULL DEFAULT 0,
  `payments_create` tinyint(1) NOT NULL DEFAULT 0,
  `payments_edit` tinyint(1) NOT NULL DEFAULT 0,
  `payments_delete` tinyint(1) NOT NULL DEFAULT 0,
  `backups_view` tinyint(1) NOT NULL DEFAULT 0,
  `backups_create` tinyint(1) NOT NULL DEFAULT 0,
  `backups_delete` tinyint(1) NOT NULL DEFAULT 0,
  `logs_view` tinyint(1) NOT NULL DEFAULT 0,
  `search_access` tinyint(1) NOT NULL DEFAULT 0,
  `health_view` tinyint(1) NOT NULL DEFAULT 0,
  `analytics_view` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'View platform analytics & statistics',
  `analytics_export` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Export analytics data (CSV/PDF/Print)',
  `export_schools` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Export schools data',
  `export_users` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Export users data',
  `export_athletes` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Export athletes data',
  `export_payments` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Export payments data',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `federations` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact_name` varchar(200) DEFAULT NULL,
  `contact_email` varchar(200) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `commission_pct` decimal(5,2) NOT NULL DEFAULT 0.00 COMMENT 'Ποσοστό % που κρατά η ομοσπονδία από τα έσοδα',
  `notes` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ομοσπονδίες / φορείς που φέρνουν σχολές στην πλατφόρμα';

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL,
  `ip` varchar(45) NOT NULL COMMENT 'IPv4 ή IPv6 address του client',
  `email` varchar(255) NOT NULL COMMENT 'Email που χρησιμοποιήθηκε (lowercase)',
  `attempted_at` int(11) NOT NULL COMMENT 'Unix timestamp της απόπειρας'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Αποτυχημένες προσπάθειες login για brute force protection';

CREATE TABLE IF NOT EXISTS `monthly_income_reports` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `report_year` int(11) NOT NULL,
  `report_month` int(11) NOT NULL,
  `total_income` decimal(10,2) DEFAULT 0.00,
  `total_expenses` decimal(10,2) DEFAULT 0.00,
  `total_subscriptions` int(11) DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_rules` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `rule_name` varchar(120) NOT NULL,
  `trigger_type` enum('days_before','on_due','days_after','after_payment','has_debt') NOT NULL,
  `trigger_days` int(11) NOT NULL DEFAULT 0,
  `channels` varchar(20) NOT NULL DEFAULT 'email',
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `subject_tpl` varchar(255) DEFAULT NULL,
  `body_tpl` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `include_name` tinyint(1) NOT NULL DEFAULT 1,
  `include_date` tinyint(1) NOT NULL DEFAULT 1,
  `include_amount` tinyint(1) NOT NULL DEFAULT 0,
  `is_system` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `overage_requests` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `type` enum('sms','email') NOT NULL DEFAULT 'sms',
  `qty` int(11) NOT NULL DEFAULT 0 COMMENT 'Αριθμός SMS/email που αγοράζονται',
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Τιμή που πληρώνει η σχολή (€)',
  `commission` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Προμήθεια admin (€)',
  `status` enum('pending','paid','rejected') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `paid_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Αιτήματα αγοράς επιπλέον πακέτων SMS/email ανά σχολή';

CREATE TABLE IF NOT EXISTS `parent_children` (
  `id` int(11) NOT NULL,
  `parent_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `parent_dashboard_view` (
`parent_id` int(11)
,`parent_email` varchar(150)
,`athlete_id` int(11)
,`athlete_name` varchar(200)
,`monthly_fee` decimal(8,2)
,`debt_from_month` varchar(7)
,`debt_until_month` varchar(7)
,`total_subscriptions` bigint(21)
,`paid_subscriptions` decimal(22,0)
,`unpaid_subscriptions` decimal(22,0)
,`last_payment_date` date
);

CREATE TABLE IF NOT EXISTS `parent_payments_view` (
`athlete_id` int(11)
,`athlete_name` varchar(200)
,`parent_email` varchar(150)
,`subscription_id` int(11)
,`subscription_type` enum('monthly','quarterly','annual','onetime')
,`subscription_amount` decimal(8,2)
,`paid_at` date
,`valid_from` date
,`valid_until` date
,`payment_status` enum('paid','pending','overdue')
,`payment_method` enum('cash','card','deposit')
,`receipt_number` varchar(100)
);

CREATE TABLE IF NOT EXISTS `parent_users` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `parent_email` varchar(150) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `first_login` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = sent auto-password, parent has not changed password yet',
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `month` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `amount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('cash','card','deposit') DEFAULT 'cash',
  `paid_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `plans` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `slug` enum('basic','pro') NOT NULL,
  `price_monthly` decimal(8,2) DEFAULT 0.00,
  `price_annual` decimal(8,2) DEFAULT 0.00,
  `max_athletes` int(11) DEFAULT 50,
  `sms_enabled` tinyint(1) DEFAULT 0,
  `email_enabled` tinyint(1) DEFAULT 1,
  `competitions_enabled` tinyint(1) DEFAULT 0,
  `economics_enabled` tinyint(1) DEFAULT 0,
  `reports_enabled` tinyint(1) DEFAULT 0,
  `elot_export` tinyint(1) DEFAULT 0,
  `features` text DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reminder_logs` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `subscription_id` int(11) DEFAULT NULL,
  `type` enum('email','sms') DEFAULT 'email',
  `trigger_type` enum('3days_before','on_expiry','5days_after','manual','after_payment','days_before','on_due','days_after') DEFAULT 'manual',
  `recipient` varchar(200) DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `status` enum('sent','failed','pending') DEFAULT 'pending',
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schools` (
  `id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `afm` varchar(20) DEFAULT NULL,
  `doy` varchar(100) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `iban` varchar(34) DEFAULT NULL,
  `bank_iban` varchar(50) DEFAULT NULL,
  `billing_cycle` enum('monthly','annual') DEFAULT 'monthly',
  `next_billing_date` date DEFAULT NULL,
  `subscription_status` enum('active','past_due','suspended','cancelled','trial') DEFAULT 'trial',
  `logo` varchar(255) DEFAULT NULL,
  `facebook` varchar(255) DEFAULT NULL,
  `instagram` varchar(255) DEFAULT NULL,
  `sms_addon` tinyint(1) DEFAULT 0,
  `sms_addon_expires` date DEFAULT NULL,
  `sms_addon_stripe_sub` varchar(100) DEFAULT NULL,
  `plan_id` int(11) DEFAULT 1,
  `plan_expires` date DEFAULT NULL,
  `plan_status` enum('active','trial','expired','suspended') DEFAULT 'trial',
  `trial_ends` date DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `admin_note` text DEFAULT NULL COMMENT 'Εσωτερική σημείωση admin - ορατή μόνο στον superadmin',
  `federation_id` int(11) DEFAULT NULL COMMENT 'FK → federations.id — NULL αν η σχολή δεν ανήκει σε ομοσπονδία',
  `federation_ref_code` varchar(100) DEFAULT NULL COMMENT 'Κωδικός αναφοράς ομοσπονδίας — εσωτερική χρήση',
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_exempt_months` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `month` varchar(7) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_meta` (
  `school_id` int(11) NOT NULL,
  `meta_key` varchar(80) NOT NULL,
  `meta_val` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `school_plan_payments` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `plan_id` int(11) NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `period` enum('monthly','annual') DEFAULT 'monthly',
  `paid_at` timestamp NULL DEFAULT current_timestamp(),
  `valid_until` date NOT NULL,
  `method` enum('card','bank','cash') DEFAULT 'card',
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_events` (
  `id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL COMMENT 'csrf_mismatch, rate_limit, suspicious_redirect κλπ',
  `ip` varchar(45) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'NULL αν δεν υπάρχει session',
  `details` text DEFAULT NULL COMMENT 'Extra context για debugging',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Security events για monitoring και incident response';

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `type` enum('monthly','quarterly','annual','onetime') DEFAULT 'monthly',
  `amount` decimal(8,2) NOT NULL,
  `paid_at` date DEFAULT NULL,
  `valid_from` date NOT NULL,
  `valid_until` date NOT NULL,
  `payment_method` enum('cash','card','deposit') DEFAULT 'cash',
  `receipt_number` varchar(100) DEFAULT NULL,
  `status` enum('paid','pending','overdue') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscription_transactions` (
`id` int(11)
,`school_id` int(11)
,`plan_id` int(11)
,`amount` decimal(8,2)
,`status` varchar(4)
,`stripe_payment_intent` binary(0)
,`created_at` timestamp
,`period` enum('monthly','annual')
,`valid_until` date
,`method` enum('card','bank','cash')
,`notes` text
);

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` int(11) NOT NULL,
  `school_id` int(11) NOT NULL,
  `type` enum('income','expense') NOT NULL,
  `category` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `payment_method` enum('cash','card','deposit','other') DEFAULT 'cash',
  `receipt` varchar(255) DEFAULT NULL,
  `athlete_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_log` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `type` enum('email','sms') NOT NULL DEFAULT 'email',
  `recipient` varchar(200) DEFAULT NULL,
  `subject` varchar(500) DEFAULT NULL,
  `status` enum('sent','failed') NOT NULL DEFAULT 'sent',
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL,
  `school_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `google_id` varchar(64) DEFAULT NULL,
  `avatar_url` varchar(512) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `role` enum('superadmin','employee','maintainer','owner','admin','coach','secretary') DEFAULT 'owner',
  `active` tinyint(1) DEFAULT 1,
  `totp_secret` varchar(100) DEFAULT NULL,
  `totp_enabled` tinyint(1) DEFAULT 0,
  `totp_backup_codes` text DEFAULT NULL,
  `totp_otp_expires` datetime DEFAULT NULL,
  `last_login` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `reset_last_sent` datetime DEFAULT NULL COMMENT 'Last reset email sent at',
  `reset_monthly_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Reset emails sent in the current month window',
  `reset_month_start` datetime DEFAULT NULL COMMENT 'Start of current monthly count window',
  `reset_weekly_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Reset emails sent this week',
  `reset_week_start` datetime DEFAULT NULL COMMENT 'Start of current weekly count window'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `weight_history` (
  `id` int(11) NOT NULL,
  `athlete_id` int(11) NOT NULL,
  `weight` decimal(5,2) NOT NULL,
  `recorded_at` date NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `adult_members`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`);

ALTER TABLE `adult_subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `member_id` (`member_id`);

ALTER TABLE `athletes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `department_id` (`department_id`),
  ADD KEY `idx_athletes_school_active` (`school_id`,`active`);

ALTER TABLE `athlete_pause_periods`
  ADD PRIMARY KEY (`id`),
  ADD KEY `aid` (`athlete_id`),
  ADD KEY `app_fk1` (`school_id`);

ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_action_school` (`action`,`school_id`,`created_at`);

ALTER TABLE `backup_log`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `bank_transfer_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_code` (`reference_code`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_ref` (`reference_code`);

ALTER TABLE `billing_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`),
  ADD KEY `idx_created` (`created_at`);

ALTER TABLE `broadcast_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sent_at` (`sent_at`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `broadcast_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `competitions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`);

ALTER TABLE `competition_participants`
  ADD PRIMARY KEY (`id`),
  ADD KEY `competition_id` (`competition_id`),
  ADD KEY `athlete_id` (`athlete_id`);

ALTER TABLE `cookie_consents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ip` (`ip_hash`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `coupons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

ALTER TABLE `coupon_redemptions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_school_coupon` (`coupon_id`,`school_id`),
  ADD KEY `school_id` (`school_id`);

ALTER TABLE `cron_runs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_job_name` (`job_name`),
  ADD KEY `idx_started_at` (`started_at`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `cron_run_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_run_at` (`run_at`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`);

ALTER TABLE `employee_privileges`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user` (`user_id`);

ALTER TABLE `federations`
  ADD PRIMARY KEY (`id`);

ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip` (`ip`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_time` (`attempted_at`);

ALTER TABLE `monthly_income_reports`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_report` (`school_id`,`report_year`,`report_month`);

ALTER TABLE `notification_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school` (`school_id`);

ALTER TABLE `overage_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_type_status` (`school_id`,`type`,`status`);

ALTER TABLE `parent_children`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_parent_child` (`parent_id`,`athlete_id`),
  ADD KEY `fk_child_athlete` (`athlete_id`);

ALTER TABLE `parent_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_parent_email` (`school_id`,`parent_email`);

ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_athlete_month` (`athlete_id`,`month`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `athlete_id` (`athlete_id`);

ALTER TABLE `plans`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `slug` (`slug`);

ALTER TABLE `reminder_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `athlete_id` (`athlete_id`);

ALTER TABLE `schools`
  ADD PRIMARY KEY (`id`),
  ADD KEY `plan_id` (`plan_id`),
  ADD KEY `idx_federation_id` (`federation_id`);

ALTER TABLE `school_exempt_months`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq` (`school_id`,`month`);

ALTER TABLE `school_meta`
  ADD PRIMARY KEY (`school_id`,`meta_key`);

ALTER TABLE `school_plan_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `plan_id` (`plan_id`);

ALTER TABLE `security_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_ip` (`ip`),
  ADD KEY `idx_created_at` (`created_at`);

ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `athlete_id` (`athlete_id`),
  ADD KEY `idx_subscriptions_status` (`school_id`,`status`,`valid_until`);

ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `athlete_id` (`athlete_id`);

ALTER TABLE `usage_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_school_type_date` (`school_id`,`type`,`sent_at`),
  ADD KEY `idx_user` (`user_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_email` (`email`),
  ADD UNIQUE KEY `google_id` (`google_id`),
  ADD UNIQUE KEY `reset_token` (`reset_token`),
  ADD KEY `school_id` (`school_id`),
  ADD KEY `idx_reset_token` (`reset_token`);

ALTER TABLE `weight_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `athlete_id` (`athlete_id`);

ALTER TABLE `adult_members`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `adult_subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `athletes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

ALTER TABLE `athlete_pause_periods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=691;

ALTER TABLE `backup_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `bank_transfer_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `broadcast_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `broadcast_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

ALTER TABLE `competitions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `competition_participants`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cookie_consents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

ALTER TABLE `coupons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `coupon_redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `cron_runs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

ALTER TABLE `cron_run_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

ALTER TABLE `employee_privileges`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

ALTER TABLE `federations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

ALTER TABLE `monthly_income_reports`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

ALTER TABLE `notification_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

ALTER TABLE `overage_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `parent_children`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `parent_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `plans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `reminder_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

ALTER TABLE `schools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

ALTER TABLE `school_exempt_months`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `school_plan_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

ALTER TABLE `security_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `subscriptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=572;

ALTER TABLE `transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=591;

ALTER TABLE `usage_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=163;

ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

ALTER TABLE `weight_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `adult_members`
  ADD CONSTRAINT `adult_members_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `adult_subscriptions`
  ADD CONSTRAINT `adult_subscriptions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `adult_subscriptions_ibfk_2` FOREIGN KEY (`member_id`) REFERENCES `adult_members` (`id`) ON DELETE CASCADE;

ALTER TABLE `athletes`
  ADD CONSTRAINT `athletes_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `athletes_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;

ALTER TABLE `athlete_pause_periods`
  ADD CONSTRAINT `app_fk1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `app_fk2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

ALTER TABLE `billing_history`
  ADD CONSTRAINT `billing_history_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `competitions`
  ADD CONSTRAINT `competitions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `competition_participants`
  ADD CONSTRAINT `competition_participants_ibfk_1` FOREIGN KEY (`competition_id`) REFERENCES `competitions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `competition_participants_ibfk_2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

ALTER TABLE `coupon_redemptions`
  ADD CONSTRAINT `coupon_redemptions_ibfk_1` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `coupon_redemptions_ibfk_2` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `departments`
  ADD CONSTRAINT `departments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `employee_privileges`
  ADD CONSTRAINT `fk_emp_priv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

ALTER TABLE `monthly_income_reports`
  ADD CONSTRAINT `monthly_income_reports_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `parent_children`
  ADD CONSTRAINT `fk_child_athlete` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_parent_child` FOREIGN KEY (`parent_id`) REFERENCES `parent_users` (`id`) ON DELETE CASCADE;

ALTER TABLE `parent_users`
  ADD CONSTRAINT `fk_parent_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

ALTER TABLE `reminder_logs`
  ADD CONSTRAINT `reminder_logs_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminder_logs_ibfk_2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

ALTER TABLE `schools`
  ADD CONSTRAINT `schools_ibfk_1` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

ALTER TABLE `school_exempt_months`
  ADD CONSTRAINT `sem_fk1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `school_plan_payments`
  ADD CONSTRAINT `school_plan_payments_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `school_plan_payments_ibfk_2` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`);

ALTER TABLE `subscriptions`
  ADD CONSTRAINT `subscriptions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `subscriptions_ibfk_2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE SET NULL;

ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE;

ALTER TABLE `weight_history`
  ADD CONSTRAINT `weight_history_ibfk_1` FOREIGN KEY (`athlete_id`) REFERENCES `athletes` (`id`) ON DELETE CASCADE;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
