<?php
// booking_helpers.php
// Shared helpers for working with reservations & items.

require_once __DIR__ . '/snipeit_client.php';

/**
 * Fetch all items for a reservation, with human-readable names.
 *
 * Returns an array of:
 *   [
 *     ['model_id' => 123, 'name' => 'Canon 5D', 'qty' => 2, 'image' => '/uploads/models/...'],
 *     ...
 *   ]
 *
 * Assumes reservation_items has: reservation_id, model_id, quantity.
 * Uses Snipe-IT get_model($modelId) to resolve names.
 */
function get_reservation_items_with_names(PDO $pdo, int $reservationId): array
{
    // Adjust columns / table name here if yours differ:
    $sql = "
        SELECT model_id, quantity
        FROM reservation_items
        WHERE reservation_id = :res_id
        ORDER BY model_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':res_id' => $reservationId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [];
    }

    $items = [];
    static $modelCache = [];

    foreach ($rows as $row) {
        $modelId = isset($row['model_id']) ? (int)$row['model_id'] : 0;
        $qty     = isset($row['quantity']) ? (int)$row['quantity'] : 0;

        if ($modelId <= 0 || $qty <= 0) {
            continue;
        }

        if (!isset($modelCache[$modelId])) {
            try {
                // Uses Snipe-IT API client function we already have
                $modelCache[$modelId] = get_model($modelId);
            } catch (Exception $e) {
                $modelCache[$modelId] = null;
            }
        }

        $model = $modelCache[$modelId];
        $name  = $model['name'] ?? ('Model #' . $modelId);
        $image = $model['image'] ?? '';

        $items[] = [
            'model_id' => $modelId,
            'name'     => $name,
            'qty'      => $qty,
            'image'    => $image,
        ];
    }

    return $items;
}

/**
 * Build a single-line text summary from an items array.
 *
 * Example:
 *   "Canon 5D (2), Tripod (1), LED Panel (3)"
 */
function build_items_summary_text(array $items): string
{
    if (empty($items)) {
        return '';
    }

    $parts = [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        $qty  = isset($item['qty']) ? (int)$item['qty'] : 0;

        if ($name === '' || $qty <= 0) {
            continue;
        }

        $parts[] = $qty > 1
            ? sprintf('%s (%d)', $name, $qty)
            : $name;
    }

    return implode(', ', $parts);
}

/**
 * Attempt to redirect a reservation to an alternate available vehicle.
 *
 * Used when:
 * - A vehicle is found damaged during checkout (driver can't use it)
 * - A vehicle is overdue and the next reservation needs rerouting (cron)
 *
 * @param array $reservation The reservation row from DB
 * @param int $unavailableAssetId The asset that can't be used
 * @param string $reason Human-readable reason for the redirect
 * @param PDO $pdo Database connection
 * @return array ['success' => bool, 'action' => string, 'vehicle' => array|null, 'message' => string]
 */
function attempt_vehicle_redirect(array $reservation, int $unavailableAssetId, string $reason, PDO $pdo): array
{
    require_once SRC_PATH . '/business_days.php';

    $pickupLocationId = $reservation['pickup_location_id'] ?? 0;
    $startDate = date('Y-m-d', strtotime($reservation['start_datetime']));
    $endDate = date('Y-m-d', strtotime($reservation['end_datetime']));

    // Find alternate vehicle at the same pickup location
    $allVehicles = get_fleet_vehicles(500);
    $alternateVehicle = null;

    foreach ($allVehicles as $vehicle) {
        if ($vehicle['id'] == $unavailableAssetId) continue;

        $vehLocationId = $vehicle['rtd_location']['id'] ?? ($vehicle['location']['id'] ?? 0);
        if ($pickupLocationId && $vehLocationId != $pickupLocationId) continue;

        $statusId = $vehicle['status_label']['id'] ?? 0;
        $avail = check_vehicle_availability($vehicle['id'], $statusId, $startDate, $endDate, $pdo);

        if ($avail['available']) {
            $alternateVehicle = $vehicle;
            break;
        }
    }

    if ($alternateVehicle) {
        $newAssetName = $alternateVehicle['name'] . ' [' . $alternateVehicle['asset_tag'] . ']';

        $updateStmt = $pdo->prepare("
            UPDATE reservations
            SET asset_id = ?, asset_name_cache = ?, redirected_from_id = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $alternateVehicle['id'],
            $newAssetName,
            $reservation['asset_id'],
            $reservation['id']
        ]);

        update_asset_status($alternateVehicle['id'], STATUS_VEH_RESERVED);

        $historyStmt = $pdo->prepare("
            INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
            VALUES (?, 'redirected', 'System', '', ?)
        ");
        $historyStmt->execute([
            $reservation['id'],
            "Redirected from {$reservation['asset_name_cache']} to {$newAssetName}. Reason: {$reason}"
        ]);

        return [
            'success' => true,
            'action' => 'redirected',
            'vehicle' => $alternateVehicle,
            'message' => "You have been assigned an alternate vehicle: {$newAssetName}",
        ];
    }

    return [
        'success' => false,
        'action' => 'no_alternate',
        'vehicle' => null,
        'message' => "No alternate vehicle is available at this location. Please contact Fleet Staff for assistance.",
    ];
}

/**
 * Attempt to redirect a reservation to an alternate available vehicle.
 *
 * Used when:
 * - A vehicle is found damaged during checkout (driver can't use it)
 * - A vehicle is overdue and the next reservation needs rerouting (cron)
 *
 * @param array $reservation The reservation row from DB
 * @param int $unavailableAssetId The asset that can't be used
 * @param string $reason Human-readable reason for the redirect
 * @param PDO $pdo Database connection
 * @return array ['success' => bool, 'action' => string, 'vehicle' => array|null, 'message' => string]
