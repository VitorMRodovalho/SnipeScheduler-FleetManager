-- ============================================================
-- v1.3.5 Migration: Future Availability & Reservation Redirect
-- Run: mysql -u vitor -p snipescheduler < migrations/v1_3_5_future_availability.sql
-- ============================================================

-- 1. Holidays table
CREATE TABLE IF NOT EXISTS holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    holiday_type ENUM('federal_major', 'federal_minor', 'custom') NOT NULL DEFAULT 'custom',
    is_recurring TINYINT(1) DEFAULT 0 COMMENT 'If 1, recurs annually (uses month/day only)',
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_holiday_date_name (holiday_date, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. New system_settings keys (business day config + redirect timing)
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('business_day_buffer', '2'),
    ('business_days_saturday', '0'),
    ('business_days_sunday', '0'),
    ('business_days_monday', '1'),
    ('business_days_tuesday', '1'),
    ('business_days_wednesday', '1'),
    ('business_days_thursday', '1'),
    ('business_days_friday', '1'),
    ('redirect_overdue_minutes', '30'),
    ('redirect_lookahead_hours', '24')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- 3. Add redirected_from_id to reservations
ALTER TABLE reservations
    ADD COLUMN IF NOT EXISTS redirected_from_id INT UNSIGNED NULL DEFAULT NULL AFTER notes;

-- Add index only if column was just added (ignore error if exists)
-- MariaDB/MySQL will error if index exists, so we use a safe approach
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = 'reservations' AND index_name = 'idx_redirected_from');
SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE reservations ADD INDEX idx_redirected_from (redirected_from_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Expand reservation status enum to include 'redirected'
ALTER TABLE reservations
    MODIFY COLUMN status ENUM(
        'pending', 'confirmed', 'completed', 'cancelled',
        'missed', 'maintenance_required', 'redirected'
    ) DEFAULT 'pending';

-- 5. Expand approval_history action enum
ALTER TABLE approval_history
    MODIFY COLUMN action ENUM(
        'submitted', 'approved', 'rejected', 'auto_approved',
        'auto_cancelled', 'missed', 'redirected', 'redirect_failed'
    ) NOT NULL;

-- 6. Expand notification_log types
ALTER TABLE notification_log
    MODIFY COLUMN notification_type ENUM(
        'pickup_reminder', 'overdue_alert', 'missed_notice',
        'redirect_success', 'redirect_failed', 'redirect_staff_alert'
    ) NOT NULL;

-- 7. New email notification events
INSERT INTO email_notification_settings (event_key, event_name, enabled, notify_requester, notify_staff, notify_admin) VALUES
    ('reservation_redirected', 'Reservation Redirected (Vehicle Swap)', 1, 1, 1, 0),
    ('reservation_redirect_failed', 'Reservation Cancelled (No Alternate)', 1, 1, 1, 0),
    ('overdue_redirect_staff', 'Overdue Vehicle - Staff Alert (Redirect)', 1, 0, 1, 1)
ON DUPLICATE KEY UPDATE event_key = event_key;

-- 8. Seed US Federal Holidays 2025-2030
-- Major holidays (most offices closed)
INSERT INTO holidays (name, holiday_date, holiday_type, is_recurring, is_active) VALUES
    -- 2025
    ("New Year's Day", '2025-01-01', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2025-01-20', 'federal_major', 0, 1),
    ('Memorial Day', '2025-05-26', 'federal_major', 0, 1),
    ('Juneteenth', '2025-06-19', 'federal_major', 0, 1),
    ('Independence Day', '2025-07-04', 'federal_major', 0, 1),
    ('Labor Day', '2025-09-01', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2025-11-27', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2025-11-28', 'federal_major', 0, 1),
    ('Christmas Day', '2025-12-25', 'federal_major', 0, 1),
    -- 2026
    ("New Year's Day", '2026-01-01', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2026-01-19', 'federal_major', 0, 1),
    ('Memorial Day', '2026-05-25', 'federal_major', 0, 1),
    ('Juneteenth', '2026-06-19', 'federal_major', 0, 1),
    ('Independence Day (Observed)', '2026-07-03', 'federal_major', 0, 1),
    ('Labor Day', '2026-09-07', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2026-11-26', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2026-11-27', 'federal_major', 0, 1),
    ('Christmas Day', '2026-12-25', 'federal_major', 0, 1),
    -- 2027
    ("New Year's Day", '2027-01-01', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2027-01-18', 'federal_major', 0, 1),
    ('Memorial Day', '2027-05-31', 'federal_major', 0, 1),
    ('Juneteenth (Observed)', '2027-06-18', 'federal_major', 0, 1),
    ('Independence Day (Observed)', '2027-07-05', 'federal_major', 0, 1),
    ('Labor Day', '2027-09-06', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2027-11-25', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2027-11-26', 'federal_major', 0, 1),
    ('Christmas Day (Observed)', '2027-12-24', 'federal_major', 0, 1),
    -- 2028
    ("New Year's Day (Observed)", '2028-01-03', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2028-01-17', 'federal_major', 0, 1),
    ('Memorial Day', '2028-05-29', 'federal_major', 0, 1),
    ('Juneteenth', '2028-06-19', 'federal_major', 0, 1),
    ('Independence Day', '2028-07-04', 'federal_major', 0, 1),
    ('Labor Day', '2028-09-04', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2028-11-23', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2028-11-24', 'federal_major', 0, 1),
    ('Christmas Day', '2028-12-25', 'federal_major', 0, 1),
    -- 2029
    ("New Year's Day", '2029-01-01', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2029-01-15', 'federal_major', 0, 1),
    ('Memorial Day', '2029-05-28', 'federal_major', 0, 1),
    ('Juneteenth', '2029-06-19', 'federal_major', 0, 1),
    ('Independence Day', '2029-07-04', 'federal_major', 0, 1),
    ('Labor Day', '2029-09-03', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2029-11-22', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2029-11-23', 'federal_major', 0, 1),
    ('Christmas Day', '2029-12-25', 'federal_major', 0, 1),
    -- 2030
    ("New Year's Day", '2030-01-01', 'federal_major', 0, 1),
    ('Martin Luther King Jr. Day', '2030-01-21', 'federal_major', 0, 1),
    ('Memorial Day', '2030-05-27', 'federal_major', 0, 1),
    ('Juneteenth', '2030-06-19', 'federal_major', 0, 1),
    ('Independence Day', '2030-07-04', 'federal_major', 0, 1),
    ('Labor Day', '2030-09-02', 'federal_major', 0, 1),
    ('Thanksgiving Day', '2030-11-28', 'federal_major', 0, 1),
    ('Day After Thanksgiving', '2030-11-29', 'federal_major', 0, 1),
    ('Christmas Day', '2030-12-25', 'federal_major', 0, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Minor holidays (some offices open)
INSERT INTO holidays (name, holiday_date, holiday_type, is_recurring, is_active) VALUES
    -- 2025
    ("Presidents' Day", '2025-02-17', 'federal_minor', 0, 0),
    ('Columbus Day', '2025-10-13', 'federal_minor', 0, 0),
    ('Veterans Day', '2025-11-11', 'federal_minor', 0, 0),
    -- 2026
    ("Presidents' Day", '2026-02-16', 'federal_minor', 0, 0),
    ('Columbus Day', '2026-10-12', 'federal_minor', 0, 0),
    ('Veterans Day', '2026-11-11', 'federal_minor', 0, 0),
    -- 2027
    ("Presidents' Day", '2027-02-15', 'federal_minor', 0, 0),
    ('Columbus Day', '2027-10-11', 'federal_minor', 0, 0),
    ('Veterans Day', '2027-11-11', 'federal_minor', 0, 0),
    -- 2028
    ("Presidents' Day", '2028-02-21', 'federal_minor', 0, 0),
    ('Columbus Day', '2028-10-09', 'federal_minor', 0, 0),
    ('Veterans Day (Observed)', '2028-11-10', 'federal_minor', 0, 0),
    -- 2029
    ("Presidents' Day", '2029-02-19', 'federal_minor', 0, 0),
    ('Columbus Day', '2029-10-08', 'federal_minor', 0, 0),
    ('Veterans Day (Observed)', '2029-11-12', 'federal_minor', 0, 0),
    -- 2030
    ("Presidents' Day", '2030-02-18', 'federal_minor', 0, 0),
    ('Columbus Day', '2030-10-14', 'federal_minor', 0, 0),
    ('Veterans Day', '2030-11-11', 'federal_minor', 0, 0)
ON DUPLICATE KEY UPDATE name = VALUES(name);
