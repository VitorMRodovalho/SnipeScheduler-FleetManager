<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/booking_helpers.php';
require_once SRC_PATH . '/layout.php';

function display_date(?string $isoDate): string
{
    return app_format_date($isoDate);
}

function display_datetime(?string $isoDatetime): string
{
    return app_format_datetime($isoDatetime);
}

$active        = basename($_SERVER['PHP_SELF']);
$isAdmin       = !empty($currentUser['is_admin']);
$isStaff       = !empty($currentUser['is_staff']) || $isAdmin;
$currentUserId = (string)($currentUser['id'] ?? '');

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
$tabRaw = $_GET['tab'] ?? 'reservations';
$tab = $tabRaw === 'checked_out' ? 'checked_out' : 'reservations';

// Load this user's reservations
try {
    $sql = "
        SELECT *
        FROM reservations
        WHERE user_id = :user_id
        ORDER BY id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user_id' => $currentUserId]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $reservations = [];
    $loadError = $e->getMessage();
}

$checkedOutItems = [];
$checkedOutError = '';
if ($tab === 'checked_out') {
    try {
        $email = strtolower(trim($currentUser['email'] ?? ''));
        $username = strtolower(trim($currentUser['username'] ?? ''));
        $name = strtolower(trim($userName));

        $stmt = $pdo->prepare("
            SELECT *
              FROM checked_out_asset_cache
             WHERE (assigned_to_email IS NOT NULL AND LOWER(assigned_to_email) = :email)
                OR (assigned_to_username IS NOT NULL AND LOWER(assigned_to_username) = :username)
                OR (assigned_to_name IS NOT NULL AND LOWER(assigned_to_name) = :name)
             ORDER BY
                CASE WHEN expected_checkin IS NULL OR expected_checkin = '' THEN 1 ELSE 0 END,
                expected_checkin ASC,
                last_checkout DESC
        ");
        $stmt->execute([
            ':email' => $email,
            ':username' => $username,
            ':name' => $name,
        ]);
        $checkedOutItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $checkedOutItems = [];
        $checkedOutError = $e->getMessage();
    }
}

$deletedMsg = '';
if (!empty($_GET['deleted'])) {
    $deletedMsg = 'Reservation #' . (int)$_GET['deleted'] . ' has been deleted.';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Reservations</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css?v=1.4.0">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1>My Reservations</h1>
            <div class="page-subtitle">
                View all your past, current and future reservations.
            </div>
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
                <a href="logout" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <?php if (!empty($deletedMsg)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($deletedMsg) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($loadError ?? '')): ?>
            <div class="alert alert-danger">
                Error loading your reservations: <?= htmlspecialchars($loadError) ?>
            </div>
        <?php endif; ?>

        <?php
            $reservationsUrl = 'my_bookings.php?tab=reservations';
            $checkedOutUrl = 'my_bookings.php?tab=checked_out';
        ?>
        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'reservations' ? 'active' : '' ?>"
                   href="<?= h($reservationsUrl) ?>">My Reservations</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'checked_out' ? 'active' : '' ?>"
                   href="<?= h($checkedOutUrl) ?>">My Checked Out Items</a>
            </li>
        </ul>

        <?php if ($tab === 'checked_out'): ?>
            <?php if (!empty($checkedOutError)): ?>
                <div class="alert alert-danger">
                    Error loading checked-out items: <?= htmlspecialchars($checkedOutError) ?>
                </div>
            <?php elseif (empty($checkedOutItems)): ?>
                <div class="alert alert-info">
                    You don’t have any checked-out items right now.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <thead>
                            <tr>
                                <th>Asset Tag</th>
                                <th>Name</th>
                                <th>Model</th>
                                <th>Assigned Since</th>
                                <th>Expected Check-in</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checkedOutItems as $row): ?>
                                <tr>
                                    <td><?= h($row['asset_tag'] ?? '') ?></td>
                                    <td><?= h($row['asset_name'] ?? '') ?></td>
                                    <td><?= h($row['model_name'] ?? '') ?></td>
                                    <td><?= h(display_datetime($row['last_checkout'] ?? '')) ?></td>
                                    <td><?= h(display_date($row['expected_checkin'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if (empty($reservations)): ?>
                <div class="alert alert-info">
                    You don’t have any reservations yet.
                </div>
            <?php else: ?>
                <?php foreach ($reservations as $res): ?>
                    <?php
                        $resId   = (int)$res['id'];
                        $items   = get_reservation_items_with_names($pdo, $resId);
                        $summary = build_items_summary_text($items);
                        $status  = strtolower((string)($res['status'] ?? ''));
                        $approvalStatus = $res['approval_status'] ?? '';
                        $assetId = $res['asset_id'] ?? 0;
                        $isApproved = in_array($approvalStatus, ['approved', 'auto_approved']);
                        
                        // Extract actual times from form data
                        $checkoutData = json_decode($res['checkout_form_data'] ?? '{}', true) ?: [];
                        $checkinData = json_decode($res['checkin_form_data'] ?? '{}', true) ?: [];
                        $actualCheckout = '';
                        $actualCheckin = '';
                        if (!empty($checkoutData['checkout_date']) && !empty($checkoutData['checkout_time'])) {
                            $actualCheckout = date('M j, Y', strtotime($checkoutData['checkout_date'])) . ' ' . $checkoutData['checkout_time'];
                        }
                        if (!empty($checkinData['return_date']) && !empty($checkinData['return_time'])) {
                            $actualCheckin = date('M j, Y', strtotime($checkinData['return_date'])) . ' ' . $checkinData['return_time'];
                        }
                        
                        // Pipeline stages
                        $stages = [
                            'booked' => ['label' => 'Booked', 'icon' => 'bi-calendar-check', 'color' => '#6c757d'],
                            'approved' => ['label' => 'Approved', 'icon' => 'bi-check-circle', 'color' => '#0dcaf0'],
                            'checked_out' => ['label' => 'Checked Out', 'icon' => 'bi-box-arrow-right', 'color' => '#ffc107'],
                            'returned' => ['label' => 'Returned', 'icon' => 'bi-box-arrow-in-left', 'color' => '#198754'],
                        ];
                        
                        if ($status === 'completed' || $status === 'maintenance_required') {
                            $activeStage = 'returned';
                        } elseif ($status === 'confirmed') {
                            $activeStage = 'checked_out';
                        } elseif ($isApproved) {
                            $activeStage = 'approved';
                        } else {
                            $activeStage = 'booked';
                        }
                        
                        $isOverdue = ($status === 'confirmed' && strtotime($res['end_datetime']) < time());
                        
                        $badgeColor = match($status) {
                            'completed' => 'success',
                            'confirmed' => ($isOverdue ? 'danger' : 'warning'),
                            'cancelled' => 'secondary',
                            'missed' => 'dark',
                            'maintenance_required' => 'danger',
                            default => 'primary',
                        };
                    ?>
                    <div class="card mb-3 <?= $isOverdue ? 'border-danger border-2' : '' ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h5 class="card-title mb-0">
                                    Reservation #<?= $resId ?>
                                    <?php if (!empty($res['asset_name_cache'])): ?>
                                        <small class="text-muted ms-2">— <?= h($res['asset_name_cache']) ?></small>
                                    <?php endif; ?>
                                </h5>
                                <span class="badge bg-<?= $badgeColor ?> px-3 py-2">
                                    <?= $isOverdue ? '⚠ OVERDUE' : ucfirst(str_replace('_', ' ', $status)) ?>
                                </span>
                            </div>
                            
                            <?php if ($status !== 'cancelled' && $status !== 'missed'): ?>
                            <div class="d-flex mb-3" style="font-size:0.8rem; border-radius:6px; overflow:hidden;">
                                <?php 
                                $reachedActive = false;
                                foreach ($stages as $key => $stage):
                                    $isActive = ($key === $activeStage);
                                    $isPast = !$reachedActive;
                                    if ($isActive) { $isPast = true; $reachedActive = true; }
                                    $bg = ($isPast || $isActive) ? $stage['color'] : '#e9ecef';
                                    $fg = ($isPast || $isActive) ? 'white' : '#6c757d';
                                    $fw = $isActive ? 'bold' : 'normal';
                                ?>
                                <div class="flex-fill text-center py-2" style="background:<?= $bg ?>; color:<?= $fg ?>; font-weight:<?= $fw ?>;">
                                    <i class="bi <?= $stage['icon'] ?> me-1"></i><?= $stage['label'] ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <div class="p-2 bg-light rounded">
                                        <small class="text-muted fw-bold d-block mb-1"><i class="bi bi-calendar3 me-1"></i>Scheduled</small>
                                        <div><strong>Start:</strong> <?= display_datetime($res['start_datetime'] ?? '') ?></div>
                                        <div><strong>End:</strong> <?= display_datetime($res['end_datetime'] ?? '') ?></div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="p-2 bg-light rounded">
                                        <small class="text-muted fw-bold d-block mb-1"><i class="bi bi-clock-history me-1"></i>Actual</small>
                                        <div><strong>Checkout:</strong> <?= $actualCheckout ?: '<span class="text-muted">—</span>' ?></div>
                                        <div><strong>Return:</strong> <?= $actualCheckin ?: '<span class="text-muted">—</span>' ?></div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <?php if ($isApproved && $assetId && $status === 'confirmed'): ?>
                                    <a href="vehicle_checkin?reservation_id=<?= $resId ?>" class="btn btn-primary btn-sm">
                                        <i class="bi bi-box-arrow-in-left me-1"></i>Return Vehicle
                                    </a>
                                <?php elseif ($isApproved && $assetId && !in_array($status, ['completed', 'confirmed', 'cancelled', 'missed', 'maintenance_required'])): ?>
                                    <a href="vehicle_checkout?reservation_id=<?= $resId ?>" class="btn btn-success btn-sm">
                                        <i class="bi bi-box-arrow-right me-1"></i>Checkout Vehicle
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($status === 'pending'): ?>
                                    <a href="reservation_edit?id=<?= $resId ?>&from=my_bookings" class="btn btn-outline-primary btn-sm">Edit</a>
                                <?php endif; ?>
                                
                                <?php if (!in_array($status, ['completed', 'confirmed', 'maintenance_required'])): ?>
                                <form method="post" action="delete_reservation" class="d-inline"
                                      onsubmit="return confirm('Delete this reservation? This cannot be undone.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="reservation_id" value="<?= $resId ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php endif; ?>
        <?php endif; ?>

    </div>
</div>
<?php layout_footer(); ?>
</body>
</html>
