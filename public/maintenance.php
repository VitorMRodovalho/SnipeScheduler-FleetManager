<?php
/**
 * Fleet Maintenance Management
 * Track maintenance schedules, log service records, manage alerts
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'maintenance.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Only staff can access
if (!$isStaff) {
    header('Location: dashboard');
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

$success = '';
$error = '';
$tab = $_GET['tab'] ?? 'alerts';

// Default maintenance intervals
define('DEFAULT_MAINTENANCE_MILES', 7500);
define('DEFAULT_MAINTENANCE_DAYS', 180);
define('ALERT_DAYS_BEFORE_EXPIRY', 30);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'log_maintenance') {
        // Log completed maintenance to Snipe-IT AND local DB
        $assetId = (int)$_POST['asset_id'];
        $maintenanceType = $_POST['maintenance_type'] ?? 'Maintenance';
        $title = trim($_POST['title'] ?? 'Scheduled Maintenance');
        $mileageAtService = (int)$_POST['mileage_at_service'];
        $serviceDate = $_POST['service_date'] ?? date('Y-m-d');
        $completionDate = $_POST['completion_date'] ?? $serviceDate;
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
        $notes = trim($_POST['notes'] ?? '');
        $supplierId = (int)($_POST['supplier_id'] ?? 1); // Default to Holman (ID 1)
        $isWarranty = isset($_POST['is_warranty']) && $_POST['is_warranty'] == '1';
        $oilChange = isset($_POST['oil_change']) && $_POST['oil_change'] == '1';
	$tireRotation = isset($_POST['tire_rotation']) && $_POST['tire_rotation'] == '1';

        // Get asset info
        $asset = get_asset_with_custom_fields($assetId);
        $assetTag = $asset['asset_tag'] ?? '';
        $assetName = $asset['name'] ?? '';
        
        // Calculate next maintenance
        $nextMaintenanceMiles = $mileageAtService + DEFAULT_MAINTENANCE_MILES;
        $nextMaintenanceDate = date('Y-m-d', strtotime($completionDate . ' + ' . DEFAULT_MAINTENANCE_DAYS . ' days'));
        
        try {
            // 1. Create maintenance record in Snipe-IT
            $snipeMaintenanceData = [
                'asset_id' => $assetId,
                'supplier_id' => $supplierId,
                'asset_maintenance_type' => $maintenanceType,
                'title' => $title,
                'start_date' => $serviceDate,
                'completion_date' => $completionDate,
                'cost' => $cost,
                'is_warranty' => $isWarranty ? 1 : 0,
                'notes' => ($mileageAtService ? "Mileage at service: {$mileageAtService} mi\n" : '') . $notes,
            ];
            
            $snipeResult = create_maintenance($snipeMaintenanceData);
            
            if (!$snipeResult) {
                throw new Exception('Failed to create maintenance record in Snipe-IT');
            }
            
            // 2. Update Snipe-IT custom fields on the asset
            $updateFields = [
                '_snipeit_last_maintenance_date_21' => $completionDate,
                '_snipeit_last_maintenance_mileage_24' => $mileageAtService,
                '_snipeit_current_mileage_6' => $mileageAtService,
            ];
            
		// Update specific maintenance types based on checkboxes
            if ($oilChange) {
                $updateFields['_snipeit_last_oil_change_miles_7'] = $mileageAtService;
            }
            if ($tireRotation) {
                $updateFields['_snipeit_last_tire_rotation_miles_8'] = $mileageAtService;
            }            


            
            update_asset_custom_fields($assetId, $updateFields);
            
            // 3. Also save to local DB for quick reporting
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_log 
                (asset_id, asset_tag, asset_name, maintenance_type, description, mileage_at_service, 
                 service_date, service_provider, cost, next_maintenance_miles, next_maintenance_date, 
                 notes, created_by_name, created_by_email)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $assetId, $assetTag, $assetName, $maintenanceType, $title, $mileageAtService,
                $completionDate, 'Holman Service Station', $cost, $nextMaintenanceMiles, $nextMaintenanceDate,
                $notes, $userName, $userEmail
            ]);
            
            $success = "Maintenance record created for {$assetName}. Asset fields updated.";
            
        } catch (Exception $e) {
            $error = "Failed to log maintenance: " . $e->getMessage();
        }
        } elseif ($action === 'update_schedule') {
        // Update maintenance schedule (expected return date)
        $assetId = (int)$_POST['asset_id'];
        $expectedReturn = $_POST['expected_return_date'] ?? null;
        $maintenanceNotes = trim($_POST['maintenance_notes'] ?? '');
        
        $asset = get_asset_with_custom_fields($assetId);
        $assetTag = $asset['asset_tag'] ?? '';
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO maintenance_schedule (asset_id, asset_tag, expected_return_date, maintenance_notes, updated_by_name)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    expected_return_date = VALUES(expected_return_date),
                    maintenance_notes = VALUES(maintenance_notes),
                    updated_by_name = VALUES(updated_by_name)
            ");
            $stmt->execute([$assetId, $assetTag, $expectedReturn ?: null, $maintenanceNotes, $userName]);
            
            $success = "Maintenance schedule updated.";
        } catch (Exception $e) {
            $error = "Failed to update schedule: " . $e->getMessage();
        }
        
    } elseif ($action === 'return_from_maintenance') {
        // Return vehicle from maintenance
        $assetId = (int)$_POST['asset_id'];
        $returnMileage = (int)$_POST['return_mileage'];
        $returnLocation = (int)$_POST['return_location'];
        
        try {
            // Update status to Available
            update_asset_status($assetId, STATUS_VEH_AVAILABLE);
            
            // Update location
            if ($returnLocation) {
                update_asset_location($assetId, $returnLocation);
            }
            
            // Update mileage in Snipe-IT
            update_asset_custom_fields($assetId, [
                '_snipeit_current_mileage_6' => $returnMileage
            ]);
            
            // Clear schedule
            $stmt = $pdo->prepare("DELETE FROM maintenance_schedule WHERE asset_id = ?");
            $stmt->execute([$assetId]);
            
            // Update any maintenance_required reservations
            $stmt = $pdo->prepare("
                UPDATE reservations 
                SET status = 'completed' 
                WHERE asset_id = ? AND status = 'maintenance_required'
            ");
            $stmt->execute([$assetId]);
            
            $success = "Vehicle returned to fleet and available for booking.";
        } catch (Exception $e) {
            $error = "Failed to return vehicle: " . $e->getMessage();
        }
    }
}

// Get all fleet vehicles with maintenance info
$allAssets = get_requestable_assets(100, null);
$assetList = is_array($allAssets) ? $allAssets : [];

// Calculate alerts
$maintenanceAlerts = [];
$insuranceAlerts = [];
$registrationAlerts = [];
$outOfServiceVehicles = [];

foreach ($assetList as $asset) {
    $assetId = $asset['id'];
    $assetName = $asset['name'];
    $assetTag = $asset['asset_tag'];
    $statusId = $asset['status_label']['id'] ?? 0;
    $cf = $asset['custom_fields'] ?? [];
    
    $currentMileage = (int)($cf['Current Mileage']['value'] ?? 0);
    $lastMaintenanceDate = $cf['Last Maintenance Date']['value'] ?? null;
    $lastMaintenanceMileage = (int)($cf['Last Maintenance Mileage']['value'] ?? 0);
    $maintenanceIntervalMiles = (int)($cf['Maintenance Interval Miles']['value'] ?? DEFAULT_MAINTENANCE_MILES);
    $maintenanceIntervalDays = (int)($cf['Maintenance Interval Days']['value'] ?? DEFAULT_MAINTENANCE_DAYS);
    $insuranceExpiry = $cf['Insurance Expiry']['value'] ?? null;
    $registrationExpiry = $cf['Registration Expiry']['value'] ?? null;
    
    // Check if out of service
    if ($statusId == STATUS_VEH_OUT_OF_SERVICE) {
        $stmt = $pdo->prepare("SELECT * FROM maintenance_schedule WHERE asset_id = ?");
        $stmt->execute([$assetId]);
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $outOfServiceVehicles[] = [
            'asset' => $asset,
            'schedule' => $schedule
        ];
    }
    
    // Check maintenance due by mileage
    $milesSinceService = $currentMileage - $lastMaintenanceMileage;
    $milesUntilDue = $maintenanceIntervalMiles - $milesSinceService;
    
    // Check maintenance due by date
    $daysSinceService = $lastMaintenanceDate 
        ? (int)((time() - strtotime($lastMaintenanceDate)) / 86400) 
        : 999;
    $daysUntilDue = $maintenanceIntervalDays - $daysSinceService;
    
    if ($milesUntilDue <= 500 || $daysUntilDue <= 14) {
        $maintenanceAlerts[] = [
            'asset' => $asset,
            'current_mileage' => $currentMileage,
            'last_maintenance_date' => $lastMaintenanceDate,
            'last_maintenance_mileage' => $lastMaintenanceMileage,
            'miles_until_due' => $milesUntilDue,
            'days_until_due' => $daysUntilDue,
            'reason' => $milesUntilDue <= 500 ? 'mileage' : 'time',
            'urgent' => $milesUntilDue <= 0 || $daysUntilDue <= 0
        ];
    }
    
    // Check insurance expiry
    if ($insuranceExpiry) {
        $daysUntilInsurance = (int)((strtotime($insuranceExpiry) - time()) / 86400);
        if ($daysUntilInsurance <= ALERT_DAYS_BEFORE_EXPIRY) {
            $insuranceAlerts[] = [
                'asset' => $asset,
                'expiry_date' => $insuranceExpiry,
                'days_until' => $daysUntilInsurance,
                'expired' => $daysUntilInsurance < 0
            ];
        }
    }
    
    // Check registration expiry
    if ($registrationExpiry) {
        $daysUntilRegistration = (int)((strtotime($registrationExpiry) - time()) / 86400);
        if ($daysUntilRegistration <= ALERT_DAYS_BEFORE_EXPIRY) {
            $registrationAlerts[] = [
                'asset' => $asset,
                'expiry_date' => $registrationExpiry,
                'days_until' => $daysUntilRegistration,
                'expired' => $daysUntilRegistration < 0
            ];
        }
    }
}

// Sort alerts by urgency
usort($maintenanceAlerts, function($a, $b) {
    return min($a['miles_until_due'], $a['days_until_due'] * 50) <=> min($b['miles_until_due'], $b['days_until_due'] * 50);
});

// Get maintenance history from Snipe-IT
$snipeMaintenances = get_maintenances(50);

// Also get local history as backup
$stmt = $pdo->prepare("
    SELECT * FROM maintenance_log 
    ORDER BY service_date DESC 
    LIMIT 50
");
$stmt->execute();
$localMaintenanceHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge and deduplicate (prefer Snipe-IT data)
$maintenanceHistory = $snipeMaintenances;

// Get pickup locations for return form
$pickupLocations = get_pickup_locations();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Maintenance Management</h1>
            <p class="text-muted">Track service schedules, log maintenance, manage fleet health</p>
        </div>
        

        <!-- App navigation -->
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- Top bar -->
        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h($userName) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="logout" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>



        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card h-100 <?= count($maintenanceAlerts) > 0 ? 'border-warning' : '' ?>">
                    <div class="card-body text-center">
                        <div class="display-6 <?= count($maintenanceAlerts) > 0 ? 'text-warning' : 'text-success' ?>">
                            <?= count($maintenanceAlerts) ?>
                        </div>
                        <small class="text-muted">Maintenance Due</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 <?= count($insuranceAlerts) > 0 ? 'border-danger' : '' ?>">
                    <div class="card-body text-center">
                        <div class="display-6 <?= count($insuranceAlerts) > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= count($insuranceAlerts) ?>
                        </div>
                        <small class="text-muted">Insurance Expiring</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 <?= count($registrationAlerts) > 0 ? 'border-danger' : '' ?>">
                    <div class="card-body text-center">
                        <div class="display-6 <?= count($registrationAlerts) > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= count($registrationAlerts) ?>
                        </div>
                        <small class="text-muted">Registration Expiring</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100">
                    <div class="card-body text-center">
                        <div class="display-6 text-secondary">
                            <?= count($outOfServiceVehicles) ?>
                        </div>
                        <small class="text-muted">In Maintenance</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>" href="?tab=alerts">
                    <i class="bi bi-exclamation-triangle me-1"></i>Alerts
                    <?php $totalAlerts = count($maintenanceAlerts) + count($insuranceAlerts) + count($registrationAlerts); ?>
                    <?php if ($totalAlerts > 0): ?>
                        <span class="badge bg-danger"><?= $totalAlerts ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'in_service' ? 'active' : '' ?>" href="?tab=in_service">
                    <i class="bi bi-tools me-1"></i>In Maintenance
                    <?php if (count($outOfServiceVehicles) > 0): ?>
                        <span class="badge bg-secondary"><?= count($outOfServiceVehicles) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'log' ? 'active' : '' ?>" href="?tab=log">
                    <i class="bi bi-plus-circle me-1"></i>Log Maintenance
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'history' ? 'active' : '' ?>" href="?tab=history">
                    <i class="bi bi-clock-history me-1"></i>History
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <?php if ($tab === 'alerts'): ?>
            <!-- Maintenance Alerts -->
            <?php if (count($maintenanceAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-wrench me-2"></i>Preventive Maintenance Due</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th>Current Mileage</th>
                                <th>Last Service</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maintenanceAlerts as $alert): ?>
                            <tr class="<?= $alert['urgent'] ? 'table-warning' : '' ?>">
                                <td>
                                    <strong><?= h($alert['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($alert['asset']['asset_tag']) ?></small>
                                </td>
                                <td><?= number_format($alert['current_mileage']) ?> mi</td>
                                <td>
                                    <?php if ($alert['last_maintenance_date']): ?>
                                        <?= date('M j, Y', strtotime($alert['last_maintenance_date'])) ?><br>
                                        <small class="text-muted"><?= number_format($alert['last_maintenance_mileage']) ?> mi</small>
                                    <?php else: ?>
                                        <span class="text-muted">No record</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($alert['urgent']): ?>
                                        <span class="badge bg-danger">OVERDUE</span>
                                    <?php elseif ($alert['reason'] === 'mileage'): ?>
                                        <span class="badge bg-warning text-dark"><?= number_format($alert['miles_until_due']) ?> mi remaining</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= $alert['days_until_due'] ?> days remaining</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="?tab=log&asset_id=<?= $alert['asset']['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-plus-circle"></i> Log Service
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Insurance Alerts -->
            <?php if (count($insuranceAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Insurance Expiring</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($insuranceAlerts as $alert): ?>
                            <tr class="<?= $alert['expired'] ? 'table-danger' : '' ?>">
                                <td>
                                    <strong><?= h($alert['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($alert['asset']['asset_tag']) ?></small>
                                </td>
                                <td><?= date('M j, Y', strtotime($alert['expiry_date'])) ?></td>
                                <td>
                                    <?php if ($alert['expired']): ?>
                                        <span class="badge bg-danger">EXPIRED</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= $alert['days_until'] ?> days</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Registration Alerts -->
            <?php if (count($registrationAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-card-text me-2"></i>Registration Expiring</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Vehicle</th>
                                <th>Expiry Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrationAlerts as $alert): ?>
                            <tr class="<?= $alert['expired'] ? 'table-danger' : '' ?>">
                                <td>
                                    <strong><?= h($alert['asset']['name']) ?></strong><br>
                                    <small class="text-muted"><?= h($alert['asset']['asset_tag']) ?></small>
                                </td>
                                <td><?= date('M j, Y', strtotime($alert['expiry_date'])) ?></td>
                                <td>
                                    <?php if ($alert['expired']): ?>
                                        <span class="badge bg-danger">EXPIRED</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= $alert['days_until'] ?> days</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (count($maintenanceAlerts) === 0 && count($insuranceAlerts) === 0 && count($registrationAlerts) === 0): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>All vehicles are up to date with maintenance and compliance!
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'in_service'): ?>
            <!-- Vehicles Currently in Maintenance -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Vehicles Currently in Maintenance</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($outOfServiceVehicles)): ?>
                        <p class="text-muted text-center py-4">No vehicles currently in maintenance.</p>
                    <?php else: ?>
                        <?php foreach ($outOfServiceVehicles as $item): 
                            $asset = $item['asset'];
                            $schedule = $item['schedule'];
                        ?>
                        <div class="card mb-3 border">
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <h5><?= h($asset['name']) ?></h5>
                                        <p class="mb-1"><strong>Tag:</strong> <?= h($asset['asset_tag']) ?></p>
                                        <p class="mb-1"><strong>Location:</strong> <?= h($asset['location']['name'] ?? 'Unknown') ?></p>
                                        <?php if ($schedule && $schedule['expected_return_date']): ?>
                                            <p class="mb-1"><strong>Expected Return:</strong> <?= date('M j, Y', strtotime($schedule['expected_return_date'])) ?></p>
                                        <?php endif; ?>
                                        <?php if ($schedule && $schedule['maintenance_notes']): ?>
                                            <p class="mb-1"><strong>Notes:</strong> <?= h($schedule['maintenance_notes']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <form method="post" class="border rounded p-3 bg-light">
                                            <input type="hidden" name="action" value="update_schedule">
                                            <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                            <div class="mb-2">
                                                <label class="form-label small">Expected Return Date</label>
                                                <input type="date" name="expected_return_date" class="form-control form-control-sm"
                                                       value="<?= $schedule['expected_return_date'] ?? '' ?>">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small">Maintenance Notes</label>
                                                <textarea name="maintenance_notes" class="form-control form-control-sm" rows="2"><?= h($schedule['maintenance_notes'] ?? '') ?></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">Update Schedule</button>
                                        </form>
                                        
                                        <hr>
                                        
                                        <form method="post">
                    <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="return_from_maintenance">
                                            <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                            <div class="row g-2">
                                                <div class="col-6">
                                                    <input type="number" name="return_mileage" class="form-control form-control-sm" 
                                                           placeholder="Return Mileage" required
                                                           value="<?= $asset['custom_fields']['Current Mileage']['value'] ?? '' ?>">
                                                </div>
                                                <div class="col-6">
                                                    <select name="return_location" class="form-select form-select-sm" required>
                                                        <option value="">Return Location</option>
                                                        <?php foreach ($pickupLocations as $loc): ?>
                                                            <option value="<?= $loc['id'] ?>"><?= h($loc['name']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-sm btn-success w-100">
                                                        <i class="bi bi-check-circle me-1"></i>Return to Fleet
                                                    </button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'log'): ?>
            <!-- Log New Maintenance -->
            <?php 
            $selectedAssetId = (int)($_GET['asset_id'] ?? 0);
            $selectedAsset = $selectedAssetId ? get_asset_with_custom_fields($selectedAssetId) : null;
            $suppliers = get_suppliers();
            $maintenanceTypes = [
                'Maintenance' => 'Preventive Maintenance',
                'Repair' => 'Repair',
                'Upgrade' => 'Upgrade',
                'Calibration' => 'Calibration',
            ];
            ?>
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Log Completed Maintenance</h5>
                    <small class="text-muted">This will create a record in Snipe-IT and update vehicle fields</small>
                </div>
                <div class="card-body">
                    <form method="post">
                    <?= csrf_field() ?>
                        <input type="hidden" name="action" value="log_maintenance">
                        
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select name="asset_id" class="form-select" required>
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($assetList as $asset): ?>
                                        <option value="<?= $asset['id'] ?>" <?= $selectedAssetId == $asset['id'] ? 'selected' : '' ?>>
                                            <?= h($asset['name']) ?> [<?= h($asset['asset_tag']) ?>]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" required
                                       placeholder="e.g., 7,500 Mile Service" value="">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Maintenance Type <span class="text-danger">*</span></label>
                                <select name="maintenance_type" class="form-select" required>
                                    <?php foreach ($maintenanceTypes as $value => $label): ?>
                                        <option value="<?= $value ?>"><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Service Provider <span class="text-danger">*</span></label>
                                <select name="supplier_id" class="form-select" required>
                                    <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['id'] ?>" <?= $supplier['id'] == 1 ? 'selected' : '' ?>>
                                            <?= h($supplier['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Mileage at Service <span class="text-danger">*</span></label>
                                <input type="number" name="mileage_at_service" class="form-control" required
                                       value="<?= $selectedAsset['custom_fields']['Current Mileage']['value'] ?? '' ?>"
                                       placeholder="Current odometer reading">
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Start Date <span class="text-danger">*</span></label>
                                <input type="date" name="service_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Completion Date <span class="text-danger">*</span></label>
                                <input type="date" name="completion_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            
<div class="col-md-4">
                                <label class="form-label">Cost ($)</label>
                                <input type="number" name="cost" class="form-control" step="0.01" placeholder="0.00">
                            </div>
                            
                            <div class="col-md-8">
                                <label class="form-label">Services Performed</label>
                                <div class="d-flex flex-wrap gap-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="oil_change" class="form-check-input" id="oilChange" value="1">
                                        <label class="form-check-label" for="oilChange">Oil Change</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" name="tire_rotation" class="form-check-input" id="tireRotation" value="1">
                                        <label class="form-check-label" for="tireRotation">Tire Rotation</label>
                                    </div>
                                    <div class="form-check">
                                        <input type="checkbox" name="is_warranty" class="form-check-input" id="isWarranty" value="1">
                                        <label class="form-check-label" for="isWarranty">Warranty Coverage</label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-control" rows="3" placeholder="Description of work performed, parts replaced, recommendations..."></textarea>
                            </div>
                            
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-2"></i>Log Maintenance Record
                                </button>
                                <a href="https://inventory.amtrakfdt.com/maintenances/create" target="_blank" class="btn btn-outline-secondary ms-2">
                                    <i class="bi bi-box-arrow-up-right me-2"></i>Open in Snipe-IT
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        <?php elseif ($tab === 'history'): ?>
            <!-- Maintenance History -->
            <?php
            // Get maintenance records from Snipe-IT
            $snipeMaintenances = get_maintenances(50);
            ?>
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Maintenance History</h5>
                    <a href="https://inventory.amtrakfdt.com/maintenances" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-box-arrow-up-right me-1"></i>View in Snipe-IT
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($snipeMaintenances)): ?>
                        <p class="text-muted text-center py-4">No maintenance records found.</p>
                    <?php else: ?>
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Provider</th>
                                    <th class="text-end">Cost</th>
                                    <th>Logged By</th>
                                    <th>Notes</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($snipeMaintenances as $record): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $completionDate = $record['completion_date']['date'] ?? $record['start_date']['date'] ?? null;
                                        echo $completionDate ? date('M j, Y', strtotime($completionDate)) : '-';
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?= h($record['asset']['name'] ?? 'Unknown') ?></strong><br>
                                        <small class="text-muted"><?= h($record['asset']['asset_tag'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= h($record['asset_maintenance_type'] ?? 'Maintenance') ?></span>
                                        <?php if (!empty($record['title'])): ?>
                                            <br><small class="text-muted"><?= h($record['title']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($record['supplier']['name'] ?? '-') ?></td>
                                    <td class="text-end">
                                        <?= isset($record['cost']) && $record['cost'] > 0 ? '$' . number_format((float)$record['cost'], 2) : '-' ?>
                                    </td>
                                    <td><?= h($record['user_id']['name'] ?? $record['created_by']['name'] ?? '-') ?></td>
                                    <td>
                                        <?php if (!empty($record['notes'])): ?>
                                            <small><?= h(substr($record['notes'], 0, 50)) ?><?= strlen($record['notes']) > 50 ? '...' : '' ?></small>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
