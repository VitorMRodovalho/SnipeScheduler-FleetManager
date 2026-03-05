<?php
/**
 * Fleet Vehicle Reservation
 * Location-based booking with dynamic custom fields from Snipe-IT
 *
 * v1.3.5: Future availability, business day calendar, flatpickr integration
 * Flow: Location → Date → Vehicle (date-aware vehicle filtering)
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/email_service.php';
require_once SRC_PATH . '/notification_service.php';
require_once SRC_PATH . '/reservation_validator.php';
require_once SRC_PATH . '/business_days.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

$error = '';
$success = '';

// Get locations
$pickupLocations = get_pickup_locations();
$destinations = get_field_destinations();

// Get selected pickup location
$selectedPickupId = isset($_REQUEST['pickup_location']) ? (int)$_REQUEST['pickup_location'] : 0;

// Load business day config for calendar
$bdConfig = get_business_day_config($pdo);

// Pre-fetch non-business dates for the calendar (current month + 3 months)
$calendarFrom = date('Y-m-01');
$calendarTo = date('Y-m-t', strtotime('+3 months'));
$nonBusinessData = get_non_business_dates($calendarFrom, $calendarTo, $pdo);
$blackoutData = get_blackout_dates($calendarFrom, $calendarTo, $pdo);

// Build flat list of disabled dates for flatpickr
$disabledDates = array_keys($nonBusinessData['non_business_dates']);
foreach ($blackoutData as $date => $reason) {
    if (!in_array($date, $disabledDates)) {
        $disabledDates[] = $date;
    }
}
sort($disabledDates);

// Ensure today's default is a business day
$defaultStartDate = $_POST['start_date'] ?? $_GET['start_date'] ?? '';
if (!$defaultStartDate || !is_business_day($defaultStartDate, $pdo)) {
    $defaultStartDate = next_business_day_on_or_after(date('Y-m-d'), $pdo);
}
$defaultEndDate = $_POST['end_date'] ?? $_GET['end_date'] ?? '';
if (!$defaultEndDate || !is_business_day($defaultEndDate, $pdo)) {
    $defaultEndDate = $defaultStartDate;
}

// Selected dates for vehicle filtering
$selectedStartDate = $_REQUEST['start_date'] ?? $defaultStartDate;
$selectedEndDate = $_REQUEST['end_date'] ?? $defaultEndDate;

// Get available assets at selected pickup location, filtered by selected dates
$availableAssets = [];
if ($selectedPickupId > 0 && $selectedStartDate && $selectedEndDate) {
    // Get all fleet vehicles (any status)
    $allAssets = get_fleet_vehicles(500);
    $assetList = is_array($allAssets) ? $allAssets : [];

    foreach ($assetList as $asset) {
        // Get location IDs
        $assetLocationId = $asset['location']['id'] ?? 0;
        $rtdLocationId = $asset['rtd_location']['id'] ?? $assetLocationId;

        // Filter by pickup location
        if ($rtdLocationId != $selectedPickupId && $assetLocationId != $selectedPickupId) {
            continue;
        }

        // Get status
        $statusId = $asset['status_label']['id'] ?? 0;

        // Skip out-of-service vehicles
        if (defined('STATUS_VEH_OUT_OF_SERVICE') && $statusId == STATUS_VEH_OUT_OF_SERVICE) {
            continue;
        }

        // Check availability using business day engine
        $availability = check_vehicle_availability(
            $asset['id'],
            $statusId,
            $selectedStartDate,
            $selectedEndDate,
            $pdo
        );

        if ($availability['available']) {
            $asset['_availability'] = $availability['status'];
            $asset['_earliest_date'] = $availability['earliest_date'];
            if ($availability['status'] === 'available_now') {
                $asset['_availability_label'] = 'Available';
            } else {
                $asset['_availability_label'] = 'Available (future)';
            }
            $availableAssets[] = $asset;
        }
    }

    // Sort alphabetically by name
    usort($availableAssets, fn($a, $b) => strcmp($a['name'], $b['name']));
}

// Get current user info
$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$userEmail = $currentUser['email'] ?? '';

// Check if user is VIP (from session, set during login)
$isVip = !empty($currentUser['is_vip']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_reservation'])) {
    $assetId = (int)($_POST['asset_id'] ?? 0);
    $pickupLocationId = (int)($_POST['pickup_location'] ?? 0);
    $destinationId = (int)($_POST['destination'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $purpose = trim($_POST['purpose'] ?? '');

    if (!$assetId) {
        $error = 'Please select a vehicle.';
    } elseif (!$pickupLocationId) {
        $error = 'Please select a pickup location.';
    } elseif (!$destinationId) {
        $error = 'Please select a destination.';
    } elseif (!$startDate || !$startTime) {
        $error = 'Please select pickup date and time.';
    } elseif (!$endDate || !$endTime) {
        $error = 'Please select return date and time.';
    } else {
        // v1.3.5: Validate business days
        if (!is_business_day($startDate, $pdo)) {
            $error = 'Pickup date falls on a non-business day. Please select a working day.';
        } elseif (!is_business_day($endDate, $pdo)) {
            $error = 'Return date falls on a non-business day. Please select a working day.';
        }

        if (empty($error)) {
            $startDatetime = $startDate . ' ' . $startTime . ':00';
            $endDatetime = $endDate . ' ' . $endTime . ':00';

            if (strtotime($endDatetime) <= strtotime($startDatetime)) {
                $error = 'Return time must be after pickup time.';
            } else {
                // Validate reservation controls (min notice, max duration, max concurrent, blackouts)
                $validation = validate_reservation(
                    $startDatetime,
                    $endDatetime,
                    $assetId,
                    $userEmail,
                    $isStaff,
                    $pdo
                );
                if (!$validation['valid']) {
                    $error = implode(' ', $validation['errors']);
                }
            }

            // v1.3.5: Validate business day buffer for future-available vehicles
            if (empty($error)) {
                $latestEnd = get_vehicle_latest_reservation_end($assetId, $pdo);
                if ($latestEnd) {
                    $earliestDate = get_earliest_booking_date($latestEnd, $pdo);
                    if ($startDate < $earliestDate) {
                        $error = "This vehicle requires a {$bdConfig['buffer']}-business-day turnaround. Earliest available date: " . date('M j, Y', strtotime($earliestDate));
                    }
                }
            }

            if (empty($error)) {
                // Check for conflicts (final safety check)
                $stmt = $pdo->prepare("
                    SELECT id FROM reservations
                    WHERE asset_id = ?
                    AND status NOT IN ('cancelled', 'completed', 'rejected', 'missed', 'redirected')
                    AND approval_status NOT IN ('rejected')
                    AND (
                        (start_datetime <= ? AND end_datetime >= ?)
                        OR (start_datetime <= ? AND end_datetime >= ?)
                        OR (start_datetime >= ? AND end_datetime <= ?)
                    )
                ");
                $stmt->execute([$assetId, $startDatetime, $startDatetime, $endDatetime, $endDatetime, $startDatetime, $endDatetime]);

                if ($stmt->fetch()) {
                    $error = 'This vehicle is already reserved for the selected time period.';
                } else {
                    $approvalStatus = $isVip ? 'auto_approved' : 'pending_approval';
                    $status = 'pending';

                    // Get asset name for cache
                    $assetName = '';
                    foreach ($availableAssets as $a) {
                        if ($a['id'] == $assetId) {
                            $assetName = $a['name'] . ' [' . $a['asset_tag'] . ']';
                            break;
                        }
                    }
                    // Fallback: fetch from Snipe-IT if not in filtered list
                    if (!$assetName) {
                        $allFleet = get_fleet_vehicles(500);
                        foreach ($allFleet as $a) {
                            if ($a['id'] == $assetId) {
                                $assetName = $a['name'] . ' [' . $a['asset_tag'] . ']';
                                break;
                            }
                        }
                    }

                    $stmt = $pdo->prepare("
                        INSERT INTO reservations (user_id, user_name, user_email, asset_id, asset_name_cache, pickup_location_id, destination_id,
                            start_datetime, end_datetime, status, approval_status, notes, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $currentUser['id'] ?? 0, $userName, $userEmail, $assetId, $assetName, $pickupLocationId, $destinationId,
                        $startDatetime, $endDatetime, $status, $approvalStatus, $purpose
                    ]);

                    $reservationId = $pdo->lastInsertId();

                    $stmt = $pdo->prepare("INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes) VALUES (?, 'submitted', ?, ?, ?)");
                    $stmt->execute([$reservationId, $userName, $userEmail, 'Reservation submitted']);

                    if ($isVip) {
                        $stmt = $pdo->prepare("INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes) VALUES (?, 'auto_approved', 'System', '', 'VIP user - auto approved')");
                        $stmt->execute([$reservationId]);
                        update_asset_status($assetId, STATUS_VEH_RESERVED);
                    }

                    $successMsg = $isVip ? 'Reservation created and auto-approved!' : 'Reservation submitted for approval.';

                    // Send notifications (email and/or Teams per event channel settings)
                    $reservationData = [
                        'user_name' => $userName,
                        'user_email' => $userEmail,
                        'asset_id' => $assetId,
                        'asset_name_cache' => $assetName,
                        'start_datetime' => $startDatetime,
                        'end_datetime' => $endDatetime,
                    ];

                    if ($isVip) {
                        NotificationService::fire('reservation_approved', array_merge($reservationData, ['approver' => 'System (Auto-Approved)']), $pdo);
                    } else {
                        NotificationService::fire('reservation_submitted', $reservationData, $pdo);
                    }

                    header('Location: my_bookings?success=' . urlencode($successMsg));
                    exit;
                }
            }
        }
    }
}

function get_location_name($locations, $id) {
    foreach ($locations as $loc) {
        if ($loc['id'] == $id) return $loc['name'];
    }
    return '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reserve Vehicle</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="assets/style.css?v=<?= trim(file_get_contents(__DIR__ . '/../version.txt')) ?>">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
    <style>
        .flatpickr-day.flatpickr-disabled {
            background: repeating-linear-gradient(
                45deg, transparent, transparent 3px, rgba(0,0,0,0.04) 3px, rgba(0,0,0,0.04) 6px
            ) !important;
            color: #bbb !important;
        }
        .availability-badge {
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 12px;
            background-color: #d4edda;
            color: #155724;
        }
        .vehicle-card.border-primary {
            border-color: #0d6efd !important;
            box-shadow: 0 0 0 2px rgba(13,110,253,0.25);
        }
        #vehicleListContainer .loading-spinner {
            display: none;
        }
        #vehicleListContainer.loading .loading-spinner {
            display: block;
        }
        #vehicleListContainer.loading .vehicle-grid {
            opacity: 0.4;
            pointer-events: none;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Book Vehicle</h1>
            <p class="text-muted">Select your pickup location, dates, then choose an available vehicle</p>
        </div>

        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($isVip): ?>
                <div class="alert alert-info">
                    <i class="bi bi-star-fill me-2"></i><strong>VIP Status:</strong> Your reservations will be auto-approved.
                </div>
            <?php endif; ?>

            <form method="post" id="reservationForm">
                <!-- Step 1: Select Locations -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Step 1: Select Locations</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label"><strong>Pickup Location</strong> <span class="text-danger">*</span></label>
                                <select name="pickup_location" id="pickupLocation" class="form-select form-select-lg" required onchange="this.form.submit()">
                                    <option value="">-- Select pickup location --</option>
                                    <?php foreach ($pickupLocations as $loc): ?>
                                        <option value="<?= $loc['id'] ?>" <?= $selectedPickupId == $loc['id'] ? 'selected' : '' ?>>
                                            <?= h($loc['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><strong>Destination</strong> <span class="text-danger">*</span></label>
                                <select name="destination" class="form-select form-select-lg" required>
                                    <option value="">-- Select destination --</option>
                                    <?php foreach ($destinations as $loc): ?>
                                        <option value="<?= $loc['id'] ?>" <?= (isset($_POST['destination']) && $_POST['destination'] == $loc['id']) ? 'selected' : '' ?>>
                                            <?= h($loc['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($selectedPickupId > 0): ?>
                    <!-- Step 2: Select Date/Time (NOW BEFORE vehicle selection) -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-calendar me-2"></i>Step 2: Select Date & Time (Estimated)</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-info-circle"></i> Select your dates first — the vehicle list below will update to show only vehicles available for your chosen window.
                                <br><i class="bi bi-calendar-x"></i> <strong>Gray dates</strong> are non-business days (weekends, holidays, blackouts) and cannot be selected.
                            </p>
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Pickup Date</strong> <span class="text-danger">*</span></label>
                                    <input type="text" name="start_date" id="startDatePicker" class="form-control" required
                                           value="<?= h($selectedStartDate) ?>" placeholder="Select date...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Pickup Time</strong> <span class="text-danger">*</span></label>
                                    <input type="time" name="start_time" class="form-control" required value="<?= $_POST['start_time'] ?? '08:00' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Return Date</strong> <span class="text-danger">*</span></label>
                                    <input type="text" name="end_date" id="endDatePicker" class="form-control" required
                                           value="<?= h($selectedEndDate) ?>" placeholder="Select date...">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Return Time</strong> <span class="text-danger">*</span></label>
                                    <input type="time" name="end_time" class="form-control" required value="<?= $_POST['end_time'] ?? '17:00' ?>">
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <label class="form-label"><strong>Purpose / Notes</strong></label>
                                    <textarea name="purpose" class="form-control" rows="2" placeholder="Briefly describe the purpose of your trip..."><?= h($_POST['purpose'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Select Vehicle (filtered by date window) -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-truck me-2"></i>Step 3: Select a Vehicle
                                <span class="badge bg-light text-dark ms-2" id="vehicleCount"><?= count($availableAssets) ?> available</span>
                            </h5>
                        </div>
                        <div class="card-body" id="vehicleListContainer">
                            <div class="text-center py-3 loading-spinner">
                                <div class="spinner-border text-success" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                                <p class="text-muted mt-2">Checking vehicle availability...</p>
                            </div>

                            <?php if (empty($availableAssets)): ?>
                                <div class="alert alert-warning vehicle-grid" id="noVehiclesAlert">
                                    <i class="bi bi-exclamation-circle me-2"></i>
                                    No vehicles available at this location for the selected dates. Try different dates or check further ahead.
                                </div>
                            <?php else: ?>
                                <div class="row vehicle-grid" id="vehicleGrid">
                                    <?php foreach ($availableAssets as $asset): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100 border-2 vehicle-card" style="cursor: pointer;"
                                                 onclick="selectVehicle(<?= $asset['id'] ?>, this)">
                                                <div class="card-body">
                                                    <div class="form-check">
                                                        <input type="radio" class="form-check-input" name="asset_id" id="asset_<?= $asset['id'] ?>" value="<?= $asset['id'] ?>" required>
                                                        <label class="form-check-label w-100" for="asset_<?= $asset['id'] ?>">
                                                            <div class="d-flex justify-content-between align-items-start">
                                                                <h6 class="mb-1"><?= h($asset['name']) ?></h6>
                                                                <span class="availability-badge">
                                                                    <i class="bi bi-check-circle-fill me-1"></i>Available
                                                                </span>
                                                            </div>
                                                            <small class="text-muted">
                                                                <strong>Tag:</strong> <?= h($asset['asset_tag']) ?><br>
                                                                <strong>Model:</strong> <?= h($asset['model']['name'] ?? 'N/A') ?><br>
                                                                <strong>Plate:</strong> <?= h($asset['custom_fields']['License Plate']['value'] ?? 'N/A') ?>
                                                            </small>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($availableAssets)): ?>
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <a href="my_bookings" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
                                    <button type="submit" name="submit_reservation" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-circle me-1"></i><?= $isVip ? 'Reserve (Auto-Approved)' : 'Submit for Approval' ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="card mb-4">
                        <div class="card-body text-center py-5">
                            <i class="bi bi-geo-alt text-muted" style="font-size: 4rem;"></i>
                            <h5 class="mt-3">Select a Pickup Location</h5>
                            <p class="text-muted">Choose where you want to pick up the vehicle.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
// Disabled dates from PHP (non-business days + blackouts)
const disabledDates = <?= json_encode(array_values($disabledDates)) ?>;
const pickupLocationId = <?= $selectedPickupId ?>;

// Flatpickr configuration
const fpConfig = {
    dateFormat: 'Y-m-d',
    minDate: 'today',
    disable: disabledDates,
    locale: { firstDayOfWeek: 1 },
    onDayCreate: function(dObj, dStr, fp, dayElem) {
        const dateStr = dayElem.dateObj.toISOString().split('T')[0];
        if (disabledDates.includes(dateStr)) {
            dayElem.classList.add('flatpickr-disabled');
        }
    }
};

// Enforce min notice hours on time selection
const minNoticeHours = <?= json_encode((int)($bdConfig['min_notice_hours'] ?? get_reservation_controls()['min_notice_hours'] ?? 0)) ?>;

function enforceMinTime() {
    const startDateEl = document.getElementById('startDatePicker');
    const startTimeEl = document.querySelector('input[name="start_time"]');
    if (!startDateEl || !startTimeEl) return;

    const today = new Date().toISOString().split('T')[0];
    if (startDateEl.value === today && minNoticeHours > 0) {
        const minTime = new Date(Date.now() + minNoticeHours * 3600000);
        const hh = String(minTime.getHours()).padStart(2, '0');
        const mm = String(minTime.getMinutes()).padStart(2, '0');
        startTimeEl.min = hh + ':' + mm;
        if (startTimeEl.value < startTimeEl.min) {
            startTimeEl.value = startTimeEl.min;
        }
    } else {
        startTimeEl.min = '';
    }
}

// Debounce helper
let refreshTimer = null;
function scheduleRefresh() {
    clearTimeout(refreshTimer);
    refreshTimer = setTimeout(refreshVehicleList, 400);
}

// Initialize date pickers
const startPicker = flatpickr('#startDatePicker', {
    ...fpConfig,
    onChange: function(selectedDates, dateStr) {
        if (endPicker && selectedDates[0]) {
            endPicker.set('minDate', dateStr);
            const endVal = document.getElementById('endDatePicker').value;
            if (!endVal || endVal < dateStr) {
                endPicker.setDate(dateStr);
            }
        }
       enforceMinTime();
        scheduleRefresh();
    }
});

const endPicker = flatpickr('#endDatePicker', {
    ...fpConfig,
    onChange: function(selectedDates, dateStr) {
        const startVal = document.getElementById('startDatePicker').value;
        if (startVal && dateStr < startVal) {
            this.setDate(startVal);
        }
        scheduleRefresh();
    }
});

// Refresh vehicle list via AJAX when dates change
function refreshVehicleList() {
    const startDate = document.getElementById('startDatePicker')?.value;
    const endDate = document.getElementById('endDatePicker')?.value;

    if (!startDate || !endDate || !pickupLocationId) return;

    const container = document.getElementById('vehicleListContainer');
    if (!container) return;

    container.classList.add('loading');

    fetch(`api/business_days?action=get_vehicle_availability&pickup_location=${pickupLocationId}&start_date=${startDate}&end_date=${endDate}`, {
        credentials: 'same-origin'
    })
    .then(r => r.json())
    .then(data => {
        container.classList.remove('loading');
        if (!data.success) {
            renderVehicles(container, [], data.error || 'Error loading vehicles.');
            return;
        }
        renderVehicles(container, data.vehicles);
        document.getElementById('vehicleCount').textContent = data.count + ' available';
    })
    .catch(err => {
        container.classList.remove('loading');
        console.error('Vehicle refresh error:', err);
    });
}

function renderVehicles(container, vehicles, errorMsg) {
    // Remove existing grid/alert (keep spinner div)
    const oldGrid = container.querySelector('.vehicle-grid');
    const oldAlert = container.querySelector('#noVehiclesAlert');
    if (oldGrid) oldGrid.remove();
    if (oldAlert) oldAlert.remove();

    // Remove old submit card if vehicles are now empty
    const submitCard = document.querySelector('.submit-card');

    if (!vehicles || vehicles.length === 0) {
        const msg = errorMsg || 'No vehicles available at this location for the selected dates. Try different dates or check further ahead.';
        const alert = document.createElement('div');
        alert.className = 'alert alert-warning vehicle-grid';
        alert.id = 'noVehiclesAlert';
        alert.innerHTML = '<i class="bi bi-exclamation-circle me-2"></i>' + msg;
        container.appendChild(alert);
        return;
    }

    const row = document.createElement('div');
    row.className = 'row vehicle-grid';
    row.id = 'vehicleGrid';

    vehicles.forEach(v => {
        const col = document.createElement('div');
        col.className = 'col-md-6 mb-3';
        col.innerHTML = `
            <div class="card h-100 border-2 vehicle-card" style="cursor: pointer;" onclick="selectVehicle(${v.id}, this)">
                <div class="card-body">
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="asset_id" id="asset_${v.id}" value="${v.id}" required>
                        <label class="form-check-label w-100" for="asset_${v.id}">
                            <div class="d-flex justify-content-between align-items-start">
                                <h6 class="mb-1">${escHtml(v.name)}</h6>
                                <span class="availability-badge">
                                    <i class="bi bi-check-circle-fill me-1"></i>Available
                                </span>
                            </div>
                            <small class="text-muted">
                                <strong>Tag:</strong> ${escHtml(v.asset_tag)}<br>
                                <strong>Model:</strong> ${escHtml(v.model)}<br>
                                <strong>Plate:</strong> ${escHtml(v.license_plate)}
                            </small>
                        </label>
                    </div>
                </div>
            </div>
        `;
        row.appendChild(col);
    });

    container.appendChild(row);
}

function escHtml(str) {
    if (!str) return 'N/A';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Initial enforcement
enforceMinTime();
function selectVehicle(assetId, card) {
    document.querySelectorAll('.vehicle-card').forEach(c => c.classList.remove('border-primary', 'bg-light'));
    card.classList.add('border-primary', 'bg-light');
    document.getElementById('asset_' + assetId).checked = true;
}
</script>
<?php layout_footer(); ?>
</body>
</html>
