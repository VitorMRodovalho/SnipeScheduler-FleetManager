<?php
/**
 * Fleet Vehicle Checkin
 * Dynamic custom fields from Snipe-IT API, automatic timestamp
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/email_service.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$error = '';

$reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;
if (!$reservationId) { header('Location: my_bookings'); exit; }

$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
$stmt->execute([$reservationId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    $error = 'Reservation not found.';
} elseif ($reservation['status'] === 'completed') {
    $error = 'This vehicle has already been returned.';
} elseif ($reservation['status'] !== 'confirmed') {
    $error = 'This vehicle has not been checked out yet.';
}

$asset = null;
$customFields = [];
if ($reservation && $reservation['asset_id']) {
    $asset = get_asset_with_custom_fields($reservation['asset_id']);
    if ($asset && !empty($asset['custom_fields'])) {
        $customFields = $asset['custom_fields'];
    }
}

$pickupLocations = get_pickup_locations();
$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

$vehicleInfoFields = ['VIN', 'License Plate', 'Vehicle Year', 'Insurance Expiry', 'Registration Expiry', 
                      'Last Oil Change (Miles)', 'Last Tire Rotation (Miles)', 'Holman Account #'];
$autoFields = ['Checkout Time', 'Return Time', 'Expected Return Time'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $asset && empty($error)) {
    $formData = [];
    $inspectionData = [];
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'field_') === 0) {
            $fieldDbColumn = substr($key, 6);
            $formData[$fieldDbColumn] = is_array($value) ? implode(', ', $value) : trim($value);
            $inspectionData[$key] = $formData[$fieldDbColumn];
        }
    }
    
    // Auto-fill return time
    $returnTime = date('H:i');
    $returnDate = date('Y-m-d');
    $formData['_snipeit_return_time_19'] = $returnTime;
    $inspectionData['return_time'] = $returnTime;
    $inspectionData['return_date'] = $returnDate;
    
    $returnLocationId = (int)($_POST['return_location'] ?? 0);
    $inspectionData['return_location_id'] = $returnLocationId;
    
    $needsMaintenance = !empty($_POST['needs_maintenance']);
    $maintenanceNotes = trim($_POST['maintenance_notes'] ?? '');
    $inspectionData['needs_maintenance'] = $needsMaintenance ? 'Yes' : 'No';
    $inspectionData['maintenance_notes'] = $maintenanceNotes;
    
    if (!$returnLocationId) {
        $error = 'Please select the return location.';
    } else {
        if (!empty($formData)) { update_asset_custom_fields($reservation['asset_id'], $formData); }
        
        $newStatusId = $needsMaintenance ? STATUS_VEH_OUT_OF_SERVICE : STATUS_VEH_AVAILABLE;
        update_asset_status($reservation['asset_id'], $newStatusId);
        update_asset_location($reservation['asset_id'], $returnLocationId);
        
        $mileage = $formData['_snipeit_current_mileage_6'] ?? 'N/A';
        $note = "Returned by {$userName} at {$returnDate} {$returnTime}. Mileage: {$mileage}";
        if ($needsMaintenance) { $note .= " | MAINTENANCE REQUIRED: {$maintenanceNotes}"; }
        
        try {
            checkin_asset($reservation['asset_id'], $note);
            
            $newStatus = $needsMaintenance ? 'maintenance_required' : 'completed';
            $stmt = $pdo->prepare("UPDATE reservations SET status = ?, checkin_form_data = ?, maintenance_flag = ?, maintenance_notes = ? WHERE id = ?");
// Send checkin receipt email
            $emailService = get_email_service($pdo);
            $emailService->notifyCheckin($reservation, $mileage, $needsMaintenance);
            
            // If maintenance flagged, also notify staff
            if ($needsMaintenance) {
                $emailService->notifyMaintenanceFlag($reservation, $maintenanceNotes);
            }            

$stmt->execute([$newStatus, json_encode($inspectionData), $needsMaintenance ? 1 : 0, $maintenanceNotes, $reservationId]);
            
            $successMsg = $needsMaintenance ? 'Vehicle returned. Maintenance flagged!' : 'Vehicle returned at ' . $returnTime;
            header('Location: my_bookings?success=' . urlencode($successMsg));
            exit;
        } catch (Exception $e) {
            $error = 'Failed to checkin: ' . $e->getMessage();
        }
    }
}

function render_field($fieldName, $fieldData, $isReadOnly = false) {
    $dbColumn = $fieldData['field'] ?? '';
    $currentValue = $fieldData['value'] ?? '';
    $element = $fieldData['element'] ?? 'text';
    $format = $fieldData['field_format'] ?? 'ANY';
    $inputName = 'field_' . h($dbColumn);
    
    $html = '<div class="mb-3"><label class="form-label"><strong>' . h($fieldName) . '</strong></label>';
    
    if ($isReadOnly) {
        $displayValue = ($format === 'DATE' && $currentValue) ? date('M j, Y', strtotime($currentValue)) : $currentValue;
        return $html . '<input type="text" class="form-control bg-light" value="' . h($displayValue) . '" readonly disabled></div>';
    }
    
    switch ($element) {
        case 'textarea':
            $html .= '<textarea name="' . $inputName . '" class="form-control" rows="2">' . h($currentValue) . '</textarea>';
            break;
        case 'listbox':
            $html .= '<select name="' . $inputName . '" class="form-select"><option value="">-- Select --</option>';
            $html .= '<option value="Yes"' . ($currentValue === 'Yes' ? ' selected' : '') . '>Yes</option>';
            $html .= '<option value="No"' . ($currentValue === 'No' ? ' selected' : '') . '>No</option></select>';
            break;
        case 'checkbox':
            $options = ['Exterior', 'Tires', 'Undercarriage', 'Interior'];
            $currentValues = array_map('trim', explode(',', $currentValue));
            foreach ($options as $opt) {
                $checked = in_array($opt, $currentValues) ? ' checked' : '';
                $html .= '<div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="' . $inputName . '[]" value="' . h($opt) . '"' . $checked . '>';
                $html .= '<label class="form-check-label">' . h($opt) . '</label></div>';
            }
            break;
        default:
            $inputType = ($format === 'DATE') ? 'date' : 'text';
            if ($format === 'DATE' && $currentValue && strtotime($currentValue)) { $currentValue = date('Y-m-d', strtotime($currentValue)); }
            $html .= '<input type="' . $inputType . '" name="' . $inputName . '" class="form-control" value="' . h($currentValue) . '">';
    }
    return $html . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vehicle Checkin</title>
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
            <h1>Vehicle Checkin</h1>
            <p class="text-muted">Complete the return inspection</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <div class="row justify-content-center">
            <div class="col-lg-10">

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                    <br><a href="my_bookings" class="btn btn-outline-danger btn-sm mt-2">Back to My Reservations</a>
                </div>
            <?php elseif ($asset && $reservation): ?>
                
                <!-- Auto Timestamp Notice -->
                <div class="alert alert-info">
                    <i class="bi bi-clock me-2"></i>
                    <strong>Return Time:</strong> <?= date('l, F j, Y \a\t g:i A') ?> (will be recorded automatically)
                </div>

                <!-- Vehicle Info -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Vehicle Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Vehicle:</strong> <?= h($asset['name']) ?></p>
                                <p class="mb-1"><strong>Tag:</strong> <?= h($asset['asset_tag']) ?></p>
                                <p class="mb-1"><strong>License Plate:</strong> <?= h($customFields['License Plate']['value'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Checked Out:</strong> <?= date('M j, Y g:i A', strtotime($reservation['start_datetime'])) ?></p>
                                <p class="mb-1"><strong>Checkout Time:</strong> <?= h($customFields['Checkout Time']['value'] ?? 'N/A') ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <form method="post">
                    <!-- Return Location -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Return Location</h5>
                        </div>
                        <div class="card-body">
                            <label class="form-label"><strong>Where are you returning the vehicle?</strong> <span class="text-danger">*</span></label>
                            <select name="return_location" class="form-select" required>
                                <option value="">-- Select return location --</option>
                                <?php foreach ($pickupLocations as $loc): ?>
                                    <option value="<?= $loc['id'] ?>"><?= h($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Inspection Form -->
                    <div class="card mb-4">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Return Inspection</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php 
                                foreach ($customFields as $fieldName => $fieldData):
                                    if (in_array($fieldName, $vehicleInfoFields) || in_array($fieldName, $autoFields)) continue;
                                ?>
                                    <div class="col-md-6"><?= render_field($fieldName, $fieldData, false) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Maintenance Flag -->
                    <div class="card mb-4 border-danger">
                        <div class="card-header bg-danger text-white">
                            <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Maintenance Required?</h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch mb-3">
                                <input type="checkbox" class="form-check-input" id="needs_maintenance" name="needs_maintenance" value="1" style="transform: scale(1.5);">
                                <label class="form-check-label ms-2" for="needs_maintenance"><strong>Yes, this vehicle needs maintenance</strong></label>
                            </div>
                            <div id="maintenance_details" style="display: none;">
                                <label class="form-label"><strong>Describe the issue:</strong></label>
                                <textarea name="maintenance_notes" class="form-control" rows="3" placeholder="What needs attention?"></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="agreement" required>
                                <label class="form-check-label" for="agreement">
                                    <strong>I confirm</strong> that I have inspected this vehicle and recorded its return condition.
                                </label>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="my_bookings" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
                                <button type="submit" class="btn btn-primary btn-lg"><i class="bi bi-box-arrow-in-left me-1"></i>Complete Return</button>
                            </div>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
 </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('needs_maintenance')?.addEventListener('change', function() {
    document.getElementById('maintenance_details').style.display = this.checked ? 'block' : 'none';
});
</script>
<?php layout_footer(); ?>
</body>
</html>
