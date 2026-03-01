<?php
/**
 * Announcements Admin
 * Manage system-wide announcements
 */
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    csrf_check();
}
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/layout.php';
require_once SRC_PATH . '/announcements.php';

$active = 'announcements.php';
$isAdmin = !empty($currentUser['is_admin']);
$isStaff = !empty($currentUser['is_staff']) || $isAdmin;

if (!$isAdmin) {
    header('Location: dashboard');
    exit;
}

$success = '';
$error = '';
$editAnnouncement = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));
    $userEmail = $currentUser['email'] ?? '';
    
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = $_POST['type'] ?? 'info';
        $startDate = $_POST['start_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '00:00';
        $endDate = $_POST['end_date'] ?? '';
        $endTime = $_POST['end_time'] ?? '23:59';
        $showOnce = isset($_POST['show_once']);
        
        if (empty($title)) {
            $error = 'Please enter a title.';
        } elseif (empty($content)) {
            $error = 'Please enter announcement content.';
        } elseif (empty($startDate) || empty($endDate)) {
            $error = 'Please select start and end dates.';
        } else {
            $startDatetime = $startDate . ' ' . $startTime . ':00';
            $endDatetime = $endDate . ' ' . $endTime . ':00';
            
            if (strtotime($endDatetime) <= strtotime($startDatetime)) {
                $error = 'End date must be after start date.';
            } else {
                create_announcement($title, $content, $type, $startDatetime, $endDatetime, $showOnce, $userName, $userEmail, $pdo);
                $success = 'Announcement created successfully.';
            }
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $type = $_POST['type'] ?? 'info';
        $startDate = $_POST['start_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '00:00';
        $endDate = $_POST['end_date'] ?? '';
        $endTime = $_POST['end_time'] ?? '23:59';
        $showOnce = isset($_POST['show_once']);
        $isActive = isset($_POST['is_active']);
        
        if ($id > 0 && !empty($title) && !empty($content)) {
            $startDatetime = $startDate . ' ' . $startTime . ':00';
            $endDatetime = $endDate . ' ' . $endTime . ':00';
            update_announcement($id, $title, $content, $type, $startDatetime, $endDatetime, $showOnce, $isActive, $pdo);
            $success = 'Announcement updated successfully.';
        } else {
            $error = 'Invalid announcement data.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['announcement_id'] ?? 0);
        if ($id > 0) {
            delete_announcement($id, $pdo);
            $success = 'Announcement deleted.';
        }
    }
}

// Check if editing
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editAnnouncement = get_announcement($editId, $pdo);
}

// Get all announcements
$announcements = get_all_announcements($pdo);

$userName = trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''));

$typeOptions = [
    'info' => ['label' => 'Info (Blue)', 'icon' => 'bi-info-circle-fill', 'color' => 'primary'],
    'success' => ['label' => 'Success (Green)', 'icon' => 'bi-check-circle-fill', 'color' => 'success'],
    'warning' => ['label' => 'Warning (Yellow)', 'icon' => 'bi-exclamation-triangle-fill', 'color' => 'warning'],
    'danger' => ['label' => 'Urgent (Red)', 'icon' => 'bi-x-octagon-fill', 'color' => 'danger'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Announcements</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/style.css?v=1.3.2">
    <link rel="stylesheet" href="/booking/css/mobile.css">
    <?= layout_theme_styles() ?>
</head>
<body class="p-4">
    <div class="container">
        <div class="page-shell">
            <?= layout_logo_tag() ?>
            <div class="page-header">
                <h1><i class="bi bi-megaphone me-2"></i>Announcements</h1>
                <p class="text-muted">Display notices to users when they log in</p>
            </div>
            
            <?= layout_render_nav($active, $isStaff, $isAdmin) ?>
            
            <ul class="nav nav-tabs reservations-subtabs mb-3">
                <li class="nav-item">
                    <a class="nav-link" href="vehicles">Vehicles</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="users">Users</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="activity_log">Activity Log</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="notifications">Notifications</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="announcements">Announcements</a>
                </li>
                <?php if (!empty($currentUser['is_super_admin'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="security">Security</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="settings">Settings</a>
                </li>
                <?php endif; ?>
            </ul>
            
            <div class="top-bar mb-3">
                <div class="top-bar-user">
                    <span class="text-muted">Logged in as:</span>
                    <strong><?= h($userName) ?></strong>
                    <span class="text-muted">(<?= h($currentUser['email'] ?? '') ?>)</span>
                    <?php if ($isAdmin): ?>
                        <span class="badge bg-danger ms-2">Admin</span>
                    <?php elseif ($isStaff): ?>
                        <span class="badge bg-primary ms-2">Staff</span>
                    <?php endif; ?>
                </div>
                <div class="top-bar-actions">
                    <a href="logout" class="btn btn-outline-secondary btn-sm">Log out</a>
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
                <!-- Create/Edit Form -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header bg-dark text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-<?= $editAnnouncement ? 'pencil' : 'plus-circle' ?> me-2"></i>
                                <?= $editAnnouncement ? 'Edit Announcement' : 'Create Announcement' ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="<?= $editAnnouncement ? 'update' : 'create' ?>">
                                <?php if ($editAnnouncement): ?>
                                    <input type="hidden" name="announcement_id" value="<?= $editAnnouncement['id'] ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label class="form-label">Title *</label>
                                    <input type="text" name="title" class="form-control" required
                                        value="<?= h($editAnnouncement['title'] ?? '') ?>"
                                        placeholder="e.g., Holiday Schedule Change">
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Message *</label>
                                    <textarea name="content" class="form-control" rows="4" required
                                        placeholder="Enter your announcement message..."><?= h($editAnnouncement['content'] ?? '') ?></textarea>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Type</label>
                                    <select name="type" class="form-select">
                                        <?php foreach ($typeOptions as $key => $opt): ?>
                                            <option value="<?= $key ?>" 
                                                <?= ($editAnnouncement['type'] ?? 'info') === $key ? 'selected' : '' ?>>
                                                <?= $opt['label'] ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <label class="form-label">Start Date *</label>
                                        <input type="date" name="start_date" class="form-control" required
                                            value="<?= $editAnnouncement ? date('Y-m-d', strtotime($editAnnouncement['start_datetime'])) : date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">Start Time</label>
                                        <input type="time" name="start_time" class="form-control"
                                            value="<?= $editAnnouncement ? date('H:i', strtotime($editAnnouncement['start_datetime'])) : '00:00' ?>">
                                    </div>
                                </div>
                                
                                <div class="row mb-3">
                                    <div class="col-6">
                                        <label class="form-label">End Date *</label>
                                        <input type="date" name="end_date" class="form-control" required
                                            value="<?= $editAnnouncement ? date('Y-m-d', strtotime($editAnnouncement['end_datetime'])) : date('Y-m-d', strtotime('+7 days')) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label">End Time</label>
                                        <input type="time" name="end_time" class="form-control"
                                            value="<?= $editAnnouncement ? date('H:i', strtotime($editAnnouncement['end_datetime'])) : '23:59' ?>">
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="show_once" id="show_once"
                                            <?= ($editAnnouncement['show_once'] ?? true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="show_once">
                                            Show once per user (can be dismissed)
                                        </label>
                                    </div>
                                </div>
                                
                                <?php if ($editAnnouncement): ?>
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active"
                                            <?= $editAnnouncement['is_active'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="is_active">
                                            Active
                                        </label>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="d-flex gap-2">
                                    <button type="submit" class="btn btn-dark flex-grow-1">
                                        <i class="bi bi-<?= $editAnnouncement ? 'check' : 'megaphone' ?> me-1"></i>
                                        <?= $editAnnouncement ? 'Update' : 'Create' ?> Announcement
                                    </button>
                                    <?php if ($editAnnouncement): ?>
                                        <a href="announcements" class="btn btn-outline-secondary">Cancel</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Existing Announcements -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-list me-2"></i>All Announcements</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($announcements)): ?>
                                <div class="text-center text-muted py-4">
                                    <i class="bi bi-megaphone" style="font-size: 3rem;"></i>
                                    <p class="mt-2">No announcements yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($announcements as $a): ?>
                                    <?php 
                                        $now = time();
                                        $start = strtotime($a['start_datetime']);
                                        $end = strtotime($a['end_datetime']);
                                        $isActive = $a['is_active'] && $start <= $now && $end >= $now;
                                        $isPast = $end < $now;
                                        $isFuture = $start > $now;
                                        $opt = $typeOptions[$a['type']] ?? $typeOptions['info'];
                                    ?>
                                    <div class="card mb-3 border-<?= $opt['color'] ?> <?= !$a['is_active'] || $isPast ? 'opacity-50' : '' ?>">
                                        <div class="card-header bg-<?= $opt['color'] ?> <?= $a['type'] === 'warning' ? 'text-dark' : 'text-white' ?> py-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span>
                                                    <i class="bi <?= $opt['icon'] ?> me-2"></i>
                                                    <strong><?= h($a['title']) ?></strong>
                                                </span>
                                                <span>
                                                    <?php if (!$a['is_active']): ?>
                                                        <span class="badge bg-secondary">Disabled</span>
                                                    <?php elseif ($isActive): ?>
                                                        <span class="badge bg-success">Active Now</span>
                                                    <?php elseif ($isFuture): ?>
                                                        <span class="badge bg-info">Scheduled</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Expired</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body py-2">
                                            <p class="mb-2"><?= nl2br(h($a['content'])) ?></p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar me-1"></i>
                                                    <?= date('M j, Y g:i A', $start) ?> - <?= date('M j, Y g:i A', $end) ?>
                                                    <?php if ($a['show_once']): ?>
                                                        <span class="badge bg-light text-dark ms-2">Dismissible</span>
                                                    <?php endif; ?>
                                                </small>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?edit=<?= $a['id'] ?>" class="btn btn-outline-secondary">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>
                                                    <form method="post" class="d-inline" 
                                                        onsubmit="return confirm('Delete this announcement?');">
                                                        <?= csrf_field() ?>
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="announcement_id" value="<?= $a['id'] ?>">
                                                        <button type="submit" class="btn btn-outline-danger">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
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
