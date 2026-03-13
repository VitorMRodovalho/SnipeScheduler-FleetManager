-- BL-006 Enhancement: Database-driven inspection checklist
-- Adds checklist profiles, categories, items, and assignments.

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

-- Seed: Migrate existing hardcoded checklist as default profile (idempotent)
INSERT IGNORE INTO checklist_profiles (id, name, description, is_default, is_active, created_by)
VALUES (1, 'Standard Fleet Inspection', 'Default 50-item inspection checklist covering general, tires, interior, lights, mechanical, windows, emergency equipment, other equipment, and overall assessment.', 1, 1, 'system');

-- Categories (9 categories matching the hardcoded INSPECTION_CHECKLIST)
INSERT IGNORE INTO checklist_categories (id, profile_id, name, sort_order, is_active) VALUES
(1, 1, 'General', 1, 1),
(2, 1, 'Tires', 2, 1),
(3, 1, 'Interior', 3, 1),
(4, 1, 'Lights & Signals', 4, 1),
(5, 1, 'Mechanical', 5, 1),
(6, 1, 'Windows & Glass', 6, 1),
(7, 1, 'Emergency Equipment', 7, 1),
(8, 1, 'Other Equipment', 8, 1),
(9, 1, 'Overall Assessment', 9, 1);

-- Items (all items from INSPECTION_CHECKLIST with safety-critical flags)
INSERT IGNORE INTO checklist_items (id, category_id, label, sort_order, is_active, is_safety_critical, applies_to) VALUES
-- General (category 1)
(1,  1, 'Valid registration document present', 1, 1, 0, 'both'),
(2,  1, 'Insurance card / proof of insurance present', 2, 1, 0, 'both'),
(3,  1, 'Vehicle exterior and interior reasonably clean', 3, 1, 0, 'both'),
-- Tires (category 2)
(4,  2, 'Front-Left tire condition and inflation', 1, 1, 0, 'both'),
(5,  2, 'Front-Right tire condition and inflation', 2, 1, 0, 'both'),
(6,  2, 'Rear-Left tire condition and inflation', 3, 1, 0, 'both'),
(7,  2, 'Rear-Right tire condition and inflation', 4, 1, 0, 'both'),
-- Interior (category 3)
(8,  3, 'All seatbelts functional', 1, 1, 1, 'both'),
(9,  3, 'Rearview and side mirrors adjusted/intact', 2, 1, 0, 'both'),
(10, 3, 'No dashboard warning lights illuminated', 3, 1, 0, 'both'),
(11, 3, 'Horn functional', 4, 1, 0, 'both'),
(12, 3, 'Windshield wipers functional', 5, 1, 0, 'both'),
(13, 3, 'Climate control / AC / heater operational', 6, 1, 0, 'both'),
(14, 3, 'Interior clean and free of debris', 7, 1, 0, 'both'),
(15, 3, 'All gauges and indicators functional', 8, 1, 0, 'both'),
(16, 3, 'USB / charging ports functional', 9, 1, 0, 'both'),
-- Lights & Signals (category 4)
(17, 4, 'Headlights — low beam', 1, 1, 1, 'both'),
(18, 4, 'Headlights — high beam', 2, 1, 0, 'both'),
(19, 4, 'Tail lights', 3, 1, 0, 'both'),
(20, 4, 'Brake lights', 4, 1, 1, 'both'),
(21, 4, 'Turn signals (left and right)', 5, 1, 0, 'both'),
(22, 4, 'Hazard / emergency flashers', 6, 1, 0, 'both'),
(23, 4, 'Reverse lights', 7, 1, 0, 'both'),
-- Mechanical (category 5)
(24, 5, 'Brakes responsive, no unusual noise', 1, 1, 1, 'both'),
(25, 5, 'Steering responsive, no play', 2, 1, 1, 'both'),
(26, 5, 'Engine starts and runs smoothly', 3, 1, 0, 'both'),
(27, 5, 'Transmission shifts smoothly', 4, 1, 0, 'both'),
(28, 5, 'Visible fluid levels normal (oil, coolant, washer)', 5, 1, 0, 'both'),
(29, 5, 'No visible fluid leaks under vehicle', 6, 1, 0, 'both'),
-- Windows & Glass (category 6)
(30, 6, 'Windshield — no cracks or chips', 1, 1, 1, 'both'),
(31, 6, 'Side windows — intact, open/close properly', 2, 1, 0, 'both'),
(32, 6, 'Rear window — intact, clear visibility', 3, 1, 0, 'both'),
-- Emergency Equipment (category 7)
(33, 7, 'Fire extinguisher present and charged', 1, 1, 1, 'both'),
(34, 7, 'First aid kit present and stocked', 2, 1, 0, 'both'),
(35, 7, 'Safety triangle / reflective warning device', 3, 1, 0, 'both'),
(36, 7, 'Emergency contact info / accident instruction sheet', 4, 1, 0, 'both'),
-- Other Equipment (category 8)
(37, 8, 'Spare tire present and inflated', 1, 1, 0, 'both'),
(38, 8, 'GPS / navigation system functional (if equipped)', 2, 1, 0, 'both'),
(39, 8, 'Vehicle binder / documentation folder present', 3, 1, 0, 'both'),
(40, 8, 'Fuel level adequate for trip', 4, 1, 0, 'both'),
(41, 8, 'Parking brake engages and releases properly', 5, 1, 0, 'both'),
-- Overall Assessment (category 9)
(42, 9, 'Additional comments or observations', 1, 1, 0, 'both');
