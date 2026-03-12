<?php
/**
 * Fleet Maintenance Management
 * Track maintenance schedules, log service records, manage alerts
 *
 * Epic 2: Fleet Health Dashboard rewrite
 *   v2: Richer overview columns (last/next maintenance, date+days for compliance),
 *       success confirmation modal, log+return integrated workflow,
 *       enriched In Maintenance tab with issue context + service history
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
$tab = $_GET['tab'] ?? 'overview';

// Default maintenance intervals
define('DEFAULT_MAINTENANCE_MILES', 7500);
define('DEFAULT_MAINTENANCE_DAYS', 180);
define('ALERT_DAYS_BEFORE_EXPIRY', 30);

// =========================================================================
// Handle form submissions
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'log_maintenance') {
        $assetId = (int)$_POST['asset_id'];
        $maintenanceType = $_POST['maintenance_type'] ?? 'Maintenance';
        $title = trim($_POST['title'] ?? 'Scheduled Maintenance');
        $mileageAtService = (int)$_POST['mileage_at_service'];
        $serviceDate = $_POST['service_date'] ?? date('Y-m-d');
        $completionDate = $_POST['completion_date'] ?? $serviceDate;
        $cost = !empty($_POST['cost']) ? (float)$_POST['cost'] : 0;
        $notes = trim($_POST['notes'] ?? '');
        $supplierId = (int)($_POST['supplier_id'] ?? 1);
        $isWarranty = isset($_POST['is_warranty']) && $_POST['is_warranty'] == '1';
        $oilChange = isset($_POST['oil_change']) && $_POST['oil_change'] == '1';
        $tireRotation = isset($_POST['tire_rotation']) && $_POST['tire_rotation'] == '1';
        $returnToFleet = isset($_POST['return_to_fleet']) && $_POST['return_to_fleet'] == '1';
        $returnLocation = (int)($_POST['return_location'] ?? 0);

        $asset = get_asset_with_custom_fields($assetId);
        $assetTag = $asset['asset_tag'] ?? '';
        $assetName = $asset['name'] ?? '';
        $assetStatusId = $asset['status_label']['id'] ?? 0;

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
                snipeit_field('last_maintenance_date') => $completionDate,
                snipeit_field('last_maintenance_mileage') => $mileageAtService,
                snipeit_field('current_mileage') => $mileageAtService,
            ];

            if ($oilChange) {
                $updateFields[snipeit_field('last_oil_change_miles')] = $mileageAtService;
            }
            if ($tireRotation) {
                $updateFields[snipeit_field('last_tire_rotation_miles')] = $mileageAtService;
            }

            update_asset_custom_fields($assetId, $updateFields);

            // 3. Save to local DB for quick reporting
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

            // 4. If "Return to Fleet" was checked and vehicle is Out of Service
            if ($returnToFleet && $assetStatusId == STATUS_VEH_OUT_OF_SERVICE) {
                update_asset_status($assetId, STATUS_VEH_AVAILABLE);

                if ($returnLocation) {
                    update_asset_location($assetId, $returnLocation);
                }

                // Clear maintenance schedule
                $stmt = $pdo->prepare("DELETE FROM maintenance_schedule WHERE asset_id = ?");
                $stmt->execute([$assetId]);

                // Close maintenance_required reservations
                $stmt = $pdo->prepare("
                    UPDATE reservations SET status = 'completed'
                    WHERE asset_id = ? AND status = 'maintenance_required'
                ");
                $stmt->execute([$assetId]);

                $success = "Maintenance recorded for {$assetName} and vehicle returned to fleet.";
            } else {
                $success = "Maintenance record created for {$assetName}. Asset fields updated.";
            }

        } catch (Exception $e) {
            $error = "Failed to log maintenance: " . $e->getMessage();
        }

    } elseif ($action === 'update_schedule') {
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
        $assetId = (int)$_POST['asset_id'];
        $returnMileage = (int)$_POST['return_mileage'];
        $returnLocation = (int)$_POST['return_location'];

        try {
            update_asset_status($assetId, STATUS_VEH_AVAILABLE);

            if ($returnLocation) {
                update_asset_location($assetId, $returnLocation);
            }

            update_asset_custom_fields($assetId, [
                snipeit_field('current_mileage') => $returnMileage
            ]);

            $stmt = $pdo->prepare("DELETE FROM maintenance_schedule WHERE asset_id = ?");
            $stmt->execute([$assetId]);

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

// =========================================================================
// Lazy data loading — only fetch what the active tab needs
// =========================================================================

$needsAssetList = in_array($tab, ['overview', 'alerts', 'in_service', 'log']);
$assetList = [];
if ($needsAssetList) {
    $allAssets = get_requestable_assets(100, null);
    $assetList = is_array($allAssets) ? $allAssets : [];
}

// ---- Fleet Overview & Alerts: compute health data from asset list --------
$fleetVehicles = [];
$maintenanceAlerts = [];
$insuranceAlerts = [];
$registrationAlerts = [];
$outOfServiceVehicles = [];

if (in_array($tab, ['overview', 'alerts', 'in_service'])) {
    foreach ($assetList as $asset) {
        $assetId = $asset['id'];
        $statusId = $asset['status_label']['id'] ?? 0;
        $cf = $asset['custom_fields'] ?? [];

        $currentMileage = (int)($cf['Current Mileage']['value'] ?? 0);
        $lastMaintenanceDate = $cf['Last Maintenance Date']['value'] ?? null;
        $lastMaintenanceMileage = (int)($cf['Last Maintenance Mileage']['value'] ?? 0);
        $maintenanceIntervalMiles = (int)($cf['Maintenance Interval Miles']['value'] ?? DEFAULT_MAINTENANCE_MILES);
        $maintenanceIntervalDays = (int)($cf['Maintenance Interval Days']['value'] ?? DEFAULT_MAINTENANCE_DAYS);
        $insuranceExpiry = $cf['Insurance Expiry']['value'] ?? null;
        $registrationExpiry = $cf['Registration Expiry']['value'] ?? null;

        // Maintenance calculations
        $milesSinceService = $currentMileage - $lastMaintenanceMileage;
        $milesUntilDue = $maintenanceIntervalMiles - $milesSinceService;
        $daysSinceService = $lastMaintenanceDate
            ? (int)((time() - strtotime($lastMaintenanceDate)) / 86400)
            : 999;
        $daysUntilDue = $maintenanceIntervalDays - $daysSinceService;

        // Next due calculations
        $nextDueMileage = $lastMaintenanceMileage + $maintenanceIntervalMiles;
        $nextDueDate = $lastMaintenanceDate
            ? date('Y-m-d', strtotime($lastMaintenanceDate . ' + ' . $maintenanceIntervalDays . ' days'))
            : null;

        // Insurance calculations
        $insuranceDays = $insuranceExpiry ? (int)((strtotime($insuranceExpiry) - time()) / 86400) : null;
        $registrationDays = $registrationExpiry ? (int)((strtotime($registrationExpiry) - time()) / 86400) : null;

        // --- Health score (0-100) ---
        $mntScore = 100;
        if ($milesUntilDue <= 0 || $daysUntilDue <= 0) {
            $mntScore = 0;
        } elseif ($milesUntilDue <= 500 || $daysUntilDue <= 14) {
            $mntScore = 40;
        } elseif ($milesUntilDue <= 1500 || $daysUntilDue <= 45) {
            $mntScore = 70;
        }

        $insScore = 100;
        if ($insuranceDays === null) { $insScore = 50; }
        elseif ($insuranceDays < 0) { $insScore = 0; }
        elseif ($insuranceDays <= 14) { $insScore = 25; }
        elseif ($insuranceDays <= 30) { $insScore = 60; }

        $regScore = 100;
        if ($registrationDays === null) { $regScore = 50; }
        elseif ($registrationDays < 0) { $regScore = 0; }
        elseif ($registrationDays <= 14) { $regScore = 25; }
        elseif ($registrationDays <= 30) { $regScore = 60; }

        $healthScore = (int)round(($mntScore + $insScore + $regScore) / 3);

        // Status labels
        $mntStatus = 'ok';
        if ($milesUntilDue <= 0 || $daysUntilDue <= 0) { $mntStatus = 'due'; }
        elseif ($milesUntilDue <= 500 || $daysUntilDue <= 14) { $mntStatus = 'warning'; }

        $insStatus = 'unknown';
        if ($insuranceDays !== null) {
            $insStatus = $insuranceDays < 0 ? 'expired' : ($insuranceDays <= 30 ? 'warning' : 'ok');
        }

        $regStatus = 'unknown';
        if ($registrationDays !== null) {
            $regStatus = $registrationDays < 0 ? 'expired' : ($registrationDays <= 30 ? 'warning' : 'ok');
        }

        $vehStatus = 'available';
        if ($statusId == STATUS_VEH_OUT_OF_SERVICE) $vehStatus = 'out_of_service';
        elseif ($statusId == STATUS_VEH_IN_SERVICE) $vehStatus = 'in_service';
        elseif ($statusId == STATUS_VEH_RESERVED) $vehStatus = 'reserved';

        $vehicleData = [
            'asset' => $asset,
            'current_mileage' => $currentMileage,
            'last_maintenance_date' => $lastMaintenanceDate,
            'last_maintenance_mileage' => $lastMaintenanceMileage,
            'next_due_mileage' => $nextDueMileage,
            'next_due_date' => $nextDueDate,
            'miles_until_due' => $milesUntilDue,
            'days_until_due' => $daysUntilDue,
            'miles_since_service' => $milesSinceService,
            'insurance_expiry' => $insuranceExpiry,
            'insurance_days' => $insuranceDays,
            'insurance_status' => $insStatus,
            'registration_expiry' => $registrationExpiry,
            'registration_days' => $registrationDays,
            'registration_status' => $regStatus,
            'maintenance_status' => $mntStatus,
            'vehicle_status' => $vehStatus,
            'health_score' => $healthScore,
        ];

        $fleetVehicles[] = $vehicleData;

        // Populate alerts
        if ($milesUntilDue <= 500 || $daysUntilDue <= 14) {
            $maintenanceAlerts[] = array_merge($vehicleData, [
                'reason' => $milesUntilDue <= 500 ? 'mileage' : 'time',
                'urgent' => $milesUntilDue <= 0 || $daysUntilDue <= 0,
            ]);
        }
        if ($insuranceDays !== null && $insuranceDays <= ALERT_DAYS_BEFORE_EXPIRY) {
            $insuranceAlerts[] = array_merge($vehicleData, ['expired' => $insuranceDays < 0]);
        }
        if ($registrationDays !== null && $registrationDays <= ALERT_DAYS_BEFORE_EXPIRY) {
            $registrationAlerts[] = array_merge($vehicleData, ['expired' => $registrationDays < 0]);
        }
        if ($statusId == STATUS_VEH_OUT_OF_SERVICE) {
            // Get schedule
            $stmt = $pdo->prepare("SELECT * FROM maintenance_schedule WHERE asset_id = ?");
            $stmt->execute([$assetId]);
            $schedule = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get original issue from reservation
            $stmt = $pdo->prepare("
                SELECT maintenance_notes, maintenance_flag, user_name, created_at
                FROM reservations
                WHERE asset_id = ? AND maintenance_flag = 1
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$assetId]);
            $originalIssue = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get recent maintenance history for this vehicle
            $stmt = $pdo->prepare("
                SELECT * FROM maintenance_log
                WHERE asset_id = ?
                ORDER BY service_date DESC LIMIT 3
            ");
            $stmt->execute([$assetId]);
            $recentHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $outOfServiceVehicles[] = [
                'asset' => $asset,
                'schedule' => $schedule,
                'original_issue' => $originalIssue,
                'recent_history' => $recentHistory,
            ];
        }
    }

    usort($maintenanceAlerts, function ($a, $b) {
        return min($a['miles_until_due'], $a['days_until_due'] * 50) <=> min($b['miles_until_due'], $b['days_until_due'] * 50);
    });

    usort($fleetVehicles, function ($a, $b) {
        return $a['health_score'] <=> $b['health_score'];
    });
}

// ---- History tab: fetch, filter, enrich, summarize ----
$historyRecords = [];
$historySummary = ['total' => 0, 'total_cost' => 0, 'date_range' => ''];
$historyAssetFilter = null;
$historyDateFrom = '';
$historyDateTo = '';
$historyVehicleList = []; // unique vehicles for dropdown

if ($tab === 'history') {
    $historyAssetFilter = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : null;
    $historyDateFrom = $_GET['date_from'] ?? '';
    $historyDateTo = $_GET['date_to'] ?? '';

    $rawMaintenances = $historyAssetFilter
        ? get_maintenances(500, $historyAssetFilter)
        : get_maintenances(500);

    // Build unique vehicle list from results
    $seenVehicles = [];

    foreach ($rawMaintenances as $record) {
        $completionDate = $record['completion_date']['date'] ?? $record['start_date']['date'] ?? null;
        if (!$completionDate) continue;

        $mDate = substr($completionDate, 0, 10);

        // Date filters
        if ($historyDateFrom && $mDate < $historyDateFrom) continue;
        if ($historyDateTo && $mDate > $historyDateTo) continue;

        // Extract mileage from notes
        $notes = $record['notes'] ?? '';
        $mileageAtService = 0;
        $cleanNotes = $notes;
        if (preg_match('/Mileage at service:\s*([\d,]+)\s*mi/i', $notes, $matches)) {
            $mileageAtService = (int)str_replace(',', '', $matches[1]);
            $cleanNotes = trim(preg_replace('/Mileage at service:\s*[\d,]+\s*mi\n?/i', '', $notes));
        }

        $cost = floatval($record['cost'] ?? 0);
        $vehicleId = $record['asset']['id'] ?? 0;
        $vehicleName = $record['asset']['name'] ?? 'Unknown';
        $vehicleTag = $record['asset']['asset_tag'] ?? '';

        // Track unique vehicles
        if ($vehicleId && !isset($seenVehicles[$vehicleId])) {
            $seenVehicles[$vehicleId] = $vehicleName . ' [' . $vehicleTag . ']';
        }

        $historyRecords[] = [
            'date' => $mDate,
            'vehicle_name' => $vehicleName,
            'vehicle_tag' => $vehicleTag,
            'vehicle_id' => $vehicleId,
            'type' => $record['asset_maintenance_type'] ?? 'Maintenance',
            'title' => $record['title'] ?? '',
            'supplier' => $record['supplier']['name'] ?? '-',
            'cost' => $cost,
            'mileage' => $mileageAtService,
            'logged_by' => $record['user_id']['name'] ?? $record['created_by']['name'] ?? '-',
            'notes' => $cleanNotes,
            'is_warranty' => !empty($record['is_warranty']),
        ];

        $historySummary['total']++;
        $historySummary['total_cost'] += $cost;
    }

    // Sort vehicles alphabetically for dropdown
    asort($seenVehicles);
    $historyVehicleList = $seenVehicles;

    // Build display date range
    if ($historyDateFrom && $historyDateTo) {
        $historySummary['date_range'] = date('M j, Y', strtotime($historyDateFrom)) . ' - ' . date('M j, Y', strtotime($historyDateTo));
    } elseif ($historyDateFrom) {
        $historySummary['date_range'] = 'Since ' . date('M j, Y', strtotime($historyDateFrom));
    } elseif ($historyDateTo) {
        $historySummary['date_range'] = 'Through ' . date('M j, Y', strtotime($historyDateTo));
    } else {
        $historySummary['date_range'] = 'All Time';
    }
}

// ---- History CSV export ----
if ($tab === 'history' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = "maintenance_history_" . date('Y-m-d') . ".csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Vehicle', 'Tag', 'Type', 'Title', 'Mileage', 'Provider', 'Cost', 'Warranty', 'Logged By', 'Notes']);
    foreach ($historyRecords as $r) {
        fputcsv($output, [
            $r['date'], $r['vehicle_name'], $r['vehicle_tag'],
            $r['type'], $r['title'],
            $r['mileage'] > 0 ? number_format($r['mileage']) : '',
            $r['supplier'],
            $r['cost'] > 0 ? '$' . number_format($r['cost'], 2) : '',
            $r['is_warranty'] ? 'Yes' : 'No',
            $r['logged_by'], $r['notes'],
        ]);
    }
    fclose($output);
    exit;
}

// ---- Tabs needing pickup locations ----
$pickupLocations = [];
if (in_array($tab, ['in_service', 'log'])) {
    $pickupLocations = get_pickup_locations();
}

// ---- Summary counts ----
$totalAlerts = count($maintenanceAlerts) + count($insuranceAlerts) + count($registrationAlerts);
$totalOOS = count($outOfServiceVehicles);
$fleetCount = count($fleetVehicles);

$avgHealth = $fleetCount > 0 ? (int)round(array_sum(array_column($fleetVehicles, 'health_score')) / $fleetCount) : 0;
$criticalCount = count(array_filter($fleetVehicles, fn($v) => $v['health_score'] < 50));
$warningCount = count(array_filter($fleetVehicles, fn($v) => $v['health_score'] >= 50 && $v['health_score'] < 80));
$healthyCount = count(array_filter($fleetVehicles, fn($v) => $v['health_score'] >= 80));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maintenance Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .health-bar { height: 8px; border-radius: 4px; background: #e9ecef; overflow: hidden; }
        .health-bar-fill { height: 100%; border-radius: 4px; transition: width 0.4s ease; }
        .health-critical { background: #dc3545; }
        .health-warning { background: #ffc107; }
        .health-good { background: #198754; }
        .health-score { font-weight: 700; font-size: 0.85rem; min-width: 32px; display: inline-block; text-align: center; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
        .status-dot-ok { background: #198754; }
        .status-dot-warning { background: #ffc107; }
        .status-dot-expired, .status-dot-due { background: #dc3545; }
        .status-dot-unknown { background: #adb5bd; }
        .vehicle-status-badge { font-size: 0.75rem; }
        .fleet-summary-card .number { font-size: 2rem; font-weight: 700; }
        .info-two-line { line-height: 1.3; }
        .info-two-line .info-primary { font-size: 0.85rem; font-weight: 600; }
        .info-two-line .info-secondary { font-size: 0.75rem; }
        .issue-banner { border-left: 4px solid #dc3545; background: #fff5f5; }
        .history-item { border-left: 3px solid #198754; }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Maintenance Management</h1>
            <p class="text-muted">Track service schedules, log maintenance, manage fleet health</p>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <?php if ($success && !in_array($tab, ['log'])): // Non-log tabs still show banner ?>
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
                <div class="card h-100 fleet-summary-card">
                    <div class="card-body text-center">
                        <div class="number <?= $avgHealth >= 80 ? 'text-success' : ($avgHealth >= 50 ? 'text-warning' : 'text-danger') ?>">
                            <?= $avgHealth ?>%
                        </div>
                        <small class="text-muted">Fleet Health</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 fleet-summary-card <?= $totalAlerts > 0 ? 'border-warning' : '' ?>">
                    <div class="card-body text-center">
                        <div class="number <?= $totalAlerts > 0 ? 'text-warning' : 'text-success' ?>">
                            <?= $totalAlerts ?>
                        </div>
                        <small class="text-muted">Active Alerts</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 fleet-summary-card <?= $criticalCount > 0 ? 'border-danger' : '' ?>">
                    <div class="card-body text-center">
                        <div class="number <?= $criticalCount > 0 ? 'text-danger' : 'text-success' ?>">
                            <?= $criticalCount ?>
                        </div>
                        <small class="text-muted">Critical Vehicles</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card h-100 fleet-summary-card">
                    <div class="card-body text-center">
                        <div class="number text-secondary"><?= $totalOOS ?></div>
                        <small class="text-muted">In Maintenance</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'overview' ? 'active' : '' ?>" href="?tab=overview">
                    <i class="bi bi-heart-pulse me-1"></i>Fleet Overview
                    <span class="badge bg-<?= $avgHealth >= 80 ? 'success' : ($avgHealth >= 50 ? 'warning' : 'danger') ?> ms-1"><?= $avgHealth ?>%</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>" href="?tab=alerts">
                    <i class="bi bi-exclamation-triangle me-1"></i>Alerts
                    <?php if ($totalAlerts > 0): ?><span class="badge bg-danger"><?= $totalAlerts ?></span><?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'in_service' ? 'active' : '' ?>" href="?tab=in_service">
                    <i class="bi bi-tools me-1"></i>In Maintenance
                    <?php if ($totalOOS > 0): ?><span class="badge bg-secondary"><?= $totalOOS ?></span><?php endif; ?>
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

        <!-- ============================================================= -->
        <!-- TAB: Fleet Overview                                           -->
        <!-- ============================================================= -->
        <?php if ($tab === 'overview'): ?>

            <!-- Health distribution bar -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <strong>Fleet Health Distribution</strong>
                        <small class="text-muted"><?= $fleetCount ?> vehicles</small>
                    </div>
                    <div class="d-flex gap-1" style="height: 24px;">
                        <?php if ($criticalCount > 0): ?>
                            <div class="health-critical rounded" style="width: <?= round($criticalCount / max(1, $fleetCount) * 100) ?>%; display:flex; align-items:center; justify-content:center;">
                                <small class="text-white fw-bold"><?= $criticalCount ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($warningCount > 0): ?>
                            <div class="health-warning rounded" style="width: <?= round($warningCount / max(1, $fleetCount) * 100) ?>%; display:flex; align-items:center; justify-content:center;">
                                <small class="fw-bold"><?= $warningCount ?></small>
                            </div>
                        <?php endif; ?>
                        <?php if ($healthyCount > 0): ?>
                            <div class="health-good rounded" style="width: <?= round($healthyCount / max(1, $fleetCount) * 100) ?>%; display:flex; align-items:center; justify-content:center;">
                                <small class="text-white fw-bold"><?= $healthyCount ?></small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-3 mt-2">
                        <small><span class="status-dot status-dot-due me-1"></span> Critical (&lt;50)</small>
                        <small><span class="status-dot status-dot-warning me-1"></span> Warning (50-79)</small>
                        <small><span class="status-dot status-dot-ok me-1"></span> Healthy (80+)</small>
                    </div>
                </div>
            </div>

            <!-- Vehicle grid -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-heart-pulse me-2"></i>All Vehicles</h5>
                    <small class="text-muted">Sorted by health score (worst first)</small>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($fleetVehicles)): ?>
                        <p class="text-muted text-center py-4">No fleet vehicles found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Vehicle</th>
                                        <th class="text-center">Status</th>
                                        <th style="width: 130px;">Health</th>
                                        <th class="text-center">Mileage</th>
                                        <th>Maintenance</th>
                                        <th>Insurance</th>
                                        <th>Registration</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fleetVehicles as $v):
                                        $hs = $v['health_score'];
                                        $hsClass = $hs >= 80 ? 'health-good' : ($hs >= 50 ? 'health-warning' : 'health-critical');
                                        $hsText = $hs >= 80 ? 'text-success' : ($hs >= 50 ? 'text-warning' : 'text-danger');
                                        $rowClass = $hs < 50 ? 'table-danger' : '';

                                        $statusBadges = [
                                            'available' => ['bg-success', 'Available'],
                                            'reserved' => ['bg-info', 'Reserved'],
                                            'in_service' => ['bg-primary', 'In Service'],
                                            'out_of_service' => ['bg-danger', 'Out of Service'],
                                        ];
                                        $sb = $statusBadges[$v['vehicle_status']] ?? ['bg-secondary', 'Unknown'];
                                    ?>
                                    <tr class="<?= $rowClass ?>">
                                        <td>
                                            <strong><?= h($v['asset']['name']) ?></strong><br>
                                            <small class="text-muted"><?= h($v['asset']['asset_tag']) ?></small>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $sb[0] ?> vehicle-status-badge"><?= $sb[1] ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="health-score <?= $hsText ?>"><?= $hs ?></span>
                                                <div class="health-bar flex-grow-1">
                                                    <div class="health-bar-fill <?= $hsClass ?>" style="width: <?= $hs ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?= $v['current_mileage'] > 0 ? number_format($v['current_mileage']) . ' mi' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <!-- Maintenance: Last + Next -->
                                        <td class="info-two-line">
                                            <span class="status-dot status-dot-<?= $v['maintenance_status'] ?> me-1"></span>
                                            <?php if ($v['maintenance_status'] === 'due'): ?>
                                                <span class="info-primary text-danger">OVERDUE</span><br>
                                                <span class="info-secondary text-danger">
                                                    by <?= number_format(abs($v['miles_until_due'])) ?> mi
                                                    <?= $v['days_until_due'] < 0 ? ' / ' . abs($v['days_until_due']) . 'd' : '' ?>
                                                </span>
                                            <?php elseif ($v['last_maintenance_date']): ?>
                                                <span class="info-primary">Last: <?= date('M j', strtotime($v['last_maintenance_date'])) ?> @ <?= number_format($v['last_maintenance_mileage']) ?> mi</span><br>
                                                <span class="info-secondary text-<?= $v['maintenance_status'] === 'warning' ? 'warning' : 'muted' ?>">
                                                    Next: ~<?= number_format($v['next_due_mileage']) ?> mi
                                                    <?php if ($v['next_due_date']): ?>
                                                        / <?= date('M j', strtotime($v['next_due_date'])) ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="info-primary text-muted">No record</span><br>
                                                <span class="info-secondary text-muted">Schedule unknown</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Insurance: Date + Days -->
                                        <td class="info-two-line">
                                            <span class="status-dot status-dot-<?= $v['insurance_status'] ?> me-1"></span>
                                            <?php if ($v['insurance_expiry']): ?>
                                                <span class="info-primary"><?= date('M j, Y', strtotime($v['insurance_expiry'])) ?></span><br>
                                                <?php if ($v['insurance_days'] < 0): ?>
                                                    <span class="info-secondary text-danger">Expired <?= abs($v['insurance_days']) ?>d ago</span>
                                                <?php elseif ($v['insurance_days'] <= 30): ?>
                                                    <span class="info-secondary text-warning"><?= $v['insurance_days'] ?> days left</span>
                                                <?php else: ?>
                                                    <span class="info-secondary text-success"><?= $v['insurance_days'] ?> days left</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="info-primary text-muted">Not set</span><br>
                                                <span class="info-secondary text-muted">No expiry date</span>
                                            <?php endif; ?>
                                        </td>
                                        <!-- Registration: Date + Days -->
                                        <td class="info-two-line">
                                            <span class="status-dot status-dot-<?= $v['registration_status'] ?> me-1"></span>
                                            <?php if ($v['registration_expiry']): ?>
                                                <span class="info-primary"><?= date('M j, Y', strtotime($v['registration_expiry'])) ?></span><br>
                                                <?php if ($v['registration_days'] < 0): ?>
                                                    <span class="info-secondary text-danger">Expired <?= abs($v['registration_days']) ?>d ago</span>
                                                <?php elseif ($v['registration_days'] <= 30): ?>
                                                    <span class="info-secondary text-warning"><?= $v['registration_days'] ?> days left</span>
                                                <?php else: ?>
                                                    <span class="info-secondary text-success"><?= $v['registration_days'] ?> days left</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="info-primary text-muted">Not set</span><br>
                                                <span class="info-secondary text-muted">No expiry date</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($v['maintenance_status'] !== 'ok'): ?>
                                                <a href="?tab=log&asset_id=<?= $v['asset']['id'] ?>" class="btn btn-sm btn-outline-warning" title="Log service">
                                                    <i class="bi bi-wrench"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <!-- ============================================================= -->
        <!-- TAB: Alerts                                                   -->
        <!-- ============================================================= -->
        <?php elseif ($tab === 'alerts'): ?>

            <?php if (count($maintenanceAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0"><i class="bi bi-wrench me-2"></i>Preventive Maintenance Due</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Vehicle</th><th>Current Mileage</th><th>Last Service</th><th>Status</th><th>Action</th></tr>
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

            <?php if (count($insuranceAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-shield-exclamation me-2"></i>Insurance Expiring</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Vehicle</th><th>Expiry Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($insuranceAlerts as $alert): ?>
                            <tr class="<?= $alert['expired'] ? 'table-danger' : '' ?>">
                                <td><strong><?= h($alert['asset']['name']) ?></strong><br><small class="text-muted"><?= h($alert['asset']['asset_tag']) ?></small></td>
                                <td><?= date('M j, Y', strtotime($alert['insurance_expiry'])) ?></td>
                                <td><?= $alert['expired'] ? '<span class="badge bg-danger">EXPIRED</span>' : '<span class="badge bg-warning text-dark">' . $alert['insurance_days'] . ' days</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if (count($registrationAlerts) > 0): ?>
            <div class="card mb-4">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-card-text me-2"></i>Registration Expiring</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light"><tr><th>Vehicle</th><th>Expiry Date</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($registrationAlerts as $alert): ?>
                            <tr class="<?= $alert['expired'] ? 'table-danger' : '' ?>">
                                <td><strong><?= h($alert['asset']['name']) ?></strong><br><small class="text-muted"><?= h($alert['asset']['asset_tag']) ?></small></td>
                                <td><?= date('M j, Y', strtotime($alert['registration_expiry'])) ?></td>
                                <td><?= $alert['expired'] ? '<span class="badge bg-danger">EXPIRED</span>' : '<span class="badge bg-warning text-dark">' . $alert['registration_days'] . ' days</span>' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($totalAlerts === 0): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>All vehicles are up to date with maintenance and compliance!
            </div>
            <?php endif; ?>

        <!-- ============================================================= -->
        <!-- TAB: In Maintenance (enriched)                                -->
        <!-- ============================================================= -->
        <?php elseif ($tab === 'in_service'): ?>
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
                            $originalIssue = $item['original_issue'];
                            $recentHistory = $item['recent_history'];
                        ?>
                        <div class="card mb-4 border">
                            <div class="card-body">
                                <div class="row">
                                    <!-- Left: Vehicle info + issue context + history -->
                                    <div class="col-md-7">
                                        <h5 class="mb-3"><?= h($asset['name']) ?> <small class="text-muted"><?= h($asset['asset_tag']) ?></small></h5>

                                        <div class="row mb-3">
                                            <div class="col-6">
                                                <small class="text-muted d-block">Location</small>
                                                <strong><?= h($asset['location']['name'] ?? 'Unknown') ?></strong>
                                            </div>
                                            <div class="col-6">
                                                <small class="text-muted d-block">Current Mileage</small>
                                                <strong><?= number_format((int)($asset['custom_fields']['Current Mileage']['value'] ?? 0)) ?> mi</strong>
                                            </div>
                                        </div>

                                        <?php if ($schedule && $schedule['expected_return_date']): ?>
                                        <div class="mb-3">
                                            <small class="text-muted d-block">Expected Return</small>
                                            <strong><?= date('M j, Y', strtotime($schedule['expected_return_date'])) ?></strong>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Original Issue -->
                                        <?php if ($originalIssue || ($schedule && $schedule['maintenance_notes'])): ?>
                                        <div class="issue-banner rounded p-3 mb-3">
                                            <small class="text-danger fw-bold d-block mb-1"><i class="bi bi-exclamation-circle me-1"></i>Reported Issue</small>
                                            <?php if ($originalIssue): ?>
                                                <p class="mb-1"><?= h($originalIssue['maintenance_notes'] ?: 'No details provided') ?></p>
                                                <small class="text-muted">Flagged by <?= h($originalIssue['user_name']) ?> on <?= date('M j, Y', strtotime($originalIssue['created_at'])) ?></small>
                                            <?php elseif ($schedule && $schedule['maintenance_notes']): ?>
                                                <p class="mb-0"><?= h($schedule['maintenance_notes']) ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>

                                        <!-- Recent maintenance history for this vehicle -->
                                        <?php if (!empty($recentHistory)): ?>
                                        <div class="mb-2">
                                            <small class="text-muted fw-bold d-block mb-2"><i class="bi bi-clock-history me-1"></i>Recent Service History</small>
                                            <?php foreach ($recentHistory as $hist): ?>
                                            <div class="history-item ps-3 py-1 mb-2">
                                                <small class="fw-bold"><?= date('M j, Y', strtotime($hist['service_date'])) ?></small>
                                                <small class="text-muted"> &mdash; <?= h($hist['maintenance_type']) ?></small>
                                                <?php if ($hist['mileage_at_service']): ?>
                                                    <small class="text-muted"> @ <?= number_format($hist['mileage_at_service']) ?> mi</small>
                                                <?php endif; ?>
                                                <?php if ($hist['cost'] > 0): ?>
                                                    <small class="text-success"> ($<?= number_format($hist['cost'], 2) ?>)</small>
                                                <?php endif; ?>
                                                <?php if ($hist['description']): ?>
                                                    <br><small class="text-muted ps-1"><?= h($hist['description']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="mb-3">
                                            <small class="text-muted"><i class="bi bi-info-circle me-1"></i>No maintenance logged for this vehicle yet.
                                                <a href="?tab=log&asset_id=<?= $asset['id'] ?>">Log maintenance now</a>
                                            </small>
                                        </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Right: Actions -->
                                    <div class="col-md-5">
                                        <!-- Log maintenance shortcut -->
                                        <a href="?tab=log&asset_id=<?= $asset['id'] ?>" class="btn btn-outline-primary w-100 mb-3">
                                            <i class="bi bi-plus-circle me-1"></i>Log Maintenance for This Vehicle
                                        </a>

                                        <!-- Update schedule -->
                                        <form method="post" class="border rounded p-3 bg-light mb-3">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_schedule">
                                            <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                            <small class="fw-bold d-block mb-2">Update Schedule</small>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1">Expected Return Date</label>
                                                <input type="date" name="expected_return_date" class="form-control form-control-sm"
                                                       value="<?= $schedule['expected_return_date'] ?? '' ?>">
                                            </div>
                                            <div class="mb-2">
                                                <label class="form-label small mb-1">Notes</label>
                                                <textarea name="maintenance_notes" class="form-control form-control-sm" rows="2"><?= h($schedule['maintenance_notes'] ?? '') ?></textarea>
                                            </div>
                                            <button type="submit" class="btn btn-sm btn-primary">Update</button>
                                        </form>

                                        <!-- Return to fleet -->
                                        <form method="post" class="border rounded p-3">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="return_from_maintenance">
                                            <input type="hidden" name="asset_id" value="<?= $asset['id'] ?>">
                                            <small class="fw-bold d-block mb-2">Return to Fleet</small>
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

        <!-- ============================================================= -->
        <!-- TAB: Log Maintenance (with Return to Fleet option)            -->
        <!-- ============================================================= -->
        <?php elseif ($tab === 'log'): ?>
            <?php
            $selectedAssetId = (int)($_GET['asset_id'] ?? 0);
            $selectedAsset = $selectedAssetId ? get_asset_with_custom_fields($selectedAssetId) : null;
            $selectedIsOOS = $selectedAsset && ($selectedAsset['status_label']['id'] ?? 0) == STATUS_VEH_OUT_OF_SERVICE;
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
                    <form method="post" id="logMaintenanceForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="log_maintenance">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                                <select name="asset_id" class="form-select" required id="vehicleSelect">
                                    <option value="">Select Vehicle</option>
                                    <?php foreach ($assetList as $asset):
                                        $isOOS = ($asset['status_label']['id'] ?? 0) == STATUS_VEH_OUT_OF_SERVICE;
                                    ?>
                                        <option value="<?= $asset['id'] ?>"
                                                <?= $selectedAssetId == $asset['id'] ? 'selected' : '' ?>
                                                data-oos="<?= $isOOS ? '1' : '0' ?>">
                                            <?= h($asset['name']) ?> [<?= h($asset['asset_tag']) ?>]
                                            <?= $isOOS ? ' (Out of Service)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" required
                                       placeholder="e.g., 7,500 Mile Service">
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

                            <!-- Return to Fleet option (shows when vehicle is Out of Service) -->
                            <div class="col-12" id="returnToFleetSection" style="display: <?= $selectedIsOOS ? 'block' : 'none' ?>;">
                                <div class="card border-success">
                                    <div class="card-body py-3">
                                        <div class="form-check form-switch mb-2">
                                            <input type="checkbox" class="form-check-input" id="returnToFleetToggle" name="return_to_fleet" value="1" style="transform: scale(1.3);">
                                            <label class="form-check-label ms-1 fw-bold" for="returnToFleetToggle">
                                                Return vehicle to fleet after logging
                                            </label>
                                        </div>
                                        <div id="returnLocationRow" style="display: none;">
                                            <label class="form-label small">Return Location</label>
                                            <select name="return_location" class="form-select form-select-sm" style="max-width: 300px;">
                                                <option value="">Same location</option>
                                                <?php foreach ($pickupLocations as $loc): ?>
                                                    <option value="<?= $loc['id'] ?>"><?= h($loc['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle me-2"></i>Log Maintenance Record
                                </button>
                                <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/maintenances/create" target="_blank" class="btn btn-outline-secondary ms-2">
                                    <i class="bi bi-box-arrow-up-right me-2"></i>Open in Snipe-IT
                                </a>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

        <!-- ============================================================= -->
        <!-- TAB: History (enriched)                                       -->
        <!-- ============================================================= -->
        <?php elseif ($tab === 'history'): ?>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body py-3">
                    <form method="get" class="row g-2 align-items-end">
                        <input type="hidden" name="tab" value="history">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Vehicle</label>
                            <select name="asset_id" class="form-select form-select-sm">
                                <option value="">All Vehicles</option>
                                <?php foreach ($historyVehicleList as $vid => $vname): ?>
                                    <option value="<?= $vid ?>" <?= $historyAssetFilter == $vid ? 'selected' : '' ?>><?= h($vname) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= h($historyDateFrom) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= h($historyDateTo) ?>">
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                                <i class="bi bi-filter me-1"></i>Filter
                            </button>
                            <a href="?tab=history" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                                <i class="bi bi-x-lg"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Summary stats -->
            <?php if ($historySummary['total'] > 0): ?>
            <div class="row g-3 mb-4">
                <div class="col-4">
                    <div class="card">
                        <div class="card-body text-center py-2">
                            <div class="h4 mb-0 text-primary"><?= $historySummary['total'] ?></div>
                            <small class="text-muted">Total Services</small>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="card">
                        <div class="card-body text-center py-2">
                            <div class="h4 mb-0 text-success">$<?= number_format($historySummary['total_cost'], 2) ?></div>
                            <small class="text-muted">Total Cost</small>
                        </div>
                    </div>
                </div>
                <div class="col-4">
                    <div class="card">
                        <div class="card-body text-center py-2">
                            <div class="h4 mb-0 text-info"><?= h($historySummary['date_range']) ?></div>
                            <small class="text-muted">Period</small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- History table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Maintenance History</h5>
                    <div class="d-flex gap-2">
                        <?php if (!empty($historyRecords)): ?>
                        <a href="?tab=history&export=csv<?= $historyAssetFilter ? '&asset_id=' . $historyAssetFilter : '' ?><?= $historyDateFrom ? '&date_from=' . urlencode($historyDateFrom) : '' ?><?= $historyDateTo ? '&date_to=' . urlencode($historyDateTo) : '' ?>"
                           class="btn btn-sm btn-outline-success">
                            <i class="bi bi-download me-1"></i>Export CSV
                        </a>
                        <?php endif; ?>
                        <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/maintenances" target="_blank" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Snipe-IT
                        </a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($historyRecords)): ?>
                        <p class="text-muted text-center py-4">No maintenance records found<?= ($historyAssetFilter || $historyDateFrom || $historyDateTo) ? ' for the selected filters' : '' ?>.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Vehicle</th>
                                        <th>Type / Title</th>
                                        <th class="text-end">Mileage</th>
                                        <th>Provider</th>
                                        <th class="text-end">Cost</th>
                                        <th>Logged By</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($historyRecords as $idx => $record): ?>
                                    <tr>
                                        <td class="text-nowrap"><?= date('M j, Y', strtotime($record['date'])) ?></td>
                                        <td>
                                            <strong><?= h($record['vehicle_name']) ?></strong><br>
                                            <small class="text-muted"><?= h($record['vehicle_tag']) ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= h($record['type']) ?></span>
                                            <?= $record['is_warranty'] ? '<span class="badge bg-info ms-1">Warranty</span>' : '' ?>
                                            <?php if (!empty($record['title'])): ?>
                                                <br><small class="text-muted"><?= h($record['title']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?= $record['mileage'] > 0 ? number_format($record['mileage']) . ' mi' : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td><?= h($record['supplier']) ?></td>
                                        <td class="text-end">
                                            <?= $record['cost'] > 0 ? '$' . number_format($record['cost'], 2) : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td><?= h($record['logged_by']) ?></td>
                                        <td>
                                            <?php if (!empty($record['notes'])): ?>
                                                <?php if (strlen($record['notes']) > 40): ?>
                                                    <small class="notes-preview" id="notesPreview<?= $idx ?>"><?= h(substr($record['notes'], 0, 40)) ?>...
                                                        <a href="#" class="text-primary" onclick="event.preventDefault(); document.getElementById('notesPreview<?= $idx ?>').style.display='none'; document.getElementById('notesFull<?= $idx ?>').style.display='inline';">more</a>
                                                    </small>
                                                    <small class="notes-full" id="notesFull<?= $idx ?>" style="display:none;"><?= h($record['notes']) ?>
                                                        <a href="#" class="text-primary" onclick="event.preventDefault(); document.getElementById('notesFull<?= $idx ?>').style.display='none'; document.getElementById('notesPreview<?= $idx ?>').style.display='inline';">less</a>
                                                    </small>
                                                <?php else: ?>
                                                    <small><?= h($record['notes']) ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <?php if ($historySummary['total_cost'] > 0): ?>
                                <tfoot class="table-light">
                                    <tr class="fw-bold">
                                        <td colspan="5" class="text-end">Total (<?= $historySummary['total'] ?> services):</td>
                                        <td class="text-end text-success">$<?= number_format($historySummary['total_cost'], 2) ?></td>
                                        <td colspan="2"></td>
                                    </tr>
                                </tfoot>
                                <?php endif; ?>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- page-shell -->
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Success confirmation modal -->
<?php if ($success): ?>
<div class="modal fade" id="successModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Success</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0"><?= h($success) ?></p>
      </div>
      <div class="modal-footer">
        <a href="?tab=overview" class="btn btn-outline-secondary"><i class="bi bi-heart-pulse me-1"></i>Fleet Overview</a>
        <a href="?tab=history" class="btn btn-outline-primary"><i class="bi bi-clock-history me-1"></i>View History</a>
        <a href="?tab=log" class="btn btn-success"><i class="bi bi-plus-circle me-1"></i>Log Another</a>
      </div>
    </div>
  </div>
</div>
<script>new bootstrap.Modal(document.getElementById('successModal')).show();</script>
<?php endif; ?>

<!-- Log Maintenance: toggle Return to Fleet section based on vehicle selection -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const vehicleSelect = document.getElementById('vehicleSelect');
    const returnSection = document.getElementById('returnToFleetSection');
    const returnToggle = document.getElementById('returnToFleetToggle');
    const returnLocationRow = document.getElementById('returnLocationRow');

    if (vehicleSelect && returnSection) {
        vehicleSelect.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const isOOS = selected?.dataset?.oos === '1';
            returnSection.style.display = isOOS ? 'block' : 'none';
            if (!isOOS && returnToggle) {
                returnToggle.checked = false;
                returnLocationRow.style.display = 'none';
            }
        });
    }

    if (returnToggle && returnLocationRow) {
        returnToggle.addEventListener('change', function() {
            returnLocationRow.style.display = this.checked ? 'block' : 'none';
        });
    }
});
</script>

<?php layout_footer(); ?>
</body>
</html>
