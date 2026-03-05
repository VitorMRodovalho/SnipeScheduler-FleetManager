<?php
/**
 * AJAX Endpoint: Test Teams Webhook
 *
 * URL:    POST /api/test_teams_webhook
 * Access: Fleet Admin only (group 4)
 *
 * Request body (JSON):
 *   { "audience": "fleet_ops" | "admin" }
 *
 * Response (JSON):
 *   { "success": true }
 *   { "success": false, "error": "..." }
 *
 * Deploy to: /var/www/snipescheduler/public/api/test_teams_webhook.php
 * (The existing .htaccess RewriteRule handles clean URLs automatically.)
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../src/teams_service.php';

header('Content-Type: application/json');

// ------------------------------------------------------------------
// Auth: Fleet Admin only
// ------------------------------------------------------------------
session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['group_id'] ?? 0) != 4) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ------------------------------------------------------------------
// CSRF check (use your existing token pattern)
// ------------------------------------------------------------------
$input = json_decode(file_get_contents('php://input'), true) ?? [];
if (empty($input['csrf_token']) || $input['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ------------------------------------------------------------------
// Validate input
// ------------------------------------------------------------------
$audience = $input['audience'] ?? 'fleet_ops';
if (!in_array($audience, ['fleet_ops', 'admin'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid audience']);
    exit;
}

// ------------------------------------------------------------------
// Load webhook URL from system_settings
// ------------------------------------------------------------------
require_once __DIR__ . '/../../src/notification_service.php';
$settings = NotificationService::loadSystemSettings($pdo);

if (empty($settings['teams_webhook_enabled']) || $settings['teams_webhook_enabled'] !== '1') {
    echo json_encode(['success' => false, 'error' => 'Teams notifications are not enabled.']);
    exit;
}

$url_key = ($audience === 'admin') ? 'teams_webhook_url_admin' : 'teams_webhook_url_fleet_ops';
$url     = $settings[$url_key] ?? '';

if (empty($url)) {
    $label = ($audience === 'admin') ? 'Admin Channel' : 'Fleet Ops Channel';
    echo json_encode(['success' => false, 'error' => "Webhook URL not configured for: {$label}"]);
    exit;
}

// ------------------------------------------------------------------
// Send test card
// ------------------------------------------------------------------
$result = TeamsService::sendTest($url, $audience);

if ($result['success']) {
    echo json_encode(['success' => true]);
} else {
    $error = $result['error'] ?: "HTTP {$result['http_code']}";
    echo json_encode(['success' => false, 'error' => "Delivery failed: {$error}"]);
}
