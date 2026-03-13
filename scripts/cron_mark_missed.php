<?php
// cron_mark_missed.php
// Mark reservations as "missed" if they were not checked out within a cutoff window.
// Releases vehicle back to VEH-Available in Snipe-IT and fires notifications.
//
// Run via cron, e.g.:
//   */10 * * * * /usr/bin/php /path/to/scripts/cron_mark_missed.php >> /var/log/layout_missed.log 2>&1

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/notification_service.php';

$config = load_config();

// Load cutoff from system_settings (admin-configurable via Booking Rules), fall back to config file
$cutoffMinutes = 60;
$bufferHours   = 0;
try {
    $sysStmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('missed_cutoff_minutes','missed_release_buffer_hours')");
    $sysStmt->execute();
    while ($sysRow = $sysStmt->fetch(PDO::FETCH_ASSOC)) {
        if ($sysRow['setting_key'] === 'missed_cutoff_minutes') {
            $cutoffMinutes = (int)$sysRow['setting_value'];
        }
        if ($sysRow['setting_key'] === 'missed_release_buffer_hours') {
            $bufferHours = (int)$sysRow['setting_value'];
        }
    }
} catch (Throwable $e) {
    // Fall back to config file
    $appCfg = $config['app'] ?? [];
    $cutoffMinutes = isset($appCfg['missed_cutoff_minutes']) ? (int)$appCfg['missed_cutoff_minutes'] : 60;
}
$cutoffMinutes = max(1, $cutoffMinutes);
$bufferHours   = max(0, $bufferHours);

// Ensure the status column includes 'missed' in the ENUM definition.
try {
    $col = $pdo->query("SHOW COLUMNS FROM reservations LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    $type = $col['Type'] ?? '';
    if ($type !== '' && stripos($type, 'missed') === false) {
        $pdo->exec("
            ALTER TABLE reservations
            MODIFY status ENUM('pending','confirmed','completed','cancelled','missed')
            NOT NULL DEFAULT 'pending'
        ");
        echo "[" . date('Y-m-d H:i:s') . "] Updated reservations.status enum to include 'missed'\n";
    }
} catch (Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Warning: could not verify/alter status column: " . $e->getMessage() . "\n";
}

// Select full reservation data for notifications before updating
$selectStmt = $pdo->prepare("
    SELECT *
      FROM reservations
     WHERE status IN ('pending', 'confirmed')
       AND start_datetime < (NOW() - INTERVAL :mins MINUTE)
");
$selectStmt->bindValue(':mins', $cutoffMinutes, PDO::PARAM_INT);
$selectStmt->execute();
$missedReservations = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($missedReservations)) {
    echo sprintf("[%s] No reservations to mark as missed (cutoff %d minutes)\n", date('Y-m-d H:i:s'), $cutoffMinutes);
    exit(0);
}

$missedIds = array_column($missedReservations, 'id');

// Mark as missed
$placeholders = implode(',', array_fill(0, count($missedIds), '?'));
$updateStmt = $pdo->prepare("UPDATE reservations SET status = 'missed' WHERE id IN ({$placeholders})");
$updateStmt->execute($missedIds);
$affected = $updateStmt->rowCount();

// Build asset summaries per reservation for logging.
$assetsByReservation = [];
$missedIdInts = array_values(array_filter(array_map('intval', $missedIds), static function (int $id): bool {
    return $id > 0;
}));
if (!empty($missedIdInts)) {
    $itemPlaceholders = implode(',', array_fill(0, count($missedIdInts), '?'));
    $itemsStmt = $pdo->prepare("
        SELECT reservation_id, model_name_cache, quantity
          FROM reservation_items
         WHERE reservation_id IN ({$itemPlaceholders})
    ");
    $itemsStmt->execute($missedIdInts);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $rid = (int)($row['reservation_id'] ?? 0);
        if ($rid <= 0) continue;
        $name = trim((string)($row['model_name_cache'] ?? ''));
        $qty = (int)($row['quantity'] ?? 0);
        if ($name === '') $name = 'Item';
        $label = $qty > 1 ? ($name . ' (x' . $qty . ')') : $name;
        $assetsByReservation[$rid][] = $label;
    }
}

// Process each missed reservation
foreach ($missedReservations as $reservation) {
    $resId = (int)$reservation['id'];
    $assetId = (int)($reservation['asset_id'] ?? 0);

    // 1. Log to activity_log
    activity_log_event('reservation_missed', 'Reservation marked as missed', [
        'subject_type' => 'reservation',
        'subject_id'   => $resId,
        'actor'        => ['email' => 'system@cron', 'first_name' => 'System', 'last_name' => 'CRON'],
        'metadata'     => [
            'assets' => $assetsByReservation[$resId] ?? [],
            'cutoff_minutes' => $cutoffMinutes,
            'key_collected' => !empty($reservation['key_collected']),
        ],
    ]);

    // 2. Release vehicle in Snipe-IT (VEH-Reserved → VEH-Available)
    //    Respect the buffer: only release if reservation was missed long enough ago
    $missedAt = strtotime($reservation['start_datetime'] ?? 'now') + ($cutoffMinutes * 60);
    $releaseAt = $missedAt + ($bufferHours * 3600);
    $shouldRelease = time() >= $releaseAt;

    if ($assetId > 0 && $shouldRelease) {
        try {
            $assetInfo = get_asset($assetId);
            $currentStatusId = $assetInfo['status_label']['id'] ?? 0;
            // Only release if currently Reserved (don't touch In Service or Out of Service)
            if ($currentStatusId === STATUS_VEH_RESERVED) {
                update_asset_status($assetId, STATUS_VEH_AVAILABLE);
                echo sprintf("[%s] Released asset #%d back to Available\n", date('Y-m-d H:i:s'), $assetId);
            }
        } catch (Throwable $e) {
            echo sprintf("[%s] Warning: could not release asset #%d: %s\n", date('Y-m-d H:i:s'), $assetId, $e->getMessage());
        }
    } elseif ($assetId > 0 && !$shouldRelease) {
        echo sprintf("[%s] Asset #%d release deferred (buffer %dh not elapsed)\n", date('Y-m-d H:i:s'), $assetId, $bufferHours);
    }

    // 3. Fire notifications
    try {
        // Driver notification
        NotificationService::fire('reservation_missed_driver', $reservation, $pdo);
        // Staff notification
        NotificationService::fire('reservation_missed_staff', $reservation, $pdo);
    } catch (Throwable $e) {
        echo sprintf("[%s] Warning: notification failed for reservation #%d: %s\n", date('Y-m-d H:i:s'), $resId, $e->getMessage());
    }
}

echo sprintf(
    "[%s] Marked %d reservation(s) as missed (cutoff %d minutes)\n",
    date('Y-m-d H:i:s'),
    $affected,
    $cutoffMinutes
);
