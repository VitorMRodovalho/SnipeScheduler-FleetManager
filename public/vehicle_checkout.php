<?php
/**
 * Fleet Vehicle Checkout
 * Dynamic custom fields from Snipe-IT API, automatic timestamp
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/email_service.php';
require_once SRC_PATH . '/notification_service.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$error = '';
$formError = '';

$reservationId = isset($_GET['reservation_id']) ? (int)$_GET['reservation_id'] : 0;
if (!$reservationId) { header('Location: my_bookings'); exit; }

$stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
$stmt->execute([$reservationId]);
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    $error = 'Reservation not found.';
} elseif (!in_array($reservation['approval_status'] ?? '', ['approved', 'auto_approved'])) {
    $error = 'This reservation has not been approved yet.';
} elseif ($reservation['status'] === 'completed') {
    $error = 'This reservation has already been completed.';
} elseif ($reservation['status'] === 'confirmed') {
    $error = 'This vehicle has already been checked out.';
}

$asset = null;
$customFields = [];
if ($reservation && $reservation['asset_id']) {
    $asset = get_asset_with_custom_fields($reservation['asset_id']);
    if ($asset && !empty($asset['custom_fields'])) {
        $customFields = $asset['custom_fields'];
    }
}

// Get allowed checkout fields from Snipe-IT field settings
$modelId = $asset['model']['id'] ?? 0;
$fieldSettings = get_model_custom_fields_with_settings($modelId);
$allowedCheckoutFields = array_column($fieldSettings['checkout_fields'], 'name');

// Get location names
$pickupLocations = get_pickup_locations();
$destinations = get_field_destinations();
$allLocations = array_merge($pickupLocations, $destinations);

function get_loc_name($locations, $id) {
    foreach ($locations as $loc) { if ($loc['id'] == $id) return $loc['name']; }
    return 'Unknown';
}

$pickupLocationName = get_loc_name($allLocations, $reservation['pickup_location_id'] ?? 0);
$destinationName = get_loc_name($allLocations, $reservation['destination_id'] ?? 0);

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

// Categorize fields: read-only (vehicle info) vs editable (inspection)
$vehicleInfoFields = ['VIN', 'License Plate', 'Vehicle Year', 'Insurance Expiry', 'Registration Expiry', 
                      'Last Oil Change (Miles)', 'Last Tire Rotation (Miles)', 'Holman Account #'];
$autoFields = ['Checkout Time', 'Return Time', 'Expected Return Time']; // Auto-filled, not shown

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
    
    // Auto-fill checkout time with current timestamp
    $checkoutTime = date('H:i');
    $checkoutDate = date('Y-m-d');
    $formData[snipeit_field('checkout_time')] = $checkoutTime;
    $inspectionData['checkout_time'] = $checkoutTime;
    $inspectionData['checkout_date'] = $checkoutDate;
    $inspectionData['pickup_location_id'] = $reservation['pickup_location_id'];
    $inspectionData['destination_id'] = $reservation['destination_id'];
    
    $snipeUser = get_snipeit_user_by_email($userEmail);
    $snipeUserId = $snipeUser ? ($snipeUser['id'] ?? 0) : 0;
    

if (!$snipeUserId) {
        $error = 'Your user account was not found in Snipe-IT.';
    } else {
        if (!empty($formData)) { update_asset_custom_fields($reservation['asset_id'], $formData); }
        
        // First set to VEH-Available so Snipe-IT allows checkout (must be Deployable)
        update_asset_status($reservation['asset_id'], STATUS_VEH_AVAILABLE);


        if ($reservation['destination_id']) {
            update_asset_location($reservation['asset_id'], $reservation['destination_id']);
        }
        
        $mileage = $formData[snipeit_field('current_mileage')] ?? 'N/A';
        $note = "Checkout by {$userName} at {$checkoutDate} {$checkoutTime}. Mileage: {$mileage}. Destination: {$destinationName}";
        $expectedCheckin = date('Y-m-d', strtotime($reservation['end_datetime']));
        
        try {
            checkout_asset_to_user($reservation['asset_id'], $snipeUserId, $note, $expectedCheckin);
            $stmt = $pdo->prepare("UPDATE reservations SET status = 'confirmed', checkout_form_data = ? WHERE id = ?");
// Send checkout receipt email
            NotificationService::fire('vehicle_checked_out', array_merge($reservation, ['mileage' => $mileage]), $pdo);            
$stmt->execute([json_encode($inspectionData), $reservationId]);
            header('Location: my_bookings?success=' . urlencode('Vehicle checked out successfully at ' . $checkoutTime));
            exit;
        } catch (Exception $e) {
            $error = 'Failed to checkout: ' . $e->getMessage();
        }
    }
}

function render_field($fieldName, $fieldData, $isReadOnly = false) {
    $dbColumn = $fieldData['field'] ?? '';
    $currentValue = $fieldData['value'] ?? '';
    $element = $fieldData['element'] ?? 'text';
    $format = $fieldData['field_format'] ?? 'ANY';
    $inputName = 'field_' . h($dbColumn);
    
    // Detect special fields by their db column name
    $isMileageField = (stripos($dbColumn, 'current_mileage') !== false);
    $isInspectionField = (stripos($dbColumn, 'visual_inspection') !== false);
    
    $html = '<div class="mb-3"><label class="form-label"><strong>' . h($fieldName) . '</strong></label>';
    
    if ($isReadOnly) {
        $displayValue = ($format === 'DATE' && $currentValue) ? date('M j, Y', strtotime($currentValue)) : $currentValue;
        return $html . '<input type="text" class="form-control bg-light" value="' . h($displayValue) . '" readonly disabled></div>';
    }
    
    switch ($element) {
        case 'textarea':
            // Detect condition description fields for checkbox coupling
            $conditionArea = '';
            $lowerName = strtolower($fieldName);
            if (strpos($lowerName, 'exterior') !== false) $conditionArea = 'exterior';
            elseif (strpos($lowerName, 'tire') !== false) $conditionArea = 'tires';
            elseif (strpos($lowerName, 'undercarriage') !== false) $conditionArea = 'undercarriage';
            elseif (strpos($lowerName, 'interior') !== false) $conditionArea = 'interior';
            
            $isConditionDesc = !empty($conditionArea);
            $disabledAttr = $isConditionDesc ? ' disabled' : '';
            $dataAttr = $isConditionDesc ? ' data-condition-area="' . $conditionArea . '"' : '';
            $placeholder = $isConditionDesc ? 'Check the corresponding issue checkbox above to enable...' : 'Enter details if applicable...';
            $dimClass = $isConditionDesc ? ' condition-textarea' : '';
            $html .= '<textarea name="' . $inputName . '" class="form-control' . $dimClass . '" rows="2" placeholder="' . $placeholder . '"' . $disabledAttr . $dataAttr . '>' . h($currentValue) . '</textarea>';
            break;
        case 'listbox':
            // FIX #4: For inspection fields, always force default to unselected
            // so the driver must actively choose "Yes" after performing inspection
            $forceEmpty = $isInspectionField;
            $html .= '<select name="' . $inputName . '" class="form-select">';
            $html .= '<option value=""' . (($forceEmpty || empty($currentValue)) ? ' selected' : '') . ' disabled>-- Select --</option>';
            $html .= '<option value="Yes"' . (!$forceEmpty && $currentValue === 'Yes' ? ' selected' : '') . '>Yes</option>';
            $html .= '<option value="No"' . (!$forceEmpty && $currentValue === 'No' ? ' selected' : '') . '>No</option></select>';
            break;
        case 'checkbox':
            // Dynamic options from Snipe-IT field_values (not hardcoded)
            $fieldValuesRaw = $fieldData['field_values_list'] ?? $fieldData['field_values'] ?? '';
            if (!empty($fieldValuesRaw)) {
                $options = array_filter(array_map('trim', preg_split('/[\n,]+/', $fieldValuesRaw)));
            } else {
                $options = ['Exterior', 'Tires', 'Undercarriage', 'Interior']; // fallback
            }
            $isConditionField = (stripos($dbColumn, 'condition') !== false || stripos($fieldName, 'issues with the condition') !== false);
            $currentValues = array_map('trim', explode(',', $currentValue));
            foreach ($options as $opt) {
                $checked = in_array($opt, $currentValues) ? ' checked' : '';
                $condClass = $isConditionField ? ' condition-checkbox' : '';
                $condData = $isConditionField ? ' data-controls="' . h(strtolower(trim($opt))) . '"' : '';
                $html .= '<div class="form-check form-check-inline"><input type="checkbox" class="form-check-input' . $condClass . '" name="' . $inputName . '[]" value="' . h($opt) . '"' . $checked . $condData . '>';
                $html .= '<label class="form-check-label">' . h($opt) . '</label></div>';
            }
            break;
        default:
            $inputType = ($format === 'DATE') ? 'date' : 'text';
            if ($format === 'DATE' && $currentValue && strtotime($currentValue)) { $currentValue = date('Y-m-d', strtotime($currentValue)); }
            
            // FIX #5: For mileage, don't pre-fill the value - use placeholder instead
            // Forces the driver to physically read the odometer and type the number
            if ($isMileageField && $currentValue) {
                $html .= '<input type="' . $inputType . '" name="' . $inputName . '" class="form-control"'
                       . ' value=""'
                       . ' placeholder="Previous: ' . h(number_format((int)$currentValue)) . ' miles"'
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
    <title>Vehicle Checkout</title>
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
            <h1>Vehicle Checkout</h1>
            <p class="text-muted">Complete the inspection before picking up the vehicle</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>
        <div class="row justify-content-center">
            <div class="col-lg-10">

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                    <br><a href="my_bookings" class="btn btn-outline-danger btn-sm mt-2">Back to My Reservations</a>
                </div>
            <?php elseif ($asset && $reservation): ?>
            
                <?php
                // Preserve user input on validation failure
                if (!empty($formError) && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    foreach ($_POST as $key => $value) {
                        if (strpos($key, 'field_') === 0) {
                            $fieldDbColumn = substr($key, 6);
                            foreach ($customFields as $cfName => &$cfData) {
                                if (($cfData['field'] ?? '') === $fieldDbColumn) {
                                    $cfData['value'] = is_array($value) ? implode(', ', $value) : trim($value);
                                }
                            }
                            unset($cfData);
                        }
                    }
                }
            ?>
            <?php if (!empty($formError)): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><strong>Please correct:</strong> <?= h($formError) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>
                
                <!-- Auto Timestamp Notice -->
                <div class="alert alert-info">
                    <i class="bi bi-clock me-2"></i>
                    <strong>Checkout Time:</strong> <?= date('l, F j, Y \a\t g:i A') ?> (will be recorded automatically)
                </div>

                <!-- Vehicle & Trip Info -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Vehicle & Trip Details</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Vehicle:</strong> <?= h($asset['name']) ?></p>
                                <p class="mb-1"><strong>Tag:</strong> <?= h($asset['asset_tag']) ?></p>
                                <p class="mb-1"><strong>Model:</strong> <?= h($asset['model']['name'] ?? 'N/A') ?></p>
                                <p class="mb-1"><strong>License Plate:</strong> <?= h($customFields['License Plate']['value'] ?? 'N/A') ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Pickup:</strong> <?= h($pickupLocationName) ?></p>
                                <p class="mb-1"><strong>Destination:</strong> <?= h($destinationName) ?></p>
                                <p class="mb-1"><strong>Expected Return:</strong> <?= date('M j, Y g:i A', strtotime($reservation['end_datetime'])) ?></p>
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

                    <!-- Inspection Form - Dynamic from Snipe-IT -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Checkout Inspection</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php 
                                // Merge field_values from Snipe-IT fieldset into customFields for dynamic rendering
                                $allFieldDefs = $fieldSettings['checkout_fields'] ?? [];
                                foreach ($allFieldDefs as $fDef) {
                                    foreach ($customFields as $cfName => &$cfData) {
                                        if (($cfData['field'] ?? '') === ($fDef['db_column'] ?? '')) {
                                            $cfData['field_values_list'] = $fDef['field_values'] ?? '';
                                        }
                                    }
                                }
                                unset($cfData);
                                foreach ($customFields as $fieldName => $fieldData):
                                    // Only show fields marked for checkout in Snipe-IT, exclude auto-filled
                                    if (!in_array($fieldName, $allowedCheckoutFields) || in_array($fieldName, $autoFields)) continue;
                                ?>
                                    <div class="col-md-6"><?= render_field($fieldName, $fieldData, false) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Confirm & Submit -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="agreement" required>
                                <label class="form-check-label" for="agreement">
                                    <strong>I confirm</strong> that I have inspected this vehicle and recorded its current condition.
                                </label>
                            </div>
                            <div class="d-flex justify-content-between">
                                <a href="my_bookings" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
                                <button type="submit" class="btn btn-success btn-lg"><i class="bi bi-check-circle me-1"></i>Complete Checkout</button>
                            </div>
                        </div>
                    </div>
                
</form>
            <?php endif; ?>
        </div>
    </div>
</div><!-- page-shell -->
</div>

<!-- Checkout confirmation modal (outside page-shell to avoid z-index conflict with Bootstrap backdrop) -->
<div class="modal fade" id="checkoutConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Confirm Vehicle Checkout</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">You are about to <strong>check out this vehicle</strong>.</p>
        <p class="text-muted small">This will mark the vehicle as in use and record your inspection. This action cannot be undone.</p>
        <p class="mb-0">Are you sure you want to proceed?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="checkoutConfirmBtn"><i class="bi bi-check-circle me-1"></i>Yes, Check Out</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    const submitBtn = form?.querySelector('button[type="submit"]');
    const agreement = document.getElementById('agreement');
    
    // Find fields by their name attributes
    const mileageInput = form?.querySelector('input[name*="current_mileage"]');
    const visualSelect = form?.querySelector('select[name*="visual_inspection"]');
    
    if (!form || !mileageInput || !visualSelect) return;
    
    // FIX #5/#6: Read previous mileage from data attribute (not input value, which is now empty)
    const previousMileage = parseInt(mileageInput.dataset.previousMileage || mileageInput.value) || 0;
    
    // Add required indicator to mileage
    const mileageLabel = mileageInput.closest('.mb-3')?.querySelector('.form-label');
    if (mileageLabel) mileageLabel.innerHTML += ' <span class="text-danger">*</span>';
    
    // Add required indicator to visual inspection
    const visualLabel = visualSelect.closest('.mb-3')?.querySelector('.form-label');
    if (visualLabel) visualLabel.innerHTML += ' <span class="text-danger">*</span>';
    
    // Set mileage as required with placeholder (placeholder already set by PHP if data attr exists)
    mileageInput.setAttribute('required', 'required');
    if (!mileageInput.placeholder || mileageInput.placeholder === '') {
        mileageInput.setAttribute('placeholder', previousMileage > 0 ? 'Previous: ' + previousMileage.toLocaleString() + ' miles' : 'Enter odometer reading');
    }
    mileageInput.setAttribute('min', previousMileage || 0);
    mileageInput.setAttribute('inputmode', 'numeric');
    
    // FIX #6: Show initial validation hints so user knows what's required
    // Highlight empty required fields on page load (subtle, not aggressive)
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
    
    // Real-time mileage validation
    mileageInput.addEventListener('input', function() {
        const val = parseInt(this.value) || 0;
        this.classList.remove('is-invalid', 'is-valid');
        let existingFeedback = this.parentNode.querySelector('.invalid-feedback');
        if (existingFeedback) existingFeedback.remove();
        
        if (val <= 0) {
            this.classList.add('is-invalid');
            addFeedback(this, 'Odometer reading is required.');
        } else if (previousMileage > 0 && val < previousMileage) {
            this.classList.add('is-invalid');
            addFeedback(this, 'Cannot be less than previous reading (' + previousMileage.toLocaleString() + ' mi).');
        } else if (previousMileage > 0 && (val - previousMileage) > 5000) {
            this.classList.add('is-invalid');
            addFeedback(this, 'Increase of ' + (val - previousMileage).toLocaleString() + ' miles seems too high. Please verify.');
        } else {
            this.classList.add('is-valid');
        }
        updateSubmitState();
    });
    
    // Visual inspection validation
    visualSelect.addEventListener('change', function() {
        this.classList.remove('is-invalid', 'is-valid');
        let existingFeedback = this.parentNode.querySelector('.invalid-feedback');
        if (existingFeedback) existingFeedback.remove();
        
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
    
    // Agreement checkbox depends on visual inspection
    if (agreement) {
        agreement.addEventListener('change', updateSubmitState);
        // Initial state: disable if visual inspection not Yes
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
    
    // Form submit - show confirmation modal
    let formConfirmed = false;
    form.addEventListener('submit', function(e) {
        const val = parseInt(mileageInput.value) || 0;
        if (val <= 0) { e.preventDefault(); mileageInput.focus(); return; }
        if (visualSelect.value !== 'Yes') { e.preventDefault(); visualSelect.focus(); return; }
        if (!formConfirmed) {
            e.preventDefault();
            const modal = new bootstrap.Modal(document.getElementById('checkoutConfirmModal'));
            modal.show();
        }
    });
    document.getElementById('checkoutConfirmBtn').addEventListener('click', function() {
        formConfirmed = true;
        bootstrap.Modal.getInstance(document.getElementById('checkoutConfirmModal')).hide();
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
                
                // Show a temporary tooltip-style message
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
    
    // FIX #6: Show why button is disabled when user tries to click
    const submitWrapper = submitBtn?.parentNode;
    if (submitWrapper) {
        submitWrapper.addEventListener('click', function(e) {
            if (submitBtn.disabled) {
                const reasons = [];
                if (!mileageInput.classList.contains('is-valid')) reasons.push('Enter current odometer reading');
                if (visualSelect.value !== 'Yes') reasons.push('Complete visual inspection (select "Yes")');
                if (!agreement?.checked) reasons.push('Check the confirmation agreement');
                
                // Show a temporary tooltip-style message
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
    
    // FIX #6: Show why button is disabled when user tries to click
    const submitWrapper = submitBtn?.parentNode;
    if (submitWrapper) {
        submitWrapper.addEventListener('click', function(e) {
            if (submitBtn.disabled) {
                const reasons = [];
                if (!mileageInput.classList.contains('is-valid')) reasons.push('Enter current odometer reading');
                if (visualSelect.value !== 'Yes') reasons.push('Complete visual inspection (select "Yes")');
                if (!agreement?.checked) reasons.push('Check the confirmation agreement');
                
                // Show a temporary tooltip-style message
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
    
    // Initial state
    updateSubmitState();
});
</script>

<script>
// Condition checkbox ↔ textarea coupling
// Textareas are disabled until their corresponding condition checkbox is checked
document.addEventListener('DOMContentLoaded', function() {
    const conditionCheckboxes = document.querySelectorAll('.condition-checkbox');
    const conditionTextareas = document.querySelectorAll('.condition-textarea');
    
    function updateTextareaState() {
        conditionCheckboxes.forEach(function(cb) {
            const area = cb.dataset.controls; // e.g. "exterior", "tires"
            if (!area) return;
            const textarea = document.querySelector('[data-condition-area="' + area + '"]');
            if (textarea) {
                if (cb.checked) {
                    textarea.disabled = false;
                    textarea.placeholder = 'Describe the ' + area + ' issue...';
                    textarea.closest('.mb-3').style.opacity = '1';
                } else {
                    textarea.disabled = true;
                    textarea.value = '';
                    textarea.placeholder = 'Check the corresponding issue checkbox above to enable...';
                    textarea.closest('.mb-3').style.opacity = '0.5';
                }
            }
        });
    }
    
    // Set initial state (all condition textareas dimmed)
    conditionTextareas.forEach(function(ta) {
        ta.closest('.mb-3').style.opacity = '0.5';
        ta.closest('.mb-3').style.transition = 'opacity 0.2s ease';
    });
    
    conditionCheckboxes.forEach(function(cb) {
        cb.addEventListener('change', updateTextareaState);
    });
    
    // Run once on load (in case of POST with preserved values)
    updateTextareaState();
});
</script>
<?php layout_footer(); ?>
</body>
</html>
