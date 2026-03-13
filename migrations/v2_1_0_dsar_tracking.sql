-- DSAR (Data Subject Access Request) Tracking
-- Tracks data access exports and deletion requests for CCPA compliance.

CREATE TABLE IF NOT EXISTS data_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_type ENUM('access', 'deletion', 'correction') NOT NULL,
    requester_email VARCHAR(255) NOT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    processed_by VARCHAR(255) DEFAULT NULL,
    status ENUM('pending', 'completed', 'denied') DEFAULT 'pending',
    notes TEXT DEFAULT NULL,
    INDEX idx_email (requester_email),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
