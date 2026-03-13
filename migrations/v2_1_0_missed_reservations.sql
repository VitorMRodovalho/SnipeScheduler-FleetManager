-- v2.0.0: Missed reservations — action tracking, key handover, configurable buffer

ALTER TABLE reservations ADD COLUMN IF NOT EXISTS missed_resolved TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS missed_resolved_by VARCHAR(255) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS missed_resolved_at DATETIME DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS key_collected TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS key_collected_by VARCHAR(255) DEFAULT NULL;
ALTER TABLE reservations ADD COLUMN IF NOT EXISTS key_collected_at DATETIME DEFAULT NULL;

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('missed_release_buffer_hours', '0'),
  ('missed_cutoff_minutes', '60');

-- Notification events for missed reservations
INSERT INTO email_notification_settings
    (event_key, event_name, enabled, notify_requester, notify_staff, notify_admin, channel)
VALUES
    ('reservation_missed_driver', 'Reservation Missed (Driver)', 1, 1, 0, 0, 'email'),
    ('reservation_missed_staff', 'Reservation Missed (Staff Alert)', 1, 0, 1, 1, 'email')
ON DUPLICATE KEY UPDATE event_key = event_key;
