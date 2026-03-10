<?php
/**
 * CRON: Sync Health Check
 * Runs every 5 minutes. Alerts if sync_checked_out_assets hasn't run
 * in over 5 minutes (indicating CRON failure).
 *
 * @since v1.4.x
 */
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';

$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'last_sync_at'");
$stmt->execute();
$lastSync = $stmt->fetchColumn();

if (!$lastSync) {
    echo "[" . date('Y-m-d H:i:s') . "] No sync timestamp found. Sync may have never run.\n";
    // Log the alert
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['sync_health_status', 'never_run']);
    exit(1);
}

$lastSyncTs = strtotime($lastSync);
$delta = time() - $lastSyncTs;
$deltaMinutes = round($delta / 60, 1);

if ($delta > 300) {
    // Stale — sync hasn't run in over 5 minutes
    echo "[" . date('Y-m-d H:i:s') . "] WARNING: Sync is stale. Last run: {$lastSync} ({$deltaMinutes} min ago)\n";

    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['sync_health_status', 'stale']);
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute(['sync_health_checked_at', date('Y-m-d H:i:s')]);

    // Send alert via NotificationService if available
    try {
        require_once SRC_PATH . '/notification_service.php';
        NotificationService::fire('maintenance_flagged', [
            'vehicle_name' => 'System Health',
            'asset_tag' => 'CRON',
            'notes' => "Asset sync CRON has not run in {$deltaMinutes} minutes (last: {$lastSync}). Vehicle availability data may be stale. Check: sudo crontab -l | grep sync",
        ], $pdo);
        echo "[" . date('Y-m-d H:i:s') . "] Alert notification sent.\n";
    } catch (Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Could not send alert: " . $e->getMessage() . "\n";
    }

    exit(1);
}

// Healthy
$pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
    ->execute(['sync_health_status', 'healthy']);
$pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
    ->execute(['sync_health_checked_at', date('Y-m-d H:i:s')]);

echo "[" . date('Y-m-d H:i:s') . "] Sync healthy. Last run: {$lastSync} ({$deltaMinutes} min ago)\n";
