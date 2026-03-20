-- BL-008 Phase 1: Vehicle Assignments
CREATE TABLE IF NOT EXISTS vehicle_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    asset_tag VARCHAR(50) NULL,
    asset_name VARCHAR(255) NULL,
    snipeit_user_id INT NOT NULL,
    user_id VARCHAR(32) NOT NULL,
    user_name VARCHAR(255) NULL,
    user_email VARCHAR(255) NULL,
    is_primary TINYINT(1) DEFAULT 0,
    assignment_label VARCHAR(50) NULL,
    assigned_by VARCHAR(32) NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes VARCHAR(255) NULL,
    UNIQUE KEY uq_asset_user (asset_id, user_id),
    INDEX idx_user (user_id),
    INDEX idx_asset (asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kill switch for phased rollout
INSERT IGNORE INTO system_settings (setting_key, setting_value)
VALUES ('vehicle_assignment_mode', 'off');
-- Values: 'off' (no enforcement), 'soft' (warn only), 'enforced' (hard block)
