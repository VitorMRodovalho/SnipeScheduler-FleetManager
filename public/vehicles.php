<?php
/**
 * Vehicle Management - Integrated with Snipe-IT
 * Admin only - Manage fleet vehicles
 * Updated: Auto-generated Vehicle Name + Asset Tag, duplicate checks, confirmation modal
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/layout.php';

$active = 'activity_log.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_super_admin']);

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

        $vin = strtoupper(trim($_POST['vin'] ?? ''));
        $licensePlate = strtoupper(trim($_POST['license_plate'] ?? ''));
        $vehicleYear = trim($_POST['vehicle_year'] ?? '');
        $currentMileage = trim($_POST['current_mileage'] ?? '');
        $insuranceExpiry = trim($_POST['insurance_expiry'] ?? '');
        $registrationExpiry = trim($_POST['registration_expiry'] ?? '');
        $maintenanceIntervalMiles = trim($_POST['maintenance_interval_miles'] ?? '7500');
        $maintenanceIntervalDays = trim($_POST['maintenance_interval_days'] ?? '180');

        // Backend validation
        $errors = [];
        if (empty($modelId)) $errors[] = 'Please select a vehicle model.';
        if (empty($vin) || strlen($vin) !== 17) $errors[] = 'A valid 17-character VIN is required.';
        if (empty($licensePlate)) $errors[] = 'License Plate is required.';
        if (empty($vehicleYear) || (int)$vehicleYear < 2000 || (int)$vehicleYear > 2030) $errors[] = 'A valid Vehicle Year is required (2000-2030).';
        if ($currentMileage === '' || (int)$currentMileage < 0) $errors[] = 'Current Mileage is required.';
        if (empty($insuranceExpiry)) $errors[] = 'Insurance Expiry date is required.';
        if (empty($registrationExpiry)) $errors[] = 'Registration Expiry date is required.';

        // Duplicate checks
        if (!empty($vin) && check_vin_exists($vin)) {
            $errors[] = "A vehicle with VIN '{$vin}' already exists in Snipe-IT.";
        }
        if (!empty($licensePlate) && check_license_plate_exists($licensePlate)) {
            $errors[] = "A vehicle with License Plate '{$licensePlate}' already exists in Snipe-IT.";
        }

        // Generate tag if empty
        if (empty($assetTag)) {
            $assetTag = get_next_vehicle_asset_tag();
        }

        if (!empty($errors)) {
            $error = implode(' ', $errors);
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
                $createdTag = $result['asset_tag'] ?? $assetTag;
                update_vehicle_tag_high_water_mark($createdTag);
                $success = "Vehicle created successfully (Asset Tag: " . h($createdTag) . ").";
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
                    $manufacturers = get_manufacturers(200);
                } else {
                    $error = 'Failed to create manufacturer.';
                }
            }
        }
        $tab = 'create';

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
                    $models = get_fleet_models(200);
                } else {
                    $error = 'Failed to create model.';
                }
            }
        }
        $tab = 'create';
    }
}

if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

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
    <link rel="stylesheet" href="assets/style.css?v=1.5.0">
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

        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item"><a class="nav-link active" href="vehicles">Vehicles</a></li>
            <li class="nav-item"><a class="nav-link" href="users">Users</a></li>
            <li class="nav-item"><a class="nav-link" href="activity_log">Activity Log</a></li>
            <li class="nav-item"><a class="nav-link" href="notifications">Notifications</a></li>
            <li class="nav-item"><a class="nav-link" href="announcements">Announcements</a></li>
            <?php if ($isSuperAdmin): ?>
            <li class="nav-item"><a class="nav-link" href="booking_rules">Booking Rules</a></li>
            <li class="nav-item"><a class="nav-link" href="security">Security</a></li>
            <li class="nav-item"><a class="nav-link" href="settings">Settings</a></li>
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
                <div class="card text-center"><div class="card-body">
                    <div class="h3 text-primary"><?= count($vehicles) ?></div>
                    <small class="text-muted">Total Vehicles</small>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center"><div class="card-body">
                    <div class="h3 text-success"><?= count(array_filter($vehicles, fn($v) => stripos($v['status_label']['name'] ?? '', 'Available') !== false)) ?></div>
                    <small class="text-muted">Available</small>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center"><div class="card-body">
                    <div class="h3 text-warning"><?= count(array_filter($vehicles, fn($v) => !empty($v['assigned_to']))) ?></div>
                    <small class="text-muted">Checked Out</small>
                </div></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card text-center"><div class="card-body">
                    <div class="h3 text-secondary"><?= count($models) ?></div>
                    <small class="text-muted">Models</small>
                </div></div>
            </div>
        </div>

        <!-- Sub-tabs -->
        <ul class="nav nav-pills mb-4">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="?tab=list">
                    <i class="bi bi-car-front me-1"></i>All Vehicles</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'create' ? 'active' : '' ?>" href="?tab=create">
                    <i class="bi bi-plus-circle me-1"></i>Add Vehicle</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'models' ? 'active' : '' ?>" href="?tab=models">
                    <i class="bi bi-diagram-3 me-1"></i>Models</a>
            </li>
        </ul>

<?php if ($tab === 'list'): ?>
    <!-- Vehicle List -->
    <div class="card">
        <div class="card-header"><h5 class="mb-0"><i class="bi bi-car-front me-2"></i>Fleet Vehicles</h5></div>
        <div class="card-body p-0">
            <?php if (empty($vehicles)): ?>
                <p class="text-muted text-center py-4">No vehicles found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr><th>Asset Tag</th><th>Name</th><th>Model</th><th>Status</th><th>Assigned To</th><th>Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vehicles as $v): ?>
                            <?php
                                $statusName = $v['status_label']['name'] ?? 'Unknown';
                                $isAvailable = stripos($statusName, 'Available') !== false;
                                $isCheckedOut = !empty($v['assigned_to']);
                                $statusClass = $isAvailable ? 'success' : ($isCheckedOut ? 'warning' : 'secondary');
                            ?>
                            <tr>
                                <td><code><?= h($v['asset_tag'] ?? '-') ?></code></td>
                                <td><strong><?= h($v['name'] ?? '-') ?></strong></td>
                                <td><?= h($v['model']['name'] ?? '-') ?></td>
                                <td><span class="badge bg-<?= $statusClass ?>"><?= h($statusName) ?></span></td>
                                <td><?= $isCheckedOut ? h($v['assigned_to']['name'] ?? '-') : '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/hardware/<?= $v['id'] ?>" target="_blank"
                                       class="btn btn-sm btn-outline-secondary" title="View in Snipe-IT">
                                        <i class="bi bi-box-arrow-up-right"></i></a>
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
    <!-- Create Vehicle -->
    <div class="row">
        <div class="col-lg-8">
            <!-- Instructions -->
            <div class="alert alert-light border mb-3">
                <h6 class="mb-1"><i class="bi bi-info-circle me-1"></i>How to Add a Vehicle</h6>
                <small class="text-muted">
                    Select a model, fill in the vehicle details, and review the summary before creating.
                    <strong>Vehicle Name</strong> and <strong>Asset Tag</strong> are auto-generated for consistency.
                    Fields marked with <span class="text-danger">*</span> are required.
                    All vehicles are created as requestable in Snipe-IT.
                </small>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Vehicle</h5></div>
                <div class="card-body">
                    <form method="post" id="createVehicleForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_vehicle">
                        <div class="row g-3">
                            <!-- Model -->
                            <div class="col-md-8">
                                <label class="form-label">Model <span class="text-danger">*</span></label>
                                <select name="model_id" class="form-select" required id="modelSelect">
                                    <option value="" data-manufacturer="" data-model-name="">Select a model...</option>
                                    <?php foreach ($models as $m): ?>
                                        <option value="<?= $m['id'] ?>"
                                                data-manufacturer="<?= h($m['manufacturer']['name'] ?? '') ?>"
                                                data-model-name="<?= h($m['name']) ?>">
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
                                    <i class="bi bi-plus me-1"></i>New Model</button>
                            </div>

                            <!-- Auto-generated -->
                            <div class="col-md-6">
                                <label class="form-label">Vehicle Name <i class="bi bi-lock-fill text-muted small" title="Auto-generated"></i></label>
                                <input type="text" name="name" id="vehicleName" class="form-control bg-light"
                                       readonly placeholder="Auto-generated: Year Manufacturer Model Plate">
                                <small class="text-muted">From Year + Manufacturer + Model + License Plate</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Asset Tag <i class="bi bi-lock-fill text-muted small" title="Auto-generated"></i></label>
                                <input type="text" name="asset_tag" id="assetTag" class="form-control bg-light"
                                       readonly placeholder="Loading...">
                                <small class="text-muted">Sequential (auto-generated) — never reused — never reused</small>
                            </div>

                            <!-- VIN + Plate + Year -->
                            <div class="col-md-4">
                                <label class="form-label">VIN <span class="text-danger">*</span></label>
                                <input type="text" name="vin" id="vinField" class="form-control" maxlength="17"
                                       placeholder="17-character VIN" required style="text-transform:uppercase;">
                                <div id="vinFeedback" class="invalid-feedback"></div>
                                <div id="vinSpinner" class="form-text text-muted d-none">
                                    <span class="spinner-border spinner-border-sm me-1"></span>Checking...</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">License Plate <span class="text-danger">*</span></label>
                                <input type="text" name="license_plate" id="plateField" class="form-control"
                                       placeholder="e.g., ABC-1234" required style="text-transform:uppercase;">
                                <div id="plateFeedback" class="invalid-feedback"></div>
                                <div id="plateSpinner" class="form-text text-muted d-none">
                                    <span class="spinner-border spinner-border-sm me-1"></span>Checking...</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Vehicle Year <span class="text-danger">*</span></label>
                                <input type="number" name="vehicle_year" id="yearField" class="form-control"
                                       min="2000" max="2030" placeholder="e.g., 2024" required>
                            </div>

                            <!-- Mileage + Dates -->
                            <div class="col-md-4">
                                <label class="form-label">Current Mileage <span class="text-danger">*</span></label>
                                <input type="number" name="current_mileage" id="mileageField" class="form-control"
                                       min="0" placeholder="e.g., 15000" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Insurance Expiry <span class="text-danger">*</span></label>
                                <input type="date" name="insurance_expiry" id="insuranceField" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Registration Expiry <span class="text-danger">*</span></label>
                                <input type="date" name="registration_expiry" id="registrationField" class="form-control" required>
                            </div>

                            <!-- Maintenance -->
                            <div class="col-md-6">
                                <label class="form-label">Maintenance Interval (Miles)</label>
                                <input type="number" name="maintenance_interval_miles" class="form-control" value="7500" min="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Maintenance Interval (Days)</label>
                                <input type="number" name="maintenance_interval_days" class="form-control" value="180" min="0">
                            </div>

                            <!-- Location, Company, Status -->
                            <div class="col-md-6">
                                <label class="form-label">Default Location</label>
                                <select name="location_id" id="locationSelect" class="form-select">
                                    <option value="">Select location...</option>
                                    <?php foreach ($locations as $loc): ?>
                                        <option value="<?= $loc['id'] ?>"><?= h($loc['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Company</label>
                                <select name="company_id" id="companySelect" class="form-select">
                                    <option value="">Select company...</option>
                                    <?php foreach ($companies as $co): ?>
                                        <option value="<?= $co['id'] ?>"><?= h($co['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Initial Status</label>
                                <select name="status_id" id="statusSelect" class="form-select">
                                    <?php foreach ($statuses as $st): ?>
                                        <option value="<?= $st['id'] ?>" <?= $st['id'] == $defaultStatusId ? 'selected' : '' ?>>
                                            <?= h($st['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-12">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" id="notesField" class="form-control" rows="2"
                                          placeholder="Additional notes about the vehicle..."></textarea>
                            </div>

                            <div class="col-12">
                                <div class="alert alert-info mb-0">
                                    <i class="bi bi-info-circle me-2"></i>
                                    Vehicle will be created in Snipe-IT as requestable. To edit or delete, use Snipe-IT directly.
                                </div>
                            </div>

                            <div class="col-12">
                                <button type="button" id="reviewBtn" class="btn btn-primary" disabled
                                        data-bs-toggle="modal" data-bs-target="#confirmModal">
                                    <i class="bi bi-eye me-2"></i>Review &amp; Create Vehicle
                                </button>
                                <small id="formStatusMsg" class="ms-2 text-muted">Fill all required fields to continue.</small>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Quick Reference Sidebar -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header"><h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Quick Reference</h6></div>
                <div class="card-body">
                    <p class="small text-muted mb-2"><strong>Available Models:</strong></p>
                    <ul class="list-unstyled small">
                        <?php foreach (array_slice($models, 0, 10) as $m): ?>
                            <li>&#8226; <?= h($m['name']) ?>
                                <?php if (!empty($m['manufacturer']['name'])): ?>
                                    <span class="text-muted">(<?= h($m['manufacturer']['name']) ?>)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($models) > 10): ?>
                            <li class="text-muted">... and <?= count($models) - 10 ?> more</li>
                        <?php endif; ?>
                    </ul>
                    <hr>
                    <p class="small text-muted mb-2"><strong>Naming Convention:</strong></p>
                    <p class="small">Vehicle names follow:<br>
                        <code>[Year] [Manufacturer] [Model] [Plate]</code><br>
                        Example: <em>2024 Ford Escape ABC-1234</em></p>
                    <hr>
                    <p class="small text-muted mb-2"><strong>Asset Tag Format:</strong></p>
                    <p class="small">Tags follow: <code><?= htmlspecialchars(get_asset_tag_prefix()) ?>###</code><br>
                        Sequential and never reused, even if a vehicle is deleted.</p>
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
                        <i class="bi bi-plus me-1"></i>New Model</button>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($models)): ?>
                        <p class="text-muted text-center py-4">No models found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Manufacturer</th><th>Model #</th><th>Assets</th><th>Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($models as $m): ?>
                                    <tr>
                                        <td><strong><?= h($m['name']) ?></strong></td>
                                        <td><?= h($m['manufacturer']['name'] ?? '-') ?></td>
                                        <td><code><?= h($m['model_number'] ?? '-') ?></code></td>
                                        <td><?= $m['assets_count'] ?? 0 ?></td>
                                        <td>
                                            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/models/<?= $m['id'] ?>" target="_blank"
                                               class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
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
                        <i class="bi bi-plus"></i></button>
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

        <div class="text-center mt-4">
            <a href="<?= htmlspecialchars($config['snipeit']['base_url']) ?>/hardware" target="_blank" class="btn btn-outline-secondary">
                <i class="bi bi-box-arrow-up-right me-2"></i>Open Snipe-IT Asset Management</a>
        </div>
    </div>
</div>

<!-- Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>Review Vehicle Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Please review all details before creating this vehicle in Snipe-IT.</p>
                <table class="table table-sm table-bordered">
                    <tbody>
                        <tr><th class="bg-light" style="width:35%">Asset Tag</th><td id="c_tag"></td></tr>
                        <tr><th class="bg-light">Vehicle Name</th><td id="c_name"></td></tr>
                        <tr><th class="bg-light">Model</th><td id="c_model"></td></tr>
                        <tr><th class="bg-light">VIN</th><td id="c_vin"></td></tr>
                        <tr><th class="bg-light">License Plate</th><td id="c_plate"></td></tr>
                        <tr><th class="bg-light">Vehicle Year</th><td id="c_year"></td></tr>
                        <tr><th class="bg-light">Current Mileage</th><td id="c_mileage"></td></tr>
                        <tr><th class="bg-light">Insurance Expiry</th><td id="c_insurance"></td></tr>
                        <tr><th class="bg-light">Registration Expiry</th><td id="c_registration"></td></tr>
                        <tr><th class="bg-light">Location</th><td id="c_location"></td></tr>
                        <tr><th class="bg-light">Company</th><td id="c_company"></td></tr>
                        <tr><th class="bg-light">Status</th><td id="c_status"></td></tr>
                        <tr><th class="bg-light">Notes</th><td id="c_notes"></td></tr>
                    </tbody>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-pencil me-1"></i>Go Back &amp; Edit</button>
                <button type="button" class="btn btn-primary" id="confirmSubmitBtn">
                    <i class="bi bi-check-circle me-1"></i>Confirm &amp; Create Vehicle</button>
            </div>
        </div>
    </div>
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
                        <input type="text" name="model_name" class="form-control" required placeholder="e.g., Escape 2024">
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
                            <a href="#" data-bs-toggle="modal" data-bs-target="#createManufacturerModal" data-bs-dismiss="modal">Create one first</a>
                        </small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Model Number</label>
                        <input type="text" name="model_number" class="form-control" placeholder="e.g., SE, XLT, Limited">
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
                        <input type="text" name="manufacturer_name" class="form-control" required placeholder="e.g., Ford, Chevrolet, Toyota">
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('createVehicleForm');
    if (!form) return;

    const modelSelect    = document.getElementById('modelSelect');
    const vehicleName    = document.getElementById('vehicleName');
    const assetTag       = document.getElementById('assetTag');
    const vinField       = document.getElementById('vinField');
    const plateField     = document.getElementById('plateField');
    const yearField      = document.getElementById('yearField');
    const mileageField   = document.getElementById('mileageField');
    const insuranceField = document.getElementById('insuranceField');
    const registrationField = document.getElementById('registrationField');
    const reviewBtn      = document.getElementById('reviewBtn');
    const formStatusMsg  = document.getElementById('formStatusMsg');

    let vinOk = false, plateOk = false;
    let vinChecking = false, plateChecking = false;
    let vinDebounce = null, plateDebounce = null;

    // 1. Fetch next asset tag
    fetch('api/vehicle_check?action=next_tag')
        .then(r => r.json())
        .then(data => { assetTag.value = (data.success && data.tag) ? data.tag : '<?= htmlspecialchars(get_asset_tag_prefix()) ?>???'; })
        .catch(() => { assetTag.value = '<?= htmlspecialchars(get_asset_tag_prefix()) ?>???'; });

    // 2. Auto-generate Vehicle Name
    function updateVehicleName() {
        const opt = modelSelect.options[modelSelect.selectedIndex];
        const mfr = opt ? (opt.dataset.manufacturer || '') : '';
        const mdl = opt ? (opt.dataset.modelName || '') : '';
        const yr  = yearField.value.trim();
        const plt = plateField.value.trim().toUpperCase();
        const parts = [yr, mfr, mdl, plt].filter(p => p !== '');
        vehicleName.value = parts.join(' ');
    }

    modelSelect.addEventListener('change', updateVehicleName);
    yearField.addEventListener('input', updateVehicleName);
    plateField.addEventListener('input', function() { this.value = this.value.toUpperCase(); updateVehicleName(); });
    vinField.addEventListener('input', function() { this.value = this.value.toUpperCase(); });

    // 3. Duplicate checks (debounced)
    vinField.addEventListener('input', function() {
        clearTimeout(vinDebounce);
        clearVal(vinField, 'vinFeedback');
        vinOk = false; updateBtn();
        if (this.value.trim().length === 17) {
            vinDebounce = setTimeout(() => checkDup('vin', this.value.trim().toUpperCase()), 500);
        }
    });

    plateField.addEventListener('input', function() {
        clearTimeout(plateDebounce);
        clearVal(plateField, 'plateFeedback');
        plateOk = false; updateBtn();
        if (this.value.trim().length >= 2) {
            plateDebounce = setTimeout(() => checkDup('plate', this.value.trim().toUpperCase()), 500);
        }
    });

    function checkDup(type, value) {
        const field = type === 'vin' ? vinField : plateField;
        const fbId  = type === 'vin' ? 'vinFeedback' : 'plateFeedback';
        const spId  = type === 'vin' ? 'vinSpinner' : 'plateSpinner';
        const action = type === 'vin' ? 'check_vin' : 'check_plate';
        const param  = type === 'vin' ? 'vin' : 'plate';

        document.getElementById(spId).classList.remove('d-none');
        if (type === 'vin') vinChecking = true; else plateChecking = true;
        updateBtn();

        fetch(`api/vehicle_check?action=${action}&${param}=${encodeURIComponent(value)}`)
            .then(r => r.json())
            .then(data => {
                document.getElementById(spId).classList.add('d-none');
                if (type === 'vin') vinChecking = false; else plateChecking = false;
                if (data.exists) {
                    field.classList.remove('is-valid');
                    field.classList.add('is-invalid');
                    document.getElementById(fbId).textContent =
                        type === 'vin'
                            ? 'This VIN already exists in Snipe-IT. Duplicate vehicles are not allowed.'
                            : 'This License Plate already exists in Snipe-IT. Duplicate plates are not allowed.';
                    if (type === 'vin') vinOk = false; else plateOk = false;
                } else {
                    field.classList.remove('is-invalid');
                    field.classList.add('is-valid');
                    if (type === 'vin') vinOk = true; else plateOk = true;
                }
                updateBtn();
            })
            .catch(() => {
                document.getElementById(spId).classList.add('d-none');
                if (type === 'vin') { vinChecking = false; vinOk = true; }
                else { plateChecking = false; plateOk = true; }
                updateBtn();
            });
    }

    function clearVal(field, fbId) {
        field.classList.remove('is-valid', 'is-invalid');
        document.getElementById(fbId).textContent = '';
    }

    // 4. Review button state
    function updateBtn() {
        const allFilled = modelSelect.value && vinField.value.trim().length === 17
            && plateField.value.trim() && yearField.value.trim()
            && mileageField.value.trim() && insuranceField.value && registrationField.value;
        const ready = allFilled && !vinChecking && !plateChecking && vinOk && plateOk;
        reviewBtn.disabled = !ready;

        if (vinChecking || plateChecking) formStatusMsg.textContent = 'Checking for duplicates...';
        else if (!allFilled) formStatusMsg.textContent = 'Fill all required fields to continue.';
        else if (!vinOk || !plateOk) { formStatusMsg.textContent = 'Resolve duplicate issues to continue.'; formStatusMsg.className = 'ms-2 text-danger'; return; }
        else formStatusMsg.textContent = '';
        formStatusMsg.className = 'ms-2 text-muted';
    }

    [modelSelect, vinField, plateField, yearField, mileageField, insuranceField, registrationField]
        .forEach(el => { el.addEventListener('change', updateBtn); el.addEventListener('input', updateBtn); });

    // 5. Populate confirmation modal
    const cModal = document.getElementById('confirmModal');
    if (cModal) {
        cModal.addEventListener('show.bs.modal', function() {
            const opt = modelSelect.options[modelSelect.selectedIndex];
            document.getElementById('c_tag').textContent = assetTag.value;
            document.getElementById('c_name').textContent = vehicleName.value || '-';
            document.getElementById('c_model').textContent = opt ? opt.textContent.trim() : '-';
            document.getElementById('c_vin').textContent = vinField.value.toUpperCase() || '-';
            document.getElementById('c_plate').textContent = plateField.value.toUpperCase() || '-';
            document.getElementById('c_year').textContent = yearField.value || '-';
            document.getElementById('c_mileage').textContent = mileageField.value ? Number(mileageField.value).toLocaleString() + ' mi' : '-';
            document.getElementById('c_insurance').textContent = insuranceField.value || '-';
            document.getElementById('c_registration').textContent = registrationField.value || '-';
            const loc = document.getElementById('locationSelect');
            const co  = document.getElementById('companySelect');
            const st  = document.getElementById('statusSelect');
            document.getElementById('c_location').textContent = loc.options[loc.selectedIndex]?.textContent.trim() || 'None';
            document.getElementById('c_company').textContent = co.options[co.selectedIndex]?.textContent.trim() || 'None';
            document.getElementById('c_status').textContent = st.options[st.selectedIndex]?.textContent.trim() || '-';
            document.getElementById('c_notes').textContent = document.getElementById('notesField').value || '-';
        });
    }

    // 6. Confirm & Submit
    document.getElementById('confirmSubmitBtn').addEventListener('click', function() {
        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating...';
        form.submit();
    });
});
</script>
<?php layout_footer(); ?>
</body>
</html>
