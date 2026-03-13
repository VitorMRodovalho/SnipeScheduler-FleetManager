#!/usr/bin/env php
<?php
/**
 * CRON: Data Retention Purge
 *
 * Automatically deletes old data per configurable retention periods.
 * Schedule: weekly, Sunday 3:00 AM
 *   0 3 * * 0 php /path/to/scripts/cron_data_retention.php >> /var/log/snipescheduler/data_retention.log 2>&1
 *
 * @since v2.0.0
 */

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';

// Prevent concurrent runs
$lockFile = sys_get_temp_dir() . '/fleet_data_retention.lock';
$fp = fopen($lockFile, 'w');
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " [SKIP] Another retention job is already running.\n";
    exit(0);
}

echo date('Y-m-d H:i:s') . " [START] Data retention purge\n";

/**
 * Load a retention setting from system_settings, with a default fallback.
 */
function get_retention_days(PDO $pdo, string $key, int $default): int
{
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int)$val : $default;
}

$totalPurged = 0;

// ──────────────────────────────────────────────
// 1. Email Queue: DELETE sent/failed rows older than N days
// ──────────────────────────────────────────────
$emailDays = get_retention_days($pdo, 'data_retention_email_queue_days', 30);
try {
    $stmt = $pdo->prepare("
        DELETE FROM email_queue
        WHERE status IN ('sent', 'failed')
          AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
    ");
    $stmt->execute([':days' => $emailDays]);
    $emailPurged = $stmt->rowCount();
    $totalPurged += $emailPurged;
    echo date('Y-m-d H:i:s') . " [EMAIL_QUEUE] Purged {$emailPurged} rows older than {$emailDays} days\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] email_queue: " . $e->getMessage() . "\n";
}

// ──────────────────────────────────────────────
// 2. Activity Log: DELETE rows older than N days
// ──────────────────────────────────────────────
$activityDays = get_retention_days($pdo, 'data_retention_activity_log_days', 365);
try {
    $stmt = $pdo->prepare("
        DELETE FROM activity_log
        WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
    ");
    $stmt->execute([':days' => $activityDays]);
    $activityPurged = $stmt->rowCount();
    $totalPurged += $activityPurged;
    echo date('Y-m-d H:i:s') . " [ACTIVITY_LOG] Purged {$activityPurged} rows older than {$activityDays} days\n";
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] activity_log: " . $e->getMessage() . "\n";
}

// ──────────────────────────────────────────────
// 3. Inspection Photos: DELETE files + DB rows for completed reservations older than N days
// ──────────────────────────────────────────────
$photoDays = get_retention_days($pdo, 'data_retention_photos_days', 730);
if ($photoDays > 0) {
    try {
        // Find photos for old completed reservations
        $stmt = $pdo->prepare("
            SELECT p.id, p.reservation_id, p.inspection_type, p.filename
            FROM inspection_photos p
            JOIN reservations r ON r.id = p.reservation_id
            WHERE r.status IN ('completed', 'cancelled', 'missed', 'maintenance_required')
              AND r.updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
        $stmt->execute([':days' => $photoDays]);
        $oldPhotos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $filesDeleted = 0;
        $dbDeleted = 0;
        $uploadBase = dirname(__DIR__) . '/uploads/inspections';

        foreach ($oldPhotos as $photo) {
            $filePath = $uploadBase . '/' . $photo['reservation_id'] . '/' . $photo['inspection_type'] . '/' . basename($photo['filename']);
            if (file_exists($filePath)) {
                unlink($filePath);
                $filesDeleted++;
            }
        }

        // Delete DB records
        if (!empty($oldPhotos)) {
            $ids = array_column($oldPhotos, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM inspection_photos WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $dbDeleted = $stmt->rowCount();
        }

        // Clean up empty directories
        if ($filesDeleted > 0) {
            $reservationIds = array_unique(array_column($oldPhotos, 'reservation_id'));
            foreach ($reservationIds as $rid) {
                $resDir = $uploadBase . '/' . $rid;
                foreach (['checkout', 'checkin'] as $subdir) {
                    $dir = $resDir . '/' . $subdir;
                    if (is_dir($dir) && count(glob($dir . '/*')) === 0) {
                        @rmdir($dir);
                    }
                }
                if (is_dir($resDir) && count(glob($resDir . '/*')) === 0) {
                    @rmdir($resDir);
                }
            }
        }

        $totalPurged += $dbDeleted;
        echo date('Y-m-d H:i:s') . " [PHOTOS] Purged {$dbDeleted} DB records, {$filesDeleted} files older than {$photoDays} days\n";
    } catch (Exception $e) {
        echo date('Y-m-d H:i:s') . " [ERROR] inspection_photos: " . $e->getMessage() . "\n";
    }
} else {
    echo date('Y-m-d H:i:s') . " [PHOTOS] Retention set to 'Never' — skipping\n";
}

// ──────────────────────────────────────────────
// 4. Notification Log: DELETE rows older than 90 days
// ──────────────────────────────────────────────
try {
    $check = $pdo->query("SHOW TABLES LIKE 'notification_log'")->fetch();
    if ($check) {
        $stmt = $pdo->prepare("
            DELETE FROM notification_log
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");
        $stmt->execute();
        $notifPurged = $stmt->rowCount();
        $totalPurged += $notifPurged;
        echo date('Y-m-d H:i:s') . " [NOTIFICATION_LOG] Purged {$notifPurged} rows older than 90 days\n";
    }
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] notification_log: " . $e->getMessage() . "\n";
}

// ──────────────────────────────────────────────
// Log the purge in activity_log (before it might be purged next cycle)
// ──────────────────────────────────────────────
if ($totalPurged > 0) {
    activity_log_event('data_retention', "Data retention purge: {$totalPurged} records removed", [
        'actor' => ['email' => 'system@cron', 'first_name' => 'System', 'last_name' => 'CRON'],
        'metadata' => [
            'email_queue_days' => $emailDays,
            'activity_log_days' => $activityDays,
            'photos_days' => $photoDays,
            'total_purged' => $totalPurged,
        ],
    ]);
}

// Update last purge timestamp
try {
    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('data_retention_last_purge', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([date('Y-m-d H:i:s')]);
} catch (Exception $e) {
    // Non-critical
}

echo date('Y-m-d H:i:s') . " [DONE] Total purged: {$totalPurged}\n";

flock($fp, LOCK_UN);
fclose($fp);
