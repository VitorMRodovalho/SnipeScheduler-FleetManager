<?php
/**
 * AJAX Endpoint: Save Per-Event Channel
 *
 * URL:    POST /api/save_event_channel
 * Access: Fleet Admin only (group 4)
 * Deploy: /var/www/snipescheduler/public/api/save_event_channel.php
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

$event_key = trim($input['event_key'] ?? '');
$channel   = trim($input['channel']   ?? '');

$valid_channels = ['email', 'teams', 'both', 'none'];
if (empty($event_key) || !in_array($channel, $valid_channels, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid event_key or channel']);
    exit;
}

// Validate event_key against known events to prevent arbitrary DB writes
require_once __DIR__ . '/../../src/notification_service.php';
$known_events = array_keys(NotificationService::getEvents());
if (!in_array($event_key, $known_events, true)) {
    echo json_encode(['success' => false, 'error' => 'Unknown event key']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        UPDATE email_notification_settings
        SET channel = :channel
        WHERE event_key = :event_key
    ");
    $stmt->execute([':channel' => $channel, ':event_key' => $event_key]);

    if ($stmt->rowCount() === 0) {
        // Row might not exist yet — insert it
        $pdo->prepare("
            INSERT INTO email_notification_settings (event_key, channel)
            VALUES (:event_key, :channel)
            ON DUPLICATE KEY UPDATE channel = VALUES(channel)
        ")->execute([':event_key' => $event_key, ':channel' => $channel]);
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('[save_event_channel] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
