-- v2.0.0: Seed missing notification events
-- training_expiring was in EVENTS constant but never seeded in DB
-- force_checkin is a new event for staff force check-in alerts

INSERT INTO email_notification_settings
    (event_key, event_name, enabled, notify_requester, notify_staff, notify_admin, channel)
VALUES
    ('training_expiring', 'Driver Training Expiring', 1, 0, 1, 1, 'email'),
    ('force_checkin', 'Force Check-In by Staff', 1, 0, 1, 1, 'email')
ON DUPLICATE KEY UPDATE event_key = event_key;
