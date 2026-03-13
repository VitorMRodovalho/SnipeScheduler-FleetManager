/*
 * Snipe-IT Booking App – Database Schema
 * -------------------------------------
 * This schema contains ONLY tables owned by the booking application.
 * It does NOT modify or depend on the Snipe-IT production database.
 *
 * Safe to commit to GitHub.
 */

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ------------------------------------------------------
-- Users table
-- (local representation of authenticated users)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_user_id (user_id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservations table
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id VARCHAR(64) NOT NULL,    -- user identifier
    user_name VARCHAR(255) NOT NULL, -- user display name
    user_email VARCHAR(255) NOT NULL,
    snipeit_user_id INT UNSIGNED DEFAULT NULL, -- optional link to Snipe-IT user id

    asset_id INT UNSIGNED NOT NULL DEFAULT 0,  -- optional: single-asset reservations
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,

    status ENUM('pending','confirmed','completed','cancelled','missed') NOT NULL DEFAULT 'pending',

    -- Cached display string of items (for quick admin lists)
    asset_name_cache TEXT NULL,

    -- Company badge data (captured at booking time)
    company_name VARCHAR(255) DEFAULT NULL,
    company_abbr VARCHAR(10) DEFAULT NULL,
    company_color VARCHAR(10) DEFAULT NULL,

    -- Missed reservation resolution
    missed_resolved TINYINT(1) NOT NULL DEFAULT 0,
    missed_resolved_by VARCHAR(255) DEFAULT NULL,
    missed_resolved_at DATETIME DEFAULT NULL,

    -- Key handover tracking
    key_collected TINYINT(1) NOT NULL DEFAULT 0,
    key_collected_by VARCHAR(255) DEFAULT NULL,
    key_collected_at DATETIME DEFAULT NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_reservations_user_id (user_id),
    KEY idx_reservations_dates (start_datetime, end_datetime),
    KEY idx_reservations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Reservation items
-- (models + quantities per reservation)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS reservation_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name_cache VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    PRIMARY KEY (id),
    KEY idx_reservation_items_reservation (reservation_id),
    KEY idx_reservation_items_model (model_id),

    CONSTRAINT fk_res_items_reservation
        FOREIGN KEY (reservation_id)
        REFERENCES reservations (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Cached checked-out assets (from Snipe-IT sync)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checked_out_asset_cache (
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    model_id INT UNSIGNED NOT NULL,
    model_name VARCHAR(255) NOT NULL,
    assigned_to_id INT UNSIGNED DEFAULT NULL,
    assigned_to_name VARCHAR(255) DEFAULT NULL,
    assigned_to_email VARCHAR(255) DEFAULT NULL,
    assigned_to_username VARCHAR(255) DEFAULT NULL,
    status_label VARCHAR(255) DEFAULT NULL,
    last_checkout VARCHAR(32) DEFAULT NULL,
    expected_checkin VARCHAR(32) DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (asset_id),
    KEY idx_checked_out_model (model_id),
    KEY idx_checked_out_expected (expected_checkin),
    KEY idx_checked_out_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Activity log
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type VARCHAR(64) NOT NULL,
    actor_user_id VARCHAR(64) DEFAULT NULL,
    actor_name VARCHAR(255) DEFAULT NULL,
    actor_email VARCHAR(255) DEFAULT NULL,
    subject_type VARCHAR(64) DEFAULT NULL,
    subject_id VARCHAR(64) DEFAULT NULL,
    message VARCHAR(255) NOT NULL,
    metadata TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_activity_event (event_type),
    KEY idx_activity_actor (actor_user_id),
    KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Optional: simple schema versioning
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_version (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    version VARCHAR(32) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_schema_version_version (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_version (version)
VALUES ('v0.8.0-beta');

-- ------------------------------------------------------
-- BL-006: Full Inspection Responses
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_responses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    inspection_type ENUM('checkout','checkin') NOT NULL,
    inspector_email VARCHAR(255) NOT NULL,
    response_data JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_insp_reservation_type (reservation_id, inspection_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- BL-007: Inspection Photos
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_photos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    inspection_type ENUM('checkout','checkin') NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    mime_type VARCHAR(50) NOT NULL,
    uploaded_by_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_photo_reservation_type (reservation_id, inspection_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
