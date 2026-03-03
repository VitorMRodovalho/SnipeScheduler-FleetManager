<?php
/**
 * Vehicle Creation AJAX API
 * Provides: next asset tag, VIN duplicate check, license plate duplicate check
 * 
 * Deploy to: /var/www/snipescheduler/public/api/vehicle_check.php
 */
require_once __DIR__ . '/../../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';

header('Content-Type: application/json');

// Admin only
$isAdmin = !empty($currentUser['is_admin']);
if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        case 'next_tag':
            // Get next available BPTR-VEH-### tag
            $nextTag = get_next_vehicle_asset_tag();
            echo json_encode(['success' => true, 'tag' => $nextTag]);
            break;

        case 'check_vin':
            // Check if VIN already exists in Snipe-IT
            $vin = trim($_GET['vin'] ?? '');
            if (empty($vin)) {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }
            $exists = check_vin_exists($vin);
            echo json_encode(['success' => true, 'exists' => $exists]);
            break;

        case 'check_plate':
            // Check if License Plate already exists in Snipe-IT
            $plate = strtoupper(trim($_GET['plate'] ?? ''));
            if (empty($plate)) {
                echo json_encode(['success' => true, 'exists' => false]);
                break;
            }
            $exists = check_license_plate_exists($plate);
            echo json_encode(['success' => true, 'exists' => $exists]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action. Use: next_tag, check_vin, check_plate']);
    }
} catch (Exception $e) {
    error_log('vehicle_check.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
