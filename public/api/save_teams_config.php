<?php
/**
 * AJAX Endpoint: Save Teams Configuration
 *
 * URL:    POST /api/save_teams_config
 * Access: Fleet Admin only (group 4)
 * Deploy: /var/www/snipescheduler/public/api/save_teams_config.php
 */

require_once __DIR__ . '/../../config/config.php';
header('Content-Type: application/json');

session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['group_id'] ?? 0) != 4) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

if (empty($input['csrf_token']) || $input['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$allowed_keys = ['teams_webhook_enabled', 'teams_webhook_url_fleet_ops', 'teams_webhook_url_admin'];

try {
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    foreach ($allowed_keys as $key) {
        if (isset($input[$key])) {
            $value = ($key === 'teams_webhook_enabled')
                ? ($input[$key] === '1' ? '1' : '0')
                : trim($input[$key]);

            // Basic URL validation for webhook URLs
            if (str_contains($key, '_url') && !empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => false, 'error' => "Invalid URL for {$key}"]);
                exit;
            }

            $stmt->execute([':key' => $key, ':value' => $value]);
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('[save_teams_config] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
