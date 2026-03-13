<?php
/**
 * CCPA User Data Deletion Tool
 * Super Admin only — permanently delete all data for a specific user.
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/activity_log.php';
require_once SRC_PATH . '/layout.php';

$active = 'activity_log'; // highlight Admin in nav
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;
$isSuperAdmin = !empty($currentUser['is_superadmin']);

if (!$isSuperAdmin) {
    header('Location: dashboard');
    exit;
}

// Ensure data_requests table exists
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS data_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_type ENUM('access', 'deletion', 'correction') NOT NULL,
            requester_email VARCHAR(255) NOT NULL,
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            processed_by VARCHAR(255) DEFAULT NULL,
            status ENUM('pending', 'completed', 'denied') DEFAULT 'pending',
            notes TEXT DEFAULT NULL,
            INDEX idx_email (requester_email),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (Throwable $e) {
    // Table may already exist
}

$searchEmail = trim($_GET['email'] ?? $_POST['search_email'] ?? '');
$success = '';
$error = '';
$preview = null;

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user_data') {
    csrf_check();
    $deleteEmail = trim($_POST['delete_email'] ?? '');
    $confirmEmail = trim($_POST['confirm_email'] ?? '');

    if ($deleteEmail === '' || $confirmEmail !== $deleteEmail) {
        $error = 'Email confirmation does not match. Deletion cancelled.';
    } else {
        // Log the deletion BEFORE removing data
        activity_log_event('ccpa_deletion', "All personal data permanently deleted for {$deleteEmail}", [
            'subject_type' => 'user',
            'metadata' => ['target_email' => $deleteEmail, 'requested_by' => $currentUser['email'] ?? ''],
        ]);

        $totalRecords = 0;
        $totalFiles = 0;

        // Tables to purge (email column name varies)
        $tables = [
            ['table' => 'users',                    'column' => 'email'],
            ['table' => 'reservations',             'column' => 'user_email'],
            ['table' => 'activity_log',             'column' => 'actor_email'],
            ['table' => 'inspection_responses',     'column' => 'inspector_email'],
            ['table' => 'email_queue',              'column' => 'recipient_email'],
            ['table' => 'notification_log',         'column' => 'recipient_email'],
            ['table' => 'approval_history',         'column' => 'actor_email'],
            ['table' => 'announcement_dismissals',  'column' => 'user_email'],
        ];

        // Delete inspection photos from disk first
        try {
            $photoStmt = $pdo->prepare("
                SELECT p.filename FROM inspection_photos p
                JOIN reservations r ON p.reservation_id = r.id
                WHERE r.user_email = ?
            ");
            $photoStmt->execute([$deleteEmail]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_COLUMN);
            $uploadDir = __DIR__ . '/../uploads/inspections/';
            foreach ($photos as $filename) {
                $filepath = $uploadDir . $filename;
                if (file_exists($filepath)) {
                    unlink($filepath);
                    $totalFiles++;
                }
            }
            // Delete photo records via reservation join
            $delPhotoStmt = $pdo->prepare("
                DELETE p FROM inspection_photos p
                JOIN reservations r ON p.reservation_id = r.id
                WHERE r.user_email = ?
            ");
            $delPhotoStmt->execute([$deleteEmail]);
            $totalRecords += $delPhotoStmt->rowCount();
        } catch (Throwable $e) {
            // Table may not exist
        }

        // Delete from each table
        foreach ($tables as $t) {
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$t['table']}` WHERE `{$t['column']}` = ?");
                $stmt->execute([$deleteEmail]);
                $totalRecords += $stmt->rowCount();
            } catch (Throwable $e) {
                // Table may not exist — skip
            }
        }

        // Also delete inspection_responses via reservation join
        try {
            $delIrStmt = $pdo->prepare("
                DELETE ir FROM inspection_responses ir
                JOIN reservations r ON ir.reservation_id = r.id
                WHERE r.user_email = ?
            ");
            $delIrStmt->execute([$deleteEmail]);
            $totalRecords += $delIrStmt->rowCount();
        } catch (Throwable $e) {}

        // Log to DSAR tracking
        try {
            $dsarStmt = $pdo->prepare("
                INSERT INTO data_requests (request_type, requester_email, processed_at, processed_by, status, notes)
                VALUES ('deletion', ?, NOW(), ?, 'completed', ?)
            ");
            $dsarStmt->execute([
                $deleteEmail,
                $currentUser['email'] ?? 'admin',
                "Deleted {$totalRecords} records, {$totalFiles} files"
            ]);
        } catch (Throwable $e) {}

        $success = "All data for {$deleteEmail} has been permanently deleted. {$totalRecords} database records removed, {$totalFiles} files deleted.";
        $searchEmail = ''; // Clear search
    }
}

// Build preview if searching
if ($searchEmail !== '' && $success === '') {
    $preview = ['email' => $searchEmail, 'tables' => []];

    // Users table
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['users'] = ['label' => 'Users (profile)', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['users'] = ['label' => 'Users (profile)', 'count' => 0]; }

    // Reservations
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['reservations'] = ['label' => 'Reservations', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['reservations'] = ['label' => 'Reservations', 'count' => 0]; }

    // Activity log
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE actor_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['activity_log'] = ['label' => 'Activity log entries', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['activity_log'] = ['label' => 'Activity log entries', 'count' => 0]; }

    // Inspection responses
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inspection_responses WHERE inspector_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['inspection_responses'] = ['label' => 'Inspection responses', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['inspection_responses'] = ['label' => 'Inspection responses', 'count' => 0]; }

    // Inspection photos
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt, COALESCE(SUM(p.file_size), 0) as total_size
            FROM inspection_photos p
            JOIN reservations r ON p.reservation_id = r.id
            WHERE r.user_email = ?
        ");
        $stmt->execute([$searchEmail]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $preview['tables']['inspection_photos'] = [
            'label' => 'Inspection photos',
            'count' => (int)$row['cnt'],
            'extra' => $row['cnt'] > 0 ? '(' . round($row['total_size'] / 1048576, 1) . ' MB)' : '',
        ];
    } catch (Throwable $e) { $preview['tables']['inspection_photos'] = ['label' => 'Inspection photos', 'count' => 0]; }

    // Email queue
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM email_queue WHERE recipient_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['email_queue'] = ['label' => 'Email queue entries', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['email_queue'] = ['label' => 'Email queue entries', 'count' => 0]; }

    // Notification log
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notification_log WHERE recipient_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['notification_log'] = ['label' => 'Notification log', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['notification_log'] = ['label' => 'Notification log', 'count' => 0]; }

    // Approval history
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM approval_history WHERE actor_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['approval_history'] = ['label' => 'Approval history', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['approval_history'] = ['label' => 'Approval history', 'count' => 0]; }

    // Announcement dismissals
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM announcement_dismissals WHERE user_email = ?");
        $stmt->execute([$searchEmail]);
        $preview['tables']['announcement_dismissals'] = ['label' => 'Announcement dismissals', 'count' => (int)$stmt->fetchColumn()];
    } catch (Throwable $e) { $preview['tables']['announcement_dismissals'] = ['label' => 'Announcement dismissals', 'count' => 0]; }

    $preview['total'] = array_sum(array_column($preview['tables'], 'count'));
}

// Load DSAR history
$dsarHistory = [];
try {
    $dsarStmt = $pdo->query("SELECT * FROM data_requests ORDER BY requested_at DESC LIMIT 50");
    $dsarHistory = $dsarStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Data Deletion — CCPA Compliance</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.5.1">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
<?= layout_loading_overlay() ?>
<div class="container">
    <div class="page-shell">
        <?= layout_logo_tag() ?>
        <div class="page-header">
            <h1><i class="bi bi-shield-exclamation me-2"></i>User Data Deletion</h1>
            <p class="text-muted">CCPA / DSAR compliance — permanently delete all data for a specific user</p>
        </div>
        <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
        <?= render_top_bar($currentUser, $isStaff, $isAdmin) ?>

        <ul class="nav nav-tabs reservations-subtabs mb-3">
            <li class="nav-item"><a class="nav-link" href="activity_log">Activity Log</a></li>
            <li class="nav-item"><a class="nav-link" href="settings">Settings</a></li>
            <li class="nav-item"><a class="nav-link" href="security">Security</a></li>
            <li class="nav-item"><a class="nav-link active" href="admin_data_delete">Data Compliance</a></li>
        </ul>

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
        <?php endif; ?>

        <!-- Search -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-search me-2"></i>Search User Data</h5></div>
            <div class="card-body">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">User Email Address</label>
                        <input type="email" name="email" class="form-control" value="<?= h($searchEmail) ?>" placeholder="user@example.com" required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($preview): ?>
        <!-- Data Preview -->
        <div class="card mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10">
                <h5 class="mb-0"><i class="bi bi-database me-2"></i>Data Found for <?= h($preview['email']) ?></h5>
            </div>
            <div class="card-body">
                <?php if ($preview['total'] === 0): ?>
                    <div class="alert alert-info mb-0">No data found for this email address.</div>
                <?php else: ?>
                    <table class="table table-sm mb-4">
                        <thead><tr><th>Data Category</th><th class="text-end">Records</th></tr></thead>
                        <tbody>
                        <?php foreach ($preview['tables'] as $t): ?>
                            <tr class="<?= $t['count'] > 0 ? '' : 'text-muted' ?>">
                                <td><?= h($t['label']) ?></td>
                                <td class="text-end"><?= $t['count'] ?> <?= $t['extra'] ?? '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr class="fw-bold"><td>Total</td><td class="text-end"><?= $preview['total'] ?> records</td></tr></tfoot>
                    </table>

                    <!-- Delete Button -->
                    <button type="button" class="btn btn-danger btn-lg" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="bi bi-trash3 me-2"></i>Permanently Delete All Data for <?= h($preview['email']) ?>
                    </button>

                    <!-- Confirmation Modal -->
                    <div class="modal fade" id="deleteModal" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Confirm Permanent Deletion</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_user_data">
                                    <input type="hidden" name="delete_email" value="<?= h($preview['email']) ?>">
                                    <div class="modal-body">
                                        <div class="alert alert-danger">
                                            <strong>This will permanently delete ALL data</strong> for <strong><?= h($preview['email']) ?></strong> across <?= count(array_filter($preview['tables'], fn($t) => $t['count'] > 0)) ?> tables.
                                            This action <strong>CANNOT be undone</strong>.
                                        </div>
                                        <label class="form-label">Type the user's email to confirm:</label>
                                        <input type="email" name="confirm_email" id="confirmEmailInput" class="form-control" placeholder="<?= h($preview['email']) ?>" autocomplete="off" required>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-danger" id="confirmDeleteBtn" disabled>
                                            <i class="bi bi-trash3 me-1"></i>Delete All Data
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- DSAR History -->
        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Data Subject Request History</h5></div>
            <div class="card-body p-0">
                <?php if (empty($dsarHistory)): ?>
                    <p class="text-muted text-center py-4">No data requests recorded yet.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm mb-0">
                            <thead class="table-light">
                                <tr><th>Date</th><th>Type</th><th>Email</th><th>Status</th><th>Processed By</th><th>Notes</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($dsarHistory as $req): ?>
                                <tr>
                                    <td><?= h($req['requested_at']) ?></td>
                                    <td><span class="badge bg-<?= $req['request_type'] === 'deletion' ? 'danger' : ($req['request_type'] === 'access' ? 'info' : 'warning') ?>"><?= h(ucfirst($req['request_type'])) ?></span></td>
                                    <td><?= h($req['requester_email']) ?></td>
                                    <td><span class="badge bg-<?= $req['status'] === 'completed' ? 'success' : ($req['status'] === 'denied' ? 'danger' : 'warning') ?>"><?= h(ucfirst($req['status'])) ?></span></td>
                                    <td><?= h($req['processed_by'] ?? '—') ?></td>
                                    <td class="small"><?= h($req['notes'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php layout_footer(); ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('confirmEmailInput');
    var btn = document.getElementById('confirmDeleteBtn');
    var target = <?= json_encode($preview['email'] ?? '') ?>;
    if (input && btn && target) {
        input.addEventListener('input', function() {
            btn.disabled = (this.value.trim() !== target);
        });
    }
});
</script>
</body>
</html>
