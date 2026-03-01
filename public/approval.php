<?php

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';

// CSRF Protection
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/email_service.php';

$active = basename($_SERVER['PHP_SELF']);
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isStaff) {
    header('Location: index');
    exit;
}

$error = '';
$success = '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reservationId = (int)($_POST['reservation_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($reservationId > 0 && in_array($action, ['approve', 'reject'])) {
        $stmt = $pdo->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reservation && $reservation['approval_status'] === 'pending_approval') {
            $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
            $userEmail = $currentUser['email'] ?? '';

            

if ($action === 'approve') {
                $stmt = $pdo->prepare("UPDATE reservations SET approval_status = 'approved', status = 'pending',
                    approved_by_name = ?, approved_by_email = ?, approved_at = '" . date('Y-m-d H:i:s') . "' WHERE id = ?");
                $stmt->execute([$userName, $userEmail, $reservationId]);

		$stmt = $pdo->prepare("INSERT INTO approval_history (reservation_id, action, actor_name, actor_email, notes)
                    VALUES (?, 'approved', ?, ?, ?)");
                $stmt->execute([$reservationId, $userName, $userEmail, $notes ?: 'Reservation approved']);


                
                // Update asset status to VEH-Reserved in Snipe-IT
                if (!empty($reservation['asset_id'])) {
                    update_asset_status($reservation['asset_id'], STATUS_VEH_RESERVED);
               }
		// Note: Don't change Snipe-IT status on approval
                // The reservation system tracks reserved state
                // Status will change to VEH-In Service on actual checkout
                
// Send approval email
                $emailService = get_email_service($pdo);
                $emailService->notifyApproved($reservation, $userName);               

 $success = "Reservation #{$reservationId} has been approved.";






            } else {
                $stmt = $pdo->prepare("UPDATE reservations SET approval_status = 'rejected', status = 'cancelled',
                    approved_by_name = ?, approved_by_email = ?, approved_at = '" . date('Y-m-d H:i:s') . "' WHERE id = ?");
                $stmt->execute([$userName, $userEmail, $reservationId]);

                
		$stmt = $pdo->prepare("INSERT INTO approval_history (reservation_id, action, performed_by_name, performed_by_email, notes)
                    VALUES (?, 'rejected', ?, ?, ?)");
		$success = "Reservation #{$reservationId} has been rejected.";


            }
        } else {
            $error = 'Reservation not found or already processed.';
        }
    }
}

// Get pending reservations
$stmt = $pdo->query("SELECT r.*, ah.notes as submission_notes FROM reservations r
    LEFT JOIN approval_history ah ON ah.reservation_id = r.id AND ah.action = 'submitted'
    WHERE r.approval_status = 'pending_approval' ORDER BY r.created_at ASC");
$pendingReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recently processed
$stmt = $pdo->query("SELECT * FROM reservations WHERE approval_status IN ('approved', 'rejected', 'auto_approved')
    AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY approved_at DESC LIMIT 50");
$recentReservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reservation Approvals</title>
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
<h1>Reservation Approvals</h1>
        <p class="text-muted">Approve or reject vehicle reservation requests</p>
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


        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle me-2"></i><?= h($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Pending Approvals -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-hourglass-split me-2"></i>Pending Approvals
                    <?php if (count($pendingReservations) > 0): ?>
                        <span class="badge bg-dark ms-2"><?= count($pendingReservations) ?></span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($pendingReservations)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-circle" style="font-size: 3rem;"></i>
                        <p class="mt-2">No pending reservations to approve.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr><th>ID</th><th>Requested By</th><th>Vehicle</th><th>Date/Time</th><th>Submitted</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pendingReservations as $res): ?>
                                    <tr>
                                        <td><strong>#<?= $res['id'] ?></strong></td>
                                        <td><strong><?= h($res['user_name']) ?></strong><br><small class="text-muted"><?= h($res['user_email']) ?></small></td>
                                        <td><?= h($res['asset_name_cache'] ?: 'N/A') ?></td>
                                        <td>
                                            <i class="bi bi-calendar me-1"></i><?= date('M j, Y', strtotime($res['start_datetime'])) ?><br>
                                            <small><?= date('g:i A', strtotime($res['start_datetime'])) ?> - <?= date('g:i A', strtotime($res['end_datetime'])) ?></small>
                                        </td>
                                        <td><small><?= date('M j, g:i A', strtotime($res['created_at'])) ?></small></td>
                                        <td>
                                            <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#approveModal<?= $res['id'] ?>">
                                                <i class="bi bi-check-lg"></i> Approve
                                            </button>
                                            <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal<?= $res['id'] ?>">
                                                <i class="bi bi-x-lg"></i> Reject
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Modals rendered outside table for proper z-index -->
                    <?php foreach ($pendingReservations as $res): ?>
                        <!-- Approve Modal -->
                        <div class="modal fade" id="approveModal<?= $res['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="reservation_id" value="<?= $res['id'] ?>">
                                        <div class="modal-header bg-success text-white">
                                            <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Approve Reservation</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Approve reservation for <strong><?= h($res['user_name']) ?></strong>?</p>
                                            <p><strong>Vehicle:</strong> <?= h($res['asset_name_cache'] ?: 'N/A') ?></p>
                                            <div class="mb-3">
                                                <label class="form-label">Notes (optional)</label>
                                                <textarea name="notes" class="form-control" rows="2"></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Approve</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Reject Modal -->
                        <div class="modal fade" id="rejectModal<?= $res['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reject">
                                        <input type="hidden" name="reservation_id" value="<?= $res['id'] ?>">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Reservation</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Reject reservation for <strong><?= h($res['user_name']) ?></strong>?</p>
                                            <div class="mb-3">
                                                <label class="form-label">Reason for rejection *</label>
                                                <textarea name="notes" class="form-control" rows="2" required></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" class="btn btn-danger"><i class="bi bi-x-lg me-1"></i>Reject</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recently Processed -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recently Processed (Last 7 Days)</h5></div>
            <div class="card-body">
                <?php if (empty($recentReservations)): ?>
                    <p class="text-muted text-center py-3">No recently processed reservations.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead><tr><th>ID</th><th>User</th><th>Vehicle</th><th>Status</th><th>Processed By</th><th>Processed At</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentReservations as $res): ?>
                                    <tr>
                                        <td>#<?= $res['id'] ?></td>
                                        <td><?= h($res['user_name']) ?></td>
                                        <td><?= h($res['asset_name_cache'] ?: 'N/A') ?></td>
					<td>
                                            <?php if ($res['approval_status'] === 'approved'): ?>
                                                <span class="badge bg-success">Approved</span>
                                            <?php elseif ($res['approval_status'] === 'auto_approved'): ?>                                                <span class="badge bg-info">Auto-Approved</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Rejected</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h($res['approved_by_name'] ?: 'System') ?></td>
                                        <td><?= $res['approved_at'] ? date('M j, g:i A', strtotime($res['approved_at'])) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
</div><!-- page-shell -->
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Move modals to body to fix stacking context issues
document.querySelectorAll(".modal").forEach(function(modal) {
    document.body.appendChild(modal);
});
</script>
<?php layout_footer(); ?>
