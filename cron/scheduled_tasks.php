<?php
/**
 * Fleet - Scheduled Tasks
 * Run via cron every 15 minutes
 * 
 * Tasks:
 * 1. Send pickup reminders (1 hour before)
 * 2. Send overdue alerts (30 min after expected return)
 * 3. Mark missed reservations (1 hour after start, no checkout)
 * 4. Process email queue (when SES is configured)
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/email_service.php';
require_once SRC_PATH . '/notification_service.php';

// Get config
$config = require CONFIG_PATH . '/config.php';
$missedCutoffMinutes = $config['app']['missed_cutoff_minutes'] ?? 60;

$emailService = get_email_service($pdo);

echo "=== Fleet Scheduled Tasks ===" . PHP_EOL;
echo "Started: " . date('Y-m-d H:i:s') . PHP_EOL;
echo PHP_EOL;

/**
 * Task 1: Pickup Reminders
 * Send reminder 1 hour before pickup for approved reservations
 */
echo "--- Task 1: Pickup Reminders ---" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               TIMESTAMPDIFF(MINUTE, NOW(), r.start_datetime) as minutes_until_start
        FROM reservations r
        WHERE r.status = 'pending'
        AND r.approval_status IN ('approved', 'auto_approved')
        AND r.start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 65 MINUTE)
        AND r.start_datetime > DATE_ADD(NOW(), INTERVAL 55 MINUTE)
        AND r.id NOT IN (
            SELECT reservation_id FROM notification_log 
            WHERE notification_type = 'pickup_reminder'
        )
    ");
    $stmt->execute();
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($reminders) . " pickup reminders to send" . PHP_EOL;
    
    foreach ($reminders as $reservation) {
        echo "  - Reservation #{$reservation['id']} for {$reservation['user_name']} ";
        echo "(pickup in {$reservation['minutes_until_start']} min)" . PHP_EOL;
        
        NotificationService::fire('pickup_reminder', $reservation, $pdo);
        
        // Log notification
        $logStmt = $pdo->prepare("
            INSERT INTO notification_log (reservation_id, notification_type, sent_at)
            VALUES (?, 'pickup_reminder', NOW())
        ");
        $logStmt->execute([$reservation['id']]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 2: Overdue Alerts
 * Send alert 30 minutes after expected return
 */
echo "--- Task 2: Overdue Alerts ---" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT r.*,
               TIMESTAMPDIFF(MINUTE, r.end_datetime, NOW()) as minutes_overdue
        FROM reservations r
        WHERE r.status = 'confirmed'
        AND r.end_datetime < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        AND r.id NOT IN (
            SELECT reservation_id FROM notification_log 
            WHERE notification_type = 'overdue_alert'
            AND sent_at > DATE_SUB(NOW(), INTERVAL 4 HOUR)
        )
    ");
    $stmt->execute();
    $overdueList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($overdueList) . " overdue vehicles" . PHP_EOL;
    
    foreach ($overdueList as $reservation) {
        echo "  - Reservation #{$reservation['id']} for {$reservation['user_name']} ";
        echo "({$reservation['minutes_overdue']} min overdue)" . PHP_EOL;
        
        NotificationService::fire('return_overdue', $reservation, $pdo);
        
        // Log notification
        $logStmt = $pdo->prepare("
            INSERT INTO notification_log (reservation_id, notification_type, sent_at)
            VALUES (?, 'overdue_alert', NOW())
        ");
        $logStmt->execute([$reservation['id']]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 3: Mark Missed Reservations
 * If no checkout within cutoff time after start, mark as missed
 */
echo "--- Task 3: Missed Reservations ---" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM reservations r
        WHERE r.status = 'pending'
        AND r.approval_status IN ('approved', 'auto_approved')
        AND r.start_datetime < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$missedCutoffMinutes]);
    $missedList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($missedList) . " missed reservations (cutoff: {$missedCutoffMinutes} min)" . PHP_EOL;
    
    foreach ($missedList as $reservation) {
        echo "  - Marking reservation #{$reservation['id']} as missed ";
        echo "({$reservation['user_name']})" . PHP_EOL;
        
        // Update reservation status
        $updateStmt = $pdo->prepare("
            UPDATE reservations SET status = 'missed' WHERE id = ?
        ");
        $updateStmt->execute([$reservation['id']]);
        
        // Reset vehicle status back to available if it was reserved
        if ($reservation['asset_id']) {
            update_asset_status($reservation['asset_id'], STATUS_VEH_AVAILABLE);
            echo "    -> Reset vehicle #{$reservation['asset_id']} to Available" . PHP_EOL;
        }
        
        // Log in approval history
        $historyStmt = $pdo->prepare("
            INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
            VALUES (?, 'missed', 'System', '', 'No checkout within cutoff period')
        ");
        $historyStmt->execute([$reservation['id']]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 4: Auto-cancel old pending approvals
 * Cancel reservations pending approval for more than 24 hours past start time
 */
echo "--- Task 4: Auto-cancel Old Pending ---" . PHP_EOL;

try {
    $stmt = $pdo->prepare("
        SELECT r.*
        FROM reservations r
        WHERE r.approval_status = 'pending_approval'
        AND r.start_datetime < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $oldPending = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($oldPending) . " old pending reservations to cancel" . PHP_EOL;
    
    foreach ($oldPending as $reservation) {
        echo "  - Cancelling reservation #{$reservation['id']} ";
        echo "({$reservation['user_name']})" . PHP_EOL;
        
        $updateStmt = $pdo->prepare("
            UPDATE reservations 
            SET status = 'cancelled', approval_status = 'rejected'
            WHERE id = ?
        ");
        $updateStmt->execute([$reservation['id']]);
        
        $historyStmt = $pdo->prepare("
            INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
            VALUES (?, 'auto_cancelled', 'System', '', 'Not approved before start time')
        ");
        $historyStmt->execute([$reservation['id']]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 5: Report pending email queue
 */
echo "--- Task 5: Email Queue Status ---" . PHP_EOL;

try {
    $pendingCount = $emailService->getPendingEmailCount();
    echo "Pending emails in queue: {$pendingCount}" . PHP_EOL;
    
    if ($pendingCount > 0) {
        echo "  (Emails will be sent once AWS SES is configured)" . PHP_EOL;
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 6: Maintenance & Compliance Alerts
 * Check for vehicles needing maintenance, expiring insurance/registration
 */
echo "--- Task 6: Maintenance & Compliance Alerts ---" . PHP_EOL;

define('DEFAULT_MAINTENANCE_MILES', 7500);
define('DEFAULT_MAINTENANCE_DAYS', 180);
define('ALERT_DAYS_BEFORE_EXPIRY', 30);

try {
    $allAssets = get_requestable_assets(100, null);
    $assetList = is_array($allAssets) ? $allAssets : [];
    
    $maintenanceDue = 0;
    $insuranceExpiring = 0;
    $registrationExpiring = 0;
    
    foreach ($assetList as $asset) {
        $cf = $asset['custom_fields'] ?? [];
        
        $currentMileage = (int)($cf['Current Mileage']['value'] ?? 0);
        $lastMaintenanceMileage = (int)($cf['Last Maintenance Mileage']['value'] ?? 0);
        $lastMaintenanceDate = $cf['Last Maintenance Date']['value'] ?? null;
        $maintenanceIntervalMiles = (int)($cf['Maintenance Interval Miles']['value'] ?? DEFAULT_MAINTENANCE_MILES);
        $maintenanceIntervalDays = (int)($cf['Maintenance Interval Days']['value'] ?? DEFAULT_MAINTENANCE_DAYS);
        
        // Check mileage
        $milesSinceService = $currentMileage - $lastMaintenanceMileage;
        if ($milesSinceService >= $maintenanceIntervalMiles) {
            $maintenanceDue++;
            echo "  - {$asset['name']}: Maintenance due (mileage)" . PHP_EOL;
        }
        
        // Check days
        if ($lastMaintenanceDate) {
            $daysSinceService = (int)((time() - strtotime($lastMaintenanceDate)) / 86400);
            if ($daysSinceService >= $maintenanceIntervalDays) {
                $maintenanceDue++;
                echo "  - {$asset['name']}: Maintenance due (time)" . PHP_EOL;
            }
        }
        
        // Check insurance
        $insuranceExpiry = $cf['Insurance Expiry']['value'] ?? null;
        if ($insuranceExpiry) {
            $daysUntil = (int)((strtotime($insuranceExpiry) - time()) / 86400);
            if ($daysUntil <= ALERT_DAYS_BEFORE_EXPIRY) {
                $insuranceExpiring++;
                echo "  - {$asset['name']}: Insurance " . ($daysUntil < 0 ? 'EXPIRED' : "expiring in {$daysUntil} days") . PHP_EOL;
            }
        }
        
        // Check registration
        $registrationExpiry = $cf['Registration Expiry']['value'] ?? null;
        if ($registrationExpiry) {
            $daysUntil = (int)((strtotime($registrationExpiry) - time()) / 86400);
            if ($daysUntil <= ALERT_DAYS_BEFORE_EXPIRY) {
                $registrationExpiring++;
                echo "  - {$asset['name']}: Registration " . ($daysUntil < 0 ? 'EXPIRED' : "expiring in {$daysUntil} days") . PHP_EOL;
            }
        }
    }
    
    echo "Summary: {$maintenanceDue} maintenance due, {$insuranceExpiring} insurance expiring, {$registrationExpiring} registration expiring" . PHP_EOL;
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

/**
 * Task 7: Overdue Reservation Redirect
 * When a vehicle is overdue past threshold, check if the next reservation
 * on that vehicle is within the lookahead window. If so, attempt to
 * redirect it to an alternate available vehicle, or cancel if none found.
 */
echo "--- Task 7: Overdue Redirect ---" . PHP_EOL;

require_once SRC_PATH . '/business_days.php';

try {
    // Load settings
    $redirectOverdueMin = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'redirect_overdue_minutes'")->fetchColumn() ?: 30);
    $redirectLookaheadHrs = (int)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'redirect_lookahead_hours'")->fetchColumn() ?: 24);

    echo "Config: overdue trigger={$redirectOverdueMin}min, lookahead={$redirectLookaheadHrs}hrs" . PHP_EOL;

    // Step 1: Find overdue checked-out reservations
    $stmt = $pdo->prepare("
        SELECT r.*, TIMESTAMPDIFF(MINUTE, r.end_datetime, NOW()) as minutes_overdue
        FROM reservations r
        WHERE r.status = 'confirmed'
        AND r.end_datetime < DATE_SUB(NOW(), INTERVAL ? MINUTE)
    ");
    $stmt->execute([$redirectOverdueMin]);
    $overdueReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($overdueReservations) . " overdue vehicles past {$redirectOverdueMin}min threshold" . PHP_EOL;

    foreach ($overdueReservations as $overdue) {
        $assetId = $overdue['asset_id'];
        echo "  Checking asset #{$assetId} ({$overdue['asset_name_cache']}) - {$overdue['minutes_overdue']}min overdue" . PHP_EOL;

        // Step 2: Find next reservation on this vehicle within lookahead window
        $stmt = $pdo->prepare("
            SELECT r.*
            FROM reservations r
            WHERE r.asset_id = ?
            AND r.status = 'pending'
            AND r.approval_status IN ('approved', 'auto_approved')
            AND r.start_datetime BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? HOUR)
            AND r.id != ?
            ORDER BY r.start_datetime ASC
            LIMIT 1
        ");
        $stmt->execute([$assetId, $redirectLookaheadHrs, $overdue['id']]);
        $nextReservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$nextReservation) {
            echo "    No upcoming reservation in lookahead window. Skipping." . PHP_EOL;
            continue;
        }

        // Check if already processed
        $alreadyProcessed = $pdo->prepare("
            SELECT 1 FROM notification_log
            WHERE reservation_id = ? AND notification_type = 'overdue_redirect'
        ");
        $alreadyProcessed->execute([$nextReservation['id']]);
        if ($alreadyProcessed->fetch()) {
            echo "    Reservation #{$nextReservation['id']} already processed. Skipping." . PHP_EOL;
            continue;
        }

        echo "    Next reservation #{$nextReservation['id']} for {$nextReservation['user_name']} ";
        echo "(starts " . date('M j g:i A', strtotime($nextReservation['start_datetime'])) . ")" . PHP_EOL;

        // Step 3: Find alternate vehicle at the same location
        $pickupLocationId = $nextReservation['pickup_location_id'] ?? 0;
        $startDate = date('Y-m-d', strtotime($nextReservation['start_datetime']));
        $endDate = date('Y-m-d', strtotime($nextReservation['end_datetime']));

        $allVehicles = get_fleet_vehicles(500);
        $alternateVehicle = null;

        foreach ($allVehicles as $vehicle) {
            // Skip the overdue vehicle
            if ($vehicle['id'] == $assetId) continue;

            // Check location match
            $vehLocationId = $vehicle['rtd_location']['id'] ?? ($vehicle['location']['id'] ?? 0);
            if ($vehLocationId != $pickupLocationId) continue;

            // Check availability
            $statusId = $vehicle['status_label']['id'] ?? 0;
            $avail = check_vehicle_availability($vehicle['id'], $statusId, $startDate, $endDate, $pdo);

            if ($avail['available']) {
                $alternateVehicle = $vehicle;
                break;
            }
        }

        if ($alternateVehicle) {
            // Step 4A: Redirect to alternate vehicle
            echo "    REDIRECT: #{$nextReservation['id']} -> {$alternateVehicle['name']} (id={$alternateVehicle['id']})" . PHP_EOL;

            $newAssetName = $alternateVehicle['name'] . ' [' . $alternateVehicle['asset_tag'] . ']';

            // Update the reservation to the new vehicle
            $updateStmt = $pdo->prepare("
                UPDATE reservations
                SET asset_id = ?, asset_name_cache = ?, redirected_from_id = ?
                WHERE id = ?
            ");
            $updateStmt->execute([
                $alternateVehicle['id'],
                $newAssetName,
                $nextReservation['id'],
                $nextReservation['id']
            ]);

            // Update Snipe-IT status for the alternate vehicle
            update_asset_status($alternateVehicle['id'], STATUS_VEH_RESERVED);

            // Log in approval history
            $historyStmt = $pdo->prepare("
                INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
                VALUES (?, 'redirected', 'System', '', ?)
            ");
            $historyStmt->execute([
                $nextReservation['id'],
                "Auto-redirected from {$overdue['asset_name_cache']} (overdue) to {$newAssetName}"
            ]);

            // Notify requester about redirect
            NotificationService::fire('reservation_redirected', array_merge($nextReservation, [
                'new_vehicle' => $alternateVehicle,
                'reason'      => "The originally assigned vehicle ({$overdue['asset_name_cache']}) has not been returned on time.",
            ]), $pdo);

            // Notify staff about the overdue situation
            NotificationService::fire('overdue_redirect_staff', array_merge($overdue, ['action' => 'redirected']), $pdo);

        } else {
            // Step 4B: No alternate — cancel the next reservation
            echo "    CANCEL: No alternate vehicle available for #{$nextReservation['id']}" . PHP_EOL;

            $updateStmt = $pdo->prepare("
                UPDATE reservations
                SET status = 'cancelled', approval_status = 'rejected'
                WHERE id = ?
            ");
            $updateStmt->execute([$nextReservation['id']]);

            // Log in approval history
            $historyStmt = $pdo->prepare("
                INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
                VALUES (?, 'auto_cancelled', 'System', '', ?)
            ");
            $historyStmt->execute([
                $nextReservation['id'],
                "Cancelled: original vehicle ({$overdue['asset_name_cache']}) overdue, no alternate available"
            ]);

            // Notify requester about cancellation
            NotificationService::fire('reservation_redirect_failed', array_merge($nextReservation, [
                'reason' => "The assigned vehicle ({$overdue['asset_name_cache']}) has not been returned and no alternate vehicle is available at your location.",
            ]), $pdo);

            // Notify staff
            NotificationService::fire('overdue_redirect_staff', array_merge($overdue, ['action' => 'cancelled']), $pdo);
        }

        // Log that we processed this
        $logStmt = $pdo->prepare("
            INSERT INTO notification_log (reservation_id, notification_type, sent_at)
            VALUES (?, 'overdue_redirect', NOW())
        ");
        $logStmt->execute([$nextReservation['id']]);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL;

echo "=== Completed: " . date('Y-m-d H:i:s') . " ===" . PHP_EOL;
