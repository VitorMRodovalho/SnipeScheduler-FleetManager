<?php
/**
 * Fleet Vehicle Reservation
 * Location-based booking with dynamic custom fields from Snipe-IT
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
$success = '';

// Get locations
$pickupLocations = get_pickup_locations();
$destinations = get_field_destinations();

// Get selected pickup location
$selectedPickupId = isset($_REQUEST['pickup_location']) ? (int)$_REQUEST['pickup_location'] : 0;

// Get available assets at selected pickup location
$availableAssets = [];
if ($selectedPickupId > 0) {
    $allAssets = get_requestable_assets(100, null);
    
    // Function returns array directly, not ['rows']
    $assetList = is_array($allAssets) ? $allAssets : [];
    
    foreach ($assetList as $asset) {
        // Get location IDs
        $assetLocationId = $asset['location']['id'] ?? 0;
        $rtdLocationId = $asset['rtd_location']['id'] ?? $assetLocationId;
        
        // Get status
        $statusId = $asset['status_label']['id'] ?? 0;
        
        // Check if at selected location AND available
        if (($rtdLocationId == $selectedPickupId || $assetLocationId == $selectedPickupId) 
            && $statusId == STATUS_VEH_AVAILABLE) {
            $availableAssets[] = $asset;
        }
    }
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
        $startDatetime = $startDate . ' ' . $startTime . ':00';
        $endDatetime = $endDate . ' ' . $endTime . ':00';
        
        if (strtotime($endDatetime) <= strtotime($startDatetime)) {
            $error = 'Return time must be after pickup time.';
        } else {
            // Check for conflicts
            $stmt = $pdo->prepare("
                SELECT id FROM reservations 
                WHERE asset_id = ? 
                AND status NOT IN ('cancelled', 'completed', 'rejected')
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
// Send email notifications
                $emailService = get_email_service($pdo);
                $reservationData = [
                    'user_name' => $userName,
                    'user_email' => $userEmail,
                    'asset_id' => $assetId,
                    'asset_name_cache' => $assetName,
                    'start_datetime' => $startDatetime,
                    'end_datetime' => $endDatetime,
                ];
                
                if ($isVip) {
                    $emailService->notifyAutoApproved($reservationData);
                } else {
                    $emailService->notifyNewReservation($reservationData);
                }                


header('Location: my_bookings.php?success=' . urlencode($successMsg));
                exit;
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
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Book Vehicle</h1>
            <p class="text-muted">Select your pickup location to see available vehicles</p>
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
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>



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

            <form method="post">
                <!-- Step 1: Select Locations -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Step 1: Select Locations</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="form-label"><strong>Pickup Location</strong> <span class="text-danger">*</span></label>
                                <select name="pickup_location" class="form-select form-select-lg" required onchange="this.form.submit()">
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
                    <!-- Step 2: Select Vehicle -->
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-truck me-2"></i>Step 2: Select a Vehicle
                                <span class="badge bg-light text-dark ms-2"><?= count($availableAssets) ?> available</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($availableAssets)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-circle me-2"></i>
                                    No vehicles currently available at this location.
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($availableAssets as $asset): ?>
                                        <div class="col-md-6 mb-3">
                                            <div class="card h-100 border-2 vehicle-card" style="cursor: pointer;" onclick="selectVehicle(<?= $asset['id'] ?>, this)">
                                                <div class="card-body">
                                                    <div class="form-check">
                                                        <input type="radio" class="form-check-input" name="asset_id" id="asset_<?= $asset['id'] ?>" value="<?= $asset['id'] ?>" required>
                                                        <label class="form-check-label w-100" for="asset_<?= $asset['id'] ?>">
                                                            <h6 class="mb-1"><?= h($asset['name']) ?></h6>
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

                    <!-- Step 3: Select Date/Time -->
                    <div class="card mb-4">
                        <div class="card-header bg-warning text-dark">
                            <h5 class="mb-0"><i class="bi bi-calendar me-2"></i>Step 3: Select Date & Time (Estimated)</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-3">
                                <i class="bi bi-info-circle"></i> These are your estimated pickup/return times. Actual times will be recorded during checkout/checkin.
                            </p>
                            <div class="row">
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Pickup Date</strong> <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= $_POST['start_date'] ?? date('Y-m-d') ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Pickup Time</strong> <span class="text-danger">*</span></label>
                                    <input type="time" name="start_time" class="form-control" required value="<?= $_POST['start_time'] ?? '08:00' ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label"><strong>Return Date</strong> <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= $_POST['end_date'] ?? date('Y-m-d') ?>">
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

                    <?php if (!empty($availableAssets)): ?>
                        <div class="card mb-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <a href="my_bookings.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Cancel</a>
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
<script>
function selectVehicle(assetId, card) {
    document.querySelectorAll('.vehicle-card').forEach(c => c.classList.remove('border-primary', 'bg-light'));
    card.classList.add('border-primary', 'bg-light');
    document.getElementById('asset_' + assetId).checked = true;
}
</script>
<?php layout_footer(); ?>
</body>
</html>
