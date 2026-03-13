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

    status ENUM('pending','confirmed','completed','cancelled','missed','maintenance_required','redirected') NOT NULL DEFAULT 'pending',
    approval_status ENUM('pending_approval','approved','rejected','auto_approved') DEFAULT 'pending_approval',

    pickup_location_id INT UNSIGNED DEFAULT NULL,
    destination_id INT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    redirected_from_id INT UNSIGNED DEFAULT NULL,

    checkout_form_data JSON DEFAULT NULL,
    checkin_form_data JSON DEFAULT NULL,
    maintenance_flag TINYINT(1) NOT NULL DEFAULT 0,
    maintenance_notes TEXT DEFAULT NULL,

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
    KEY idx_reservations_status (status),
    KEY idx_reservations_approval (approval_status),
    KEY idx_redirected_from (redirected_from_id)
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
VALUES ('v2.0.0');

-- ------------------------------------------------------
-- System settings (key-value configuration store)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(255) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Email notification settings (per-event configuration)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_notification_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_key VARCHAR(255) NOT NULL,
    event_name VARCHAR(255) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    notify_requester TINYINT(1) NOT NULL DEFAULT 0,
    notify_staff TINYINT(1) NOT NULL DEFAULT 0,
    notify_admin TINYINT(1) NOT NULL DEFAULT 1,
    custom_emails TEXT DEFAULT NULL,
    subject_template VARCHAR(255) DEFAULT NULL,
    body_template TEXT DEFAULT NULL,
    channel VARCHAR(50) NOT NULL DEFAULT 'email',

    PRIMARY KEY (id),
    UNIQUE KEY uq_event_key (event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Email queue (async delivery for email and Teams)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    to_email VARCHAR(255) DEFAULT NULL,
    to_name VARCHAR(255) DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    body LONGTEXT NOT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    channel VARCHAR(50) NOT NULL DEFAULT 'email',
    teams_audience VARCHAR(255) DEFAULT NULL,
    action_url VARCHAR(512) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at DATETIME DEFAULT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT DEFAULT NULL,

    PRIMARY KEY (id),
    KEY idx_queue_status (status),
    KEY idx_queue_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Approval history
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS approval_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    action ENUM('submitted','approved','rejected','auto_approved','auto_cancelled','missed','redirected','redirect_failed') NOT NULL,
    actor_name VARCHAR(255) NOT NULL,
    actor_email VARCHAR(255) NOT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_approval_reservation (reservation_id),
    KEY idx_approval_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Announcements
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    type ENUM('info','warning','success','danger') NOT NULL DEFAULT 'info',
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    show_once TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    created_by_name VARCHAR(255) NOT NULL,
    created_by_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_announcements_active (is_active, start_datetime, end_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Announcement dismissals (per-user tracking)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcement_dismissals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    announcement_id INT UNSIGNED NOT NULL,
    user_email VARCHAR(255) NOT NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_dismiss_user (announcement_id, user_email),
    KEY idx_dismiss_email (user_email),
    CONSTRAINT fk_dismiss_announcement
        FOREIGN KEY (announcement_id) REFERENCES announcements (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Blackout slots (blocked reservation periods)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS blackout_slots (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    asset_id INT UNSIGNED DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    created_by_name VARCHAR(255) NOT NULL,
    created_by_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_blackout_dates (start_datetime, end_datetime),
    KEY idx_blackout_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Notification log (CRON dedup tracking)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reservation_id INT UNSIGNED NOT NULL,
    notification_type ENUM('pickup_reminder','overdue_alert','missed_notice','redirect_success','redirect_failed','redirect_staff_alert','overdue_redirect') NOT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notif_reservation (reservation_id),
    KEY idx_notif_type (notification_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Holidays (business day exclusions)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_type ENUM('federal_major','federal_minor','custom') NOT NULL DEFAULT 'custom',
    is_recurring TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uk_holiday_date_name (holiday_date, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Maintenance log (service records)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    asset_name VARCHAR(255) NOT NULL,
    maintenance_type VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    mileage_at_service INT UNSIGNED NOT NULL DEFAULT 0,
    service_date DATE NOT NULL,
    service_provider VARCHAR(255) NOT NULL,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    next_maintenance_miles INT UNSIGNED DEFAULT 0,
    next_maintenance_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_by_name VARCHAR(255) NOT NULL,
    created_by_email VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_maint_asset (asset_id),
    KEY idx_maint_date (service_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Maintenance schedule (per-vehicle planned maintenance)
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_schedule (
    asset_id INT UNSIGNED NOT NULL,
    asset_tag VARCHAR(255) NOT NULL,
    expected_return_date DATE DEFAULT NULL,
    maintenance_notes TEXT DEFAULT NULL,
    updated_by_name VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Seed default notification events
-- ------------------------------------------------------
INSERT INTO email_notification_settings (event_key, event_name, enabled, notify_requester, notify_staff, notify_admin, channel) VALUES
    ('reservation_submitted',       'Reservation Submitted',               1, 1, 1, 0, 'email'),
    ('reservation_approved',        'Reservation Approved',                1, 1, 0, 0, 'email'),
    ('reservation_rejected',        'Reservation Rejected',                1, 1, 0, 0, 'email'),
    ('vehicle_checked_out',         'Vehicle Checked Out',                 1, 1, 1, 0, 'email'),
    ('vehicle_checked_in',          'Vehicle Checked In',                  1, 1, 1, 0, 'email'),
    ('maintenance_flagged',         'Maintenance Flagged',                 1, 0, 1, 1, 'email'),
    ('pickup_reminder',             'Pickup Reminder',                     1, 1, 0, 0, 'email'),
    ('return_overdue',              'Vehicle Overdue',                     1, 1, 1, 0, 'email'),
    ('reservation_cancelled',       'Reservation Cancelled',               1, 1, 0, 0, 'email'),
    ('mileage_anomaly',             'Mileage Anomaly Detected',            1, 0, 0, 1, 'email'),
    ('compliance_expiring',         'Compliance Document Expiring',        1, 0, 0, 1, 'email'),
    ('reservation_redirected',      'Reservation Redirected',              1, 1, 1, 0, 'email'),
    ('reservation_redirect_failed', 'Reservation Cancelled (No Alternate)',1, 1, 1, 0, 'email'),
    ('overdue_redirect_staff',      'Overdue Vehicle Staff Alert',         1, 0, 1, 1, 'email'),
    ('training_expiring',           'Driver Training Expiring',            1, 0, 1, 1, 'email'),
    ('force_checkin',               'Force Check-In by Staff',             1, 0, 1, 1, 'email'),
    ('reservation_missed_driver',   'Reservation Missed (Driver)',         1, 1, 0, 0, 'email'),
    ('reservation_missed_staff',    'Reservation Missed (Staff Alert)',    1, 0, 1, 1, 'email'),
    ('malware_detected',            'Malware Detected in Upload',          1, 0, 0, 1, 'email'),
    ('safety_critical_override',    'Safety-Critical Override at Checkout', 1, 0, 1, 1, 'email')
ON DUPLICATE KEY UPDATE event_key = event_key;

-- ------------------------------------------------------
-- Seed default system settings
-- ------------------------------------------------------
INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
    ('smtp_enabled', '0'),
    ('inspection_mode', 'full'),
    ('session_timeout_minutes', '30'),
    ('missed_cutoff_minutes', '60'),
    ('missed_release_buffer_hours', '0'),
    ('business_day_buffer', '2'),
    ('business_days_monday', '1'),
    ('business_days_tuesday', '1'),
    ('business_days_wednesday', '1'),
    ('business_days_thursday', '1'),
    ('business_days_friday', '1'),
    ('business_days_saturday', '0'),
    ('business_days_sunday', '0'),
    ('redirect_overdue_minutes', '30'),
    ('redirect_lookahead_hours', '24'),
    ('data_retention_activity_log_days', '365'),
    ('data_retention_photos_days', '730'),
    ('data_retention_email_queue_days', '30'),
    ('multi_company_mode', 'auto'),
    ('show_release_announcements', '1');

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

-- ------------------------------------------------------
-- DSAR (Data Subject Access Request) Tracking
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS data_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_type ENUM('access', 'deletion', 'correction') NOT NULL,
    requester_email VARCHAR(255) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    processed_by VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'completed', 'denied') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    INDEX idx_data_requests_email (requester_email),
    INDEX idx_data_requests_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- BL-006: Inspection Checklist Profiles
-- ------------------------------------------------------
CREATE TABLE IF NOT EXISTS checklist_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT NULL,
    is_default TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_by VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checklist_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    INDEX idx_profile (profile_id),
    FOREIGN KEY (profile_id) REFERENCES checklist_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checklist_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    is_safety_critical TINYINT(1) DEFAULT 0,
    applies_to ENUM('both','checkout','checkin') DEFAULT 'both',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category_id),
    FOREIGN KEY (category_id) REFERENCES checklist_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS checklist_profile_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id INT UNSIGNED NOT NULL,
    snipeit_model_id INT UNSIGNED DEFAULT NULL,
    snipeit_category_id INT UNSIGNED DEFAULT NULL,
    INDEX idx_profile (profile_id),
    INDEX idx_model (snipeit_model_id),
    INDEX idx_category (snipeit_category_id),
    FOREIGN KEY (profile_id) REFERENCES checklist_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
