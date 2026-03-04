<?php
/**
 * Business Days API Endpoint
 * Provides non-business dates, holiday data, and date validation for the booking calendar.
 *
 * Actions:
 *   - get_non_business_dates: Returns all non-business dates for a date range
 *   - check_date: Validates if a specific date is a business day
 *   - get_vehicle_availability: Returns availability info for vehicles at a location for a date range
 *
 * @since v1.3.5
 */

require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/business_days.php';

header('Content-Type: application/json');

// Must be authenticated
if (empty($currentUser)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {

        /**
         * Get non-business dates for a date range.
         * Used by flatpickr to disable dates in the calendar.
         *
         * GET ?action=get_non_business_dates&from=2026-03-01&to=2026-05-31
         * Optional: &asset_id=123 (to also include vehicle-specific blackouts)
         */
        case 'get_non_business_dates':
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-t', strtotime('+2 months'));
            $assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;

            // Validate date format
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date format. Use Y-m-d.']);
                exit;
            }

            // Cap range to 6 months to prevent abuse
            $fromDate = new DateTime($from);
            $toDate = new DateTime($to);
            $diff = $fromDate->diff($toDate)->days;
            if ($diff > 185) {
                $toDate = (clone $fromDate)->modify('+6 months');
                $to = $toDate->format('Y-m-d');
            }

            $result = get_non_business_dates($from, $to, $pdo);
            $blackouts = get_blackout_dates($from, $to, $pdo, $assetId);

            // Merge all disabled dates into a flat array for flatpickr
            $disabledDates = [];
            foreach ($result['non_business_dates'] as $date => $reason) {
                $disabledDates[] = [
                    'date' => $date,
                    'reason' => $reason,
                    'type' => isset($result['holidays'][$date]) ? 'holiday' : 'weekend',
                ];
            }
            foreach ($blackouts as $date => $reason) {
                // Avoid duplicates
                $exists = false;
                foreach ($disabledDates as $d) {
                    if ($d['date'] === $date) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $disabledDates[] = [
                        'date' => $date,
                        'reason' => $reason,
                        'type' => 'blackout',
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'disabled_dates' => $disabledDates,
                'holidays' => $result['holidays'],
                'weekends' => array_keys($result['weekends']),
                'blackouts' => $blackouts,
            ]);
            break;

        /**
         * Check if a specific date is a business day.
         *
         * GET ?action=check_date&date=2026-03-15
         */
        case 'check_date':
            $date = $_GET['date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid date format. Use Y-m-d.']);
                exit;
            }

            $isBusiness = is_business_day($date, $pdo);
            $reason = '';
            if (!$isBusiness) {
                $dayOfWeek = strtolower(date('l', strtotime($date)));
                $config = get_business_day_config($pdo);
                if (empty($config['days'][$dayOfWeek])) {
                    $reason = ucfirst($dayOfWeek) . ' is not a working day';
                } else {
                    $holidays = get_active_holidays($pdo, $date, $date);
                    $reason = $holidays[$date] ?? 'Non-business day';
                }
            }

            echo json_encode([
                'success' => true,
                'date' => $date,
                'is_business_day' => $isBusiness,
                'reason' => $reason,
            ]);
            break;

        /**
         * Get vehicle availability for a date range at a specific location.
         * Returns all vehicles (any status) with their availability info.
         *
         * GET ?action=get_vehicle_availability&pickup_location=5&start_date=2026-03-10&end_date=2026-03-12
         */
        case 'get_vehicle_availability':
            $pickupLocationId = (int)($_GET['pickup_location'] ?? 0);
            $startDate = $_GET['start_date'] ?? '';
            $endDate = $_GET['end_date'] ?? '';

            if (!$pickupLocationId || !$startDate || !$endDate) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'pickup_location, start_date, and end_date are required.']);
                exit;
            }

            // Validate dates are business days
            if (!is_business_day($startDate, $pdo)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Start date is not a business day.',
                ]);
                exit;
            }
            if (!is_business_day($endDate, $pdo)) {
                echo json_encode([
                    'success' => false,
                    'error' => 'End date is not a business day.',
                ]);
                exit;
            }

            require_once SRC_PATH . '/snipeit_client.php';

            // Get ALL fleet vehicles (any status) at the selected location
            $allAssets = get_fleet_vehicles(500);
            $vehicles = [];

            foreach ($allAssets as $asset) {
                $assetLocationId = $asset['location']['id'] ?? 0;
                $rtdLocationId = $asset['rtd_location']['id'] ?? $assetLocationId;

                // Filter by pickup location
                if ($rtdLocationId != $pickupLocationId && $assetLocationId != $pickupLocationId) {
                    continue;
                }

                $statusId = $asset['status_label']['id'] ?? 0;

                $availability = check_vehicle_availability(
                    $asset['id'],
                    $statusId,
                    $startDate,
                    $endDate,
                    $pdo
                );

                // Only include available vehicles (now or future)
                if ($availability['available']) {
                    $vehicles[] = [
                        'id' => $asset['id'],
                        'name' => $asset['name'],
                        'asset_tag' => $asset['asset_tag'],
                        'model' => $asset['model']['name'] ?? 'N/A',
                        'license_plate' => $asset['custom_fields']['License Plate']['value'] ?? 'N/A',
                        'availability_status' => $availability['status'],
                        'earliest_date' => $availability['earliest_date'],
                        'reason' => $availability['reason'],
                    ];
                }
            }

            echo json_encode([
                'success' => true,
                'vehicles' => $vehicles,
                'count' => count($vehicles),
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action. Use: get_non_business_dates, check_date, get_vehicle_availability']);
            break;
    }
} catch (Exception $e) {
    error_log('business_days API error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
