<?php
/**
 * Vehicle Management - Integrated with Snipe-IT
 * Admin only - Manage fleet vehicles
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$active = 'activity_log.php'; // Keep Admin highlighted in nav
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_super_admin']);

// Admin only access
if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

$success = '';
$error = '';
$tab = $_GET['tab'] ?? 'list';

// Load data for dropdowns
$manufacturers = get_manufacturers(200);
$models = get_fleet_models(200);
$locations = get_locations(100);
$companies = get_companies();
$statuses = get_deployable_status_labels();

// Get defaults
$defaultStatusId = get_veh_available_status_id();
$defaultCategoryId = get_fleet_category_id();
$defaultFieldsetId = get_fleet_fieldset_id();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create_vehicle') {
        $modelId = (int)$_POST['model_id'];
        $name = trim($_POST['name'] ?? '');
        $assetTag = trim($_POST['asset_tag'] ?? '');
        $serial = trim($_POST['serial'] ?? '');
        $statusId = (int)($_POST['status_id'] ?? $defaultStatusId);
        $locationId = (int)($_POST['location_id'] ?? 0);
        $companyId = (int)($_POST['company_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        
        // Custom fields
        $vin = trim($_POST['vin'] ?? '');
        $licensePlate = trim($_POST['license_plate'] ?? '');
        $vehicleYear = trim($_POST['vehicle_year'] ?? '');
        $currentMileage = trim($_POST['current_mileage'] ?? '');
        $insuranceExpiry = trim($_POST['insurance_expiry'] ?? '');
        $registrationExpiry = trim($_POST['registration_expiry'] ?? '');
        $maintenanceIntervalMiles = trim($_POST['maintenance_interval_miles'] ?? '7500');
        $maintenanceIntervalDays = trim($_POST['maintenance_interval_days'] ?? '180');
        
        if (empty($modelId)) {
            $error = 'Please select a vehicle model.';
        } else {
            $vehicleData = [
                'model_id' => $modelId,
                'name' => $name,
                'asset_tag' => $assetTag,
                'serial' => $serial,
                'status_id' => $statusId,
                'location_id' => $locationId ?: null,
                'company_id' => $companyId ?: null,
                'notes' => $notes,
                'vin' => $vin,
                'license_plate' => $licensePlate,
                'vehicle_year' => $vehicleYear,
                'current_mileage' => $currentMileage,
                'insurance_expiry' => $insuranceExpiry,
                'registration_expiry' => $registrationExpiry,
                'maintenance_interval_miles' => $maintenanceIntervalMiles,
                'maintenance_interval_days' => $maintenanceIntervalDays,
            ];
            
            $result = create_vehicle($vehicleData);
            
            if ($result && isset($result['id'])) {
                $success = "Vehicle created successfully (Asset Tag: " . ($result['asset_tag'] ?? $result['id']) . ").";
                // Redirect to list to see new vehicle
                header('Location: vehicles?tab=list&success=' . urlencode($success));
                exit;
            } else {
                $error = 'Failed to create vehicle in Snipe-IT. Check logs for details.';
            }
        }
        
    } elseif ($action === 'create_manufacturer') {
        $mfrName = trim($_POST['manufacturer_name'] ?? '');
        
        if (empty($mfrName)) {
            $error = 'Manufacturer name is required.';
        } else {
            $existing = find_manufacturer_by_name($mfrName);
            if ($existing) {
                $error = "Manufacturer '{$mfrName}' already exists.";
            } else {
                $result = create_manufacturer($mfrName);
                if ($result && isset($result['id'])) {
                    $success = "Manufacturer '{$mfrName}' created successfully.";
                    // Refresh manufacturers list
                    $manufacturers = get_manufacturers(200);
                } else {
                    $error = 'Failed to create manufacturer.';
                }
            }
        }
        $tab = 'create'; // Stay on create tab
        
    } elseif ($action === 'create_model') {
        $modelName = trim($_POST['model_name'] ?? '');
        $manufacturerId = (int)$_POST['manufacturer_id'];
        $modelNumber = trim($_POST['model_number'] ?? '');
        
        if (empty($modelName) || empty($manufacturerId)) {
            $error = 'Model name and manufacturer are required.';
        } else {
            $existing = find_model_by_name($modelName, $defaultCategoryId);
            if ($existing) {
                $error = "Model '{$modelName}' already exists.";
            } else {
                $result = create_model([
                    'name' => $modelName,
                    'manufacturer_id' => $manufacturerId,
                    'model_number' => $modelNumber,
                ]);
                if ($result && isset($result['id'])) {
                    $success = "Model '{$modelName}' created successfully.";
                    // Refresh models list
                    $models = get_fleet_models(200);
                } else {
                    $error = 'Failed to create model.';
                }
            }
        }
        $tab = 'create'; // Stay on create tab
    }
}

// Check for success message in URL
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Get fleet vehicles for listing
$vehicles = get_fleet_vehicles(200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vehicle Management - Admin</title>
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
            <h1>Admin</h1>
            <p class="text-muted">Manage vehicles, users, view activity, and configure settings</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <!-- User Info -->
        <div class="d-flex justify-content-between align-items-center mb-4 p-3 bg-light rounded">
            <div>
                <span class="text-muted">Logged in as:</span> 
                <strong><?= h($userName) ?></strong> 
                <span class="text-muted">(<?= h($userEmail) ?>)</span>
            </div>
            <a href="logout" class="text-decoration-none">Log out</a>
        </div>

        <!-- Admin Tabs -->
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link active" href="vehicles">Vehicles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="users">Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="activity_log">Activity Log</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="notifications">Notifications</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="announcements">Announcements</a>
            </li>
            <?php if ($isSuperAdmin): ?>
            <li class="nav-item">
                <a class="nav-link" href="security">Security</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="settings">Settings</a>
            </li>
            <?php endif; ?>
        </ul>

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
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-primary"><?= count($vehicles) ?></div>
                        <small class="text-muted">Total Vehicles</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-success"><?= count(array_filter($vehicles, fn($v) => stripos($v['status_label']['name'] ?? '', 'Available') !== false)) ?></div>
                        <small class="text-muted">Available</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-warning"><?= count(array_filter($vehicles, fn($v) => !empty($v['assigned_to']))) ?></div>
                        <small class="text-muted">Checked Out</small>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="h3 text-secondary"><?= count($models) ?></div>
                        <small class="text-muted">Models</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sub-tabs -->
        <ul class="nav nav-pills mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="?tab=list">
                    <i class="bi bi-car-front me-1"></i>All Vehicles
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'create' ? 'active' : '' ?>" href="?tab=create">
                    <i class="bi bi-plus-circle me-1"></i>Add Vehicle
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'models' ? 'active' : '' ?>" href="?tab=models">
                    <i class="bi bi-diagram-3 me-1"></i>Models
                </a>
            </li>
        </ul>

        <?php if ($tab === 'list'): ?>
            <!-- Vehicle List -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-car-front me-2"></i>Fleet Vehicles</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($vehicles)): ?>
                        <p class="text-muted text-center py-4">No vehicles found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Asset Tag</th>
                                        <th>Name</th>
                                        <th>Model</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vehicles as $v): ?>
                                    <?php
                                        $statusName = $v['status_label']['name'] ?? 'Unknown';
                                        $statusMeta = $v['status_label']['status_meta'] ?? '';
                                        $isAvailable = stripos($statusName, 'Available') !== false;
                                        $isCheckedOut = !empty($v['assigned_to']);
                                        $statusClass = $isAvailable ? 'success' : ($isCheckedOut ? 'warning' : 'secondary');
                                    ?>
                                    <tr>
                                        <td><code><?= h($v['asset_tag'] ?? '-') ?></code></td>
                                        <td><strong><?= h($v['name'] ?? '-') ?></strong></td>
                                        <td><?= h($v['model']['name'] ?? '-') ?></td>
                                        <td>
                                            <span class="badge bg-<?= $statusClass ?>">
                                                <?= h($statusName) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($isCheckedOut): ?>
                                                <?= h($v['assigned_to']['name'] ?? '-') ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="https://inventory.amtrakfdt.com/hardware/<?= $v['id'] ?>" target="_blank" 
                                               class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                                <i class="bi bi-box-arrow-up-right"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($tab === 'create'): ?>
            <!-- Create Vehicle Form -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Vehicle</h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                    <?= csrf_field() ?>
                                <input type="hidden" name="action" value="create_vehicle">
                                
                                <div class="row g-3">
                                    <!-- Model Selection -->
                                    <div class="col-md-8">
                                        <label class="form-label">Model <span class="text-danger">*</span></label>
                                        <select name="model_id" class="form-select" required id="modelSelect">
                                            <option value="">Select a model...</option>
                                            <?php foreach ($models as $m): ?>
                                                <option value="<?= $m['id'] ?>">
                                                    <?= h($m['name']) ?>
                                                    <?php if (!empty($m['manufacturer']['name'])): ?>
                                                        (<?= h($m['manufacturer']['name']) ?>)
                                                    <?php endif; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4 d-flex align-items-end">
                                        <button type="button" class="btn btn-outline-primary w-100" data-bs-toggle="modal" data-bs-target="#createModelModal">
                                            <i class="bi bi-plus me-1"></i>New Model
                                        </button>
                                    </div>
                                    
                                    <!-- Basic Info -->
                                    <div class="col-md-6">
                                        <label class="form-label">Vehicle Name</label>
                                        <input type="text" name="name" class="form-control" 
                                               placeholder="e.g., 2024 Ford Escape #1">
                                        <small class="text-muted">Display name for the vehicle</small>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Asset Tag</label>
                                        <input type="text" name="asset_tag" class="form-control" 
                                               placeholder="Auto-generated if empty">
                                        <small class="text-muted">Unique identifier</small>
                                    </div>
                                    
                                    <!-- Vehicle Details -->
                                    <div class="col-md-4">
                                        <label class="form-label">VIN</label>
                                        <input type="text" name="vin" class="form-control" maxlength="17"
                                               placeholder="17-character VIN">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">License Plate</label>
                                        <input type="text" name="license_plate" class="form-control" 
                                               placeholder="e.g., ABC-1234">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Vehicle Year</label>
                                        <input type="number" name="vehicle_year" class="form-control" 
                                               min="2000" max="2030" placeholder="e.g., 2024">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Current Mileage</label>
                                        <input type="number" name="current_mileage" class="form-control" 
                                               min="0" placeholder="e.g., 15000">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Insurance Expiry</label>
                                        <input type="date" name="insurance_expiry" class="form-control">
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <label class="form-label">Registration Expiry</label>
                                        <input type="date" name="registration_expiry" class="form-control">
                                    </div>
                                    
                                    <!-- Maintenance Intervals -->
                                    <div class="col-md-6">
                                        <label class="form-label">Maintenance Interval (Miles)</label>
                                        <input type="number" name="maintenance_interval_miles" class="form-control" 
                                               value="7500" min="0">
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Maintenance Interval (Days)</label>
                                        <input type="number" name="maintenance_interval_days" class="form-control" 
                                               value="180" min="0">
                                    </div>
                                    
                                    <!-- Location & Status -->
                                    <div class="col-md-6">
                                        <label class="form-label">Default Location</label>
                                        <select name="location_id" class="form-select">
                                            <option value="">Select location...</option>
                                            <?php foreach ($locations as $loc): ?>
                                                <option value="<?= $loc['id'] ?>" 
                                                    <?= stripos($loc['name'], 'B&P') !== false ? 'selected' : '' ?>>
                                                    <?= h($loc['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Company</label>
                                        <select name="company_id" class="form-select">
                                            <option value="">Select company...</option>
                                            <?php foreach ($companies as $co): ?>
                                                <option value="<?= $co['id'] ?>"
                                                    <?= stripos($co['name'], 'Amtrak') !== false ? 'selected' : '' ?>>
                                                    <?= h($co['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6">
                                        <label class="form-label">Initial Status</label>
                                        <select name="status_id" class="form-select">
                                            <?php foreach ($statuses as $st): ?>
                                                <option value="<?= $st['id'] ?>" 
                                                    <?= $st['id'] == $defaultStatusId ? 'selected' : '' ?>>
                                                    <?= h($st['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-12">
                                        <label class="form-label">Notes</label>
                                        <textarea name="notes" class="form-control" rows="2" 
                                                  placeholder="Additional notes about the vehicle..."></textarea>
                                    </div>
                                    
                                    <div class="col-12">
                                        <div class="alert alert-info mb-0">
                                            <i class="bi bi-info-circle me-2"></i>
                                            Vehicle will be created in Snipe-IT as requestable. 
                                            To edit or delete, use Snipe-IT directly.
                                        </div>
                                    </div>
                                    
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-plus-circle me-2"></i>Create Vehicle
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Reference -->
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Quick Reference</h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2"><strong>Available Models:</strong></p>
                            <ul class="list-unstyled small">
                                <?php foreach (array_slice($models, 0, 5) as $m): ?>
                                    <li>• <?= h($m['name']) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($models) > 5): ?>
                                    <li class="text-muted">... and <?= count($models) - 5 ?> more</li>
                                <?php endif; ?>
                            </ul>
                            
                            <hr>
                            
                            <p class="small text-muted mb-2"><strong>Manufacturers:</strong></p>
                            <ul class="list-unstyled small">
                                <?php foreach (array_slice($manufacturers, 0, 5) as $m): ?>
                                    <li>• <?= h($m['name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($tab === 'models'): ?>
            <!-- Models List -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Fleet Vehicle Models</h5>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createModelModal">
                                <i class="bi bi-plus me-1"></i>New Model
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($models)): ?>
                                <p class="text-muted text-center py-4">No models found.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Name</th>
                                                <th>Manufacturer</th>
                                                <th>Model #</th>
                                                <th>Assets</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($models as $m): ?>
                                            <tr>
                                                <td><strong><?= h($m['name']) ?></strong></td>
                                                <td><?= h($m['manufacturer']['name'] ?? '-') ?></td>
                                                <td><code><?= h($m['model_number'] ?? '-') ?></code></td>
                                                <td><?= $m['assets_count'] ?? 0 ?></td>
                                                <td>
                                                    <a href="https://inventory.amtrakfdt.com/models/<?= $m['id'] ?>" target="_blank" 
                                                       class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                                        <i class="bi bi-box-arrow-up-right"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h6 class="mb-0"><i class="bi bi-building me-2"></i>Manufacturers</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createManufacturerModal">
                                <i class="bi bi-plus"></i>
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <ul class="list-group list-group-flush">
                                <?php foreach ($manufacturers as $mfr): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= h($mfr['name']) ?>
                                    <span class="badge bg-secondary"><?= $mfr['assets_count'] ?? 0 ?></span>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Link to Snipe-IT -->
        <div class="text-center mt-4">
            <a href="https://inventory.amtrakfdt.com/hardware" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right me-2"></i>Open Snipe-IT Asset Management
            </a>
        </div>

    </div><!-- page-shell -->
</div>

<!-- Create Model Modal -->
<div class="modal fade" id="createModelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                    <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_model">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-diagram-3 me-2"></i>Create New Model</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Model Name <span class="text-danger">*</span></label>
                        <input type="text" name="model_name" class="form-control" required
                               placeholder="e.g., Escape 2024">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Manufacturer <span class="text-danger">*</span></label>
                        <select name="manufacturer_id" class="form-select" required>
                            <option value="">Select manufacturer...</option>
                            <?php foreach ($manufacturers as $mfr): ?>
                                <option value="<?= $mfr['id'] ?>"><?= h($mfr['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">
                            Don't see your manufacturer? 
                            <a href="#" data-bs-toggle="modal" data-bs-target="#createManufacturerModal" 
                               data-bs-dismiss="modal">Create one first</a>
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Model Number</label>
                        <input type="text" name="model_number" class="form-control"
                               placeholder="e.g., SE, XLT, Limited">
                    </div>
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Model will be created in the "Fleet Vehicles" category with the correct fieldset.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Model</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Manufacturer Modal -->
<div class="modal fade" id="createManufacturerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                    <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_manufacturer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-building me-2"></i>Create New Manufacturer</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Manufacturer Name <span class="text-danger">*</span></label>
                        <input type="text" name="manufacturer_name" class="form-control" required
                               placeholder="e.g., Ford, Chevrolet, Toyota">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Manufacturer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
