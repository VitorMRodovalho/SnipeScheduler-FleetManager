<?php
/**
 * Fleet Dashboard
 * Overview of fleet status, reservations, and alerts
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';

$active = 'dashboard.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

// Get all fleet vehicles
$allAssets = get_requestable_assets(100, null);
$assetList = is_array($allAssets) ? $allAssets : [];

// Count by status
$statusCounts = [
    'available' => 0,
    'reserved' => 0,
    'in_service' => 0,
    'out_of_service' => 0,
    'total' => count($assetList)
];

foreach ($assetList as $asset) {
    $statusId = $asset['status_label']['id'] ?? 0;
    switch ($statusId) {
        case STATUS_VEH_AVAILABLE:
            $statusCounts['available']++;
            break;
        case STATUS_VEH_RESERVED:
            $statusCounts['reserved']++;
            break;
        case STATUS_VEH_IN_SERVICE:
            $statusCounts['in_service']++;
            break;
        case STATUS_VEH_OUT_OF_SERVICE:
            $statusCounts['out_of_service']++;
            break;
    }
}

// Today's date range
$todayStart = date('Y-m-d 00:00:00');
$todayEnd = date('Y-m-d 23:59:59');

// Get today's pickups (approved, pending checkout)
$stmt = $pdo->prepare("
    SELECT r.*, 
           TIMESTAMPDIFF(MINUTE, NOW(), r.start_datetime) as minutes_until
    FROM reservations r
    WHERE r.status = 'pending'
    AND r.approval_status IN ('approved', 'auto_approved')
    AND DATE(r.start_datetime) = CURDATE()
    ORDER BY r.start_datetime ASC
");
$stmt->execute();
$todayPickups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get today's returns (checked out, due today)
$stmt = $pdo->prepare("
    SELECT r.*,
           TIMESTAMPDIFF(MINUTE, r.end_datetime, NOW()) as minutes_overdue
    FROM reservations r
    WHERE r.status = 'confirmed'
    AND DATE(r.end_datetime) = CURDATE()
    ORDER BY r.end_datetime ASC
");
$stmt->execute();
$todayReturns = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overdue vehicles
$stmt = $pdo->prepare("
    SELECT r.*,
           TIMESTAMPDIFF(MINUTE, r.end_datetime, NOW()) as minutes_overdue
    FROM reservations r
    WHERE r.status = 'confirmed'
    AND r.end_datetime < NOW()
    ORDER BY r.end_datetime ASC
");
$stmt->execute();
$overdueList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending approvals
$stmt = $pdo->prepare("
    SELECT r.*
    FROM reservations r
    WHERE r.approval_status = 'pending_approval'
    ORDER BY r.created_at ASC
");
$stmt->execute();
$pendingApprovals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get vehicles needing maintenance
$stmt = $pdo->prepare("
    SELECT r.*, r.maintenance_notes
    FROM reservations r
    WHERE r.status = 'maintenance_required'
    ORDER BY r.updated_at DESC
    LIMIT 10
");
$stmt->execute();
$maintenanceList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent activity (last 7 days)
$stmt = $pdo->prepare("
    SELECT ah.*, r.asset_name_cache, r.user_name as requester_name
    FROM approval_history ah
    JOIN reservations r ON ah.reservation_id = r.id
    WHERE ah.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    ORDER BY ah.created_at DESC
    LIMIT 15
");
$stmt->execute();
$recentActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Weekly stats
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END) as missed,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM reservations
    WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->execute();
$weeklyStats = $stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fleet Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= layout_theme_styles() ?>
    <style>
        .stat-card {
            border-radius: 10px;
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-2px);
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
        }
        .alert-card {
            border-left: 4px solid;
        }
        .alert-card.overdue {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .alert-card.pending {
            border-left-color: #ffc107;
            background: #fffbf0;
        }
        .alert-card.maintenance {
            border-left-color: #fd7e14;
            background: #fff8f0;
        }
        .timeline-item {
            border-left: 2px solid #dee2e6;
            padding-left: 15px;
            margin-left: 10px;
            padding-bottom: 15px;
        }
        .timeline-item:last-child {
            border-left: 2px solid transparent;
        }
        .timeline-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            position: absolute;
            left: -7px;
            top: 5px;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>Fleet Dashboard</h1>
            <p class="text-muted">Overview of vehicle status and reservations</p>
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



        <!-- Fleet Status Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="card stat-card bg-success text-white h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?= $statusCounts['available'] ?></div>
                            <div>Available</div>
                        </div>
                        <i class="bi bi-check-circle stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card stat-card bg-primary text-white h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?= $statusCounts['reserved'] ?></div>
                            <div>Reserved</div>
                        </div>
                        <i class="bi bi-calendar-check stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card stat-card bg-info text-white h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?= $statusCounts['in_service'] ?></div>
                            <div>In Service</div>
                        </div>
                        <i class="bi bi-truck stat-icon"></i>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card stat-card bg-warning text-dark h-100">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <div class="stat-number"><?= $statusCounts['out_of_service'] ?></div>
                            <div>Out of Service</div>
                        </div>
                        <i class="bi bi-tools stat-icon"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alerts Row -->
        <?php if (count($overdueList) > 0 || count($pendingApprovals) > 0 || count($maintenanceList) > 0): ?>
        <div class="row g-3 mb-4">
            <?php if (count($overdueList) > 0): ?>
            <div class="col-md-4">
                <div class="card alert-card overdue h-100">
                    <div class="card-body">
                        <h6 class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Overdue Vehicles</h6>
                        <div class="stat-number text-danger"><?= count($overdueList) ?></div>
                        <a href="#overdue-section" class="btn btn-sm btn-outline-danger mt-2">View Details</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (count($pendingApprovals) > 0): ?>
            <div class="col-md-4">
                <div class="card alert-card pending h-100">
                    <div class="card-body">
                        <h6 class="text-warning"><i class="bi bi-clock me-2"></i>Pending Approvals</h6>
                        <div class="stat-number text-warning"><?= count($pendingApprovals) ?></div>
                        <a href="approval.php" class="btn btn-sm btn-outline-warning mt-2">Review Now</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (count($maintenanceList) > 0): ?>
            <div class="col-md-4">
                <div class="card alert-card maintenance h-100">
                    <div class="card-body">
                        <h6 class="text-orange"><i class="bi bi-wrench me-2"></i>Needs Maintenance</h6>
                        <div class="stat-number" style="color: #fd7e14;"><?= count($maintenanceList) ?></div>
                        <a href="#maintenance-section" class="btn btn-sm btn-outline-secondary mt-2">View Details</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- Today's Schedule -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-calendar-day me-2"></i>Today's Schedule - <?= date('l, M j') ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Pickups -->
                            <div class="col-md-6">
                                <h6 class="text-success"><i class="bi bi-box-arrow-right me-2"></i>Pickups (<?= count($todayPickups) ?>)</h6>
                                <?php if (empty($todayPickups)): ?>
                                    <p class="text-muted small">No pickups scheduled today</p>
                                <?php else: ?>
                                    <?php foreach ($todayPickups as $pickup): ?>
                                        <div class="border rounded p-2 mb-2 bg-light">
                                            <div class="d-flex justify-content-between">
                                                <strong><?= h($pickup['asset_name_cache'] ?: 'Vehicle #' . $pickup['asset_id']) ?></strong>
                                                <span class="badge bg-success"><?= date('g:i A', strtotime($pickup['start_datetime'])) ?></span>
                                            </div>
                                            <small class="text-muted"><?= h($pickup['user_name']) ?></small>
                                            <?php if ($pickup['minutes_until'] < 0): ?>
                                                <span class="badge bg-warning ms-2">Ready</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <!-- Returns -->
                            <div class="col-md-6">
                                <h6 class="text-info"><i class="bi bi-box-arrow-in-left me-2"></i>Expected Returns (<?= count($todayReturns) ?>)</h6>
                                <?php if (empty($todayReturns)): ?>
                                    <p class="text-muted small">No returns expected today</p>
                                <?php else: ?>
                                    <?php foreach ($todayReturns as $return): ?>
                                        <div class="border rounded p-2 mb-2 <?= $return['minutes_overdue'] > 0 ? 'bg-danger bg-opacity-10' : 'bg-light' ?>">
                                            <div class="d-flex justify-content-between">
                                                <strong><?= h($return['asset_name_cache'] ?: 'Vehicle #' . $return['asset_id']) ?></strong>
                                                <span class="badge <?= $return['minutes_overdue'] > 0 ? 'bg-danger' : 'bg-info' ?>">
                                                    <?= date('g:i A', strtotime($return['end_datetime'])) ?>
                                                </span>
                                            </div>
                                            <small class="text-muted"><?= h($return['user_name']) ?></small>
                                            <?php if ($return['minutes_overdue'] > 0): ?>
                                                <span class="badge bg-danger ms-2"><?= round($return['minutes_overdue']) ?> min overdue</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Overdue Vehicles -->
                <?php if (count($overdueList) > 0): ?>
                <div class="card mb-4" id="overdue-section">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Overdue Vehicles</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Vehicle</th>
                                    <th>User</th>
                                    <th>Due</th>
                                    <th>Overdue</th>
                                    <th>Contact</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($overdueList as $overdue): ?>
                                <tr>
                                    <td><strong><?= h($overdue['asset_name_cache'] ?: 'Vehicle #' . $overdue['asset_id']) ?></strong></td>
                                    <td><?= h($overdue['user_name']) ?></td>
                                    <td><?= date('M j, g:i A', strtotime($overdue['end_datetime'])) ?></td>
                                    <td><span class="badge bg-danger"><?= round($overdue['minutes_overdue']) ?> min</span></td>
                                    <td><a href="mailto:<?= h($overdue['user_email']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-envelope"></i></a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Maintenance Required -->
                <?php if (count($maintenanceList) > 0): ?>
                <div class="card mb-4" id="maintenance-section">
                    <div class="card-header" style="background: #fd7e14; color: white;">
                        <h5 class="mb-0"><i class="bi bi-wrench me-2"></i>Maintenance Required</h5>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Reported By</th>
                                    <th>Issue</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($maintenanceList as $maint): ?>
                                <tr>
                                    <td><strong><?= h($maint['asset_name_cache'] ?: 'Vehicle #' . $maint['asset_id']) ?></strong></td>
                                    <td><?= h($maint['user_name']) ?></td>
                                    <td><small><?= h(substr($maint['maintenance_notes'] ?? 'No details', 0, 50)) ?>...</small></td>
                                    <td><?= date('M j', strtotime($maint['updated_at'] ?? $maint['created_at'])) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Weekly Stats -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>This Week</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <span>Total Reservations</span>
                            <strong><?= $weeklyStats['total'] ?? 0 ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-success">Completed</span>
                            <strong class="text-success"><?= $weeklyStats['completed'] ?? 0 ?></strong>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-warning">Missed</span>
                            <strong class="text-warning"><?= $weeklyStats['missed'] ?? 0 ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-secondary">Cancelled</span>
                            <strong class="text-secondary"><?= $weeklyStats['cancelled'] ?? 0 ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-lightning me-2"></i>Quick Actions</h6>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="scan.php" class="btn btn-primary">
                                <i class="bi bi-qr-code-scan me-2"></i>Scan QR Code
                            </a>
                            <a href="vehicle_reserve.php" class="btn btn-outline-primary">
                                <i class="bi bi-calendar-plus me-2"></i>New Reservation
                            </a>
                            <?php if ($isStaff): ?>
                            <a href="approval.php" class="btn btn-outline-warning">
                                <i class="bi bi-check-square me-2"></i>Approvals (<?= count($pendingApprovals) ?>)
                            </a>
                            <a href="reservations.php" class="btn btn-outline-secondary">
                                <i class="bi bi-list-ul me-2"></i>All Reservations
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-activity me-2"></i>Recent Activity</h6>
                    </div>
                    <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                        <?php if (empty($recentActivity)): ?>
                            <p class="text-muted text-center">No recent activity</p>
                        <?php else: ?>
                            <?php foreach ($recentActivity as $activity): ?>
                                <div class="timeline-item position-relative">
                                    <?php
                                    $dotColor = 'bg-secondary';
                                    $icon = 'bi-circle';
                                    switch ($activity['action']) {
                                        case 'submitted': $dotColor = 'bg-primary'; $icon = 'bi-plus-circle'; break;
                                        case 'approved': $dotColor = 'bg-success'; $icon = 'bi-check-circle'; break;
                                        case 'auto_approved': $dotColor = 'bg-success'; $icon = 'bi-check-circle-fill'; break;
                                        case 'rejected': $dotColor = 'bg-danger'; $icon = 'bi-x-circle'; break;
                                        case 'checked_out': $dotColor = 'bg-info'; $icon = 'bi-box-arrow-right'; break;
                                        case 'checked_in': $dotColor = 'bg-info'; $icon = 'bi-box-arrow-in-left'; break;
                                        case 'missed': $dotColor = 'bg-warning'; $icon = 'bi-clock'; break;
                                    }
                                    ?>
                                    <span class="timeline-dot <?= $dotColor ?>"></span>
                                    <div class="small">
                                        <i class="bi <?= $icon ?> me-1"></i>
                                        <strong><?= ucfirst(str_replace('_', ' ', $activity['action'])) ?></strong>
                                        <br>
                                        <span class="text-muted">
                                            <?= h($activity['asset_name_cache'] ?: 'Reservation #' . $activity['reservation_id']) ?>
                                        </span>
                                        <br>
                                        <small class="text-muted">
                                            <?= date('M j, g:i A', strtotime($activity['created_at'])) ?>
                                            by <?= h($activity['actor_name'] ?: $activity['requester_name']) ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- page-shell -->
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php layout_footer(); ?>
</body>
</html>
