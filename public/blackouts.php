<?php
/**
 * Blackout Slots Management
 * Block specific dates/times from reservations
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/reservation_validator.php';

$active = 'blackouts.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $startDate = $_POST['start_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '00:00';
        $endDate = $_POST['end_date'] ?? '';
        $endTime = $_POST['end_time'] ?? '23:59';
        $assetId = !empty($_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
        $reason = trim($_POST['reason'] ?? '');
        
        if (empty($title)) {
            $error = 'Please enter a title for the blackout slot.';
        } elseif (empty($startDate) || empty($endDate)) {
            $error = 'Please select start and end dates.';
        } else {
            $startDatetime = $startDate . ' ' . $startTime . ':00';
            $endDatetime = $endDate . ' ' . $endTime . ':00';
            
            if (strtotime($endDatetime) <= strtotime($startDatetime)) {
                $error = 'End time must be after start time.';
            } else {
                $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
                $userEmail = $currentUser['email'] ?? '';
                
                create_blackout_slot($title, $startDatetime, $endDatetime, $assetId, $reason, $userName, $userEmail, $pdo);
                $success = 'Blackout slot created successfully.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['blackout_id'] ?? 0);
        if ($id > 0) {
            delete_blackout_slot($id, $pdo);
            $success = 'Blackout slot deleted.';
        }
    }
}

// Get all blackout slots
$blackouts = get_blackout_slots($pdo);

// Get vehicles for dropdown
$vehicles = get_requestable_assets(100, null);

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Blackout Slots</title>
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
                <h1><i class="bi bi-calendar-x me-2"></i>Blackout Slots</h1>
                <p class="text-muted">Block specific dates/times from reservations</p>
            </div>
            
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
            
            <div class="top-bar mb-3">
                <div class="top-bar-user">
                    Logged in as: <strong><?= h($userName) ?></strong>
                </div>
                <div class="top-bar-actions">
                    <a href="settings" class="btn btn-outline-secondary btn-sm me-2">
                        <i class="bi bi-arrow-left me-1"></i>Back to Settings
                    </a>
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
            
            <div class="row">
                <!-- Create New Blackout -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Create Blackout Slot</h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="create">
                                
                                <div class="mb-3">
                                    <label class="form-label">Title *</label>
                                    <input type="text" name="title" class="form-control" required 
                                        placeholder="e.g., Holiday - Office Closed">
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <label class="form-label">Start Date *</label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Start Time</label>
                                        <input type="time" name="start_time" class="form-control" value="00:00">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <label class="form-label">End Date *</label>
                                        <input type="date" name="end_date" class="form-control" required>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">End Time</label>
                                        <input type="time" name="end_time" class="form-control" value="23:59">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Apply To</label>
                                    <select name="asset_id" class="form-select">
                                        <option value="">All Vehicles</option>
                                        <?php foreach ($vehicles as $v): ?>
                                            <option value="<?= $v['id'] ?>"><?= h($v['name']) ?> [<?= h($v['asset_tag']) ?>]</option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Leave blank to block all vehicles</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Reason</label>
                                    <textarea name="reason" class="form-control" rows="2" 
                                        placeholder="Optional explanation"></textarea>
                                </div>
                                
                                <button type="submit" class="btn btn-dark w-100">
                                    <i class="bi bi-calendar-x me-1"></i>Create Blackout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Existing Blackouts -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list me-2"></i>Existing Blackout Slots</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($blackouts)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-check" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No blackout slots configured.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Title</th>
                                                <th>Start</th>
                                                <th>End</th>
                                                <th>Applies To</th>
                                                <th>Created By</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($blackouts as $b): ?>
                                                <?php 
                                                    $isPast = strtotime($b['end_datetime']) < time();
                                                    $isActive = strtotime($b['start_datetime']) <= time() && strtotime($b['end_datetime']) >= time();
                                                ?>
                                                <tr class="<?= $isPast ? 'text-muted' : '' ?>">
                                                    <td>
                                                        <strong><?= h($b['title']) ?></strong>
                                                        <?php if ($isActive): ?>
                                                            <span class="badge bg-danger ms-1">Active</span>
                                                        <?php elseif ($isPast): ?>
                                                            <span class="badge bg-secondary ms-1">Past</span>
                                                        <?php endif; ?>
                                                        <?php if ($b['reason']): ?>
                                                            <br><small class="text-muted"><?= h($b['reason']) ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= date('M j, Y', strtotime($b['start_datetime'])) ?><br>
                                                        <small><?= date('g:i A', strtotime($b['start_datetime'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?= date('M j, Y', strtotime($b['end_datetime'])) ?><br>
                                                        <small><?= date('g:i A', strtotime($b['end_datetime'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <?php if ($b['asset_id']): ?>
                                                            <?php 
                                                                $assetName = 'Vehicle #' . $b['asset_id'];
                                                                foreach ($vehicles as $v) {
                                                                    if ($v['id'] == $b['asset_id']) {
                                                                        $assetName = $v['name'];
                                                                        break;
                                                                    }
                                                                }
                                                            ?>
                                                            <span class="badge bg-info"><?= h($assetName) ?></span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark">All Vehicles</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small><?= h($b['created_by_name'] ?: 'System') ?></small><br>
                                                        <small class="text-muted"><?= date('M j', strtotime($b['created_at'])) ?></small>
                                                    </td>
                                                    <td>
                                                        <form method="post" class="d-inline" 
                                                            onsubmit="return confirm('Delete this blackout slot?');">
                                                            <?= csrf_field() ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="blackout_id" value="<?= $b['id'] ?>">
                                                            <button type="submit" class="btn btn-outline-danger btn-sm">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php layout_footer(); ?>
</body>
</html>
