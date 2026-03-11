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

// Get allowed checkin fields from Snipe-IT field settings
$modelId = $asset['model']['id'] ?? 0;
$fieldSettings = get_model_custom_fields_with_settings($modelId);
$allowedCheckinFields = array_column($fieldSettings['checkin_fields'], 'name');

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
    $formData[snipeit_field('return_time')] = $returnTime;
    $inspectionData['return_time'] = $returnTime;
    $inspectionData['return_date'] = $returnDate;
    
    $returnLocationId = (int)($_POST['return_location'] ?? 0);
    $inspectionData['return_location_id'] = $returnLocationId;
    
    $needsMaintenance = !empty($_POST['needs_maintenance']);
    $maintenanceNotes = trim($_POST['maintenance_notes'] ?? '');
    $inspectionData['needs_maintenance'] = $needsMaintenance ? 'Yes' : 'No';
    $inspectionData['maintenance_notes'] = $maintenanceNotes;
    
    // === BUSINESS RULES VALIDATION ===
    
    // 1. Current Mileage is mandatory
    $newMileage = (int)($formData[snipeit_field('current_mileage')] ?? 0);
    $previousMileage = (int)($customFields['Current Mileage']['value'] ?? 0);
    
    if (empty($formData[snipeit_field('current_mileage')]) || $newMileage <= 0) {
        $error = 'Current Mileage is required. Please enter the odometer reading.';
    }
    // Mileage cannot be less than previously recorded (checkout mileage)
    elseif ($newMileage < $previousMileage) {
        $error = "Current Mileage ({$newMileage}) cannot be less than the checkout mileage ({$previousMileage}).";
    }
    // Mileage plausibility: max 80 mph average over trip duration
    elseif ($previousMileage > 0 && !empty($reservation['start_datetime'])) {
        $checkoutTime = strtotime($reservation['start_datetime']);
        $hoursElapsed = max(1, (time() - $checkoutTime) / 3600); // min 1 hour
        $maxPlausibleMiles = ceil($hoursElapsed * 80);
        $mileageDiff = $newMileage - $previousMileage;
        if ($mileageDiff > $maxPlausibleMiles) {
            $error = "Mileage increase of {$mileageDiff} miles over " . round($hoursElapsed, 1) . " hours exceeds the plausible maximum ({$maxPlausibleMiles} miles at 80 mph avg). Please verify the odometer reading.";
        }
    }
    
    // 2. Visual Inspection must be "Yes"
    $visualInspection = $formData[snipeit_field('visual_inspection_complete')] ?? '';
    if (empty($error) && $visualInspection !== 'Yes') {
        $error = 'Visual Inspection must be marked as "Yes" before proceeding. You must complete the vehicle inspection.';
    }
    
    // 3. Return location required
    if (empty($error) && !$returnLocationId) {
        $error = 'Please select the return location.';
    }
    
    // === END VALIDATION ===
    
    if ($error) {
        // Validation failed - don't proceed  
    } else {
        if (!empty($formData)) { update_asset_custom_fields($reservation['asset_id'], $formData); }
        
        $newStatusId = $needsMaintenance ? STATUS_VEH_OUT_OF_SERVICE : STATUS_VEH_AVAILABLE;
        update_asset_status($reservation['asset_id'], $newStatusId);
        update_asset_location($reservation['asset_id'], $returnLocationId);
        
        $mileage = $formData[snipeit_field('current_mileage')] ?? 'N/A';
        $note = "Returned by {$userName} at {$returnDate} {$returnTime}. Mileage: {$mileage}";
        if ($needsMaintenance) { $note .= " | MAINTENANCE REQUIRED: {$maintenanceNotes}"; }
        
        try {
            checkin_asset($reservation['asset_id'], $note);
            
            $newStatus = $needsMaintenance ? 'maintenance_required' : 'completed';
            $stmt = $pdo->prepare("UPDATE reservations SET status = ?, checkin_form_data = ?, maintenance_flag = ?, maintenance_notes = ? WHERE id = ?");
// Send checkin notifications (email and/or Teams per event channel settings)
            NotificationService::fire('vehicle_checked_in', array_merge($reservation, ['mileage' => $mileage, 'maintenance_flag' => $needsMaintenance]), $pdo);

            // If maintenance flagged, also notify staff
            if ($needsMaintenance) {
                NotificationService::fire('maintenance_flagged', array_merge($reservation, ['notes' => $maintenanceNotes]), $pdo);
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
    
    // Detect special fields by their db column name
    $isMileageField = (stripos($dbColumn, 'current_mileage') !== false);
    $isInspectionField = (stripos($dbColumn, 'visual_inspection') !== false);
    
    switch ($element) {
        case 'textarea':
            $html .= '<textarea name="' . $inputName . '" class="form-control" rows="2">' . h($currentValue) . '</textarea>';
            break;
        case 'listbox':
            // FIX #4: For inspection fields, always force default to unselected
            $forceEmpty = $isInspectionField;
            $html .= '<select name="' . $inputName . '" class="form-select">';
            $html .= '<option value=""' . (($forceEmpty || empty($currentValue)) ? ' selected' : '') . ' disabled>-- Select --</option>';
            $html .= '<option value="Yes"' . (!$forceEmpty && $currentValue === 'Yes' ? ' selected' : '') . '>Yes</option>';
            $html .= '<option value="No"' . (!$forceEmpty && $currentValue === 'No' ? ' selected' : '') . '>No</option></select>';
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
            
            // FIX #5: For mileage, don't pre-fill - use placeholder instead
            if ($isMileageField && $currentValue) {
                $html .= '<input type="' . $inputType . '" name="' . $inputName . '" class="form-control"'
                       . ' value=""'
                       . ' placeholder="Checkout: ' . h(number_format((int)$currentValue)) . ' miles"'
                       . ' data-previous-mileage="' . h($currentValue) . '"'
                       . ' inputmode="numeric" pattern="[0-9]*">';
            } else {
                $html .= '<input type="' . $inputType . '" name="' . $inputName . '" class="form-control" value="' . h($currentValue) . '">';
            }
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
    <link rel="stylesheet" href="assets/style.css?v=1.4.3">
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
                    <!-- Vehicle Compliance Status (read-only) -->
                    <?php
                    $insExpiry = $customFields['Insurance Expiry']['value'] ?? null;
                    $regExpiry = $customFields['Registration Expiry']['value'] ?? null;
                    $curMileage = (int)($customFields['Current Mileage']['value'] ?? 0);
                    $lastSvcMileage = (int)($customFields['Last Maintenance Mileage']['value'] ?? 0);
                    $milesSinceSvc = $curMileage - $lastSvcMileage;
                    
                    $insDays = $insExpiry ? (int)((strtotime($insExpiry) - time()) / 86400) : null;
                    $regDays = $regExpiry ? (int)((strtotime($regExpiry) - time()) / 86400) : null;
                    
                    $insStatus = $insDays === null ? 'unknown' : ($insDays < 0 ? 'expired' : ($insDays <= 30 ? 'warning' : 'ok'));
                    $regStatus = $regDays === null ? 'unknown' : ($regDays < 0 ? 'expired' : ($regDays <= 30 ? 'warning' : 'ok'));
                    $mntStatus = $curMileage <= 0 ? 'unknown' : ($milesSinceSvc >= 7500 ? 'due' : ($milesSinceSvc >= 7000 ? 'warning' : 'ok'));
                    
                    $statusIcon = ['ok' => '✅', 'warning' => '⚠️', 'expired' => '❌', 'due' => '❌', 'unknown' => '❓'];
                    $statusBg = ['ok' => 'success', 'warning' => 'warning', 'expired' => 'danger', 'due' => 'danger', 'unknown' => 'secondary'];
                    ?>
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="bi bi-shield-check me-2"></i>Vehicle Compliance Status</h6>
                        </div>
                        <div class="card-body py-2">
                            <div class="row text-center g-2">
                                <div class="col-md-4">
                                    <div class="p-2 rounded border border-<?= $statusBg[$insStatus] ?>">
                                        <div class="fs-5"><?= $statusIcon[$insStatus] ?></div>
                                        <small class="fw-bold d-block">Insurance</small>
                                        <small class="text-<?= $statusBg[$insStatus] ?>">
                                            <?php if ($insStatus === 'unknown'): ?>Unknown
                                            <?php elseif ($insStatus === 'expired'): ?>Expired
                                            <?php elseif ($insStatus === 'warning'): ?>Expires in <?= $insDays ?> days
                                            <?php else: ?>Valid until <?= date('M j, Y', strtotime($insExpiry)) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded border border-<?= $statusBg[$regStatus] ?>">
                                        <div class="fs-5"><?= $statusIcon[$regStatus] ?></div>
                                        <small class="fw-bold d-block">Registration</small>
                                        <small class="text-<?= $statusBg[$regStatus] ?>">
                                            <?php if ($regStatus === 'unknown'): ?>Unknown
                                            <?php elseif ($regStatus === 'expired'): ?>Expired
                                            <?php elseif ($regStatus === 'warning'): ?>Expires in <?= $regDays ?> days
                                            <?php else: ?>Valid until <?= date('M j, Y', strtotime($regExpiry)) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="p-2 rounded border border-<?= $statusBg[$mntStatus] ?>">
                                        <div class="fs-5"><?= $statusIcon[$mntStatus] ?></div>
                                        <small class="fw-bold d-block">Maintenance</small>
                                        <small class="text-<?= $statusBg[$mntStatus] ?>">
                                            <?php if ($mntStatus === 'unknown'): ?>No data
                                            <?php elseif ($mntStatus === 'due'): ?>Service overdue (<?= number_format($milesSinceSvc) ?> mi)
                                            <?php elseif ($mntStatus === 'warning'): ?><?= number_format(7500 - $milesSinceSvc) ?> mi until service
                                            <?php else: ?><?= number_format(7500 - $milesSinceSvc) ?> mi until service
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

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
                                    // Only show fields marked for checkin in Snipe-IT, exclude auto-filled
                                    if (!in_array($fieldName, $allowedCheckinFields) || in_array($fieldName, $autoFields)) continue;
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
                
<!-- Checkin confirmation modal -->
<div class="modal fade" id="checkinConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-box-arrow-in-left me-2"></i>Confirm Vehicle Return</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">You are about to <strong>return this vehicle</strong>.</p>
        <p class="text-muted small">This will mark the vehicle as available and record your return inspection. This action cannot be undone.</p>
        <p class="mb-0">Are you sure you want to proceed?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="checkinConfirmBtn"><i class="bi bi-box-arrow-in-left me-1"></i>Yes, Return Vehicle</button>
      </div>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const submitBtn = form?.querySelector('button[type="submit"]');
    const agreement = document.getElementById('agreement');
    
    const mileageInput = form?.querySelector('input[name*="current_mileage"]');
    const visualSelect = form?.querySelector('select[name*="visual_inspection"]');
    
    if (!form || !mileageInput || !visualSelect) return;
    
    // FIX #5/#6: Read previous mileage from data attribute (input is now empty)
    const previousMileage = parseInt(mileageInput.dataset.previousMileage || mileageInput.value) || 0;
    
    // Add required indicators
    const mileageLabel = mileageInput.closest('.mb-3')?.querySelector('.form-label');
    if (mileageLabel) mileageLabel.innerHTML += ' <span class="text-danger">*</span>';
    const visualLabel = visualSelect.closest('.mb-3')?.querySelector('.form-label');
    if (visualLabel) visualLabel.innerHTML += ' <span class="text-danger">*</span>';
    
    mileageInput.setAttribute('required', 'required');
    if (!mileageInput.placeholder || mileageInput.placeholder === '') {
        mileageInput.setAttribute('placeholder', previousMileage > 0 ? 'Checkout: ' + previousMileage.toLocaleString() + ' miles' : 'Enter odometer reading');
    }
    mileageInput.setAttribute('min', previousMileage || 0);
    mileageInput.setAttribute('inputmode', 'numeric');
    
    // FIX #6: Show initial validation hints
    setTimeout(function() {
        if (!mileageInput.value) {
            mileageInput.classList.add('is-invalid');
            addFeedback(mileageInput, 'Required: Enter the current odometer reading.');
        }
        if (visualSelect.value !== 'Yes') {
            visualSelect.classList.add('is-invalid');
            addFeedback(visualSelect, 'Required: Complete visual inspection and select "Yes".');
        }
    }, 300);
    
    mileageInput.addEventListener('input', function() {
        const val = parseInt(this.value) || 0;
        this.classList.remove('is-invalid', 'is-valid');
        let fb = this.parentNode.querySelector('.invalid-feedback');
        if (fb) fb.remove();
        
        if (val <= 0) {
            this.classList.add('is-invalid');
            addFeedback(this, 'Odometer reading is required.');
        } else if (previousMileage > 0 && val < previousMileage) {
            this.classList.add('is-invalid');
            addFeedback(this, 'Cannot be less than checkout reading (' + previousMileage.toLocaleString() + ' mi).');
        } else {
            this.classList.add('is-valid');
        }
        updateSubmitState();
    });
    
    visualSelect.addEventListener('change', function() {
        this.classList.remove('is-invalid', 'is-valid');
        let fb = this.parentNode.querySelector('.invalid-feedback');
        if (fb) fb.remove();
        
        if (this.value !== 'Yes') {
            this.classList.add('is-invalid');
            addFeedback(this, 'Visual inspection must be completed and marked "Yes" to proceed.');
            if (agreement) { agreement.checked = false; agreement.disabled = true; }
        } else {
            this.classList.add('is-valid');
            if (agreement) agreement.disabled = false;
        }
        updateSubmitState();
    });
    
    if (agreement) {
        agreement.addEventListener('change', updateSubmitState);
        if (visualSelect.value !== 'Yes') agreement.disabled = true;
    }
    
    function updateSubmitState() {
        if (!submitBtn) return;
        const mileageOk = mileageInput.classList.contains('is-valid');
        const visualOk = visualSelect.value === 'Yes';
        const agreed = agreement?.checked;
        submitBtn.disabled = !(mileageOk && visualOk && agreed);
    }
    
    function addFeedback(el, msg) {
        const div = document.createElement('div');
        div.className = 'invalid-feedback';
        div.textContent = msg;
        el.parentNode.appendChild(div);
    }
    
    let formConfirmed = false;
    form.addEventListener('submit', function(e) {
        const val = parseInt(mileageInput.value) || 0;
        if (val <= 0) { e.preventDefault(); mileageInput.focus(); return; }
        if (visualSelect.value !== 'Yes') { e.preventDefault(); visualSelect.focus(); return; }
        if (!formConfirmed) {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('checkinConfirmModal'));
            modal.show();
        }
    });
    document.getElementById('checkinConfirmBtn').addEventListener('click', function() {
        formConfirmed = true;
        bootstrap.Modal.getInstance(document.getElementById('checkinConfirmModal')).hide();
        form.submit();
    });
    
    // FIX #6: Show why button is disabled when user tries to click
    const submitWrapper = submitBtn?.parentNode;
    if (submitWrapper) {
        submitWrapper.addEventListener('click', function(e) {
            if (submitBtn.disabled) {
                const reasons = [];
                if (!mileageInput.classList.contains('is-valid')) reasons.push('Enter current odometer reading');
                if (visualSelect.value !== 'Yes') reasons.push('Complete visual inspection (select "Yes")');
                if (!agreement?.checked) reasons.push('Check the confirmation agreement');
                
                let hint = submitWrapper.querySelector('.submit-hint');
                if (!hint) {
                    hint = document.createElement('div');
                    hint.className = 'submit-hint text-danger small mt-2';
                    submitWrapper.appendChild(hint);
                }
                hint.innerHTML = '<strong>Cannot submit yet:</strong><br>' + reasons.map(r => '&bull; ' + r).join('<br>');
                setTimeout(() => { if (hint) hint.remove(); }, 5000);
            }
        });
    }
    
    updateSubmitState();
});
</script>
<?php layout_footer(); ?>
</body>
</html>
