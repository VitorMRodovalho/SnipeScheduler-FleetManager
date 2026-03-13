-- v2.0.0: Data compliance settings
-- Retention periods + session timeout

INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES
  ('data_retention_activity_log_days', '365'),
  ('data_retention_photos_days', '730'),
  ('data_retention_email_queue_days', '30'),
  ('session_timeout_minutes', '30');
