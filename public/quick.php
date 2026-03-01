<?php
/**
 * Quick Checkout/Checkin via QR Code
 * Detects if user has reservation and shows appropriate action
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'quick.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

$error = '';
$asset = null;
$reservation = null;
$action = null;

// Get asset by ID or tag
$assetId = isset($_GET['asset_id']) ? (int)$_GET['asset_id'] : 0;
$assetTag = isset($_GET['tag']) ? trim($_GET['tag']) : '';

// Extract asset_id from Snipe-IT URL if provided
$snipeitUrl = isset($_GET['url']) ? trim($_GET['url']) : '';
if ($snipeitUrl && preg_match('/\/hardware\/(\d+)/', $snipeitUrl, $matches)) {
    $assetId = (int)$matches[1];
}

// Get current user info
$userEmail = $currentUser['email'] ?? '';
$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

if ($assetId > 0) {
    // Fetch asset from Snipe-IT
    $asset = get_asset_with_custom_fields($assetId);
    
    if (!$asset) {
        $error = 'Vehicle not found.';
    }
} elseif ($assetTag) {
    // Search by tag
    $assets = get_requestable_assets(100, null);
    foreach ($assets as $a) {
        if (strcasecmp($a['asset_tag'], $assetTag) === 0) {
            $asset = get_asset_with_custom_fields($a['id']);
            $assetId = $a['id'];
            break;
        }
    }
    if (!$asset) {
        $error = 'Vehicle with tag "' . htmlspecialchars($assetTag) . '" not found.';
    }
}

if ($asset && !$error) {
    // Check for user's reservation for this asset
    $stmt = $pdo->prepare("
        SELECT * FROM reservations 
        WHERE asset_id = ? 
        AND user_email = ?
        AND status IN ('pending', 'confirmed')
        AND approval_status IN ('approved', 'auto_approved')
        ORDER BY start_datetime ASC
        LIMIT 1
    ");
    $stmt->execute([$assetId, $userEmail]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reservation) {
        if ($reservation['status'] === 'pending') {
            $action = 'checkout';
        } elseif ($reservation['status'] === 'confirmed') {
            $action = 'checkin';
        }
    } else {
        // Check if vehicle is checked out to someone else
        $statusId = $asset['status_label']['id'] ?? 0;
        if ($statusId == STATUS_VEH_AVAILABLE) {
            $action = 'reserve';
        } elseif ($statusId == STATUS_VEH_RESERVED) {
            // Check if reserved by current user
            $stmt = $pdo->prepare("
                SELECT * FROM reservations 
                WHERE asset_id = ? 
                AND user_email = ?
                AND status = 'pending'
                AND approval_status IN ('approved', 'auto_approved')
                LIMIT 1
            ");
            $stmt->execute([$assetId, $userEmail]);
            $myReservation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($myReservation) {
                $reservation = $myReservation;
                $action = 'checkout';
            } else {
                $action = 'reserved_other';
            }
        } elseif ($statusId == STATUS_VEH_IN_SERVICE) {
            // Check if checked out to current user
            $assignedTo = $asset['assigned_to']['username'] ?? '';
            $assignedEmail = $asset['assigned_to']['email'] ?? '';
            
            if (strcasecmp($assignedEmail, $userEmail) === 0) {
                // Find the reservation
                $stmt = $pdo->prepare("
                    SELECT * FROM reservations 
                    WHERE asset_id = ? 
                    AND user_email = ?
                    AND status = 'confirmed'
                    LIMIT 1
                ");
                $stmt->execute([$assetId, $userEmail]);
                $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
                $action = 'checkin';
            } else {
                $action = 'in_use_other';
            }
        } elseif ($statusId == STATUS_VEH_OUT_OF_SERVICE) {
            $action = 'out_of_service';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quick Action</title>
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
            <h1>Quick Action</h1>
            <p class="text-muted">Scan QR code or enter vehicle info</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                
                <?php if (!$assetId && !$assetTag): ?>
                    <!-- No asset specified - show search form -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-search me-2"></i>Find Vehicle</h5>
                        </div>
                        <div class="card-body">
                            <form method="get" class="row g-3">
                                <div class="col-12">
                                    <label class="form-label"><strong>Asset Tag</strong></label>
                                    <input type="text" name="tag" class="form-control form-control-lg" 
                                           placeholder="e.g., BPTR-VEH-001" autofocus>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary btn-lg w-100">
                                        <i class="bi bi-search me-2"></i>Find Vehicle
                                    </button>
                                </div>
                            </form>
                            <hr>
                            <div class="text-center">
                                <a href="scan" class="btn btn-outline-secondary btn-lg">
                                    <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code
                                </a>
                            </div>
                        </div>
                    </div>
                
                <?php elseif ($error): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                    </div>
                    <a href="quick" class="btn btn-outline-primary">
                        <i class="bi bi-arrow-left me-2"></i>Try Again
                    </a>
                
                <?php elseif ($asset): ?>
                    <!-- Vehicle found - show info and action -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-truck me-2"></i>Vehicle Found</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 text-center mb-3">
                                    <i class="bi bi-truck" style="font-size: 4rem; color: #6c757d;"></i>
                                </div>
                                <div class="col-md-9">
                                    <h4><?= h($asset['name']) ?></h4>
                                    <p class="mb-1"><strong>Tag:</strong> <?= h($asset['asset_tag']) ?></p>
                                    <p class="mb-1"><strong>Model:</strong> <?= h($asset['model']['name'] ?? 'N/A') ?></p>
                                    <p class="mb-1"><strong>Plate:</strong> <?= h($asset['custom_fields']['License Plate']['value'] ?? 'N/A') ?></p>
                                    <p class="mb-1">
                                        <strong>Status:</strong> 
                                        <span class="badge bg-<?= $asset['status_label']['status_meta'] === 'deployable' ? 'success' : ($asset['status_label']['status_meta'] === 'pending' ? 'warning' : 'danger') ?>">
                                            <?= h($asset['status_label']['name'] ?? 'Unknown') ?>
                                        </span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($action === 'checkout'): ?>
                        <div class="card border-success mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-box-arrow-right text-success" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Ready for Checkout</h4>
                                <p class="text-muted">You have an approved reservation for this vehicle.</p>
                                <?php if ($reservation): ?>
                                    <p><strong>Reservation #<?= $reservation['id'] ?></strong><br>
                                    <?= date('M j, Y g:i A', strtotime($reservation['start_datetime'])) ?> - 
                                    <?= date('M j, Y g:i A', strtotime($reservation['end_datetime'])) ?></p>
                                <?php endif; ?>
                                <a href="vehicle_checkout?reservation_id=<?= $reservation['id'] ?>" class="btn btn-success btn-lg">
                                    <i class="bi bi-check-circle me-2"></i>Proceed to Checkout
                                </a>
                            </div>
                        </div>

                    <?php elseif ($action === 'checkin'): ?>
                        <div class="card border-info mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-box-arrow-in-left text-info" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Ready for Return</h4>
                                <p class="text-muted">This vehicle is currently checked out to you.</p>
                                <?php if ($reservation): ?>
                                    <p><strong>Reservation #<?= $reservation['id'] ?></strong><br>
                                    Return by: <?= date('M j, Y g:i A', strtotime($reservation['end_datetime'])) ?></p>
                                <?php endif; ?>
                                <a href="vehicle_checkin?reservation_id=<?= $reservation['id'] ?>" class="btn btn-info btn-lg text-white">
                                    <i class="bi bi-box-arrow-in-left me-2"></i>Proceed to Return
                                </a>
                            </div>
                        </div>

                    <?php elseif ($action === 'reserve'): ?>
                        <div class="card border-primary mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-calendar-plus text-primary" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Vehicle Available</h4>
                                <p class="text-muted">This vehicle is available. Would you like to reserve it?</p>
                                <a href="vehicle_reserve?asset_id=<?= $assetId ?>" class="btn btn-primary btn-lg">
                                    <i class="bi bi-calendar-plus me-2"></i>Reserve This Vehicle
                                </a>
                            </div>
                        </div>

                    <?php elseif ($action === 'reserved_other'): ?>
                        <div class="card border-warning mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-clock text-warning" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Vehicle Reserved</h4>
                                <p class="text-muted">This vehicle is reserved by another user.</p>
                                <a href="vehicle_catalogue" class="btn btn-outline-primary btn-lg">
                                    <i class="bi bi-search me-2"></i>Find Another Vehicle
                                </a>
                            </div>
                        </div>

                    <?php elseif ($action === 'in_use_other'): ?>
                        <div class="card border-secondary mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-person-fill text-secondary" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Vehicle In Use</h4>
                                <p class="text-muted">This vehicle is currently checked out to another user.</p>
                                <?php if (isset($asset['assigned_to']['name'])): ?>
                                    <p><strong>Assigned to:</strong> <?= h($asset['assigned_to']['name']) ?></p>
                                <?php endif; ?>
                                <a href="vehicle_catalogue" class="btn btn-outline-primary btn-lg">
                                    <i class="bi bi-search me-2"></i>Find Another Vehicle
                                </a>
                            </div>
                        </div>

                    <?php elseif ($action === 'out_of_service'): ?>
                        <div class="card border-danger mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-tools text-danger" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">Out of Service</h4>
                                <p class="text-muted">This vehicle is currently out of service for maintenance.</p>
                                <a href="vehicle_catalogue" class="btn btn-outline-primary btn-lg">
                                    <i class="bi bi-search me-2"></i>Find Another Vehicle
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="card mb-4">
                            <div class="card-body text-center py-4">
                                <i class="bi bi-question-circle text-muted" style="font-size: 3rem;"></i>
                                <h4 class="mt-3">No Action Available</h4>
                                <p class="text-muted">You don't have a reservation for this vehicle.</p>
                                <a href="vehicle_reserve" class="btn btn-primary btn-lg">
                                    <i class="bi bi-calendar-plus me-2"></i>Book a Vehicle
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="text-center">
                        <a href="quick" class="btn btn-outline-secondary">
                            <i class="bi bi-qr-code-scan me-2"></i>Scan Another Vehicle
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
